<?php

namespace App\Models;

use Database\Factories\ContactGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name'])]
class ContactGroup extends Model
{
    /** @use HasFactory<ContactGroupFactory> */
    use HasFactory;
}
