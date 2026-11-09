<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['permission'])]
class UserPermission extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
