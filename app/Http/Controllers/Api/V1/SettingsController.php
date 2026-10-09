<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Crm\CrmConfigLimits;
use App\Domain\Crm\LeadPicklistService;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function __construct(protected LeadPicklistService $leadPicklists)
    {
    }
    /** GET /api/v1/stages — stage catalogue + dynamic checklist definitions. */
    public function stages()
    {
        $stages = \App\Models\PipelineStage::with(['checklistItems' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json($stages);
    }

    /** GET /api/v1/settings/checklists — settings-driven checklist config. */
    public function getChecklists()
    {
        $keys = \App\Models\PipelineStage::where('is_active', true)->pluck('code');

        $checklists = [];
        foreach ($keys as $code) {
            $checklists[$code] = Setting::get("crm.checklists.{$code}", []);
        }

        return response()->json($checklists);
    }

    /** PUT /api/v1/settings/checklists — admin only: dynamic add/remove/modify. */
    public function updateChecklists(Request $request)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            '*.*.title' => 'required|string|max:255',
            '*.*.description' => 'nullable|string',
            '*.*.is_required' => 'nullable|boolean',
        ]);

        $updated = [];
        foreach ($data as $stageCode => $items) {
            // Validate the stage exists
            if (!\App\Models\PipelineStage::where('code', $stageCode)->exists()) {
                throw ValidationException::withMessages([$stageCode => ['Unknown stage.']]);
            }
            Setting::put("crm.checklists.{$stageCode}", array_values($items));
            $updated[] = $stageCode;
        }

        return response()->json(['updated' => $updated]);
    }

    /** GET/PUT crm.stale_task_days and other thresholds. */
    public function getGeneral()
    {
        return response()->json([
            'crm.stale_task_days' => Setting::get('crm.stale_task_days', 5),
            'crm.client_id_prefix' => Setting::get('crm.client_id_prefix', 'CUS'),
        ]);
    }

    public function updateGeneral(Request $request)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'crm.stale_task_days' => 'sometimes|integer|min:1|max:90',
            'crm.client_id_prefix' => 'sometimes|string|max:10',
        ]);

        foreach ($data as $key => $value) {
            Setting::put($key, $value);
        }

        return $this->getGeneral();
    }

    public function roles()
    {
        return response()->json(Role::orderBy('level')->get());
    }

    /** GET /api/v1/settings/lead-picklists — dropdown catalogues (settings-backed). */
    public function getLeadPicklists()
    {
        return response()->json($this->leadPicklists->all());
    }

    /** PUT /api/v1/settings/lead-picklists — admin: edit option lists (industry, geo, segment…). */
    public function updateLeadPicklists(Request $request)
    {
        $this->authorizeAdmin($request);

        $keys = $this->leadPicklists->catalogKeys();
        $unknown = array_diff(array_keys($request->all()), $keys);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                (string) array_values($unknown)[0] => ['Unknown picklist catalogue key.'],
            ]);
        }
        $rules = [];
        foreach ($keys as $key) {
            $rules[$key] = 'sometimes|array|max:'.CrmConfigLimits::PICKLIST_OPTIONS_MAX_COUNT;
            $rules["{$key}.*"] = 'string|max:'.CrmConfigLimits::PICKLIST_OPTION_MAX_LENGTH;
        }
        $data = $request->validate($rules);

        return response()->json($this->leadPicklists->put($data, $request->user()->id));
    }

    protected function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->hasRole(Role::ADMIN), 403, 'System settings require the admin role.');
    }
}
