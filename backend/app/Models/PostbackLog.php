<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostbackLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['params' => 'array', 'hash_valid' => 'boolean'];
    }
}
