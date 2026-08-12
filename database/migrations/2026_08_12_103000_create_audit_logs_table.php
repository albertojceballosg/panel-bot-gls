<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historial de cambios (CONTEXTO.md §4).
     *
     * Una fila = un cambio, y no se actualiza ni se borra nunca. Una sola tabla
     * para todas las entidades: con cuatro modelos, cuatro tablas de historial
     * serían cuatro consultas para responder una sola pregunta.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Quién. Nullable porque el sistema también escribe: seeders,
            // comandos de consola y cualquier cosa fuera de una sesión.
            //
            // nullOnDelete y no cascade: si algún día se borra a alguien de
            // verdad, su historial no se va con él.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Desnormalizado a propósito: el email hace legible la fila aunque
            // el usuario esté dado de baja, se le cambie el correo, o deje de
            // existir. El historial tiene que poder leerse dentro de dos años.
            $table->string('user_email')->nullable();

            $table->string('action');

            // Polimórfica en vez de un `module` de texto libre: guarda la clase
            // del modelo, así que no se puede escribir mal. Un typo en un
            // string ('merchant' por 'merchants') parte el historial en dos sin
            // que salte nada. `morphs` ya crea el índice compuesto.
            $table->morphs('auditable');

            // jsonb y no json: Postgres lo guarda parseado y lo puede indexar.
            // En UPDATE van sólo los campos que cambiaron; en alta y baja, el
            // registro entero.
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();

            // Sin updated_at: una fila de historial que se modifica no es un
            // historial.
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index('created_at', 'audit_logs_created_at_index');
            $table->index('user_email', 'audit_logs_user_email_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
