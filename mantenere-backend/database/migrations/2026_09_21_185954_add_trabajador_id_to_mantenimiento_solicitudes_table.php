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
        Schema::table('mantenimiento_solicitudes', function (Blueprint $table) {
            $table->foreignId('trabajador_id')->nullable()->after('estado')->constrained('trabajadores')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mantenimiento_solicitudes', function (Blueprint $table) {
            $table->dropForeign(['trabajador_id']);
            $table->dropColumn('trabajador_id');
        });
    }
};
