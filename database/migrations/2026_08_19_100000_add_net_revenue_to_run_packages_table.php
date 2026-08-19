<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que se facturó por el envío, sin IVA, en euros (CONTEXTO.md §3.1, v4).
 *
 * Sale de Envexpress (Mensaglobal), el otro portal de la agencia: es la suma de la columna
 * `Precio` de sus valoraciones —servicio más suplementos—, que el bot cruza por código de
 * barras y manda ya resuelto. Este panel no habla con Envexpress.
 *
 * **No es el margen.** El margen sería `ganancia − coste`, y el coste no viaja en el
 * contrato por decisión del 19/08/2026. En un envío de 3,06 € de precio y 2,46 € de coste,
 * donde el portal rotula «Beneficio: 0,60 €», esta columna guarda **3,06 €**.
 *
 * **Nula, no cero, cuando el envío no aparece en Envexpress** — 30 de 543 el 07/08/2026.
 * Es el mismo criterio que `volume_m3` y por el mismo motivo: un cero aquí diría «no se ganó
 * nada» y falsearía a la baja el total de una ruta, además de esconder que el dato faltaba.
 * De ahí que cualquier suma en la interfaz tenga que decir **sobre cuántos envíos** se hizo.
 *
 * Nullable, además, por un segundo motivo que conviene no confundir con el anterior: una
 * corrida de un bot anterior a la v4 no trae el campo en absoluto.
 *
 * **Sin backfill a propósito**: el cliente vacía las tablas antes de la primera corrida v4.
 *
 * `decimal(10,2)`: son euros con dos decimales, los envíos reales van de 1,60 € a 60 € y una
 * jornada entera ronda los 5.000 € (4.871,10 € el 07/08/2026), así que diez dígitos dejan
 * sitio de sobra para sumar cualquier rango de fechas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('run_packages', function (Blueprint $table) {
            $table->decimal('net_revenue', 10, 2)->nullable()->after('volume_m3');
        });
    }

    public function down(): void
    {
        Schema::table('run_packages', function (Blueprint $table) {
            $table->dropColumn('net_revenue');
        });
    }
};
