<?php

namespace App\Domain\Crm;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

/** Free-text list search (filter before pagination). */
final class CrmListSearch
{
    public static function normalizeQuery(?string $q): ?string
    {
        $q = trim((string) $q);
        if ($q === '') {
            return null;
        }

        if (strlen($q) > CrmConfigLimits::LIST_SEARCH_QUERY_MAX) {
            $q = substr($q, 0, CrmConfigLimits::LIST_SEARCH_QUERY_MAX);
        }

        return $q;
    }

    /** @param Builder<\App\Models\Customer> $query */
    public static function applyCustomerSearch(Builder $query, ?string $q): Builder
    {
        $term = self::normalizeQuery($q);
        if ($term === null) {
            return $query;
        }

        $pattern = '%'.self::escapeLike($term).'%';

        return $query->where(function (Builder $inner) use ($pattern) {
            $inner->where('client_id', 'like', $pattern)
                ->orWhere('name', 'like', $pattern)
                ->orWhere('company', 'like', $pattern)
                ->orWhere('email', 'like', $pattern)
                ->orWhere('phone', 'like', $pattern);
        });
    }

    /** @param Builder<\App\Models\Lead> $query */
    public static function applyLeadSearch(Builder $query, ?string $q): Builder
    {
        $term = self::normalizeQuery($q);
        if ($term === null) {
            return $query;
        }

        $pattern = '%'.self::escapeLike($term).'%';

        $customerIds = Customer::query()->where('client_id', 'like', $pattern)->select('id');
        $leadIdsViaCustomer = Customer::query()
            ->where('client_id', 'like', $pattern)
            ->whereNotNull('lead_id')
            ->select('lead_id');

        return $query->where(function (Builder $inner) use ($pattern, $customerIds, $leadIdsViaCustomer) {
            $inner->where('name', 'like', $pattern)
                ->orWhere('company', 'like', $pattern)
                ->orWhereIn('customer_id', $customerIds)
                ->orWhereIn('id', $leadIdsViaCustomer);
        });
    }

    /** Escape `%` and `\` only — client IDs contain `_` (SQLite LIKE has no default escape). */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%'], ['\\\\', '\\%'], $value);
    }
}
