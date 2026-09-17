<?php

namespace App\Http\Controllers\Autonomo;

use App\Http\Controllers\Controller;
use App\Models\Negocio;
use App\Models\User;
use App\Models\Role;
use App\Models\Trabajador;
use App\Models\Trabajo;
use Illuminate\Http\Request;

/**
 * NegocioController — Ecosistema AUTÓNOMO
 * Maneja negocios del ecosistema autónomo (admin_autonomo_id IS NOT NULL).
 * Roles: propietario-autonomo (4), administrador-general (5), gerente-sucursal (6)
 */
class NegocioController extends Controller
{
    private function resolveAdminId($user): ?int
    {
        return strtolower($user->role->name) === 'propietario-autonomo'
            ? $user->id
            : ($user->admin_autonomo_id ?? null);
    }

    public function index(Request $request)
    {
        $user     = $request->user();
        $roleName = strtolower($user->role->name);

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
        ])->whereNotNull('admin_autonomo_id');

        if (in_array($roleName, ['root', 'admin'])) {
            // Sin filtro: supervisan todo
        } elseif ($roleName === 'gerente-sucursal') {
            if ($user->negocio_id) $query->where('id', $user->negocio_id);
        } else {
            $adminId = $this->resolveAdminId($user);
            if ($adminId) $query->where('admin_autonomo_id', $adminId);
        }

        return response()->json($query->get());
    }

    public function show($id)
    {
        $negocio = Negocio::whereNotNull('admin_autonomo_id')->with(['user', 'areas.equipos.categoria'])->find($id);
        if (!$negocio) {
            return response()->json(['message' => 'Negocio no encontrado'], 404);
        }
        return response()->json($negocio);
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombre'             => 'required|string|max:255',
            'tipo'               => 'required|string|max:255',
            'gerente'            => 'nullable|string',
            'telefonoGerente'    => 'nullable|string',
            'subgerente'         => 'nullable|string',
            'telefonoSubgerente' => 'nullable|string',
        ]);

        $authUser = $request->user();
        $adminId  = $this->resolveAdminId($authUser);

        $data = $request->all();
        $data['admin_autonomo_id'] = $adminId;

        $negocio = Negocio::create($data);
        return response()->json(['message' => 'Negocio creado', 'data' => $negocio], 201);
    }

    public function update(Request $request, $id)
    {
        $negocio = Negocio::whereNotNull('admin_autonomo_id')->find($id);
        if (!$negocio) {
            return response()->json(['message' => 'Negocio no encontrado'], 404);
        }

        $negocio->update($request->except(['levantamiento', 'user_id', 'admin_autonomo_id']));

        if ($request->has('levantamiento')) {
            $this->syncLevantamiento($negocio, $request->input('levantamiento', []));
        }

        $negocio->load('areas.equipos.categoria');
        return response()->json(['message' => 'Negocio actualizado', 'data' => $negocio]);
    }

    // ── Asignar Gerente de Sucursal ────────────────────────────────────────
    public function asignarGerenteSucursal(Request $request, $id)
    {
        $request->validate([
            'email'    => 'required|email',
            'name'     => 'required|string',
            'password' => 'required|min:8',
        ]);

        $negocio = Negocio::whereNotNull('admin_autonomo_id')->findOrFail($id);

        $roleGerente = Role::where('name', 'gerente-sucursal')->first();
        if (!$roleGerente) {
            return response()->json(['message' => 'Rol gerente-sucursal no existe en el sistema'], 500);
        }

        $gerenteSucursal = User::where('negocio_id', $id)->where('role_id', $roleGerente->id)->first();

        if ($gerenteSucursal) {
            $gerenteSucursal->update([
                'email'    => $request->email,
                'name'     => $request->name,
                'password' => \Illuminate\Support\Facades\Hash::make($request->password),
            ]);
        } else {
            $existing = User::where('email', $request->email)->first();
            if ($existing) {
                return response()->json(['message' => 'El correo ya está en uso'], 422);
            }
            $gerenteSucursal = User::create([
                'name'              => $request->name,
                'email'             => $request->email,
                'password'          => \Illuminate\Support\Facades\Hash::make($request->password),
                'role_id'           => $roleGerente->id,
                'negocio_id'        => $id,
                'admin_autonomo_id' => $negocio->admin_autonomo_id,
                'active'            => 1,
            ]);
        }

        return response()->json(['message' => 'Gerente de sucursal asignado', 'gerente' => $gerenteSucursal]);
    }

    public function getGerenteSucursal($id)
    {
        $roleGerente = Role::where('name', 'gerente-sucursal')->first();
        if (!$roleGerente) return response()->json(['gerente' => null]);

        $gerente = User::where('negocio_id', $id)->where('role_id', $roleGerente->id)->first();
        return response()->json([
            'gerente' => $gerente ? ['name' => $gerente->name, 'email' => $gerente->email] : null
        ]);
    }

    private function syncLevantamiento(Negocio $negocio, array $areasData): void
    {
        $existingAreaIds = $negocio->areas()->pluck('id')->toArray();
        $keptAreaIds = collect($areasData)->pluck('id')->filter(function($id) use ($existingAreaIds) {
            return is_numeric($id) && in_array((int)$id, $existingAreaIds);
        })->map(fn($id) => (int)$id)->toArray();

        $negocio->areas()->whereNotIn('id', $keptAreaIds)->delete();

        foreach ($areasData as $areaInput) {
            $area = null;
            if (!empty($areaInput['id']) && is_numeric($areaInput['id'])) {
                $area = $negocio->areas()->find($areaInput['id']);
            }
            if (!$area) {
                $area = new \App\Models\LevantamientoArea();
            }

            $area->nombreArea = $areaInput['nombreArea'] ?? 'Área';
            $area->sub_areas_json = collect($areaInput['subAreas'] ?? [])->map(fn($sa) => ['id' => $sa['id'] ?? null, 'nombreSubArea' => $sa['nombreSubArea'] ?? null])->toArray();
            $negocio->areas()->save($area);

            $rawEquipos = array_merge($areaInput['equipos'] ?? [], ...array_map(fn($sa) => $sa['equipos'] ?? [], $areaInput['subAreas'] ?? []));
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

    public function destroy($id)
    {
        $negocio = Negocio::whereNotNull('admin_autonomo_id')->find($id);
        if (!$negocio) {
            return response()->json(['message' => 'Negocio no encontrado'], 404);
        }

        $negocio->delete();
        return response()->json(['message' => 'Negocio eliminado']);
    }

    /**
     * Mini-tablero: conteos de trabajos por estado para una sucursal.
     * GET /api/autonomo/negocios/{id}/resumen
     */
    public function resumen($id)
    {
        $negocio = Negocio::findOrFail($id);

        $today = now()->toDateString();

        $counts = Trabajo::selectRaw("
            COUNT(CASE WHEN (prioridad = 'Alta' OR tipo = 'SOS')
                            AND estado NOT IN ('Finalizado','Completado')
                       THEN 1 END) as sos,
            COUNT(CASE WHEN estado IN ('Solicitud','Pendiente')
                            AND NOT (prioridad = 'Alta' OR tipo = 'SOS')
                       THEN 1 END) as solicitud,
            COUNT(CASE WHEN estado = 'En Proceso'
                            AND NOT (prioridad = 'Alta' OR tipo = 'SOS')
                       THEN 1 END) as en_proceso,
            COUNT(CASE WHEN estado IN ('Finalizado','Completado')
                            AND DATE(updated_at) = ?
                       THEN 1 END) as finalizado_hoy
        ", [$today])
            ->where('negocio_id', $negocio->id)
            ->first();

        return response()->json([
            'sos'            => (int) $counts->sos,
            'solicitud'      => (int) $counts->solicitud,
            'en_proceso'     => (int) $counts->en_proceso,
            'finalizado_hoy' => (int) $counts->finalizado_hoy,
        ]);
    }
}