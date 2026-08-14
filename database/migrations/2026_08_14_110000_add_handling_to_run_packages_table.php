<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La gestión de una incidencia: atendida y con comentario (CONTEXTO.md §8).
 *
 * **Columnas del panel en una tabla que escribe el bot.** Conviven porque el
 * *upsert* de §3.1 escribe una lista explícita de columnas —las del contrato— y
 * no toca las demás: reenviar la jornada corrige los datos del bot y **no
 * borra** lo que una persona anotó. Hay un test que lo fija, porque es la clase
 * de cosa que se rompe callando.
 *
 * La alternativa era una tabla aparte, `run_package_handlings`. Se descartó: es
 * uno a uno con el paquete, la pantalla la leería siempre, y obligaría a un join
 * o a una consulta por fila para pintar un distintivo en el listado.
 *
 * `handled_by_name` está desnormalizado a propósito, como `audit_logs.user_name`
 * (§4): quien atendió una incidencia en agosto tiene que seguir leyéndose
 * dentro de dos años, se haya dado de baja o haya cambiado de nombre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('run_packages', function (Blueprint $table) {
            // Cuándo se atendió. Nulo es «pendiente», y es la única fuente de
            // verdad del estado: un booleano aparte podría contradecirla.
            $table->timestampTz('handled_at')->nullable();

            // Quién. nullOnDelete sólo salta ante un forceDelete —el panel da de
            // baja en pasivo—, y aun entonces queda el nombre copiado.
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('handled_by_name')->nullable();

            // El comentario. Text y no string: es prosa —«hablado con la UT, se
            // confundió de jaula»— y 255 caracteres se quedan cortos enseguida.
            $table->text('handling_note')->nullable();

            // Para contar pendientes de una jornada sin recorrer sus ~500 filas.
            $table->index(['incident_run_id', 'handled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('run_packages', function (Blueprint $table) {
            $table->dropIndex(['incident_run_id', 'handled_at']);
            $table->dropConstrainedForeignId('handled_by');
            $table->dropColumn(['handled_at', 'handled_by_name', 'handling_note']);
        });
    }
};
