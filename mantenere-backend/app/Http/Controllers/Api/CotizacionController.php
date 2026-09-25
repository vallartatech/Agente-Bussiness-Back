<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cotizacion;
use App\Models\Trabajo;
use App\Mail\NuevaCotizacionMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class CotizacionController extends Controller
{
    // 🔍 1. Obtener TODAS las cotizaciones de un trabajo (array)
    public function showByTrabajo($trabajo_id)
    {
        $cotizaciones = Cotizacion::where('trabajo_id', $trabajo_id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($cotizaciones);
    }

    // ➕ 2. Crear una NUEVA cotización (permite múltiples por trabajo)
    public function store(Request $request)
    {
        if ($request->has('monto') && is_string($request->monto)) {
            $montoLimpio = preg_replace('/[^\d.]/', '', str_replace(',', '', $request->monto));
            $request->merge(['monto' => $montoLimpio]);
        }

        $request->validate([
            'trabajo_id' => 'required|exists:trabajos,id',
            'monto'      => 'required|numeric',
            'archivo'    => 'nullable|file|max:10240',
        ]);

        $pathArchivo = null;
        if ($request->hasFile('archivo')) {
            $pathArchivo = $request->file('archivo')->store('cotizaciones', 'public');
        }

        $cotizacion = Cotizacion::create([
            'trabajo_id'  => $request->trabajo_id,
            'descripcion' => $request->descripcion,
            'monto'       => $request->monto,
            'estado'      => 'Pendiente',
            'archivo'     => $pathArchivo,
        ]);

        $this->enviarNotificacionCliente($cotizacion);

        return response()->json([
            'message' => 'Cotización creada exitosamente.',
            'data'    => $cotizacion
        ], 201);
    }

    private function enviarNotificacionCliente(Cotizacion $cotizacion)
    {
        try {
            $trabajo = Trabajo::with('negocio.user')->find($cotizacion->trabajo_id);
            
            if ($trabajo && $trabajo->negocio) {
                // 1. Intentar con el correo específico de la sucursal/negocio
                // 2. Fallback al correo del dueño (usuario)
                $destinatario = $trabajo->negocio->correo ?? ($trabajo->negocio->user->email ?? null);

                if ($destinatario) {
                    Mail::to($destinatario)->send(new NuevaCotizacionMail($cotizacion));
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error enviando correo de cotización: ' . $e->getMessage());
        }
    }

    // ✏️ 3. Editar una cotización existente (Admin o Cliente)
    public function update(Request $request, $id)
    {
        $cotizacion = Cotizacion::find($id);

        if (!$cotizacion) {
            return response()->json(['message' => 'Cotización no encontrada.'], 404);
        }

        $request->validate([
            'monto'   => 'sometimes|numeric',
            'estado'  => 'sometimes|in:Pendiente,Aprobada,Rechazada',
            'archivo' => 'nullable|file|max:10240',
        ]);

        $pathArchivo = $cotizacion->archivo;
        if ($request->hasFile('archivo')) {
            $pathArchivo = $request->file('archivo')->store('cotizaciones', 'public');
        }

        $updateData = [
            'descripcion' => $request->descripcion ?? $cotizacion->descripcion,
            'monto'       => $request->monto ?? $cotizacion->monto,
            'archivo'     => $pathArchivo,
        ];
        if ($request->has('estado')) {
            $updateData['estado'] = $request->estado;
        }

        $cotizacion->update($updateData);

        if ($request->estado === 'Aprobada') {
            $trabajo = \App\Models\Trabajo::find($cotizacion->trabajo_id);
            if ($trabajo) {
                $trabajo->estado = 'Cotización Aprobada';
                $trabajo->trabajador_id = null;
                $trabajo->visitado = false;
                $trabajo->save();

                $mantenimiento = \App\Models\MantenimientoSolicitud::where('visita_trabajo_id', $trabajo->id)->first();
                if ($mantenimiento) {
                    $mantenimiento->estado = 'Cotización Aceptada';
                    $mantenimiento->save();
                }
            }
        }

        return response()->json([
            'message' => 'Cotización actualizada correctamente.',
            'data'    => $cotizacion->fresh()
        ]);
    }

    // 🗑️ 4. Eliminar una cotización (Admin)
    public function destroy($id)
    {
        $cotizacion = Cotizacion::find($id);

        if (!$cotizacion) {
            return response()->json(['message' => 'Cotización no encontrada.'], 404);
        }

        $cotizacion->delete();

        return response()->json(['message' => 'Cotización eliminada correctamente.'], 200);
    }

    // ✅❌ 5. Cambiar estado individual (Aprobar o Rechazar — desde el Cliente)
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'estado' => 'required|in:Pendiente,Aprobada,Rechazada'
        ]);

        $cotizacion = Cotizacion::find($id);

        if (!$cotizacion) {
            return response()->json(['message' => 'Cotización no encontrada.'], 404);
        }

        $cotizacion->update(['estado' => $request->estado]);

        // Si se aprueba una cotización, actualizar el estado del trabajo.
        // Las demás cotizaciones permanecen con su estado actual.
        if ($request->estado === 'Aprobada') {
            $trabajo = \App\Models\Trabajo::find($cotizacion->trabajo_id);
            if ($trabajo) {
                $trabajo->estado = 'Cotización Aprobada';
                $trabajo->trabajador_id = null;
                $trabajo->visitado = false;
                $trabajo->save();

                // Check if this work is a Maintenance Visit
                $mantenimiento = \App\Models\MantenimientoSolicitud::where('visita_trabajo_id', $trabajo->id)->first();
                if ($mantenimiento) {
                    $mantenimiento->estado = 'Cotización Aceptada';
                    $mantenimiento->save();
                }
            }
        }

        return response()->json([
            'message' => 'Estado actualizado a ' . $request->estado,
            'data'    => $cotizacion->fresh()
        ]);
    }
}
