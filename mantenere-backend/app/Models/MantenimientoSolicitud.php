<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MantenimientoSolicitud extends Model
{
    use HasFactory;

    protected $table = 'mantenimiento_solicitudes';

    protected $fillable = [
        'cliente_id',
        'negocio_id',
        'equipo_id',
        'levantamiento_equipo_id',
        'descripcion_problema',
        'estado',
        'trabajador_id',
        'visita_trabajo_id',
        'reparacion_trabajo_id',
        'admin_cotizacion',
        'admin_cotizacion_pdf'
    ];

    public function cliente()
    {
        return $this->belongsTo(User::class, 'cliente_id');
    }

    public function negocio()
    {
        return $this->belongsTo(Negocio::class);
    }

    public function equipo()
    {
        return $this->belongsTo(LevantamientoEquipo::class, 'equipo_id');
    }

    public function levantamientoEquipo()
    {
        return $this->belongsTo(LevantamientoEquipo::class);
    }

    public function trabajador()
    {
        return $this->belongsTo(Trabajador::class, 'trabajador_id');
    }

    public function visitaTrabajo()
    {
        return $this->belongsTo(Trabajo::class, 'visita_trabajo_id');
    }

    public function reparacionTrabajo()
    {
        return $this->belongsTo(Trabajo::class, 'reparacion_trabajo_id');
    }

    public function visitas()
    {
        return $this->hasMany(MantenimientoVisita::class, 'mantenimiento_solicitud_id');
    }

    public function reportes()
    {
        return $this->hasMany(MantenimientoReporte::class, 'mantenimiento_solicitud_id');
    }
}
