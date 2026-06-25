<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value'])]
class SystemSetting extends Model
{
    public $timestamps = false;
    const UPDATED_AT = 'updated_at';
    const CREATED_AT = null;
}
