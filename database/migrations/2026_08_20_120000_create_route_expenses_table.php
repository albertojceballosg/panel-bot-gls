<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que le cuesta cada ruta al mes, concepto a concepto (CONTEXTO.md §7, fase 15).
 *
 * **Por qué esta tabla y no un `cost` en `expenses`.** El importe no es del concepto, es de
 * la ruta: ni todos los transportistas cobran lo mismo, ni todas las rutas queman la misma
 * gasolina. Un único importe en el catálogo obligaría a repetir el concepto —«Gasolina Ruta
 * 1», «Gasolina Ruta 3»— y entonces nadie podría preguntar cuánto se va en gasolina en total,
 * porque para la base serían dos cosas distintas. Así que `expenses` se queda con el nombre y
 * aquí va el dinero. La columna `expenses.cost`, que nació el 20/08/2026 y no llegó a tener
 * datos, se muda en esta misma migración.
 *
 * **Todo es mensual** (decisión del cliente, 20/08/2026): `amount` es lo que cuesta ese
 * concepto en esa ruta **en un mes**. No hay importes diarios ni anuales; un seguro que se
 * paga de una vez se anota en el mes en que se paga.
 *
 * **Recurrente frente a puntual, que es lo que el cliente pidió poder distinguir.** El sueldo
 * del transportista se cobra todos los meses; el mantenimiento del camión puede pasar este mes
 * y ninguno más. Se resuelve con tres columnas:
 *
 * - `recurrent`: la intención. Se guarda aparte y no se deduce de las fechas porque un gasto
 *   recurrente que se canceló al mes siguiente tiene exactamente las mismas fechas que uno
 *   puntual, y no son lo mismo.
 * - `starts_on` y `ends_on`: desde qué mes aplica y hasta cuál, **ambos inclusive**.
 *   `ends_on` nulo es «sigue vigente», y sólo lo tiene un recurrente. En un puntual las dos
 *   son el mismo mes.
 *
 * Con eso, los gastos de una ruta en un mes M son una sola consulta que recoge a la vez los
 * recurrentes vigentes y los puntuales de ese mes:
 * `starts_on <= M AND (ends_on IS NULL OR ends_on >= M)`.
 *
 * **Una subida de sueldo no reescribe el pasado**: se cierra la línea vieja en el último mes
 * que valió y se abre otra. Agosto sigue diciendo lo que costó agosto, que es la única forma
 * de que contrastarlo contra la ganancia de agosto (§3.1) signifique algo.
 *
 * **Los meses se guardan como `date` con el día 1.** Postgres no tiene un tipo «mes», y un
 * `char(7)` con «2026-08» ordena bien pero no deja hacer aritmética de fechas ni comparar
 * contra un `Carbon` sin convertir en los dos lados. El día 1 es una convención que la
 * aplicación impone: ver `RouteExpense::month()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_expenses', function (Blueprint $table) {
            $table->id();

            // A qué ruta. Obligatorio: hoy todo gasto es de una ruta. El día que haya que
            // anotar el alquiler de la nave —que no es de ninguna— esta columna se hace
            // nullable y la pantalla gana un «General»; no hay nada más que cambiar.
            //
            // restrictOnDelete: con baja pasiva no llega a dispararse, pero cubre el
            // forceDelete. La regla que de verdad protege está en el modelo.
            $table->foreignId('pickup_route_id')->constrained()->restrictOnDelete();

            // De qué es. Apunta al catálogo en vez de repetir el nombre en texto, por lo
            // mismo que `merchants` apunta a su ruta: si no, «Gasolina» y «gasolina» son dos.
            $table->foreignId('expense_id')->constrained()->restrictOnDelete();

            // Euros al mes. `decimal(10,2)` como el resto del dinero del panel. No nulo: una
            // línea sin importe no es una línea a medias, es que no hay que crearla. El cero
            // sí vale — un concepto que este mes no ha costado nada y se quiere dejar dicho.
            $table->decimal('amount', 10, 2);

            $table->boolean('recurrent');

            // Meses, con el día 1. `ends_on` nulo = sigue vigente.
            $table->date('starts_on');
            $table->date('ends_on')->nullable();

            $table->softDeletes();
            $table->timestamps();

            // Lo que se consulta siempre: los gastos de una ruta en un mes.
            $table->index(['pickup_route_id', 'starts_on']);

            // Para «cuánto se va en gasolina», que es la pregunta que justifica el catálogo.
            $table->index(['expense_id', 'starts_on']);
        });

        // El importe se muda a `route_expenses`: dejarlo aquí serían dos fuentes para el
        // mismo número. La columna se creó el mismo día y nunca llegó a tener datos.
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('cost');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->decimal('cost', 10, 2)->nullable();
        });

        Schema::dropIfExists('route_expenses');
    }
};
