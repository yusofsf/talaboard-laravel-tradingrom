<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AssetBalance extends Model { protected $fillable=['user_id','asset','quantity']; protected function casts(): array{return ['quantity'=>'decimal:3'];} }
