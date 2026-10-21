<?php

namespace App\Domain\Crm;

use App\Models\Role;
use App\Models\User;

final class DashboardPersonaResolver
{
    /** @return array{persona:string,sections:list<string>} */
    public function resolve(User $user): array
    {
        $role = $user->role?->code ?? Role::STAFF;

        if (in_array($role, [Role::CEO, Role::SENIOR_MANAGEMENT], true)) {
            return [
                'persona' => 'leadership',
                'sections' => ['actions', 'kpis', 'mql_sql', 'revenue', 'rep_activity', 'funnel', 'marketing', 'checklist'],
            ];
        }

        if ($role === Role::MARKETING) {
            return [
                'persona' => 'marketing',
                'sections' => ['actions', 'kpis', 'mql_sql', 'marketing', 'funnel'],
            ];
        }

        if ($role === Role::CUSTOMER_SERVICE) {
            return [
                'persona' => 'customer_service',
                'sections' => ['actions', 'checklist'],
            ];
        }

        return [
            'persona' => in_array($role, [Role::SUPERVISOR, Role::SENIOR_STAFF], true) ? 'sales_manager' : 'sales',
            'sections' => ['actions', 'kpis', 'mql_sql', 'rep_activity', 'funnel'],
        ];
    }
}
