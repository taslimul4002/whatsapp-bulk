<?php

namespace App\Models;

use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'phone', 'email', 'opted_in'])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['opted_in' => 'boolean'];
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(ContactGroup::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(ContactTag::class);
    }
}
