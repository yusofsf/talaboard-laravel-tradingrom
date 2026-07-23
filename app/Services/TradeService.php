<?php
namespace App\Services;
use App\Models\{AssetBalance,Trade,User,WalletTransaction};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class TradeService
{
    public function __construct(private TalaboardClient $client, private TradingHours $hours, private OrderExpiry $expiry) {}
    public function create(User $user, array $data): Trade
    {
        if (!$this->hours->isOpen()) throw ValidationException::withMessages(['trading'=>$this->hours->message()]);
        $symbol=$data['unit']==='mesghal' ? 'gold_mesghal' : 'gold_9999_gram'; $snapshot=$this->client->prices()->get($symbol);
        if (!$snapshot) throw ValidationException::withMessages(['price'=>'قیمت لحظه‌ای در دسترس نیست.']);
        $total=(int) round((float)$data['quantity']*(float)$snapshot->price);
        return DB::transaction(function () use ($user,$data,$symbol,$snapshot,$total) {
            $user=User::lockForUpdate()->findOrFail($user->id);
            $asset=$data['unit']==='gram' ? 'gold_9999' : 'gold_995';
            if ($data['side']==='sell') { if ($user->wallet_balance < $total) throw ValidationException::withMessages(['wallet'=>'موجودی کیف پول کافی نیست.']); $user->decrement('wallet_balance',$total); }
            else { $balance=AssetBalance::firstOrCreate(['user_id'=>$user->id,'asset'=>$asset]); $balance=AssetBalance::lockForUpdate()->find($balance->id); if ($balance->quantity < $data['quantity']) throw ValidationException::withMessages(['assets'=>'موجودی دارایی کافی نیست.']); $balance->decrement('quantity',$data['quantity']); }
            $trade=Trade::create(['user_id'=>$user->id,'side'=>$data['side'],'unit'=>$data['unit'],'quantity'=>$data['quantity'],'unit_price'=>$snapshot->price,'total_price'=>$total,'price_symbol'=>$symbol,'status'=>'submitted','traded_at'=>now(config('trading.timezone')),'expires_at'=>$this->expiry->forNow()]);
            if ($data['side']==='sell') WalletTransaction::create(['user_id'=>$user->id,'amount'=>-$total,'type'=>'trade_reserve','reference_type'=>Trade::class,'reference_id'=>$trade->id,'description'=>'رزرو وجه معامله']);
            return $trade;
        });
    }
}
