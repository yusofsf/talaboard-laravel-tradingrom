<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PriceSnapshot extends Model { protected $fillable=['symbol','title','price','source_updated_at']; protected function casts(): array { return ['price'=>'decimal:0','source_updated_at'=>'datetime']; } }
