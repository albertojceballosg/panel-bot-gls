<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El apellido, aparte del nombre.
     *
     * Nullable y no `default('')`: las cuentas que ya existen —la del seeder
     * inicial, entre ellas— no tienen apellido y nadie puede inventárselo. Un
     * NULL dice «no consta», que es la verdad; una cadena vacía diría que se
     * comprobó y no lo tiene.
     *
     * `name` se queda como está y sigue siendo el nombre de pila: es lo que
     * saluda en la cabecera, lo que firma en `audit_logs.user_name` y lo que
     * `AuditPresenter::record()` usa para nombrar la fila. Partirlo en dos
     * columnas de verdad —y no añadir una— habría reescrito ese historial.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('last_name')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_name');
        });
    }
};
