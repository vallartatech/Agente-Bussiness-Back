<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ActividadController;
use App\Http\Controllers\Api\ImageController;
use App\Http\Controllers\Api\MantenimientoSolicitudController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\CategoriaEquipoController;
use App\Http\Controllers\Api\NotificacionController;
use App\Http\Controllers\Api\ReporteController;
use App\Http\Controllers\Api\CotizacionController;
use App\Http\Controllers\Api\ChecklistEquipoController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\SolicitudProveedorController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\AdminAutonomoController;
use App\Http\Controllers\Api\NegocioController;
use App\Http\Controllers\Api\TrabajoController;
use App\Http\Controllers\Api\TrabajadorController;

// ── HEALTH CHECK ─────────────────────────────────────────────────────────────
Route::get('/ping', fn() => response()->json([
    'pong' => true,
    'deploy_version' => 'force-deploy-1',
    'time' => date('Y-m-d H:i:s'),
]));

// ── AUTH (público) ────────────────────────────────────────────────────────────
Route::post('/login',           [AuthController::class, 'login']);
Route::post('/register',        [AuthController::class, 'register']);
Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
Route::post('/reset-password',  [PasswordResetController::class, 'resetPassword']);
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);
Route::middleware('auth:sanctum')->post('/auth/change-mandatory-password', [AuthController::class, 'changeMandatoryPassword']);

// ── USUARIOS (compartido, jerarquía numérica) ─────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    \Illuminate\Support\Facades\Broadcast::routes();
    Route::get('/users',        [UserController::class, 'index']);
    Route::get('/users/{user}', [UserController::class, 'show']);
});
Route::middleware(['auth:sanctum', 'role.hierarchy'])->group(function () {
    Route::post  ('/users',          [UserController::class, 'store']);
    Route::put   ('/users/{user}',   [UserController::class, 'update']);
    Route::delete('/users/{user}',   [UserController::class, 'destroy']);
});

// ════════════════════════════════════════════════════════════════════════════
//  ECOSISTEMA BASE  — roles: root(0), Admin(1), Cliente(2), tecnico-normal(3)
//  Prefijo: /api/base/*
// ════════════════════════════════════════════════════════════════════════════
Route::middleware(['auth:sanctum', 'base.role'])->prefix('base')->group(function () {

    // 🛠️ Trabajos base
    Route::get   ('trabajos',                 [\App\Http\Controllers\Base\TrabajoController::class, 'index']);
    Route::post  ('trabajos',                 [\App\Http\Controllers\Base\TrabajoController::class, 'store']);
    Route::get   ('trabajos/{id}',            [\App\Http\Controllers\Base\TrabajoController::class, 'show']);
    Route::put   ('trabajos/{id}',            [\App\Http\Controllers\Base\TrabajoController::class, 'update']);
    Route::put   ('trabajos/{id}/asignar',    [\App\Http\Controllers\Base\TrabajoController::class, 'asignarTrabajador']);
    Route::put   ('trabajos/{id}/estado',     [\App\Http\Controllers\Base\TrabajoController::class, 'cambiarEstado']);
    Route::delete('trabajos/{id}',            [\App\Http\Controllers\Base\TrabajoController::class, 'destroy']);

    // 👷 Técnicos base (tecnico-normal)
    Route::get   ('trabajadores',             [\App\Http\Controllers\Base\TrabajadorController::class, 'index']);
    Route::get   ('trabajadores/{id}',        [\App\Http\Controllers\Base\TrabajadorController::class, 'show']);
    Route::post  ('trabajadores',             [\App\Http\Controllers\Base\TrabajadorController::class, 'store']);
    Route::put   ('trabajadores/{id}',        [\App\Http\Controllers\Base\TrabajadorController::class, 'update']);
    Route::patch ('trabajadores/{id}/estado', [\App\Http\Controllers\Base\TrabajadorController::class, 'toggleEstado']);

    // 🏢 Negocios base
    Route::get   ('negocios',               [\App\Http\Controllers\Base\NegocioController::class, 'index']);
    Route::post  ('negocios',               [\App\Http\Controllers\Base\NegocioController::class, 'store']);
    Route::get   ('negocios/{id}',          [\App\Http\Controllers\Base\NegocioController::class, 'show']);
    Route::put   ('negocios/{id}',          [\App\Http\Controllers\Base\NegocioController::class, 'update']);
    Route::delete('negocios/{id}',          [\App\Http\Controllers\Base\NegocioController::class, 'destroy']);
    Route::put   ('equipos/{id}',           [\App\Http\Controllers\Base\NegocioController::class, 'updateEquipo']);
    Route::get   ('equipos/{id}/historial', [\App\Http\Controllers\Base\NegocioController::class, 'getEquipoHistorial']);

    // 🔧 Solicitudes de mantenimiento (base)
    Route::get  ('mantenimiento-solicitudes',                         [MantenimientoSolicitudController::class, 'index']);
    Route::post ('mantenimiento-solicitudes',                         [MantenimientoSolicitudController::class, 'store']);
    Route::get  ('mantenimiento-solicitudes/{id}',                    [MantenimientoSolicitudController::class, 'show']);
    Route::post ('mantenimiento-solicitudes/{id}/asignar-visita',     [MantenimientoSolicitudController::class, 'asignarVisita']);
    Route::post ('mantenimiento-solicitudes/{id}/asignar-reparacion', [MantenimientoSolicitudController::class, 'asignarReparacion']);

    // 📋 Solicitudes proveedor
    Route::post('tecnico/solicitar-proveedor',             [SolicitudProveedorController::class, 'store']);
    Route::get ('tecnico/mi-solicitud-proveedor',          [SolicitudProveedorController::class, 'miSolicitud']);
    Route::get ('admin/solicitudes-proveedor',             [SolicitudProveedorController::class, 'index']);
    Route::get ('admin/solicitudes-proveedor/{id}',        [SolicitudProveedorController::class, 'show']);
    Route::put ('admin/solicitudes-proveedor/{id}/aprobar',[SolicitudProveedorController::class, 'aprobar']);
    Route::put ('admin/solicitudes-proveedor/{id}/rechazar',[SolicitudProveedorController::class, 'rechazar']);

    // 🔍 Supervisión de propietarios autónomos (Admin/Root desde panel base)
    Route::get('propietarios-autonomos',                   [AdminAutonomoController::class, 'index']);
    Route::get('propietarios-autonomos/{id}/dashboard',    [AdminAutonomoController::class, 'dashboard']);
    Route::get('propietarios-autonomos/{id}/negocios',     [AdminAutonomoController::class, 'negocios']);
    Route::get('propietarios-autonomos/{id}/trabajadores', [AdminAutonomoController::class, 'trabajadores']);
    Route::get('propietarios-autonomos/{id}/trabajos',     [AdminAutonomoController::class, 'trabajos']);
    Route::put('propietarios-autonomos/{id}/bloquear',     [AdminAutonomoController::class, 'toggleBloqueo']);
});

// ════════════════════════════════════════════════════════════════════════════
//  ECOSISTEMA AUTÓNOMO — roles: propietario-autonomo(4), administrador-general(5),
//                                gerente-sucursal(6), tecnico-autonomo(7)
//  Prefijo: /api/autonomo/*
// ════════════════════════════════════════════════════════════════════════════
Route::middleware(['auth:sanctum', 'autonomo.role'])->prefix('autonomo')->group(function () {

    // 🛠️ Trabajos autónomos
    Route::get   ('trabajos',              [\App\Http\Controllers\Autonomo\TrabajoController::class, 'index']);
    Route::post  ('trabajos',              [\App\Http\Controllers\Autonomo\TrabajoController::class, 'store']);
    Route::get   ('trabajos/{id}',         [\App\Http\Controllers\Autonomo\TrabajoController::class, 'show']);
    Route::put   ('trabajos/{id}',         [\App\Http\Controllers\Autonomo\TrabajoController::class, 'update']);
    Route::put   ('trabajos/{id}/asignar', [\App\Http\Controllers\Autonomo\TrabajoController::class, 'asignarTrabajador']);
    Route::put   ('trabajos/{id}/estado',  [\App\Http\Controllers\Autonomo\TrabajoController::class, 'cambiarEstado']);
    Route::delete('trabajos/{id}',         [\App\Http\Controllers\Autonomo\TrabajoController::class, 'destroy']);

    // 👷 Técnicos autónomos (tecnico-autonomo)
    Route::get   ('trabajadores',             [\App\Http\Controllers\Autonomo\TrabajadorController::class, 'index']);
    Route::get   ('trabajadores/{id}',        [\App\Http\Controllers\Autonomo\TrabajadorController::class, 'show']);
    Route::post  ('trabajadores',             [\App\Http\Controllers\Autonomo\TrabajadorController::class, 'store']);
    Route::put   ('trabajadores/{id}',        [\App\Http\Controllers\Autonomo\TrabajadorController::class, 'update']);
    Route::patch ('trabajadores/{id}/estado', [\App\Http\Controllers\Autonomo\TrabajadorController::class, 'toggleEstado']);

    // 🏢 Negocios autónomos
    Route::get  ('negocios',                          [\App\Http\Controllers\Autonomo\NegocioController::class, 'index']);
    Route::post ('negocios',                          [\App\Http\Controllers\Autonomo\NegocioController::class, 'store']);
    Route::get  ('negocios/{id}',                     [\App\Http\Controllers\Autonomo\NegocioController::class, 'show']);
    Route::put  ('negocios/{id}',                     [\App\Http\Controllers\Autonomo\NegocioController::class, 'update']);
    Route::delete('negocios/{id}',                    [\App\Http\Controllers\Autonomo\NegocioController::class, 'destroy']);
    Route::post ('negocios/{id}/gerente-sucursal',    [\App\Http\Controllers\Autonomo\NegocioController::class, 'asignarGerenteSucursal']);
    Route::get  ('negocios/{id}/gerente-sucursal',    [\App\Http\Controllers\Autonomo\NegocioController::class, 'getGerenteSucursal']);
    Route::get  ('negocios/{id}/resumen',             [\App\Http\Controllers\Autonomo\NegocioController::class, 'resumen']);

    // 📊 Dashboard y gerente del propietario autónomo
    Route::get ('dashboard', [AdminAutonomoController::class, 'dashboard']);
    Route::get ('gerente',   [AdminAutonomoController::class, 'getGerenteGeneral']);
    Route::post('gerente',   [AdminAutonomoController::class, 'asignarGerenteGeneral']);
});

// ════════════════════════════════════════════════════════════════════════════
//  RUTAS COMPARTIDAS (cross-ecosystem) — sin prefijo de ecosistema
// ════════════════════════════════════════════════════════════════════════════
Route::middleware('auth:sanctum')->group(function () {

    // 📋 Reportes
    Route::get ('reportes/trabajo/{trabajo_id}', [ReporteController::class, 'showByTrabajo']);
    Route::post('reportes',                      [ReporteController::class, 'store']);

    Route::get   ('cotizaciones/trabajo/{trabajo_id}', [CotizacionController::class, 'showByTrabajo']);
    Route::post  ('cotizaciones',                      [CotizacionController::class, 'store']);
    Route::match(['put', 'post'], 'cotizaciones/{id}', [CotizacionController::class, 'update']);
    Route::put   ('cotizaciones/{id}/estado',          [CotizacionController::class, 'updateStatus']);
    Route::delete('cotizaciones/{id}',                 [CotizacionController::class, 'destroy']);

    // 🔔 Notificaciones
    Route::get ('notificaciones/usuario/{user_id}',             [NotificacionController::class, 'indexByUsuario']);
    Route::post('notificaciones',                               [NotificacionController::class, 'store']);
    Route::post('notificaciones/rol',                           [NotificacionController::class, 'notifyByRole']);
    Route::post('notificaciones/ecosistema',                    [NotificacionController::class, 'notifyEcosistema']);
    Route::post('notificaciones/negocio',                       [NotificacionController::class, 'notifyNegocio']);
    Route::put ('notificaciones/{id}/leer',                     [NotificacionController::class, 'markAsRead']);
    Route::put ('notificaciones/usuario/{user_id}/leer-todas',  [NotificacionController::class, 'markAllAsRead']);

    // 🔔 Notifications (Laravel-style)
    Route::get('notifications',           [NotificationController::class, 'index']);
    Route::put('notifications/read-all',  [NotificationController::class, 'markAllAsRead']);
    Route::put('notifications/{id}/read', [NotificationController::class, 'markAsRead']);

    // 🧰 Checklist
    Route::get ('checklist/trabajo/{trabajo_id}', [ChecklistEquipoController::class, 'showByTrabajo']);
    Route::post('checklist',                      [ChecklistEquipoController::class, 'store']);

    // 📝 Actividades
    Route::post  ('actividades',               [ActividadController::class, 'store']);
    Route::put   ('actividades/{id}',          [ActividadController::class, 'update']);
    Route::get   ('trabajos/{id}/actividades', [ActividadController::class, 'getByTrabajo']);
    Route::delete('actividades/{id}',          [ActividadController::class, 'destroy']);

    // 💬 Chat
    Route::get   ('trabajos/{id}/chat',         [ChatController::class, 'index']);
    Route::post  ('trabajos/{id}/chat',         [ChatController::class, 'store']);
    Route::post  ('trabajos/{id}/quote-action', [ChatController::class, 'quoteAction']);
    Route::delete('trabajos/{id}/chat',         [ChatController::class, 'destroy']);

    // 🏷️ Categorías de equipos
    Route::get   ('categorias-equipos',               [CategoriaEquipoController::class, 'index']);
    Route::post  ('categorias-equipos',               [CategoriaEquipoController::class, 'store']);
    Route::delete('categorias-equipos/{id}',          [CategoriaEquipoController::class, 'destroy']);
    Route::get   ('equipos-consumo',                  [CategoriaEquipoController::class, 'consumoReporte']);
    Route::post  ('equipos-consumo',                  [CategoriaEquipoController::class, 'addConsumoManual']);
    Route::put   ('equipos-consumo/{id}/categoria',   [CategoriaEquipoController::class, 'updateConsumoCategoria']);

    // 🖼️ Imágenes
    Route::post('upload-imagen', [ImageController::class, 'upload']);

    // 🏢 Negocios generales
    Route::get   ('negocios',               [NegocioController::class, 'index']);
    Route::post  ('negocios',               [NegocioController::class, 'store']);
    Route::get   ('negocios/{id}',          [NegocioController::class, 'show']);
    Route::put   ('negocios/{id}',          [NegocioController::class, 'update']);
    Route::delete('negocios/{id}',          [NegocioController::class, 'destroy']);
    Route::post  ('negocios/{id}/encargado', [NegocioController::class, 'asignarEncargado']);
    Route::get   ('negocios/{id}/encargado', [NegocioController::class, 'getEncargado']);
    Route::put   ('equipos/{id}',           [NegocioController::class, 'updateEquipo']);
    Route::get   ('equipos/{id}/historial', [NegocioController::class, 'getEquipoHistorial']);

    // 🛠️ Trabajos generales
    Route::get   ('trabajos',              [TrabajoController::class, 'index']);
    Route::post  ('trabajos',              [TrabajoController::class, 'store']);
    Route::get   ('trabajos/{id}',         [TrabajoController::class, 'show']);
    Route::put   ('trabajos/{id}',         [TrabajoController::class, 'update']);
    Route::put   ('trabajos/{id}/asignar', [TrabajoController::class, 'asignarTrabajador']);
    Route::put   ('trabajos/{id}/estado',  [TrabajoController::class, 'cambiarEstado']);
    Route::delete('trabajos/{id}',         [TrabajoController::class, 'destroy']);

    // 👷 Trabajadores generales
    Route::get   ('trabajadores',             [TrabajadorController::class, 'index']);
    Route::get   ('trabajadores/{id}',        [TrabajadorController::class, 'show']);
    Route::post  ('trabajadores',             [TrabajadorController::class, 'store']);
    Route::put   ('trabajadores/{id}',        [TrabajadorController::class, 'update']);
    Route::patch ('trabajadores/{id}/estado', [TrabajadorController::class, 'toggleEstado']);

    // 🔧 Solicitudes de mantenimiento generales
    Route::get  ('mantenimiento-solicitudes',                         [MantenimientoSolicitudController::class, 'index']);
    Route::post ('mantenimiento-solicitudes',                         [MantenimientoSolicitudController::class, 'store']);
    Route::get  ('mantenimiento-solicitudes/{id}',                    [MantenimientoSolicitudController::class, 'show']);
    Route::post ('mantenimiento-solicitudes/{id}/asignar-visita',     [MantenimientoSolicitudController::class, 'asignarVisita']);
    Route::post ('mantenimiento-solicitudes/{id}/asignar-reparacion', [MantenimientoSolicitudController::class, 'asignarReparacion']);
});

// Servir archivos de storage local
Route::get('/storage/uploads/{filename}', function ($filename) {
    $path = 'uploads/' . $filename;
    if (!\Illuminate\Support\Facades\Storage::disk('public')->exists($path)) {
        abort(404);
    }
    $filePath = \Illuminate\Support\Facades\Storage::disk('public')->path($path);
    return response()->file($filePath, [
        'Access-Control-Allow-Origin'  => '*',
        'Access-Control-Allow-Methods' => 'GET, OPTIONS',
    ]);
});
