<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los gastos de la agencia (CONTEXTO.md §7, fase 15).
 *
 * Tabla propia y no una fila en `settings`: aquélla es clave/valor por módulo —un parámetro
 * suelto que alguien ajusta— y esto es una entidad con tres campos que se da de alta, se
 * edita y se da de baja. Meterlo en `settings` obligaría a inventar claves numeradas
 * (`expense_1_name`) y dejaría el historial ilegible, que es justo lo que aquella tabla
 * evita.
 *
 * **Baja pasiva**, como el resto del maestro (§4): un gasto que se deja de tener no se borra
 * —hay cálculos y auditoría que lo mencionan— sino que se retira, y se puede reactivar.
 *
 * `cost` es `decimal(10,2)` como `run_packages.net_revenue` y `real_cost`, a propósito: son
 * euros y los tres se acaban comparando entre sí. **No es nullable**: un gasto sin importe no
 * es un gasto a medio rellenar, es un dato que falta; para eso está no crearlo. Y el `0` sí se
 * admite, porque un gasto que este mes no cuesta nada sigue siendo un gasto que existe.
 *
 * `description` es `text` y nullable: es prosa de longitud imprevisible —para eso no vale un
 * `string(255)`— y opcional, porque hay gastos cuyo nombre ya lo dice todo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('cost', 10, 2);
            $table->text('description')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        // Único sólo entre los vivos, como en `couriers` y por lo mismo: dar de baja
        // «Alquiler» y volver a crearlo el año que viene tiene que poder hacerse, y un único
        // normal lo impediría para siempre por una fila que ya nadie ve.
        DB::statement('CREATE UNIQUE INDEX expenses_name_unique ON expenses (name) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
