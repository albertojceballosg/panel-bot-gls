<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una corrida diaria del bot (CONTEXTO.md §3.1).
 *
 * **No es `Auditable` a propósito.** El historial de §4 registra lo que hacen
 * las personas en el panel; esto lo escribe el bot solo, cada mañana, y meterlo
 * ahí llenaría la auditoría de ruido hasta enterrar los cambios de verdad.
 */
class IncidentRun extends Model
{
    protected $fillable = [
        'run_date', 'payload_version', 'generated_at', 'master_generated_at',
        'reliable', 'tolerance_minutes', 'batch_gap_minutes',
        'shipments', 'evaluated', 'incidents_reported',
        'without_belt_time', 'without_route', 'alerts',
    ];

    protected function casts(): array
    {
        return [
            'run_date' => 'date',
            'generated_at' => 'datetime',
            'master_generated_at' => 'datetime',
            'reliable' => 'boolean',
            'alerts' => 'array',
        ];
    }

    /** Todos los paquetes evaluados del día, con incidencia o sin ella. */
    public function packages(): HasMany
    {
        return $this->hasMany(RunPackage::class);
    }

    /**
     * Los que siguen vigentes. Un paquete retirado dejó de venir en un reenvío
     * de esa jornada, pero no se borra: hay que poder ver que existió.
     */
    public function currentPackages(): HasMany
    {
        return $this->packages()->whereNull('withdrawn_at');
    }

    /** Sólo lo que salió mal: 168 de los 493 con ruta que tuvo el 03/08. */
    public function currentIncidents(): HasMany
    {
        return $this->currentPackages()->whereNotNull('type');
    }
}
