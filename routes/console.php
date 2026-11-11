<?php

use App\Support\BillingService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('billing:expire-credits', function (BillingService $billing): void {
    DB::table('billing_purchases')->where('status', 'paid')->whereNotNull('expires_at')
        ->where('expires_at', '<=', now())->orderBy('id')->chunkById(200, function ($purchases) use ($billing): void {
            foreach ($purchases as $purchase) {
                $billing->expire($purchase->id);
            }
        });
})->purpose('Record expired workspace credits');

Schedule::command('billing:expire-credits')->everyMinute()->withoutOverlapping();
