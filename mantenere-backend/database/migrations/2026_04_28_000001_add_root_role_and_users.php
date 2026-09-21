<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * - Inserta el rol Root (id=0, hierarchy_level=0) si no existe.
     * - Actualiza el usuario root@mantenere.com para que use role_id=0.
     * - Crea un segundo admin (admin2@mantenere.com) con role_id=1 si no existe.
     * - NO toca ningún otro registro existente.
     */
    public function up(): void
    {
        $isMysql = DB::getDriverName() === 'mysql';
        if ($isMysql) {
            // Desactivar FK checks para poder insertar id=0 sin conflictos
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            // Permitir id=0 en columnas AUTO_INCREMENT de MySQL
            DB::statement("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO';");
        }

        // 1. Insertar el rol Root con id=0 (solo si no existe)
        $exists = DB::table('roles')->where('id', 0)->exists();
        if (!$exists) {
            DB::table('roles')->insert([
                'id'              => 0,
                'name'            => 'Root',
                'hierarchy_level' => 0,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        if ($isMysql) {
            // Reactivar FK checks antes de tocar usuarios
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }

        // 2. Asegurarse de que el rol Admin (id=1) existe antes de usarlo
        //    (en un fresh migrate los seeders no han corrido todavía)
        $adminRoleExists = DB::table('roles')->where('id', 1)->exists();
        if (!$adminRoleExists) {
            DB::table('roles')->insert([
                'id'              => 1,
                'name'            => 'Admin',
                'hierarchy_level' => 1,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        // 3. Actualizar el usuario root para que use role_id=0
        DB::table('users')
            ->where('email', 'root@mantenere.com')
            ->update(['role_id' => 0]);

        // 4. Crear el segundo admin si no existe (no se toca si ya existe)
        $adminExists = DB::table('users')->where('email', 'admin2@mantenere.com')->exists();
        if (!$adminExists) {
            DB::table('users')->insert([
                'name'       => 'Admin 2',
                'email'      => 'admin2@mantenere.com',
                'password'   => Hash::make('Admin2@Mantenere2024!'),
                'role_id'    => 1,
                'active'     => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations (rollback).
     *
     * - Devuelve root@mantenere.com a role_id=1.
     * - Elimina el rol Root (id=0).
     * - Elimina admin2@mantenere.com.
     */
    public function down(): void
    {
        // Revertir usuario root a role_id=1
        DB::table('users')
            ->where('email', 'root@mantenere.com')
            ->update(['role_id' => 1]);

        // Eliminar el rol Root
        DB::table('roles')->where('id', 0)->delete();

        // Eliminar el segundo admin
        DB::table('users')->where('email', 'admin2@mantenere.com')->delete();
    }
};
