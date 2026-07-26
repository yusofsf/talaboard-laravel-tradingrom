<?php
use App\Http\Controllers\{TelegramWebhookController,TradingController}; use Illuminate\Support\Facades\Route;
Route::post('/telegram/webhook',TelegramWebhookController::class);
Route::get('/prices',[TradingController::class,'prices']);
Route::middleware('auth')->group(function(){Route::get('/trades',[TradingController::class,'trades']);Route::get('/assets',[TradingController::class,'assets']);Route::post('/trades',[TradingController::class,'storeTrade']);Route::post('/inventory-deliveries',[TradingController::class,'storeInventoryDelivery']);Route::post('/deposits',[TradingController::class,'storeDeposit']);Route::post('/admin/deposits/{deposit}/approve',[TradingController::class,'approveDeposit']);Route::post('/admin/deposits/{deposit}/reject',[TradingController::class,'rejectDeposit']);Route::post('/admin/trades/{trade}/accept',[TradingController::class,'acceptTrade']);});
