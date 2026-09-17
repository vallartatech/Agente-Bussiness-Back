<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Negocio;
use App\Models\LevantamientoEquipo;
use App\Models\MantenimientoSolicitud;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\CredencialesSucursalMail;

class NegocioController extends Controller
{
    // 🔍 Obtener todos los negocios (Para ListaNegocios del Admin)
    public function index(Request $request)
    {
        $user = $request->user();
        $roleName = $user && $user->role ? strtolower($user->role->name) : '';

        $myLevel = $user && $user->role ? $user->role->hierarchy_level : 0;


        $query = Negocio::with('areas.equipos.categoria')->select([
            'id',
            'admin_autonomo_id',
            'nombre',
            'tipo',
            'encargado',
            'user_id',
            'nombrePlaza',
            'calle',
            'numero',
            'colonia',
            'calleAv',
            'manzana',
            'lote',
            'ciudad',
            'estado',
            'cp',
            'imagenPerfil',
            'imagen_portada',
            'estado_aprobacion',
            'latitud',
            'longitud',
            'created_at'
        ]);

        // Admin Autónomo o Gerente General solo ve SUS negocios
        if (in_array($roleName, ['propietario-autonomo', 'administrador-general', 'admin-autonomo', 'autonomo'])) {
            $query->where('admin_autonomo_id', $user->admin_autonomo_id ?? $user->id);
        } elseif ($roleName === 'gerente-sucursal') {
            $query->where('id', $user->negocio_id);
        } elseif ($roleName === 'cliente') {
            $query->whereNull('admin_autonomo_id')->where('user_id', $user->id);
        } elseif ($roleName !== 'admin' && $roleName !== 'root') {
            // Técnicos etc. solo ven negocios del sistema principal
            $query->whereNull('admin_autonomo_id');
        }
        // Admin / Root ven TODOS los negocios

        $negocios = $query->get();
        return response()->json($negocios);
    }

    // ✏️ Actualizar datos de un equipo individual (Admin desde Inventario General)
    public function updateEquipo(Request $request, $id)
    {
        $equipo = LevantamientoEquipo::find($id);

        if (!$equipo) {
            return response()->json(['message' => 'Equipo no encontrado'], 404);
        }

        $equipo->fill([
            'nombre'          => $request->input('nombre', $equipo->nombre),
            'marca'           => $request->input('marca', $equipo->marca),
            'modelo'          => $request->input('modelo', $equipo->modelo),
            'serie'           => $request->input('serie', $equipo->serie),
            'anioFabricacion' => $request->input('anioFabricacion', $equipo->anioFabricacion),
            'anioUso'         => $request->input('anioUso', $equipo->anioUso),
            'categoria_id'    => $request->input('categoria_id', $equipo->categoria_id),
        ]);
        $equipo->save();

        $equipo->load('categoria');

        return response()->json([
            'message' => 'Equipo actualizado correctamente',
            'data'    => $equipo,
        ]);
    }

    // 📋 Historial de solicitudes de mantenimiento para un equipo específico
    public function getEquipoHistorial($id)
    {
        $equipo = LevantamientoEquipo::with(['categoria', 'area.negocio'])->find($id);

        if (!$equipo) {
            return response()->json(['message' => 'Equipo no encontrado'], 404);
        }

        $solicitudes = MantenimientoSolicitud::with([
            'visitas.tecnico',
            'reportes',
            'visitaTrabajo.reporte',
            'reparacionTrabajo.reporte',
        ])
        ->where(function ($q) use ($id) {
            $q->where('equipo_id', $id)->orWhere('levantamiento_equipo_id', $id);
        })
        ->orderBy('created_at', 'desc')
        ->get();

        return response()->json([
            'equipo'      => $equipo,
            'solicitudes' => $solicitudes,
        ]);
    }

    // 🔍 Obtener un solo negocio (Para Editar PerfilEmpresa)
    public function show($id)
    {
        $negocio = Negocio::with(['user', 'areas.equipos.categoria'])->find($id);

        if (!$negocio) {
            return response()->json(['message' => 'Negocio no encontrado'], 404);
        }

        return response()->json($negocio);
    }

    // ➕ Registrar un nuevo negocio (Para PerfilEmpresa POST)
    public function store(Request $request)
    {
        // Validación de datos básicos
        $request->validate([
            'nombre' => 'required|string|max:255',
            'tipo'   => 'required|string|max:255',
            'gerente'             => 'nullable|string',
            'telefonoGerente'     => 'nullable|string',
            'subgerente'          => 'nullable|string',
            'telefonoSubgerente'  => 'nullable|string',
        ]);

        $data = $request->all();

        // Si quien crea es Admin Autónomo o Gerente General, taggear el negocio con su ID
        $user = $request->user();
        $roleName = $user && $user->role ? strtolower($user->role->name) : '';

        $myLevel = $user && $user->role ? $user->role->hierarchy_level : 0;

        if (in_array($roleName, ['propietario-autonomo', 'administrador-general', 'admin-autonomo', 'autonomo'])) {
            $data['admin_autonomo_id'] = $user->admin_autonomo_id ?? $user->id;
        }

        $negocio = Negocio::create($data);

        return response()->json([
            'message' => 'Negocio creado exitosamente',
            'data'    => $negocio
        ], 201);
    }

    // ✏️ Actualizar un negocio existente
    public function update(Request $request, $id)
    {
        $negocio = Negocio::find($id);

        if (!$negocio) {
            return response()->json(['message' => 'Negocio no encontrado'], 404);
        }

        // Actualizamos los datos básicos de la empresa (ignorando user_id para no robar propiedad)
        $negocio->update($request->except(['levantamiento', 'user_id']));

        // Sincronizamos el levantamiento si viene en la petición
        if ($request->has('levantamiento')) {
            $areasData = $request->input('levantamiento', []);
            
            // 1. Recolectar IDs existentes en BD para no borrar por error IDs temporales del frontend
            $existingAreaIds = $negocio->areas()->pluck('id')->toArray();
            $keptAreaIds = collect($areasData)->pluck('id')->filter(function($id) use ($existingAreaIds) {
                return is_numeric($id) && in_array((int)$id, $existingAreaIds);
            })->map(fn($id) => (int)$id)->toArray();

            // Borramos áreas que existían en BD pero ya no vienen en el request
            $negocio->areas()->whereNotIn('id', $keptAreaIds)->delete();

            foreach ($areasData as $areaInput) {
                // Si el ID existe en BD lo buscamos, si no (nuevo con ID tipo Date.now()), creamos una nueva
                $area = null;
                if (!empty($areaInput['id']) && is_numeric($areaInput['id'])) {
                    $area = $negocio->areas()->find($areaInput['id']);
                }
                if (!$area) {
                    $area = new \App\Models\LevantamientoArea();
                }

                $area->nombreArea = $areaInput['nombreArea'] ?? 'Área';

                $cleanSubAreas = [];
                if (isset($areaInput['subAreas']) && is_array($areaInput['subAreas'])) {
                    foreach ($areaInput['subAreas'] as $subAreaInput) {
                        $cleanSubAreas[] = [
                            'id' => $subAreaInput['id'] ?? null,
                            'nombreSubArea' => $subAreaInput['nombreSubArea'] ?? null
                        ];
                    }
                }
                $area->sub_areas_json = $cleanSubAreas;

                $negocio->areas()->save($area);

                // Sincronizar Equipos (recolectando de equipos principales y subAreas)
                $rawEquipos = $areaInput['equipos'] ?? [];
                if (isset($areaInput['subAreas']) && is_array($areaInput['subAreas'])) {
                    foreach ($areaInput['subAreas'] as $subAreaInput) {
                        if (isset($subAreaInput['equipos']) && is_array($subAreaInput['equipos'])) {
                            $rawEquipos = array_merge($rawEquipos, $subAreaInput['equipos']);
                        }
                    }
                }

                $uniqueEquipos = [];
                foreach ($rawEquipos as $eq) {
                    $key = (isset($eq['id']) && is_numeric($eq['id']))
                        ? 'id_'.$eq['id']
                        : (isset($eq['id']) && !empty($eq['id'])
                            ? 'str_'.$eq['id']
                            : 'name_'.($eq['nombre'] ?? '').'_'.($eq['subAreaId'] ?? '').'_'.($eq['serie'] ?? ''));
                    $uniqueEquipos[$key] = $eq;
                }
                $equiposData = array_values($uniqueEquipos);
                
                $existingEqIds = $area->equipos()->pluck('id')->toArray();
                $keptEqIds = collect($equiposData)->pluck('id')->filter(function($id) use ($existingEqIds) {
                    return is_numeric($id) && in_array((int)$id, $existingEqIds);
                })->map(fn($id) => (int)$id)->toArray();

                $area->equipos()->whereNotIn('id', $keptEqIds)->delete();

                foreach ($equiposData as $eqInput) {
                    $equipo = null;
                    if (!empty($eqInput['id']) && is_numeric($eqInput['id'])) {
                        $equipo = $area->equipos()->find($eqInput['id']);
                    }
                    if (!$equipo) {
                        $equipo = new \App\Models\LevantamientoEquipo();
                    }

                    $catId = (!empty($eqInput['categoria_id']) && is_numeric($eqInput['categoria_id']))
                        ? (int)$eqInput['categoria_id']
                        : null;

                    $equipo->fill([
                        'nombre' => $eqInput['nombre'] ?? 'Equipo',
                        'marca' => $eqInput['marca'] ?? '',
                        'modelo' => $eqInput['modelo'] ?? '',
                        'serie' => $eqInput['serie'] ?? null,
                        'anioFabricacion' => $eqInput['anioFabricacion'] ?? null,
                        'anioUso' => $eqInput['anioUso'] ?? null,
                        'foto' => $eqInput['foto'] ?? null,
                        'fotoPlaca' => $eqInput['fotoPlaca'] ?? null,
                        'categoria_id' => $catId,
                        'subAreaId' => $eqInput['subAreaId'] ?? null,
                        'nombreSubArea' => $eqInput['nombreSubArea'] ?? null,
                        'subCategoria' => $eqInput['subCategoria'] ?? null,
                    ]);
                    $area->equipos()->save($equipo);
                }
            }
        }

        // Refrescamos el modelo para devolverlo completo con su categoría
        $negocio->load('areas.equipos.categoria');

        return response()->json([
            'message' => 'Negocio actualizado correctamente',
            'data' => $negocio
        ]);
    }
    // Método para crear/actualizar al encargado de la sucursal
    public function asignarEncargado(Request $request, $id)
    {
        $request->validate([
            'email' => 'required|email',
            'name' => 'required|string',
            'password' => 'required|min:8',
        ]);
        $negocio = \App\Models\Negocio::findOrFail($id);
        
        $roleEncargado = \App\Models\Role::where('name', 'gerente-sucursal')->first();
        if (!$roleEncargado) {
            return response()->json(['message' => 'Rol de gerente de sucursal no existe en el sistema'], 500);
        }
        // Buscar si ya hay un encargado para esta sucursal
        $encargado = \App\Models\User::where('negocio_id', $id)
                                     ->where('role_id', $roleEncargado->id)
                                     ->first();
        $plainPassword = $request->password;
          if (!$negocio->admin_autonomo_id && $negocio->user_id) {
              $negocio->admin_autonomo_id = $negocio->user?->admin_autonomo_id ?? $negocio->user_id;
              $negocio->save();
          }
        if ($encargado) {
            // Actualizar datos
            $encargado->update([
                'email' => $request->email,
                'name' => $request->name,
                'password' => \Illuminate\Support\Facades\Hash::make($plainPassword),
            ]);
        } else {
            // Asegurarse de que el correo no esté en uso por otro usuario (opcional según regla de negocio, aquí validamos por seguridad)
            $existingUser = \App\Models\User::where('email', $request->email)->first();
            if ($existingUser) {
                return response()->json(['message' => 'El correo ya está en uso por otro usuario'], 422);
            }
            // Crear nuevo encargado
            $encargado = \App\Models\User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => \Illuminate\Support\Facades\Hash::make($plainPassword),
                'role_id' => $roleEncargado->id,
                'negocio_id' => $id,
                'active' => 1
            ]);
        }
        // Enviar correo (Deshabilitado temporalmente)
        // \Illuminate\Support\Facades\Mail::to($encargado->email)->send(
        //     new \App\Mail\CredencialesSucursalMail($encargado, $plainPassword, $negocio->nombre)
        // );
        return response()->json([
            'message' => 'Encargado asignado correctamente',
            'encargado' => $encargado
        ]);
    }
    // Método para obtener el encargado actual de la sucursal
    public function getEncargado($id)
    {
        $roleEncargado = \App\Models\Role::where('name', 'gerente-sucursal')->first();
        if (!$roleEncargado) {
            return response()->json(['encargado' => null]);
        }
        $encargado = \App\Models\User::where('negocio_id', $id)
                                     ->where('role_id', $roleEncargado->id)
                                     ->first();
        return response()->json([
            'encargado' => $encargado ? [
                'name' => $encargado->name,
                'email' => $encargado->email,
            ] : null
        ]);
    }

    // ❌ Eliminar un negocio existente (Para PerfilEmpresa DELETE)
    public function destroy($id)
    {
        $negocio = Negocio::find($id);

        if (!$negocio) {
            return response()->json(['message' => 'Negocio no encontrado'], 404);
        }

        $negocio->delete();

        return response()->json([
            'message' => 'Negocio eliminado correctamente'
        ]);
    }
}

