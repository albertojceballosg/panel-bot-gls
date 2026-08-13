<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una corrida del bot: la jornada analizada y el marco en que hay que leer sus
 * incidencias (CONTEXTO.md §3.1).
 *
 * Va en su propia tabla y no repetida en cada incidencia porque no es adorno:
 * `reliable` dice si esa corrida pudo consultar bastantes envíos, y una jornada
 * dudosa no se puede enseñar como si cubriera el 100 %. Los contadores permiten
 * además decir "168 incidencias sobre 459 evaluados de 983 envíos", que es muy
 * distinto de enseñar 168 a secas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_runs', function (Blueprint $table) {
            $table->id();

            // La jornada analizada. Única: el bot manda siempre el día completo
            // (§3.1, regla 1), así que reenviar el 03/08 actualiza esta fila, no
            // crea otra. Es media clave natural del contrato.
            $table->date('run_date')->unique();

            // Versión del contrato con que llegó. Guardarla es lo que permitirá
            // saber, cuando el payload cambie, con qué forma se guardó cada día.
            $table->unsignedSmallInteger('payload_version');

            // Con zona explícita: los tres vienen del bot con su offset, y el de
            // la cinta es UTC (lo que muestra GLS Atlas). Los `timestamps()` de
            // abajo son de este panel y siguen la convención del resto del repo.
            $table->timestampTz('generated_at');
            $table->timestampTz('master_generated_at')->nullable();

            // Si es false, la corrida no pudo consultar bastantes envíos. **La
            // pantalla tiene que enseñarlo**: es la diferencia entre "no hubo
            // más incidencias" y "no se pudo mirar".
            $table->boolean('reliable');

            $table->unsignedSmallInteger('tolerance_minutes');
            $table->unsignedSmallInteger('batch_gap_minutes');

            // El balance del día tal y como lo contó el bot. Se guarda su cuenta
            // en vez de derivarla de las filas guardadas porque cuenta cosas que
            // aquí no llegan: los envíos correctos y los que no se pudieron
            // evaluar no viajan, sólo las incidencias.
            $table->unsignedInteger('shipments');
            $table->unsignedInteger('evaluated');
            $table->unsignedInteger('incidents_reported');
            $table->unsignedInteger('without_belt_time');
            $table->unsignedInteger('without_route');

            // Alertas de jornada: son del día y de la ruta, no de un paquete, así
            // que no tienen dónde colgarse en `incidents`. Como JSON porque son
            // texto ya redactado más su tipo; si algún día hay que filtrarlas o
            // agruparlas por ruta, se despiezan en su tabla.
            $table->jsonb('alerts');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_runs');
    }
};
