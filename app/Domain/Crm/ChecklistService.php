<?php

namespace App\Domain\Crm;

use App\Models\ChecklistCompletion;
use App\Models\Customer;
use App\Models\PipelineStage;
use App\Models\Setting;
use App\Models\StageChecklistItem;
use Illuminate\Support\Facades\DB;

class ChecklistService
{
    /**
     * Checklist definitions per stage. Stored in the `settings` table under
     * `crm.checklists.<stage_code>` as an array of {title, description?, is_required?}.
     * Admins add/remove/modify these from System Settings without a deploy.
     */
    public function definitionsForStage(string $stageCode): array
    {
        return Setting::get("crm.checklists.{$stageCode}", []);
    }

    /**
     * Instantiate the dynamic checklist for a customer:
     * one checklist_completions row per configured item, all not done.
     */
    public function instantiateForCustomer(Customer $customer, string $stageCode): int
    {
        $stage = PipelineStage::query()->where('code', $stageCode)->first();
        if (!$stage) {
            return 0;
        }

        $definitions = $this->definitionsForStage($stageCode);
        if (empty($definitions)) {
            return 0;
        }

        return DB::transaction(function () use ($customer, $stage, $definitions) {
            // Sync the definition table (dynamic, settings-driven)
            $itemIds = [];
            foreach (array_values($definitions) as $i => $def) {
                $item = StageChecklistItem::updateOrCreate(
                    ['stage_id' => $stage->id, 'title' => $def['title']],
                    [
                        'description' => $def['description'] ?? null,
                        'is_required' => $def['is_required'] ?? true,
                        'sort_order' => $i,
                        'is_active' => true,
                    ]
                );
                $itemIds[$item->id] = ['source' => 'settings'];
            }

            // Deactivate items no longer present in settings (removed by admin)
            StageChecklistItem::query()
                ->where('stage_id', $stage->id)
                ->whereNotIn('id', array_keys($itemIds))
                ->update(['is_active' => false]);

            $created = 0;
            foreach (array_keys($itemIds) as $itemId) {
                ChecklistCompletion::firstOrCreate(
                    ['customer_id' => $customer->id, 'checklist_item_id' => $itemId],
                    ['is_done' => false]
                );
                $created++;
            }

            return $created;
        });
    }

    /** Completion report for a customer grouped by stage. */
    public function completionFor(Customer $customer): array
    {
        $completions = ChecklistCompletion::with('item.stage')
            ->where('customer_id', $customer->id)
            ->get();

        $grouped = [];
        foreach ($completions as $c) {
            $stageName = $c->item?->stage?->name ?? 'General';
            $grouped[$stageName]['items'][] = [
                'id' => $c->id,                                  // completion row id
                'checklist_item_id' => $c->checklist_item_id,    // definition id (used by toggle endpoint)
                'title' => $c->item?->title,
                'is_required' => (bool) $c->item?->is_required,
                'is_done' => (bool) $c->is_done,
                'done_at' => $c->done_at?->toISOString(),
                'done_by' => $c->doneBy?->name,
            ];
        }

        foreach ($grouped as $stage => &$data) {
            $items = $data['items'];
            $data['total'] = count($items);
            $data['done'] = count(array_filter($items, fn ($i) => $i['is_done']));
            $data['percent'] = $data['total'] > 0 ? (int) round($data['done'] / $data['total'] * 100) : 0;
        }

        return $grouped;
    }

    /** Toggle one checklist item for a customer (audit: done_by / done_at). */
    public function setItemDone(Customer $customer, int $itemId, bool $done, ?string $note = null): ChecklistCompletion
    {
        $completion = ChecklistCompletion::query()
            ->where('customer_id', $customer->id)
            ->where(function ($q) use ($itemId) {
                $q->where('checklist_item_id', $itemId)
                  ->orWhere('id', $itemId);
            })
            ->firstOrFail();

        $completion->update([
            'is_done' => $done,
            'done_by' => $done ? (auth()->id() ?? $completion->done_by) : null,
            'done_at' => $done ? now() : null,
            'note' => $note ?? $completion->note,
        ]);

        return $completion;
    }
}
