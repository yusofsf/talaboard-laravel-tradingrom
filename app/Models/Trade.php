<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Trade extends Model
{
    public const MINIMUM_GRAMS = 100;
    public const MINIMUM_MESGHAL = 21.702;

    protected $fillable = ['user_id', 'side', 'asset', 'unit', 'quantity', 'unit_price', 'total_price', 'price_symbol', 'status', 'talaboard_reference', 'traded_at', 'expires_at', 'accepted_by'];
    protected function casts(): array { return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:0', 'total_price' => 'decimal:0', 'traded_at' => 'datetime', 'expires_at' => 'datetime']; }
    public function user() { return $this->belongsTo(User::class); }

    public static function meetsMinimumQuantity(string $unit, float $quantity): bool
    {
        return match ($unit) {
            'gram' => $quantity >= self::MINIMUM_GRAMS,
            'mesghal' => $quantity >= self::MINIMUM_MESGHAL,
            default => true,
        };
    }

    public function scopeTradable(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->whereNotIn('unit', ['gram', 'mesghal'])
                ->orWhere(fn (Builder $query) => $query->where('unit', 'gram')->where('quantity', '>=', self::MINIMUM_GRAMS))
                ->orWhere(fn (Builder $query) => $query->where('unit', 'mesghal')->where('quantity', '>=', self::MINIMUM_MESGHAL));
        });
    }
}
