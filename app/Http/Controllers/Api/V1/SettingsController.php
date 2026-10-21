<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Crm\CrmConfigLimits;
use App\Domain\Crm\DashboardConfig;
use App\Domain\Crm\LeadPicklistService;
use App\Domain\Inbox\InboxAccess;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Role;
use App\Models\Setting;
use App\Models\SystemNotification;
use App\Support\SsoSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
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

        if ($updated !== []) {
            Log::info('settings.checklists_updated', [
                'stages' => $updated,
                'admin_id' => $request->user()->id,
            ]);
        }

        return response()->json([
            'updated' => $updated,
            'audit' => [
                'events' => array_map(static fn ($c) => "crm.checklists.{$c}", $updated),
                'logged_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /** GET/PUT crm.stale_task_days and other thresholds. */
    public function getGeneral()
    {
        $qualify = Lead::qualifyConfig();

        return response()->json($this->generalSettingsPayload());
    }

    public function updateGeneral(Request $request)
    {
        $this->authorizeAdmin($request);

        $body = $request->all();
        $auditEvents = [];

        if (array_key_exists('crm.stale_task_days', $body)) {
            $days = validator(
                ['v' => $body['crm.stale_task_days']],
                ['v' => 'required|integer|min:1|max:90']
            )->validate()['v'];
            Setting::put('crm.stale_task_days', $days, 'crm', $request->user()->id);
            $auditEvents[] = 'crm.stale_task_days';
        }

        if (array_key_exists('crm.qualify', $body)) {
            if (!is_array($body['crm.qualify'])) {
                throw ValidationException::withMessages([
                    'crm.qualify' => ['The crm.qualify field must be an array.'],
                ]);
            }

            $qualify = validator($body['crm.qualify'], [
                'max_idle_touches' => 'sometimes|integer|min:1|max:50',
                'progress_outcomes' => 'sometimes|array|min:1|max:20',
                'progress_outcomes.*' => 'string|max:'.CrmConfigLimits::PICKLIST_OPTION_MAX_LENGTH,
            ])->validate();

            if (isset($qualify['progress_outcomes'])) {
                $allowed = $this->leadPicklists->options('activity_outcome');
                foreach ($qualify['progress_outcomes'] as $outcome) {
                    if (!in_array($outcome, $allowed, true)) {
                        throw ValidationException::withMessages([
                            'crm.qualify.progress_outcomes' => ['Each progress outcome must match activity_outcome picklist options.'],
                        ]);
                    }
                }
            }

            Setting::put('crm.qualify', array_replace(Lead::qualifyDefaults(), $qualify), 'crm', $request->user()->id);
            $auditEvents[] = 'crm.qualify';
        }

        $dashboardStageKeys = [
            DashboardConfig::MQL_STAGES_KEY,
            DashboardConfig::SQL_STAGES_KEY,
            DashboardConfig::PIPELINE_STAGES_KEY,
        ];
        $allowedStages = $this->leadPicklists->options('current_stage');
        foreach ($dashboardStageKeys as $key) {
            if (! array_key_exists($key, $body)) {
                continue;
            }

            $stages = validator(
                ['v' => $body[$key]],
                [
                    'v' => 'required|array|min:1',
                    'v.*' => ['required', 'string', Rule::in($allowedStages)],
                ]
            )->validate()['v'];

            Setting::put($key, array_values(array_unique($stages)), 'dashboard', $request->user()->id);
            $auditEvents[] = $key;
        }

        foreach (['microsoft', 'google'] as $provider) {
            $flatKey = SsoSetting::settingKey($provider);
            if (! array_key_exists($flatKey, $body)) {
                continue;
            }
            $enabled = validator(
                ['v' => $body[$flatKey]],
                ['v' => 'required|boolean']
            )->validate()['v'];
            Setting::put($flatKey, $enabled, 'auth', $request->user()->id);
            $auditEvents[] = $flatKey;
        }

        if (array_key_exists('crm.inbox_enabled_roles', $body)) {
            $roles = validator(
                ['r' => $body['crm.inbox_enabled_roles']],
                [
                    'r' => 'present|array',
                    'r.*' => ['string', Rule::in(Role::INBOX_AGENTS)],
                ]
            )->validate()['r'];
            $roles = array_values(array_intersect($roles, Role::INBOX_AGENTS));
            Setting::put(InboxAccess::SETTING_KEY, $roles, 'crm', $request->user()->id);
            Log::info('settings.inbox_access_updated', [
                'roles' => $roles,
                'admin_id' => $request->user()->id,
            ]);
            $auditEvents[] = InboxAccess::SETTING_KEY;
        }

        if ($auditEvents !== []) {
            Log::info('settings.general_updated', [
                'keys' => $auditEvents,
                'admin_id' => $request->user()->id,
            ]);
        }

        return response()->json(array_merge($this->generalSettingsPayload(), [
            'audit' => [
                'events' => $auditEvents,
                'logged_at' => now()->toIso8601String(),
            ],
        ]));
    }

    /** @return array<string, mixed> */
    protected function generalSettingsPayload(): array
    {
        return array_merge([
            'crm.stale_task_days' => Setting::get('crm.stale_task_days', 5),
            'crm.qualify' => Lead::qualifyConfig(),
            'crm.inbox_enabled_roles' => InboxAccess::enabledRoleCodes(),
            'crm.inbox_role_catalog' => InboxAccess::roleCatalog(),
            'crm.dashboard.stage_catalog' => $this->leadPicklists->options('current_stage'),
            SsoSetting::settingKey('microsoft') => SsoSetting::isProviderEnabled('microsoft'),
            SsoSetting::settingKey('google') => SsoSetting::isProviderEnabled('google'),
        ], DashboardConfig::all());
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

        Log::info('settings.lead_picklist_restored', [
            'key' => $key,
            'admin_id' => $request->user()->id,
        ]);

        return response()->json([
            'key' => $key,
            'options' => $restored,
            'picklists' => $this->leadPicklists->all(),
            'audit' => [
                'events' => ["crm.lead_picklists.{$key}.restore"],
                'logged_at' => now()->toIso8601String(),
            ],
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

        $keysUpdated = array_keys($data);
        if ($keysUpdated !== []) {
            Log::info('settings.lead_picklists_updated', [
                'keys' => $keysUpdated,
                'admin_id' => $request->user()->id,
            ]);
        }

        return response()->json(array_merge(
            $this->leadPicklists->put($data, $request->user()->id),
            [
                'audit' => [
                    'events' => array_map(static fn ($k) => "crm.lead_picklists.{$k}", $keysUpdated),
                    'logged_at' => now()->toIso8601String(),
                ],
            ]
        ));
    }

    /** GET /api/v1/settings/system-notifications — admin list (incl. inactive). */
    public function getSystemNotifications(Request $request)
    {
        $this->authorizeAdmin($request);

        $rows = SystemNotification::query()
            ->with('createdBy:id,name')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json($rows);
    }

    /** POST /api/v1/settings/system-notifications — broadcast to all users. */
    public function storeSystemNotification(Request $request)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'body' => 'required|string|max:2000',
            'effective_from' => 'nullable|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from',
        ]);

        $row = SystemNotification::create([
            'body' => $data['body'],
            'effective_from' => $data['effective_from'] ?? now(),
            'effective_until' => $data['effective_until'] ?? null,
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);

        Log::info('system_notification.created', [
            'id' => $row->id,
            'admin_id' => $request->user()->id,
        ]);

        return response()->json($row->load('createdBy:id,name'), 201);
    }

    /** PATCH /api/v1/settings/system-notifications/{systemNotification} — deactivate. */
    public function updateSystemNotification(Request $request, SystemNotification $systemNotification)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'is_active' => 'sometimes|boolean',
            'body' => 'sometimes|string|max:2000',
            'effective_until' => 'nullable|date',
        ]);

        $systemNotification->update($data);

        return response()->json($systemNotification->fresh());
    }

    protected function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->hasRole(Role::ADMIN), 403, 'System settings require the admin role.');
    }
}
