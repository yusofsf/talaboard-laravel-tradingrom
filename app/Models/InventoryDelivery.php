<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryDelivery extends Model
{
    protected $fillable = ['user_id', 'asset', 'unit', 'quantity', 'status', 'reviewed_by', 'reviewed_at', 'admin_note'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'reviewed_at' => 'datetime'];
    }

    public function user() { return $this->belongsTo(User::class); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }
}
