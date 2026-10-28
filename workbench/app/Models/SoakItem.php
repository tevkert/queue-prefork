<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SoakItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
