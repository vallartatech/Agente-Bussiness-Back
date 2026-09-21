<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'mysql') {
            \Illuminate\Support\Facades\DB::statement("ALTER TABLE negocios MODIFY COLUMN tipo VARCHAR(255) DEFAULT 'FC'");
        } else {
            Schema::table('negocios', function (Blueprint $table) {
                $table->string('tipo', 255)->default('FC')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'mysql') {
            \Illuminate\Support\Facades\DB::statement("ALTER TABLE negocios MODIFY COLUMN tipo ENUM('FC', 'FS', 'MALL', 'W/M') DEFAULT 'FC'");
        }
    }
};
