<?php

namespace App\Domain\Crm;

use App\Models\Setting;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Single source for lead dropdown catalogues.
 * Stored in settings key `crm.lead_picklists` (JSON); code defaults only fill missing keys.
 */
class LeadPicklistService
{
    public const SETTINGS_KEY = 'crm.lead_picklists';

    /** Keys editable in System Settings UI (one textarea each). */
    public const ADMIN_UI_KEYS = [
        'customer_segment',
        'industry',
        'geo_location',
        'lead_source',
        'product_interest',
        'current_stage',
    ];

    /** Catalogues that must retain at least PICKLIST_MIN_OPTIONS_CRITICAL options on save. */
    public const CRITICAL_MIN_KEYS = ['current_stage', 'activity_outcome'];

    public function catalogKeys(): array
    {
        return array_keys($this->defaults());
    }

    public function defaults(): array
    {
        return [
            'lead_source' => ['Referrals', 'Organic Search', 'Paid Ads', 'Social Media', 'Website', 'Events', 'Partner', 'Walk-in', 'Other'],
            'product_interest' => ['CRM', 'Marketing', 'Project Management', 'HR', 'Finance', 'Custom Integration', 'Other'],
            'customer_segment' => ['Startup', 'SME', 'Enterprise', 'Government', 'Non-profit', 'Education', 'Other'],
            'industry' => [
                'Banking & Finance',
                'Education',
                'Telecommunications',
                'Healthcare',
                'Retail & E-commerce',
                'Manufacturing',
                'Logistics & Transport',
                'Government & Public',
                'Hospitality & Tourism',
                'Energy & Utilities',
                'Agriculture',
                'Media & Entertainment',
                'Professional Services',
                'Technology / Software',
                'NGO / Development',
                'Other',
            ],
            'geo_location' => [
                'Yangon',
                'Mandalay',
                'Naypyidaw',
                'Bago',
                'Ayeyarwady',
                'Magway',
                'Sagaing',
                'Tanintharyi',
                'Mon',
                'Kayin',
                'Kayah',
                'Chin',
                'Rakhine',
                'Shan (North)',
                'Shan (South)',
                'Shan (East)',
                'Kachin',
                'Nationwide / Multi-region',
                'International',
                'Other',
            ],
            'contact_role' => ['Owner', 'Director', 'Manager', 'Staff', 'Procurement', 'IT', 'Other'],
            'current_stage' => ['New', 'Contacted', 'Qualified', 'Demo / Meeting', 'Proposal', 'Negotiation', 'Won', 'Lost'],
            'interest_level' => ['Hot', 'Warm', 'Cold'],
            'buying_timeline' => ['Immediate', '1 Month', '3 Months', '6 Months', 'Unknown'],
            'contact_method' => ['Call', 'Email', 'Viber', 'LINE', 'Facebook', 'Telegram', 'Meeting', 'Other'],
            'activity_outcome' => ['No response', 'Connected', 'Follow-up needed', 'Demo booked', 'Proposal sent', 'Won', 'Lost'],
            'completed' => ['Yes', 'No'],
        ];
    }

    public function all(): array
    {
        $defaults = $this->defaults();
        $stored = Setting::get(self::SETTINGS_KEY, []);
        if (!is_array($stored)) {
            return $defaults;
        }

        $merged = array_replace($defaults, array_intersect_key($stored, $defaults));

        foreach ($merged as $key => $values) {
            $merged[$key] = $this->sanitizeOptions($values, $defaults[$key] ?? []);
        }

        return $merged;
    }

    public function options(string $key): array
    {
        if (!in_array($key, $this->catalogKeys(), true)) {
            return [];
        }

        return $this->all()[$key] ?? [];
    }

    /** Code defaults for one catalogue (poka-yoke restore source). */
    public function defaultOptions(string $key): array
    {
        $defaults = $this->defaults();

        return $defaults[$key] ?? [];
    }

    /**
     * Replace one catalogue in settings with code defaults; other keys unchanged.
     *
     * @return array<int, string>
     */
    public function restoreKeyToDefaults(string $key, ?int $updatedBy = null): array
    {
        if (!in_array($key, $this->catalogKeys(), true)) {
            throw ValidationException::withMessages([
                $key => ['Unknown picklist catalogue key.'],
            ]);
        }

        $defaults = $this->defaults();
        $restored = $this->sanitizeOptions($defaults[$key] ?? [], $defaults[$key] ?? []);

        $stored = Setting::get(self::SETTINGS_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $stored[$key] = $restored;
        $clean = [];
        foreach ($this->catalogKeys() as $catalogKey) {
            $clean[$catalogKey] = array_key_exists($catalogKey, $stored)
                ? $this->sanitizeOptions((array) $stored[$catalogKey], $defaults[$catalogKey] ?? [])
                : $this->sanitizeOptions($defaults[$catalogKey] ?? [], $defaults[$catalogKey] ?? []);
        }

        Setting::put(self::SETTINGS_KEY, $clean, 'crm', $updatedBy);

        return $restored;
    }

    /**
     * @return list<array{field: string, value: string}>
     */
    public function legacyWarningsForLead(\App\Models\Lead $lead): array
    {
        $warnings = [];
        foreach (self::ADMIN_UI_KEYS as $field) {
            $value = $lead->{$field};
            if ($value === null || $value === '') {
                continue;
            }
            if (!in_array((string) $value, $this->options($field), true)) {
                $warnings[] = ['field' => $field, 'value' => (string) $value];
            }
        }

        return $warnings;
    }

    public function rule(string $key, bool $sometimes = false): array
    {
        $prefix = $sometimes ? ['sometimes'] : ['nullable'];
        $allowed = $this->options($key);
        if ($allowed === []) {
            return $prefix;
        }

        return array_merge($prefix, ['string', 'max:'.CrmConfigLimits::PICKLIST_OPTION_MAX_LENGTH, Rule::in($allowed)]);
    }

    /** Filter query param must match a configured option (fail closed). */
    public function assertFilterValue(string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (!in_array($value, $this->options($key), true)) {
            throw ValidationException::withMessages([
                $key => ['Invalid filter value.'],
            ]);
        }
    }

    /**
     * Persist catalogue (admin). Unknown top-level keys rejected; options sanitized and capped.
     *
     * @throws ValidationException
     */
    public function put(array $picklists, ?int $updatedBy = null): array
    {
        $allowedKeys = $this->catalogKeys();
        foreach (array_keys($picklists) as $key) {
            if (!in_array($key, $allowedKeys, true)) {
                throw ValidationException::withMessages([
                    $key => ['Unknown picklist catalogue key.'],
                ]);
            }
        }

        $defaults = $this->defaults();
        $clean = [];

        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $picklists)) {
                $clean[$key] = $this->sanitizeOptions($this->options($key), $defaults[$key]);
                continue;
            }
            if (!is_array($picklists[$key])) {
                throw ValidationException::withMessages([
                    $key => ['Picklist must be an array of strings.'],
                ]);
            }
            $clean[$key] = $this->sanitizeOptions($picklists[$key], $defaults[$key]);
            if ($clean[$key] === [] && ($defaults[$key] ?? []) !== []) {
                throw ValidationException::withMessages([
                    $key => ['Picklist cannot be empty after validation.'],
                ]);
            }
            $this->assertMinimumOptionCount($key, $clean[$key]);
        }

        Setting::put(self::SETTINGS_KEY, $clean, 'crm', $updatedBy);

        return $this->all();
    }

    /**
     * @param  array<int, mixed>  $values
     * @param  array<int, string>  $fallback
     * @return array<int, string>
     */
    public function sanitizeOptions(array $values, array $fallback = []): array
    {
        $out = [];
        foreach ($values as $v) {
            if (!is_string($v)) {
                continue;
            }
            $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', trim($v)) ?? '';
            if ($v === '') {
                continue;
            }
            $v = mb_substr($v, 0, CrmConfigLimits::PICKLIST_OPTION_MAX_LENGTH);
            $out[] = $v;
            if (count($out) >= CrmConfigLimits::PICKLIST_OPTIONS_MAX_COUNT) {
                break;
            }
        }
        $out = array_values(array_unique($out));

        return $out !== [] ? $out : $fallback;
    }

    protected function assertMinimumOptionCount(string $key, array $options): void
    {
        if (!in_array($key, self::CRITICAL_MIN_KEYS, true)) {
            return;
        }
        if (count($options) < CrmConfigLimits::PICKLIST_MIN_OPTIONS_CRITICAL) {
            throw ValidationException::withMessages([
                $key => ['This catalogue must keep at least '.CrmConfigLimits::PICKLIST_MIN_OPTIONS_CRITICAL.' options.'],
            ]);
        }
    }
}
