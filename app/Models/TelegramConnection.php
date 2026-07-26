<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramConnection extends Model
{
    protected $fillable = ['user_id', 'telegram_user_id', 'telegram_chat_id', 'telegram_username', 'connected_at'];

    protected function casts(): array
    {
        return ['connected_at' => 'datetime'];
    }

    public function user() { return $this->belongsTo(User::class); }
}
