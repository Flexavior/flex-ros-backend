<?php

namespace App\Domain\Crm;

use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ScopeService
{
    /**
     * Apply visibility scope to a leads query based on the reporting hierarchy:
     * - Staff/Sales/Marketing: own records only
     * - Senior staff: own + records of staff assigned under them (downstream works)
     * - Supervisor: whole team unit
     * - CEO / Senior Management / Admin: entire organisation
     */
    public function applyLeadScope(Builder $query, User $user): Builder
    {
        if ($user->isOrgLevel()) {
            return $query; // org-wide
        }

        if ($user->hasRole(Role::SUPERVISOR)) {
            // Team scope: users assigned to the same team unit (incl. self)
            $teamUserIds = $this->teamMemberIds($user);

            return $query->where(function ($q) use ($user, $teamUserIds) {
                $q->whereIn('owner_id', $teamUserIds)
                  ->orWhere('created_by', $user->id);
            });
        }

        if ($user->hasRole(Role::SENIOR_STAFF)) {
            // Own + downstream (direct staff under this senior staff)
            $downstreamIds = $user->subordinates()->pluck('id')->all();

            return $query->where(function ($q) use ($user, $downstreamIds) {
                $q->whereIn('owner_id', array_merge([$user->id], $downstreamIds))
                  ->orWhere('created_by', $user->id);
            });
        }

        // Staff / Sales / Marketing: own records only
        return $query->where(function ($q) use ($user) {
            $q->where('owner_id', $user->id)
              ->orWhere('created_by', $user->id);
        });
    }

    /** Can the user view/modify this lead? */
    public function canAccessLead(User $user, Lead $lead): bool
    {
        if ($user->isOrgLevel()) {
            return true;
        }

        if ($user->hasRole(Role::SUPERVISOR)) {
            $teamUserIds = $this->teamMemberIds($user);
            if (in_array($lead->owner_id, $teamUserIds) || $lead->created_by === $user->id) {
                return true;
            }

            return false;
        }

        $visibleOwnerIds = [$user->id];
        if ($user->hasRole(Role::SENIOR_STAFF)) {
            $visibleOwnerIds = array_merge($visibleOwnerIds, $user->subordinates()->pluck('id')->all());
        }

        return in_array($lead->owner_id, $visibleOwnerIds) || $lead->created_by === $user->id;
    }

    /**
     * Apply visibility scope to a customers query (same hierarchy rules as leads).
     */
    public function applyCustomerScope(\Illuminate\Database\Eloquent\Builder $query, User $user): \Illuminate\Database\Eloquent\Builder
    {
        if ($user->isOrgLevel()) {
            return $query;
        }

        if ($user->hasRole(Role::SUPERVISOR)) {
            $teamUserIds = $this->teamMemberIds($user);

            return $query->where(function ($q) use ($user, $teamUserIds) {
                $q->whereIn('owner_id', $teamUserIds)
                  ->orWhere('created_by', $user->id);
            });
        }

        if ($user->hasRole(Role::SENIOR_STAFF)) {
            $downstreamIds = $user->subordinates()->pluck('id')->all();

            return $query->where(function ($q) use ($user, $downstreamIds) {
                $q->whereIn('owner_id', array_merge([$user->id], $downstreamIds))
                  ->orWhere('created_by', $user->id);
            });
        }

        return $query->where(function ($q) use ($user) {
            $q->where('owner_id', $user->id)
              ->orWhere('created_by', $user->id);
        });
    }

    /**
     * IDs of every user belonging to the same team unit as $user (including self).
     */
    protected function teamMemberIds(User $user): array
    {
        if (!$user->team_id) {
            return [$user->id];
        }

        return User::where('team_id', $user->team_id)->pluck('id')->all() ?: [$user->id];
    }
}
