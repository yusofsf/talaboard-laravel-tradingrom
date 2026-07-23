<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WalletTransaction extends Model { protected $fillable=['user_id','amount','type','reference_type','reference_id','description']; protected function casts(): array { return ['amount'=>'decimal:0']; } }
