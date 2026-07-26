<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramUpdate extends Model
{
    public $timestamps = false;
    protected $fillable = ['update_id', 'processed_at'];
    protected function casts(): array { return ['processed_at' => 'datetime']; }
}
