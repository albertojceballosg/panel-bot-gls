<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un paquete evaluado de una jornada (CONTEXTO.md §3.1).
 *
 * **Una fila por paquete, no por incidencia.** Nació como tabla `incidents` el
 * 13/08/2026 y se reescribió el mismo día, antes de desplegar nada: la pantalla
 * necesita enseñar la ruta entera —«94 paquetes, 11 con incidencia»— y no sólo
 * lo que salió mal. Una segunda tabla para los paquetes correctos habría dejado
 * el mismo envío en dos sitios, que es como empiezan a discrepar. El grano
 * natural del dato es el paquete; «incidencia» es un filtro sobre él
 * (`type` no nulo).
 *
 * Tres ideas gobiernan este esquema:
 *
 * 1. **La incertidumbre se guarda con el dato.** `confidence` y
 *    `confidence_reasons` no son metadatos: sin ellos, una fila con `type` es
 *    una acusación contra un mensajero, y el 03/08 el bot marcó 160 de 168 como
 *    no concluyentes. Una pantalla que las ignore difama.
 *
 * 2. **Lo que se guarda es la foto del día, no el estado de hoy.** Los nombres
 *    de comercio, ruta y mensajero se copian aunque haya FK: si mañana renombran
 *    la ruta o reasignan al conductor, esta fila debe seguir diciendo lo que
 *    pasó aquel día. Las FK sirven para agrupar y enlazar; los nombres, para
 *    contar la verdad.
 *
 * 3. **Un paquete sin incidencia es un dato, no un hueco.** Es la mitad de la
 *    pregunta del cliente: saber que 83 de los 94 de la Ruta 1 fueron donde
 *    debían es lo que pone las 11 restantes en proporción.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('run_packages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('incident_run_id')->constrained()->cascadeOnDelete();

            // El identificador del envío en GLS. Con la jornada, la clave natural
            // del contrato: el panel hace upsert por ahí (§3.1, regla 2), de modo
            // que reenviar un día no duplica nada.
            $table->string('shipment_id');

            // El código de barras. Informativo: sirve para buscar el paquete en
            // el portal de GLS cuando alguien discuta la incidencia.
            $table->string('barcode')->nullable();

            // Nulos si el maestro de aquel día no traía identificadores (el Excel
            // de recambio, o un panel anterior al 13/08/2026): el contrato los
            // marca opcionales, así que el nombre tiene que bastar para leerlas.
            //
            // nullOnDelete: con borrado pasivo la fila del comercio sigue viva,
            // así que esto sólo salta ante un forceDelete. Aun entonces la fila
            // se conserva, con su nombre copiado.
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('merchant_name');

            // La ruta que el maestro decía que le tocaba a ese comercio.
            $table->foreignId('assigned_route_id')->nullable()
                ->constrained('pickup_routes')->nullOnDelete();
            $table->string('assigned_route_name')->nullable();

            // Quién conducía **aquel día**. Copiado y sin FK a propósito: el
            // panel puede reasignar el mensajero de una ruta, y eso no puede
            // reescribir quién recogió el paquete el 3 de agosto.
            $table->string('assigned_courier_name')->nullable();

            // La ruta en cuya tanda pasó el paquete: la acusación. **Nula cuando
            // `type` es `fuera_de_tanda`** —56 de las 168 del 03/08—, porque el
            // paquete pasó descolgado y no hay a quién señalar. Son dos hallazgos
            // distintos y la pantalla no debe mezclarlos.
            $table->foreignId('observed_route_id')->nullable()
                ->constrained('pickup_routes')->nullOnDelete();
            $table->string('observed_route_name')->nullable();

            // `tanda_de_otra_ruta` | `fuera_de_tanda`, y **nulo cuando el paquete
            // pasó donde debía**. Es lo que separa las 168 incidencias de los 493
            // paquetes con ruta que tuvo el 03/08.
            $table->string('type')->nullable();

            // Hora de paso por la cinta, en UTC como la muestra GLS Atlas. Nula
            // si el paquete no llegó a escanearse: 34 ese día.
            $table->timestampTz('belt_time')->nullable();

            // Minutos contra la mediana de su ruta. Con signo: negativo es antes.
            $table->decimal('deviation_minutes', 8, 1)->nullable();

            // Otras rutas que compartían esa tanda: ese día son indistinguibles
            // por hora. Si no está vacío, la acusación no señala a una sola.
            $table->jsonb('compatible_routes')->nullable();

            // `alta` | `baja`, y por qué: `ruta_dispersa`, `tanda_compartida`.
            // Nulos en un paquete sin incidencia: no hay nada que sostener.
            $table->string('confidence')->nullable();
            $table->jsonb('confidence_reasons')->nullable();

            // Cuándo dejó de venir en los reenvíos de esa jornada. **No se borra**
            // (§3.1, regla 2, y los borrados pasivos de §4): si el bot corrige un
            // día y una fila desaparece, hay que poder ver que existió y dejó de
            // estar, no que nunca hubo nada.
            $table->timestampTz('withdrawn_at')->nullable();

            $table->timestamps();

            // La clave natural del contrato. `incident_run_id` va en lugar de la
            // fecha porque `run_date` ya es única en `incident_runs`: son la
            // misma clave, y así no hay dos sitios donde la fecha pueda mentir.
            $table->unique(['incident_run_id', 'shipment_id']);

            // La pantalla agrupa por ruta y separa las incidencias del resto.
            $table->index(['incident_run_id', 'assigned_route_name']);
            $table->index(['incident_run_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_packages');
    }
};
