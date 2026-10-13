<?php

namespace App\Console\Commands;

use App\Domain\Crm\LeadFieldCanonical;
use App\Domain\Crm\LeadPipelineSyncService;
use App\Models\Lead;
use Illuminate\Console\Command;

/**
 * After SQL reimport: backfill canonical columns from legacy mirrors (source, status, segment).
 * Safe to run repeatedly (idempotent for already-normalized rows).
 */
class NormalizeLeadLegacyFieldsCommand extends Command
{
    protected $signature = 'crm:normalize-lead-legacy-fields {--dry-run : Report counts only}';

    protected $description = 'Find and fix legacy lead columns (source, stage, School→Education, organization_type)';

    public function handle(LeadPipelineSyncService $sync): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;
        $segmentSchoolToEducation = 0;
        $organizationTypeBackfilled = 0;
        $pipelineOrSourceSynced = 0;

        Lead::query()->orderBy('id')->chunkById(200, function ($leads) use (
            $sync,
            $dryRun,
            &$updated,
            &$segmentSchoolToEducation,
            &$organizationTypeBackfilled,
            &$pipelineOrSourceSynced
        ) {
            foreach ($leads as $lead) {
                $attrs = $lead->only([
                    LeadFieldCanonical::PIPELINE_STAGE,
                    LeadFieldCanonical::LEGACY_STATUS,
                    LeadFieldCanonical::LEAD_SOURCE,
                    LeadFieldCanonical::LEGACY_SOURCE,
                    LeadFieldCanonical::ORGANIZATION_TYPE,
                    LeadFieldCanonical::CUSTOMER_SEGMENT,
                ]);

                $hadLegacySchool = strcasecmp(trim((string) ($attrs[LeadFieldCanonical::CUSTOMER_SEGMENT] ?? '')), 'School') === 0;
                $orgWasEmpty = trim((string) ($attrs[LeadFieldCanonical::ORGANIZATION_TYPE] ?? '')) === '';

                $sync->syncWritableAttributes($attrs);
                $sync->backfillCustomerSegmentLabels($attrs);
                $sync->backfillOrganizationType($attrs);

                $dirty = false;
                foreach ($attrs as $key => $value) {
                    if ($lead->getAttribute($key) != $value) {
                        $dirty = true;
                        break;
                    }
                }

                if (!$dirty) {
                    continue;
                }

                if ($hadLegacySchool && ($attrs[LeadFieldCanonical::CUSTOMER_SEGMENT] ?? '') === 'Education') {
                    $segmentSchoolToEducation++;
                }
                if ($orgWasEmpty && trim((string) ($attrs[LeadFieldCanonical::ORGANIZATION_TYPE] ?? '')) !== '') {
                    $organizationTypeBackfilled++;
                }
                if (
                    $lead->getAttribute(LeadFieldCanonical::PIPELINE_STAGE) != ($attrs[LeadFieldCanonical::PIPELINE_STAGE] ?? null)
                    || $lead->getAttribute(LeadFieldCanonical::LEAD_SOURCE) != ($attrs[LeadFieldCanonical::LEAD_SOURCE] ?? null)
                    || $lead->getAttribute(LeadFieldCanonical::LEGACY_STATUS) != ($attrs[LeadFieldCanonical::LEGACY_STATUS] ?? null)
                    || $lead->getAttribute(LeadFieldCanonical::LEGACY_SOURCE) != ($attrs[LeadFieldCanonical::LEGACY_SOURCE] ?? null)
                ) {
                    $pipelineOrSourceSynced++;
                }

                $updated++;
                if ($dryRun) {
                    continue;
                }

                $lead->fill($attrs);
                $lead->saveQuietly();
            }
        });

        $prefix = $dryRun ? 'Would update' : 'Updated';
        $this->info("{$prefix} {$updated} lead(s).");
        $this->line("  School → Education (segment): {$segmentSchoolToEducation}");
        $this->line("  organization_type backfilled: {$organizationTypeBackfilled}");
        $this->line("  pipeline/source/status sync: {$pipelineOrSourceSynced}");

        return self::SUCCESS;
    }
}
