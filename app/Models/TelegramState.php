<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramState extends Model
{
    protected $fillable = ['telegram_user_id', 'state', 'data', 'expires_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'expires_at' => 'datetime'];
    }
}
