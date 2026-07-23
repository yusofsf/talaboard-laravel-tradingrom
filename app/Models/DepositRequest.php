<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DepositRequest extends Model { protected $fillable=['user_id','amount','receipt_path','status','reviewed_by','reviewed_at','admin_note']; protected function casts(): array { return ['amount'=>'decimal:0','reviewed_at'=>'datetime']; } public function user(){return $this->belongsTo(User::class);} public function reviewer(){return $this->belongsTo(User::class, 'reviewed_by');} }
