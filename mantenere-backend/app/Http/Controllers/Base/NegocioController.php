<?php

namespace App\Http\Controllers\Base;

use App\Http\Controllers\Controller;
use App\Models\Negocio;
use App\Models\MantenimientoSolicitud;
use App\Models\LevantamientoEquipo;
use Illuminate\Http\Request;

/**
 * NegocioController — Ecosistema BASE
 * Maneja negocios del sistema principal (admin_autonomo_id IS NULL).
 * Roles: root (0), Admin (1), Cliente (2), tecnico-normal (3)
 */
class NegocioController extends Controller
{
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
        ])->whereNull('admin_autonomo_id'); // Solo negocios base

        // Cliente solo ve sus propios negocios
        if ($roleName === 'cliente') {
            $query->where('user_id', $user->id);
        }

        return response()->json($query->get());
    }

    public function show($id)
    {
        $negocio = Negocio::whereNull('admin_autonomo_id')->with(['user', 'areas.equipos.categoria'])->find($id);
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

        $data = $request->all();
        $data['admin_autonomo_id'] = null; // Siempre null en base

        $negocio = Negocio::create($data);
        return response()->json(['message' => 'Negocio creado', 'data' => $negocio], 201);
    }

    public function update(Request $request, $id)
    {
        $negocio = Negocio::whereNull('admin_autonomo_id')->find($id);
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

        return response()->json(['message' => 'Equipo actualizado', 'data' => $equipo]);
    }

    public function getEquipoHistorial($id)
    {
        $equipo = LevantamientoEquipo::with(['categoria', 'area.negocio'])->find($id);
        if (!$equipo) {
            return response()->json(['message' => 'Equipo no encontrado'], 404);
        }

        $solicitudes = MantenimientoSolicitud::with([
            'visitas.tecnico', 'reportes', 'visitaTrabajo.reporte', 'reparacionTrabajo.reporte',
        ])
        ->where(fn($q) => $q->where('equipo_id', $id)->orWhere('levantamiento_equipo_id', $id))
        ->orderBy('created_at', 'desc')
        ->get();

        return response()->json(['equipo' => $equipo, 'solicitudes' => $solicitudes]);
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
            $cleanSubAreas = [];
            if (isset($areaInput['subAreas']) && is_array($areaInput['subAreas'])) {
                foreach ($areaInput['subAreas'] as $sa) {
                    $cleanSubAreas[] = ['id' => $sa['id'] ?? null, 'nombreSubArea' => $sa['nombreSubArea'] ?? null];
                }
            }
            $area->sub_areas_json = $cleanSubAreas;
            $negocio->areas()->save($area);

            $rawEquipos = $areaInput['equipos'] ?? [];
            if (isset($areaInput['subAreas'])) {
                foreach ($areaInput['subAreas'] as $sa) {
                    if (isset($sa['equipos'])) $rawEquipos = array_merge($rawEquipos, $sa['equipos']);
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

    public function destroy($id)
    {
        $negocio = Negocio::whereNull('admin_autonomo_id')->find($id);
        if (!$negocio) {
            return response()->json(['message' => 'Negocio no encontrado'], 404);
        }

        $negocio->delete();
        return response()->json(['message' => 'Negocio eliminado']);
    }
}

