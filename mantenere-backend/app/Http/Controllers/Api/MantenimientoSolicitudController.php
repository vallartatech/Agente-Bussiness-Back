<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MantenimientoSolicitud;
use App\Models\Trabajo;
use App\Models\Notificacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MantenimientoSolicitudController extends Controller
{
    // GET /api/mantenimiento-solicitudes (Para el Admin o para listar)
    public function index(Request $request)
    {
        $user = $request->user();
        $roleName = $user && $user->role ? strtolower($user->role->name) : '';

        $query = MantenimientoSolicitud::with([
            'cliente', 
            'negocio', 
            'levantamientoEquipo', 
            'visitaTrabajo.reporte', 
            'reparacionTrabajo.reporte'
        ]);

        if ($roleName === 'propietario-autonomo' || $roleName === 'administrador-general') {
            $query->whereHas('negocio', function ($q) use ($user) {
                $q->where('admin_autonomo_id', $user->admin_autonomo_id ?? $user->id);
            });
        } elseif ($roleName === 'gerente-sucursal') {
            if (isset($user->negocio_id)) {
                $query->where('negocio_id', $user->negocio_id);
            }
        } elseif ($roleName === 'cliente') {
            // El cliente puede ver solicitudes donde él es el cliente_id
            // O solicitudes de negocios que le pertenecen
            $negociosIds = \App\Models\Negocio::where('user_id', $user->id)
                ->pluck('id');
            $query->where(function ($q) use ($user, $negociosIds) {
                $q->where('cliente_id', $user->id)
                  ->orWhereIn('negocio_id', $negociosIds);
            });
        }

        if ($request->has('negocio_id')) {
            $query->where('negocio_id', $request->query('negocio_id'));
        }

        $solicitudes = $query->orderBy('created_at', 'desc')->get();
        return response()->json($solicitudes);
    }

    // POST /api/mantenimiento-solicitudes (Cliente reporta un problema)
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cliente_id' => 'required|exists:users,id',
            'negocio_id' => 'required|exists:negocios,id',
            'levantamiento_equipo_id' => 'required|exists:levantamiento_equipos,id',
            'descripcion_problema' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $solicitud = MantenimientoSolicitud::create([
            'cliente_id' => $request->cliente_id,
            'negocio_id' => $request->negocio_id,
            'levantamiento_equipo_id' => $request->levantamiento_equipo_id,
            'descripcion_problema' => $request->descripcion_problema,
            'estado' => 'Pendiente',
        ]);

        // Notificar al Encargado y Admin Autónomo
        $negocio = \App\Models\Negocio::with(['encargados', 'user.role'])->find($request->negocio_id);
        if ($negocio) {
            $solicitud->load('levantamientoEquipo');
            $equipoNombre = $solicitud->levantamientoEquipo->nombre ?? 'Equipo';
            $mensaje = "Se ha reportado un problema/mantenimiento para el equipo: " . $equipoNombre . " en la sucursal " . $negocio->nombre . ".";

            $adminAutonomoId = $negocio->admin_autonomo_id;
            if (!$adminAutonomoId && $negocio->user) {
                $ownerRole = strtolower($negocio->user->role->name ?? '');
                if (in_array($ownerRole, ['propietario-autonomo', 'administrador-general', 'admin-autonomo', 'autonomo'])) {
                    $adminAutonomoId = $negocio->user->admin_autonomo_id ?? $negocio->user->id;
                }
            }

            if ($adminAutonomoId) {
                $ecosistemaUsers = \App\Models\User::where(function($q) use ($adminAutonomoId) {
                    $q->where('id', $adminAutonomoId)
                      ->orWhere(function($sub) use ($adminAutonomoId) {
                          $sub->where('admin_autonomo_id', $adminAutonomoId)
                              ->whereHas('role', function($query) {
                                  $query->whereIn('name', ['propietario-autonomo', 'administrador-general', 'gerente-general', 'admin-autonomo', 'autonomo']);
                              });
                      });
                })->get();

                foreach ($ecosistemaUsers as $ecoUser) {
                    $notif = Notificacion::create([
                        'user_id' => $ecoUser->id,
                        'titulo' => 'NUEVA SOLICITUD 🛠️',
                        'mensaje' => $mensaje,
                        'tipo' => 'mantenimiento',
                        'enlace' => '/autonomo/mantenimiento-detalle/' . $solicitud->id,
                        'leido' => false,
                    ]);
                    try {
                        broadcast(new \App\Events\NotificationSent($notif));
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning("Error broadcasting notification: " . $e->getMessage());
                    }
                }
            } else {
                $baseAdmins = \App\Models\User::whereHas('role', function($q) {
                    $q->whereIn('name', ['Admin', 'admin', 'root']);
                })->get();
                foreach ($baseAdmins as $bAdmin) {
                    $notif = Notificacion::create([
                        'user_id' => $bAdmin->id,
                        'titulo' => 'NUEVA SOLICITUD 🛠️',
                        'mensaje' => $mensaje,
                        'tipo' => 'mantenimiento',
                        'enlace' => '/menu/mantenimiento-detalle/' . $solicitud->id,
                        'leido' => false,
                    ]);
                    try {
                        broadcast(new \App\Events\NotificationSent($notif));
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning("Error broadcasting notification: " . $e->getMessage());
                    }
                }
            }

            $authUserId = $request->user()?->id ?? $request->cliente_id;
            foreach ($negocio->encargados as $encargado) {
                if ($encargado->id == $authUserId) continue;
                $notif = Notificacion::create([
                    'user_id' => $encargado->id,
                    'titulo' => 'NUEVA SOLICITUD 🛠️',
                    'mensaje' => $mensaje,
                    'tipo' => 'mantenimiento',
                    'enlace' => '/gerente-sucursal/mantenimiento-detalle/' . $solicitud->id,
                    'leido' => false,
                ]);
                try {
                    broadcast(new \App\Events\NotificationSent($notif));
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("Error broadcasting notification: " . $e->getMessage());
                }
            }
        }

        return response()->json(['message' => 'Problema reportado exitosamente', 'data' => $solicitud], 201);
    }

    // GET /api/mantenimiento-solicitudes/{id} (Ver detalle)
    public function show($id)
    {
        $solicitud = MantenimientoSolicitud::with(['cliente', 'negocio', 'levantamientoEquipo', 'trabajador.user', 'visitaTrabajo.trabajador.user', 'reparacionTrabajo.trabajador.user', 'visitas.tecnico', 'reportes.tecnico'])->find($id);

        if (!$solicitud) {
            return response()->json(['message' => 'Solicitud no encontrada'], 404);
        }

        return response()->json($solicitud);
    }

    // POST /api/mantenimiento-solicitudes/{id}/asignar-visita
    public function asignarVisita($id, Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tecnico_id' => 'required|exists:users,id',
            'fecha_programada' => 'required|date',
            'hora_programada' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $solicitud = MantenimientoSolicitud::with('levantamientoEquipo')->find($id);

        if (!$solicitud) {
            return response()->json(['message' => 'Solicitud no encontrada'], 404);
        }

        if ($solicitud->estado !== 'Pendiente') {
            return response()->json(['message' => 'La solicitud no está en estado Pendiente'], 400);
        }

        $trabajador = \App\Models\Trabajador::where('user_id', $request->tecnico_id)->first();
        if (!$trabajador) {
            return response()->json(['message' => 'El técnico asignado no existe como trabajador.'], 400);
        }

        // Crear el Trabajo de Visita
        $trabajo = Trabajo::create([
            'titulo' => 'Mantenimiento (Visita): ' . ($solicitud->levantamientoEquipo->nombre ?? 'Equipo'),
            'descripcion' => "Revisión y diagnóstico.\nProblema reportado: " . $solicitud->descripcion_problema,
            'fecha_programada' => $request->fecha_programada,
            'fechaAsignada' => $request->fecha_programada,
            'horaAsignada' => $request->hora_programada,
            'trabajador_id' => $trabajador->id,
            'negocio_id' => $solicitud->negocio_id,
            'estado' => 'Asignado',
            'prioridad' => 'Media',
            'tipo' => 'Visita',
            'visitado' => false,
        ]);

        // Actualizar solicitud
        $solicitud->estado = 'Visita Asignada';
        $solicitud->visita_trabajo_id = $trabajo->id;
        $solicitud->trabajador_id = $trabajador->id;
        $solicitud->save();

        // Notificar al Técnico
        try {
            $notif = Notificacion::create([
                'user_id' => $request->tecnico_id,
                'titulo' => 'Nueva Visita de Mantenimiento 🛠️',
                'mensaje' => 'Se te ha asignado una visita para el equipo: ' . ($solicitud->levantamientoEquipo->nombre ?? 'Equipo'),
                'tipo' => 'mantenimiento',
                'enlace' => '/tecnico/trabajo-detalle/' . $trabajo->id,
                'leido' => false,
            ]);
            broadcast(new \App\Events\NotificationSent($notif));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Error creating/broadcasting notif: " . $e->getMessage());
        }

        return response()->json([
            'message' => 'Visita asignada y notificada correctamente',
            'trabajo' => $trabajo
        ]);
    }

    // POST /api/mantenimiento-solicitudes/{id}/asignar-reparacion
    public function asignarReparacion($id, Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tecnico_id' => 'required|exists:users,id',
            'fecha_programada' => 'required|date',
            'hora_programada' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $solicitud = MantenimientoSolicitud::with('levantamientoEquipo')->find($id);

        if (!$solicitud) {
            return response()->json(['message' => 'Solicitud no encontrada'], 404);
        }

        if ($solicitud->estado !== 'Cotización Aceptada') {
            return response()->json(['message' => 'La solicitud no tiene una cotización aceptada.'], 400);
        }

        if ($solicitud->reparacion_trabajo_id !== null) {
            return response()->json(['message' => 'Ya se ha asignado un trabajo de reparación para esta solicitud.'], 400);
        }

        $trabajador = \App\Models\Trabajador::where('user_id', $request->tecnico_id)->first();
        if (!$trabajador) {
            return response()->json(['message' => 'El técnico asignado no existe como trabajador.'], 400);
        }

        // Crear el Trabajo de Reparación
        $trabajo = Trabajo::create([
            'titulo' => 'Mantenimiento (Reparación): ' . ($solicitud->levantamientoEquipo->nombre ?? 'Equipo'),
            'descripcion' => "Reparación tras cotización aprobada.\nProblema reportado: " . $solicitud->descripcion_problema,
            'fecha_programada' => $request->fecha_programada,
            'fechaAsignada' => $request->fecha_programada,
            'horaAsignada' => $request->hora_programada,
            'trabajador_id' => $trabajador->id,
            'negocio_id' => $solicitud->negocio_id,
            'estado' => 'Asignado',
            'prioridad' => 'Alta', // Alta hace que en el frontend actúe como SOS (Alerta)
            'tipo' => 'Trabajo',
            'visitado' => false,
        ]);

        // Actualizar solicitud con el ESTADO ENUM CORRECTO: "Trabajo Asignado"
        $solicitud->estado = 'Trabajo Asignado';
        $solicitud->reparacion_trabajo_id = $trabajo->id;
        $solicitud->trabajador_id = $trabajador->id;
        $solicitud->save();

        // Notificar al Técnico
        try {
            $notif = Notificacion::create([
                'user_id' => $request->tecnico_id,
                'titulo' => 'Nuevo Trabajo de Reparación 🛠️',
                'mensaje' => 'Se te ha asignado un trabajo de mantenimiento para el equipo: ' . ($solicitud->levantamientoEquipo->nombre ?? 'Equipo'),
                'tipo' => 'mantenimiento',
                'enlace' => '/tecnico/trabajo-detalle/' . $trabajo->id,
                'leido' => false,
            ]);
            broadcast(new \App\Events\NotificationSent($notif));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Error creating/broadcasting notif: " . $e->getMessage());
        }

        return response()->json([
            'message' => 'Trabajo de reparación asignado y notificado correctamente',
            'trabajo' => $trabajo
        ]);
    }

    // POST /api/mantenimiento-solicitudes/{id}/actualizar-asignacion
    public function actualizarAsignacion($id, Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tecnico_id' => 'required|exists:users,id',
            'fecha_programada' => 'required|date',
            'hora_programada' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $solicitud = MantenimientoSolicitud::with(['levantamientoEquipo', 'visitaTrabajo', 'reparacionTrabajo'])->find($id);

        if (!$solicitud) {
            return response()->json(['message' => 'Solicitud no encontrada'], 404);
        }

        $trabajador = \App\Models\Trabajador::where('user_id', $request->tecnico_id)->first();
        if (!$trabajador) {
            return response()->json(['message' => 'El técnico seleccionado no existe como trabajador.'], 400);
        }

        $trabajo = null;
        if ($solicitud->estado === 'Visita Asignada' && $solicitud->visitaTrabajo) {
            $trabajo = $solicitud->visitaTrabajo;
        } elseif (($solicitud->estado === 'Reparación Asignada' || $solicitud->estado === 'Trabajo Asignado') && $solicitud->reparacionTrabajo) {
            $trabajo = $solicitud->reparacionTrabajo;
        }

        if (!$trabajo) {
            return response()->json(['message' => 'No hay un trabajo activo para actualizar en esta solicitud.'], 400);
        }

        // Actualizar el trabajo en la BD
        $trabajo->trabajador_id = $trabajador->id;
        $trabajo->fecha_programada = $request->fecha_programada;
        $trabajo->fechaAsignada = $request->fecha_programada;
        if ($request->filled('hora_programada')) {
            $trabajo->horaAsignada = $request->hora_programada;
        }
        $trabajo->save();

        $solicitud->trabajador_id = $trabajador->id;
        $solicitud->save();

        // Notificar al nuevo técnico
        try {
            $notif = Notificacion::create([
                'user_id' => $request->tecnico_id,
                'titulo' => 'Asignación Actualizada 🛠️',
                'mensaje' => 'Se te ha reasignado el trabajo/visita para el equipo: ' . ($solicitud->levantamientoEquipo->nombre ?? 'Equipo'),
                'tipo' => 'mantenimiento',
                'enlace' => '/tecnico/trabajo-detalle/' . $trabajo->id,
                'leido' => false,
            ]);
            broadcast(new \App\Events\NotificationSent($notif));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Error creating/broadcasting notif: " . $e->getMessage());
        }

        return response()->json([
            'message' => 'Asignación de técnico actualizada exitosamente',
            'trabajo' => $trabajo,
            'solicitud' => $solicitud->fresh(['cliente', 'negocio', 'levantamientoEquipo', 'trabajador.user', 'visitaTrabajo.trabajador.user', 'reparacionTrabajo.trabajador.user'])
        ]);
    }

    // POST /api/mantenimiento-solicitudes/{id}/cancelar-asignacion
    public function cancelarAsignacion($id)
    {
        $solicitud = MantenimientoSolicitud::with(['visitaTrabajo', 'reparacionTrabajo'])->find($id);

        if (!$solicitud) {
            return response()->json(['message' => 'Solicitud no encontrada'], 404);
        }

        if ($solicitud->visita_trabajo_id && $solicitud->estado === 'Visita Asignada') {
            $trabajo = $solicitud->visitaTrabajo;
            $solicitud->visita_trabajo_id = null;
            $solicitud->trabajador_id = null;
            $solicitud->estado = 'Pendiente';
            $solicitud->save();

            if ($trabajo) {
                $trabajo->delete();
            }

            return response()->json([
                'message' => 'Asignación de visita cancelada exitosamente. La solicitud vuelve a estar Pendiente.',
                'solicitud' => $solicitud->fresh(['cliente', 'negocio', 'levantamientoEquipo', 'visitaTrabajo', 'reparacionTrabajo'])
            ]);
        }

        if ($solicitud->reparacion_trabajo_id && ($solicitud->estado === 'Reparación Asignada' || $solicitud->estado === 'Trabajo Asignado')) {
            $trabajo = $solicitud->reparacionTrabajo;
            $solicitud->reparacion_trabajo_id = null;
            $solicitud->trabajador_id = null;
            $solicitud->estado = 'Cotización Aceptada';
            $solicitud->save();

            if ($trabajo) {
                $trabajo->delete();
            }

            return response()->json([
                'message' => 'Asignación de reparación cancelada exitosamente.',
                'solicitud' => $solicitud->fresh(['cliente', 'negocio', 'levantamientoEquipo', 'visitaTrabajo', 'reparacionTrabajo'])
            ]);
        }

        return response()->json(['message' => 'La solicitud no tiene una asignación activa que pueda cancelarse.'], 400);
    }

}
