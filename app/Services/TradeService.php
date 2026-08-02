<?php

namespace App\Services;

use App\Models\{AssetBalance, Trade, User, WalletTransaction};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TradeService
{
    public function __construct(private TalaboardClient $client, private TradingHours $hours, private OrderExpiry $expiry) {}

    public function create(User $user, array $data): Trade
    {
        if (! empty($data['idempotency_key']) && ($existing = Trade::where('idempotency_key', $data['idempotency_key'])->first())) {
            if ($existing->user_id !== $user->id) throw ValidationException::withMessages(['idempotency_key' => 'کلید درخواست نامعتبر است.']);
            return $existing;
        }
        if (! $this->hours->isOpen()) throw ValidationException::withMessages(['trading' => $this->hours->message()]);
        $isCoin = in_array($data['asset'], ['full_coin', 'half_coin', 'quarter_coin'], true);
        if ($isCoin !== ($data['unit'] === 'count')) throw ValidationException::withMessages(['unit' => 'واحد انتخاب‌شده با دارایی هم‌خوانی ندارد.']);
        if (! $isCoin && $data['unit'] !== 'gram') throw ValidationException::withMessages(['unit' => 'معامله طلا و نقره فقط بر حسب گرم قابل ثبت است.']);

        if (! Trade::meetsMinimumQuantity($data['unit'], (float) $data['quantity'], $data['asset'])) {
            throw ValidationException::withMessages(['quantity' => 'حداقل مقدار معامله نقره ۱۰۰ گرم است.']);
        }

        $symbol = $isCoin ? $data['asset'] : ($data['asset'] === 'gold' ? 'gold_'.$data['unit'] : $data['asset'].'_'.$data['unit']);
        $snapshot = $this->client->prices()->get($symbol);
        $unitPrice = (int) ($data['unit_price'] ?? $snapshot?->price ?? 0);
        if ($unitPrice < 1) throw ValidationException::withMessages(['price' => 'قیمت پیش‌فرض این دارایی از سایت در دسترس نیست؛ قیمت واحد را وارد کنید.']);
        $total = (int) round((float) $data['quantity'] * $unitPrice);

        return DB::transaction(function () use ($user, $data, $symbol, $unitPrice, $total) {
            if (! empty($data['idempotency_key']) && ($existing = Trade::where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first())) {
                if ($existing->user_id !== $user->id) throw ValidationException::withMessages(['idempotency_key' => 'کلید درخواست نامعتبر است.']);
                return $existing;
            }
            $user = User::lockForUpdate()->findOrFail($user->id);
            if ($data['side'] === 'sell') {
                if ($user->wallet_balance < $total) throw ValidationException::withMessages(['wallet' => 'موجودی کیف پول کافی نیست.']);
                $user->decrement('wallet_balance', $total);
            } else {
                $balance = AssetBalance::firstOrCreate(['user_id' => $user->id, 'asset' => $data['asset']]);
                $balance = AssetBalance::lockForUpdate()->find($balance->id);
                if ($balance->quantity < $data['quantity']) throw ValidationException::withMessages(['assets' => 'موجودی دارایی کافی نیست.']);
                $balance->decrement('quantity', $data['quantity']);
            }
            $trade = Trade::create(['user_id' => $user->id, 'side' => $data['side'], 'asset' => $data['asset'], 'unit' => $data['unit'], 'quantity' => $data['quantity'], 'unit_price' => $unitPrice, 'total_price' => $total, 'price_symbol' => $symbol, 'status' => 'submitted', 'allow_partial' => $data['allow_partial'] ?? true, 'idempotency_key' => $data['idempotency_key'] ?? null, 'traded_at' => now(config('trading.timezone')), 'expires_at' => $this->expiry->forNow()]);
            if ($data['side'] === 'sell') WalletTransaction::create(['user_id' => $user->id, 'amount' => -$total, 'type' => 'trade_reserve', 'reference_type' => Trade::class, 'reference_id' => $trade->id, 'description' => 'رزرو وجه معامله']);
            return $trade;
        });
    }
}
