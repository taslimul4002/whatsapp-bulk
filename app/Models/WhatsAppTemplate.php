<?php

namespace App\Models;

use Database\Factories\WhatsAppTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsAppTemplate extends Model
{
    /** @use HasFactory<WhatsAppTemplateFactory> */
    use HasFactory;

    protected $fillable = ['business_account_id', 'meta_id', 'name', 'language', 'category', 'status', 'reason', 'components', 'status_event_time'];

    protected function casts(): array
    {
        return ['components' => 'array'];
    }
}
