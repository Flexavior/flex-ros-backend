<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Crm\CrmConfigLimits;
use App\Domain\Crm\LeadPicklistService;
use App\Http\Controllers\Controller;
use App\Models\Lead;
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
        $qualify = Lead::qualifyConfig();

        return response()->json([
            'crm.stale_task_days' => Setting::get('crm.stale_task_days', 5),
            'crm.client_id_prefix' => Setting::get('crm.client_id_prefix', 'CUS'),
            'crm.qualify' => $qualify,
        ]);
    }

    public function updateGeneral(Request $request)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'crm.stale_task_days' => 'sometimes|integer|min:1|max:90',
            'crm.client_id_prefix' => 'sometimes|string|max:10',
        ]);

        $requestData = $request->all();
        if (array_key_exists('crm.qualify', $requestData)) {
            if (!is_array($requestData['crm.qualify'])) {
                throw ValidationException::withMessages([
                    'crm.qualify' => ['The crm.qualify field must be an array.'],
                ]);
            }

            $qualify = validator($requestData['crm.qualify'], [
                'max_idle_touches' => 'sometimes|integer|min:1|max:50',
                'progress_outcomes' => 'sometimes|array|min:1|max:20',
                'progress_outcomes.*' => 'string|max:'.CrmConfigLimits::PICKLIST_OPTION_MAX_LENGTH,
            ])->validate();

            $data['crm.qualify'] = $qualify;
        }

        if (isset($data['crm.qualify']['progress_outcomes'])) {
            $allowed = $this->leadPicklists->options('activity_outcome');
            foreach ($data['crm.qualify']['progress_outcomes'] as $outcome) {
                if (!in_array($outcome, $allowed, true)) {
                    throw ValidationException::withMessages([
                        'crm.qualify.progress_outcomes' => ['Each progress outcome must match activity_outcome picklist options.'],
                    ]);
                }
            }
        }

        foreach ($data as $key => $value) {
            if ($key === 'crm.qualify') {
                $value = array_replace(Lead::qualifyDefaults(), $value);
            }
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

    /** GET /api/v1/settings/lead-picklists/defaults — code defaults (poka-yoke reference). */
    public function getLeadPicklistDefaults(Request $request)
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'defaults' => $this->leadPicklists->defaults(),
            'admin_ui_keys' => LeadPicklistService::ADMIN_UI_KEYS,
        ]);
    }

    /** POST /api/v1/settings/lead-picklists/restore/{key} — reset one catalogue to code defaults. */
    public function restoreLeadPicklist(Request $request, string $key)
    {
        $this->authorizeAdmin($request);

        if (!in_array($key, $this->leadPicklists->catalogKeys(), true)) {
            throw ValidationException::withMessages([
                $key => ['Unknown picklist catalogue key.'],
            ]);
        }

        $restored = $this->leadPicklists->restoreKeyToDefaults($key, $request->user()->id);

        return response()->json([
            'key' => $key,
            'options' => $restored,
            'picklists' => $this->leadPicklists->all(),
        ]);
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
