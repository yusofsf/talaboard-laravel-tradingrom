<?php

use Illuminate\Support\Facades\Route;
use App\Models\{DepositRequest,PriceSnapshot,Trade};

Route::get('/', fn () => view('trading.index',['prices'=>PriceSnapshot::latest()->get()->unique('symbol'),'buys'=>Trade::tradable()->where('side','buy')->latest('traded_at')->take(20)->get(),'sells'=>Trade::tradable()->where('side','sell')->latest('traded_at')->take(20)->get()]));
Route::get('/admin/deposits',function(){abort_unless(auth()->user()?->is_admin,403);return view('trading.deposits',['deposits'=>DepositRequest::with('user')->latest()->get()]);})->middleware('auth');
Route::get('/admin/inventory-deliveries',function(){abort_unless(auth()->user()?->is_admin,403);return view('trading.inventory-deliveries',['deliveries'=>\App\Models\InventoryDelivery::with('user')->latest()->get()]);})->middleware('auth');
Route::middleware('auth')->group(function(){Route::post('/admin/deposits/{deposit}/approve',[\App\Http\Controllers\TradingController::class,'approveDeposit'])->name('admin.deposits.approve');Route::post('/admin/deposits/{deposit}/reject',[\App\Http\Controllers\TradingController::class,'rejectDeposit'])->name('admin.deposits.reject');});
Route::middleware('auth')->group(function(){Route::post('/admin/inventory-deliveries/{delivery}/approve',[\App\Http\Controllers\TradingController::class,'approveInventoryDelivery'])->name('admin.inventory-deliveries.approve');Route::post('/admin/inventory-deliveries/{delivery}/reject',[\App\Http\Controllers\TradingController::class,'rejectInventoryDelivery'])->name('admin.inventory-deliveries.reject');});
