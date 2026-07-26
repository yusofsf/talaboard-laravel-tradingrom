<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->string('asset')->nullable()->after('side');
        });
    }

    public function down(): void
    {
        Schema::table('trades', fn (Blueprint $table) => $table->dropColumn('asset'));
    }
};
