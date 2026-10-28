<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\GroupBuyCampaign;
use App\Services\GroupBuyLifecycleService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('drops:process-due', function () {
    $count = 0;
    GroupBuyCampaign::whereIn('status', ['scheduled', 'active'])->orderBy('id')->chunkById(100, function ($campaigns) use (&$count) {
        foreach ($campaigns as $campaign) {
            app(GroupBuyLifecycleService::class)->processDueCampaign($campaign->id);
            $count++;
        }
    });
    $this->info("Processed {$count} scheduled or active Price Drops.");
})->purpose('Activate scheduled Price Drops and conclude expired conditional orders safely');

Schedule::command('drops:process-due')->everyMinute()->withoutOverlapping();
