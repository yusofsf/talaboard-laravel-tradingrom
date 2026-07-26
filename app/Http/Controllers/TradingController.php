<?php

namespace App\Http\Controllers;

use App\Models\{AssetBalance, DepositRequest, InventoryDelivery, Trade, User, WalletTransaction};
use App\Services\{TalaboardClient, TradeService};
use Illuminate\Http\Request;

class TradingController extends Controller
{
    public function prices(TalaboardClient $client) { return response()->json($client->prices()->values()); }

    public function storeTrade(Request $request, TradeService $service)
    {
        $data = $request->validate(['side' => 'required|in:buy,sell', 'asset' => 'required|in:gold,silver_995,silver_9999,full_coin,half_coin,quarter_coin', 'unit' => 'required|in:mesghal,gram,count', 'quantity' => 'required|numeric|min:0.001', 'unit_price' => 'nullable|numeric|min:1']);
        return response()->json($service->create($request->user(), $data), 201);
    }

    public function storeDeposit(Request $request)
    {
        $data = $request->validate(['amount' => 'required|integer|min:10000', 'receipt' => 'required|image|max:5120']);
        $deposit = DepositRequest::create(['user_id' => $request->user()->id, 'amount' => $data['amount'], 'receipt_path' => $request->file('receipt')->store('receipts', 'public')]);
        return response()->json($deposit, 201);
    }

    public function storeInventoryDelivery(Request $request)
    {
        $data = $request->validate(['asset' => 'required|in:gold,silver_995,silver_9999,full_coin,half_coin,quarter_coin', 'unit' => 'required|in:mesghal,gram,count', 'quantity' => 'required|numeric|min:0.001']);
        $coin = in_array($data['asset'], ['full_coin', 'half_coin', 'quarter_coin'], true);
        abort_if($coin !== ($data['unit'] === 'count'), 422, 'واحد انتخاب‌شده با دارایی هم‌خوانی ندارد.');
        return response()->json(InventoryDelivery::create([...$data, 'user_id' => $request->user()->id]), 201);
    }

    public function trades(Request $request) { return Trade::where('user_id', $request->user()->id)->latest('traded_at')->paginate(); }

    public function assets(Request $request)
    {
        $names = ['gold' => 'طلا', 'silver_9999' => 'نقره ۹۹۹.۹', 'silver_995' => 'نقره ۹۹۵', 'full_coin' => 'تمام سکه', 'half_coin' => 'نیم سکه', 'quarter_coin' => 'ربع سکه'];
        return collect($names)->map(fn ($title, $asset) => ['asset' => $asset, 'title' => $title, 'quantity' => AssetBalance::where('user_id', $request->user()->id)->where('asset', $asset)->value('quantity') ?? 0])->values();
    }

    public function acceptTrade(Request $request, Trade $trade, TalaboardClient $client)
    {
        abort_unless($request->user()->is_admin, 403);
        abort_if($trade->status !== 'submitted' || $trade->expires_at->isPast(), 422, 'سفارش قابل پذیرش نیست.');
        $trade->update(['status' => 'accepted', 'accepted_by' => $request->user()->id, 'talaboard_reference' => $client->registerTrade(['local_trade_id' => $trade->id, 'side' => $trade->side, 'asset' => $trade->asset, 'unit' => $trade->unit, 'quantity' => $trade->quantity, 'unit_price' => $trade->unit_price, 'total_price' => $trade->total_price])]);
        if ($trade->side === 'sell') AssetBalance::firstOrCreate(['user_id' => $trade->user_id, 'asset' => $trade->asset])->increment('quantity', $trade->quantity);
        return response()->json($trade);
    }

    public function approveDeposit(Request $request, DepositRequest $deposit)
    {
        abort_unless($request->user()->is_admin, 403);
        if ($deposit->status !== 'pending') return response()->json(['message' => 'قبلاً بررسی شده است.'], 422);
        \DB::transaction(function () use ($request, $deposit) {
            $deposit->update(['status' => 'approved', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'admin_note' => request('note')]);
            $user = User::lockForUpdate()->find($deposit->user_id);
            $user->increment('wallet_balance', $deposit->amount);
            WalletTransaction::create(['user_id' => $user->id, 'amount' => $deposit->amount, 'type' => 'deposit', 'reference_type' => DepositRequest::class, 'reference_id' => $deposit->id, 'description' => 'تأیید فیش واریزی']);
        });
        return response()->json(['message' => 'کیف پول شارژ شد.']);
    }
}
