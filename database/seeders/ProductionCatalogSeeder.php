<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Prod-safe catalogue bootstrap (Docker CMD). Does not run RoleAndUserSeeder.
 */
class ProductionCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CrmStagesAndSettingsSeeder::class,
            CrmChecklistsAndIntegrationsSeeder::class,
            MarketingChannelSeeder::class,
        ]);
    }
}
