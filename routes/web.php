<?php

use Illuminate\Support\Facades\Route;
use App\Models\{DepositRequest,PriceSnapshot,Trade};

Route::get('/', fn () => view('trading.index',['prices'=>PriceSnapshot::latest()->get()->unique('symbol'),'buys'=>Trade::tradable()->where('side','buy')->latest('traded_at')->take(20)->get(),'sells'=>Trade::tradable()->where('side','sell')->latest('traded_at')->take(20)->get()]));
Route::get('/admin/deposits',function(){abort_unless(auth()->user()?->is_admin,403);return view('trading.deposits',['deposits'=>DepositRequest::with('user')->latest()->get()]);})->middleware('auth');
