<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que le cuesta a la agencia el envío, sin IVA, en euros (CONTEXTO.md §3.1, v5).
 *
 * Es el campo «Costes reales» que alguien **teclea a mano** en la ficha del envío en
 * Envexpress (Mensaglobal). No se calcula: o está escrito o no está. El bot lo captura y lo
 * manda ya en euros, por envío —no por bulto— y redondeado a dos decimales; este panel no
 * habla con Envexpress.
 *
 * **No confundirlo con los otros dos importes del mismo envío.** En el 408622:
 * `ganancia` = 2,89 € (lo facturado sin IVA, columna `net_revenue`), el `coste` de las
 * valoraciones = 2,29 € (el que el portal usa para su «Beneficio», y que **no viaja en el
 * contrato**) y `costes_reales` = 2,20 €, que es esta columna.
 *
 * **Aquí el cero es un dato, no un hueco** — y es la diferencia con `net_revenue` y con
 * `volume_m3`. Hay 7 envíos con un cero tecleado en cinco días, frente a 671 con la ficha
 * vacía. Por eso el intake usa `??` y nunca `?:` ni `empty()`: los dos últimos convertirían
 * ese cero en nulo y borrarían lo que una persona escribió. Nulo significa sólo «nadie lo
 * rellenó» (o «la corrida es anterior a la v5»).
 *
 * **Nulo no es cero al sumar**, con la consecuencia de siempre: un total sobre esta columna
 * tiene que decir **sobre cuántos envíos** se hizo, y con **su propio contador**. No vale
 * reutilizar el de `net_revenue`: aunque el 07/08/2026 coincidan (513 y 513), esto lo rellena
 * una persona y puede faltar en envíos que sí tienen ganancia.
 *
 * **El margen lo calcula el panel**, y sólo cuando están los dos datos:
 * `margen = net_revenue − real_cost`, nulo si falta cualquiera de los dos. Ninguno de ellos
 * es el margen por su cuenta, y el bot no publica ningún margen.
 *
 * **Sin backfill a propósito**: las filas que ya están se quedan a nulo, que es la verdad —
 * aquellas jornadas no traían el dato. Un cero ahí se leería como «no costó nada».
 *
 * `decimal(10,2)`: gemela de `net_revenue`, y por las mismas razones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('run_packages', function (Blueprint $table) {
            $table->decimal('real_cost', 10, 2)->nullable()->after('net_revenue');
        });
    }

    public function down(): void
    {
        Schema::table('run_packages', function (Blueprint $table) {
            $table->dropColumn('real_cost');
        });
    }
};
