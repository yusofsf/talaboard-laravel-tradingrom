<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Existing snapshots were saved directly from Metalsp (toman). Convert
        // them once so fallback prices use the same rial scale as new prices.
        DB::table('price_snapshots')->update(['price' => DB::raw('price * 10')]);
    }

    public function down(): void
    {
        DB::table('price_snapshots')->update(['price' => DB::raw('price / 10')]);
    }
};
