<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SolicitudProveedor;
use App\Models\Trabajador;
use App\Models\User;
use App\Models\Role;
use App\Models\Notificacion;
use Illuminate\Http\Request;

class SolicitudProveedorController extends Controller
{
    // 📝 1. ENVIAR SOLICITUD DE UPGRADE A TÉCNICO PROVEEDOR
    public function store(Request $request)
    {
        $request->validate([
            'nombre_empresa' => 'required|string|max:255',
            'telefono' => 'required|string|max:50',
            'identificacion_proveedor' => 'nullable|file|max:10240', // hasta 10MB
            'escuadron' => 'nullable' // JSON o array
        ]);

        $user = $request->user();

        // Verificar si ya tiene una solicitud pendiente
        $existente = SolicitudProveedor::where('user_id', $user->id)
            ->where('estado', 'Pendiente')
            ->first();

        if ($existente) {
            return response()->json([
                'message' => 'Ya tienes una solicitud de proveedor pendiente de revisión por el Administrador.'
            ], 400);
        }

        // Subida de foto INE del proveedor
        $identificacionUrl = null;
        if ($request->hasFile('identificacion_proveedor')) {
            $isLocal = app()->environment('local');
            if ($isLocal) {
                $path = $request->file('identificacion_proveedor')->store('proveedores/ines', 'public');
                $identificacionUrl = asset('storage/' . $path);
            } else {
                $uploaded = \CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary::upload(
                    $request->file('identificacion_proveedor')->getRealPath(),
                    ['folder' => 'mantenere/proveedores/ines']
                )->getSecurePath();
                $identificacionUrl = $uploaded;
            }
        }

        // Procesar escuadrón
        $escuadronInput = $request->input('escuadron');
        if (is_string($escuadronInput)) {
            $escuadronInput = json_decode($escuadronInput, true);
        }

        $escuadronData = [];
        if (is_array($escuadronInput)) {
            foreach ($escuadronInput as $index => $miembro) {
                $ineUrl = null;
                $fileKey = "escuadron_ine_{$index}";
                if ($request->hasFile($fileKey)) {
                    $isLocal = app()->environment('local');
                    if ($isLocal) {
                        $path = $request->file($fileKey)->store('proveedores/escuadron', 'public');
                        $ineUrl = asset('storage/' . $path);
                    } else {
                        $uploaded = \CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary::upload(
                            $request->file($fileKey)->getRealPath(),
                            ['folder' => 'mantenere/proveedores/escuadron']
                        )->getSecurePath();
                        $ineUrl = $uploaded;
                    }
                }

                $escuadronData[] = [
                    'nombre' => $miembro['nombre'] ?? 'Técnico de Escuadrón',
                    'telefono' => $miembro['telefono'] ?? '',
                    'especialidad' => $miembro['especialidad'] ?? 'Mantenimiento General',
                    'ine_url' => $ineUrl || ($miembro['ine_url'] ?? null)
                ];
            }
        }

        $solicitud = SolicitudProveedor::create([
            'user_id' => $user->id,
            'nombre_empresa' => $request->nombre_empresa,
            'telefono' => $request->telefono,
            'identificacion_proveedor_url' => $identificacionUrl,
            'estado' => 'Pendiente',
            'escuadron_json' => $escuadronData
        ]);

        // Notificar al Admin Normal
        $adminRole = Role::whereIn('name', ['admin', 'root', 'Admin', 'Root'])->first();
        if ($adminRole) {
            $admins = User::where('role_id', $adminRole->id)->get();
            foreach ($admins as $admin) {
                Notificacion::create([
                    'user_id' => $admin->id,
                    'titulo' => '📋 Nueva Solicitud de Técnico Proveedor',
                    'mensaje' => "El técnico {$user->name} ha solicitado convertirse en Técnico Proveedor ({$request->nombre_empresa}).",
                    'leido' => false
                ]);
            }
        }

        return response()->json([
            'message' => 'Solicitud enviada exitosamente. El Administrador la revisará a la brevedad.',
            'solicitud' => $solicitud
        ], 201);
    }

    // 🔍 2. OBTENER MI SOLICITUD ACTUAL (TÉCNICO)
    public function miSolicitud(Request $request)
    {
        $user = $request->user();
        $solicitud = SolicitudProveedor::where('user_id', $user->id)
            ->latest()
            ->first();

        return response()->json($solicitud);
    }

    // 📋 3. LISTAR SOLICITUDES (ADMIN NORMAL)
    public function index(Request $request)
    {
        $solicitudes = SolicitudProveedor::with('user')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($solicitudes);
    }

    // 🔍 4. VER DETALLE DE SOLICITUD (ADMIN NORMAL)
    public function show($id)
    {
        $solicitud = SolicitudProveedor::with('user')->findOrFail($id);
        return response()->json($solicitud);
    }

    // ✅ 5. APROBAR SOLICITUD (ADMIN NORMAL)
    public function aprobar(Request $request, $id)
    {
        $solicitud = SolicitudProveedor::findOrFail($id);
        $solicitud->estado = 'Aprobado';
        $solicitud->save();

        $user = User::findOrFail($solicitud->user_id);

        // Asegurar que existe el rol tecnico-proveedor
        $roleProveedor = Role::firstOrCreate(
            ['name' => 'tecnico-proveedor'],
            ['description' => 'Técnico Proveedor con escuadrón a su cargo']
        );

        $user->role_id = $roleProveedor->id;
        $user->save();

        // Buscar o crear registro de Trabajador para este Proveedor
        $trabajadorProveedor = Trabajador::where('user_id', $user->id)->first();
        if (!$trabajadorProveedor) {
            $trabajadorProveedor = Trabajador::create([
                'nombre' => $user->name,
                'correo' => $user->email,
                'user_id' => $user->id,
                'puesto' => 'Técnico Proveedor (' . $solicitud->nombre_empresa . ')',
                'estado' => 'Disponible',
                'es_proveedor' => true
            ]);
        } else {
            $trabajadorProveedor->puesto = 'Técnico Proveedor (' . $solicitud->nombre_empresa . ')';
            $trabajadorProveedor->es_proveedor = true;
            $trabajadorProveedor->save();
        }

        // Crear los miembros de su escuadrón como trabajadores desvinculados de encargados
        if (is_array($solicitud->escuadron_json)) {
            foreach ($solicitud->escuadron_json as $miembro) {
                Trabajador::create([
                    'nombre' => $miembro['nombre'] ?? 'Técnico de Escuadrón',
                    'telefono' => $miembro['telefono'] ?? null,
                    'puesto' => $miembro['especialidad'] ?? 'Mantenimiento General',
                    'estado' => 'Disponible',
                    'proveedor_id' => $trabajadorProveedor->id,
                    'creador_id' => $user->id,
                    'avatar' => $miembro['ine_url'] ?? null
                ]);
            }
        }

        // Notificar al usuario
        Notificacion::create([
            'user_id' => $user->id,
            'titulo' => '🎉 ¡Solicitud Aprobada!',
            'mensaje' => "Tu solicitud para convertirte en Técnico Proveedor ({$solicitud->nombre_empresa}) ha sido aprobada por el Administrador.",
            'leido' => false
        ]);

        return response()->json([
            'message' => 'Solicitud aprobada exitosamente. Se asignó el rol de Técnico Proveedor y su escuadrón de técnicos.',
            'solicitud' => $solicitud,
            'user' => $user
        ]);
    }

    // ❌ 6. RECHAZAR SOLICITUD (ADMIN NORMAL)
    public function rechazar(Request $request, $id)
    {
        $request->validate([
            'motivo' => 'nullable|string'
        ]);

        $solicitud = SolicitudProveedor::findOrFail($id);
        $solicitud->estado = 'Rechazado';
        $solicitud->motivo_rechazo = $request->input('motivo', 'No se proporcionó información suficiente.');
        $solicitud->save();

        // Notificar al usuario
        Notificacion::create([
            'user_id' => $solicitud->user_id,
            'titulo' => '❌ Solicitud de Proveedor Rechazada',
            'mensaje' => "Tu solicitud para convertirse en Técnico Proveedor fue rechazada. Motivo: {$solicitud->motivo_rechazo}",
            'leido' => false
        ]);

        return response()->json([
            'message' => 'Solicitud rechazada correctamente.',
            'solicitud' => $solicitud
        ]);
    }
}
