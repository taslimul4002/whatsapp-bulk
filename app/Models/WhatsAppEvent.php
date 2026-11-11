<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['event_key', 'business_account_id', 'phone_number_id', 'type', 'message_id', 'status', 'event_timestamp', 'payload', 'context'])]
class WhatsAppEvent extends Model
{
    protected $table = 'whatsapp_events';

    protected function casts(): array
    {
        return ['payload' => 'array', 'context' => 'array'];
    }
}
