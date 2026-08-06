<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Trade extends Model
{
    public const MINIMUM_GOLD_GRAMS = 1;
    public const MINIMUM_SILVER_GRAMS = 10;
    public const MINIMUM_SILVER_MESGHAL = 2.171;

    protected $fillable = ['user_id', 'side', 'asset', 'unit', 'quantity', 'unit_price', 'total_price', 'price_symbol', 'status', 'allow_partial', 'talaboard_reference', 'idempotency_key', 'traded_at', 'expires_at', 'accepted_by'];
    protected function casts(): array { return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:0', 'total_price' => 'decimal:0', 'allow_partial' => 'boolean', 'traded_at' => 'datetime', 'expires_at' => 'datetime']; }
    public function user() { return $this->belongsTo(User::class); }

    public static function meetsMinimumQuantity(string $unit, float $quantity, ?string $asset = null): bool
    {
        if ($quantity <= 0) {
            return false;
        }

        if ($asset === 'gold' && $unit === 'gram') {
            return $quantity >= self::MINIMUM_GOLD_GRAMS;
        }

        if (in_array($asset, ['silver_995', 'silver_999', 'silver_9999'], true)) {
            return match ($unit) {
                'gram' => $quantity >= self::MINIMUM_SILVER_GRAMS,
                'mesghal' => $quantity >= self::MINIMUM_SILVER_MESGHAL,
                default => true,
            };
        }

        return true;
    }

    public static function minimumQuantityMessage(?string $asset = null): string
    {
        if ($asset === 'gold') {
            return 'حداقل مقدار فروش یا پذیرش جزئی طلا ۱ گرم است.';
        }

        if (in_array($asset, ['silver_995', 'silver_999', 'silver_9999'], true)) {
            return 'حداقل مقدار فروش یا پذیرش جزئی نقره ۱۰ گرم است.';
        }

        return 'مقدار معامله باید بیشتر از صفر باشد.';
    }

    public function scopeTradable(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->whereNotIn('asset', ['gold', 'silver_995', 'silver_999', 'silver_9999'])
                ->orWhereNull('asset')
                ->orWhere(function (Builder $query) {
                    $query->where('asset', 'gold')
                        ->where(function (Builder $query) {
                            $query->where('unit', '!=', 'gram')
                                ->orWhere('quantity', '>=', self::MINIMUM_GOLD_GRAMS);
                        });
                })
                ->orWhere(function (Builder $query) {
                    $query->whereIn('asset', ['silver_995', 'silver_999', 'silver_9999'])
                        ->where(function (Builder $query) {
                            $query->whereNotIn('unit', ['gram', 'mesghal'])
                                ->orWhere(fn (Builder $query) => $query->where('unit', 'gram')->where('quantity', '>=', self::MINIMUM_SILVER_GRAMS))
                                ->orWhere(fn (Builder $query) => $query->where('unit', 'mesghal')->where('quantity', '>=', self::MINIMUM_SILVER_MESGHAL));
                        });
                });
        });
    }
}
