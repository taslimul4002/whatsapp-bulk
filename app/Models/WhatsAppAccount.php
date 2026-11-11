<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['access_token', 'app_secret', 'verify_token'])]
class WhatsAppAccount extends Model
{
    public $incrementing = false;

    protected $table = 'whatsapp_accounts';

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'app_secret' => 'encrypted',
            'verify_token' => 'encrypted',
        ];
    }
}
