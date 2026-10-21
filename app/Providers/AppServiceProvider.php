<?php

namespace App\Providers;

use App\Domain\Crm\CrmDashboardBroadcastService;
use App\Models\Agreement;
use App\Models\Appointment;
use App\Models\Engagement;
use App\Models\Lead;
use App\Models\MarketingCampaign;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $notify = static function (string $reason) {
            app(CrmDashboardBroadcastService::class)->notify($reason);
        };

        Lead::saved(static fn () => $notify('lead'));
        Lead::deleted(static fn () => $notify('lead'));
        Engagement::saved(static fn () => $notify('engagement'));
        Engagement::deleted(static fn () => $notify('engagement'));
        Agreement::saved(static fn () => $notify('agreement'));
        Agreement::deleted(static fn () => $notify('agreement'));
        Appointment::saved(static fn () => $notify('appointment'));
        Appointment::deleted(static fn () => $notify('appointment'));
        MarketingCampaign::saved(static fn () => $notify('marketing'));
        MarketingCampaign::deleted(static fn () => $notify('marketing'));
    }
}
