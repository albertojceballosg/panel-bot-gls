<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\PickupRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * El historial de cambios (CONTEXTO.md §4).
 *
 * Existe para una pregunta muy concreta: si alguien mueve un comercio de la
 * ruta 3 a la 5 y el informe del bot cambia al día siguiente, poder saber quién
 * y cuándo. El propósito del sistema entero es control de calidad, y el dato
 * que lo gobierna sin historial es un punto ciego.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(string $name = 'COBO FAMILY, S.L.'): Merchant
    {
        return Merchant::create([
            'name' => $name,
            'pickup_route_id' => PickupRoute::firstOrCreate(['name' => '3'])->id,
        ]);
    }

    // --- Qué se registra ----------------------------------------------------

    public function test_creating_a_record_logs_the_whole_thing(): void
    {
        $merchant = $this->merchant();

        $log = $merchant->auditLogs()->sole();

        $this->assertSame(AuditAction::Create, $log->action);
        $this->assertNull($log->before);
        $this->assertSame('COBO FAMILY, S.L.', $log->after['name']);
    }

    public function test_updating_logs_only_what_changed(): void
    {
        $merchant = $this->merchant();
        $otherRoute = PickupRoute::create(['name' => '5']);

        $merchant->update(['pickup_route_id' => $otherRoute->id]);

        // La pregunta que motivó la tabla, respondida.
        $log = $merchant->auditLogs()->first();
        $this->assertSame(AuditAction::Update, $log->action);
        $this->assertSame(['pickup_route_id'], array_keys($log->after));
        $this->assertSame($otherRoute->id, $log->after['pickup_route_id']);
        $this->assertNotSame($otherRoute->id, $log->before['pickup_route_id']);
    }

    public function test_saving_without_changes_does_not_write_a_row(): void
    {
        $merchant = $this->merchant();
        $this->assertSame(1, $merchant->auditLogs()->count());

        // En un formulario esto pasa constantemente: si ensuciase el historial,
        // los cambios de verdad quedarían enterrados.
        $merchant->update(['name' => 'COBO FAMILY, S.L.']);

        $this->assertSame(1, $merchant->fresh()->auditLogs()->count());
    }

    public function test_deleting_logs_the_last_known_state(): void
    {
        $merchant = $this->merchant();
        $merchant->delete();

        $log = $merchant->auditLogs()->first();
        $this->assertSame(AuditAction::Delete, $log->action);
        $this->assertSame('COBO FAMILY, S.L.', $log->before['name']);
        $this->assertNull($log->after);
    }

    public function test_restoring_logs_a_restore_and_not_a_spurious_update(): void
    {
        $merchant = $this->merchant();
        $merchant->delete();
        $merchant->restore();

        // `restore()` llama a `save()`, así que dispara `updated` además de
        // `restored`. Como `deleted_at` está excluido, ese update se queda
        // vacío y no genera una fila de más.
        $acciones = $merchant->auditLogs()->pluck('action')->all();

        $this->assertSame(
            [AuditAction::Restore, AuditAction::Delete, AuditAction::Create],
            $acciones,
        );
    }

    public function test_the_history_survives_the_record_it_describes(): void
    {
        $merchant = $this->merchant();
        $merchant->delete();

        // El registro está dado de baja; la relación lo resuelve igual porque
        // `auditable()` lleva withTrashed. Sin eso, el historial de todo lo que
        // se da de baja se quedaría sin poder pintar de qué habla.
        $log = AuditLog::where('auditable_type', Merchant::class)->latest('id')->first();

        $this->assertTrue($merchant->is($log->auditable));
    }

    // --- Quién ---------------------------------------------------------------

    public function test_it_records_who_made_the_change(): void
    {
        $user = User::factory()->create(['email' => 'alguien@panel.local']);
        $this->actingAs($user);

        $log = $this->merchant()->auditLogs()->sole();

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('alguien@panel.local', $log->user_email);
        $this->assertSame($user->name, $log->authorName());
    }

    public function test_without_a_session_the_author_is_the_system(): void
    {
        // Seeders, consola, cron. Preferible a inventarse un autor.
        $log = $this->merchant()->auditLogs()->sole();

        $this->assertNull($log->user_id);
        $this->assertNull($log->user_email);
        $this->assertSame('Sistema', $log->authorName());
    }

    public function test_the_email_keeps_the_row_readable_after_the_user_is_deleted(): void
    {
        $user = User::factory()->create(['email' => 'sequedo@panel.local']);
        $this->actingAs($user);
        $merchant = $this->merchant();

        $user->delete();

        // Desnormalizado a propósito: el historial tiene que leerse dentro de
        // dos años, con o sin el usuario.
        $log = $merchant->auditLogs()->sole();
        $this->assertSame('sequedo@panel.local', $log->user_email);
    }

    // --- Datos sensibles -----------------------------------------------------

    public function test_the_password_never_reaches_the_history(): void
    {
        $user = User::create([
            'name' => 'Alguien',
            'email' => 'nuevo@panel.local',
            'password' => 'una-contraseña-larga',
        ]);
        $user->update(['password' => 'otra-contraseña-distinta']);

        $volcado = AuditLog::all()->toJson();

        $this->assertStringNotContainsString('password', $volcado);
        $this->assertStringNotContainsString('remember_token', $volcado);
        $this->assertStringNotContainsString('una-contraseña-larga', $volcado);
        $this->assertStringNotContainsString($user->password, $volcado);
    }

    public function test_users_are_audited_for_everything_else(): void
    {
        $user = User::create([
            'name' => 'Alguien',
            'email' => 'nuevo@panel.local',
            'password' => 'una-contraseña-larga',
        ]);

        $this->assertSame('nuevo@panel.local', $user->auditLogs()->sole()->after['email']);
    }

    public function test_a_password_only_change_still_leaves_no_empty_row(): void
    {
        $user = User::create([
            'name' => 'Alguien',
            'email' => 'nuevo@panel.local',
            'password' => 'una-contraseña-larga',
        ]);

        $user->update(['password' => 'otra-contraseña-distinta']);

        // El único campo que cambió está excluido, así que el diff queda vacío.
        $this->assertSame(1, $user->auditLogs()->count());
    }

    // --- Inmutabilidad y ruido ------------------------------------------------

    public function test_a_history_entry_cannot_be_modified(): void
    {
        $log = $this->merchant()->auditLogs()->sole();

        $this->expectException(RuntimeException::class);
        $log->update(['action' => AuditAction::Delete]);
    }

    public function test_a_history_entry_cannot_be_deleted(): void
    {
        $log = $this->merchant()->auditLogs()->sole();

        $this->expectException(RuntimeException::class);
        $log->delete();
    }

    public function test_bulk_loads_can_be_silenced(): void
    {
        AuditLog::withoutRecording(fn () => $this->merchant());

        $this->assertSame(0, AuditLog::count());

        // Y el interruptor vuelve solo, incluso si algo revienta dentro.
        try {
            AuditLog::withoutRecording(fn () => throw new RuntimeException('lo que sea'));
        } catch (RuntimeException) {
            // esperado
        }

        $this->merchant('Otro comercio');
        $this->assertSame(1, AuditLog::count());
    }

    public function test_the_three_audited_entities_write_history(): void
    {
        $pickupRoute = PickupRoute::create(['name' => '1']);
        Courier::create(['name' => 'Benjamin GLS', 'pickup_route_id' => $pickupRoute->id]);
        Merchant::create(['name' => 'Zona Joven', 'pickup_route_id' => $pickupRoute->id]);

        $this->assertSame(3, AuditLog::count());
        $this->assertSame(
            [Courier::class, Merchant::class, PickupRoute::class],
            AuditLog::pluck('auditable_type')->sort()->values()->all(),
        );
    }
}
