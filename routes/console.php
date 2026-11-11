<?php

use App\Jobs\SendBroadcastRecipient;
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

Artisan::command('broadcasts:dispatch', function (): void {
    DB::table('broadcast_recipients')->where('status', 'sending')->where('attempted_at', '<', now()->subMinutes(2))
        ->update(['status' => 'review', 'error' => 'Worker stopped before the send was confirmed.', 'updated_at' => now()]);
    DB::table('broadcast_recipients')->join('broadcasts', 'broadcasts.id', '=', 'broadcast_recipients.broadcast_id')
        ->where('broadcast_recipients.status', 'pending')->where('broadcasts.scheduled_at', '<=', now())
        ->where(fn ($query) => $query->whereNull('broadcast_recipients.next_attempt_at')->orWhere('broadcast_recipients.next_attempt_at', '<=', now()))
        ->orderBy('broadcast_recipients.id')->limit(60)->pluck('broadcast_recipients.id')
        ->each(fn ($id) => SendBroadcastRecipient::dispatch($id));
    DB::table('broadcast_send_attempts')->where('created_at', '<', now()->subDay())->delete();
})->purpose('Dispatch due broadcast recipients');

Schedule::command('broadcasts:dispatch')->everyMinute()->withoutOverlapping();
