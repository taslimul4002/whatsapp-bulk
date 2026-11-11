<?php

namespace App\Models;

use Database\Factories\ContactTagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name'])]
class ContactTag extends Model
{
    /** @use HasFactory<ContactTagFactory> */
    use HasFactory;
}
