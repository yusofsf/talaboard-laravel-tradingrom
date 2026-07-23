<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Trade extends Model { protected $fillable=['user_id','side','unit','quantity','unit_price','total_price','price_symbol','status','talaboard_reference','traded_at','expires_at','accepted_by']; protected function casts(): array { return ['quantity'=>'decimal:3','unit_price'=>'decimal:0','total_price'=>'decimal:0','traded_at'=>'datetime','expires_at'=>'datetime']; } public function user(){return $this->belongsTo(User::class);} }
