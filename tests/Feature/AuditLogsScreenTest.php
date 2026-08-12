<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\PickupRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Listado de auditoría (CONTEXTO.md §7, módulo 6).
 *
 * Responde la pregunta que el historial de la ficha no puede: «el informe de
 * ayer cambió, ¿qué tocó alguien?». Cronológica y entre módulos.
 */
class AuditLogsScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private PickupRoute $tres;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuario = User::factory()->create(['name' => 'Alberto', 'email' => 'alberto@panel.local']);
        $this->actingAs($this->usuario);
        $this->tres = PickupRoute::create(['name' => '3']);
    }

    public function test_it_is_behind_the_session(): void
    {
        auth()->logout();

        $this->get('/audit-logs')->assertRedirect('/login');
    }

    // --- El listado ---------------------------------------------------------

    public function test_it_lists_who_changed_what_and_in_which_module(): void
    {
        $merchant = Merchant::create(['name' => 'COBO FAMILY, S.L.', 'pickup_route_id' => $this->tres->id]);
        $merchant->update(['pickup_route_id' => PickupRoute::create(['name' => '5'])->id]);

        Livewire::test('audit-logs')
            ->assertSee('Alberto')
            ->assertSee('Comercios')
            ->assertSee('COBO FAMILY, S.L.')
            ->assertSee('Modificación')
            ->assertSee('Ver detalle');
    }

    public function test_the_newest_change_comes_first(): void
    {
        PickupRoute::create(['name' => 'Primera']);
        PickupRoute::create(['name' => 'Segunda']);

        $entradas = Livewire::test('audit-logs')->viewData('entries')->values();

        $this->assertSame('Segunda', $entradas[0]['record']);
        $this->assertSame('Primera', $entradas[1]['record']);
    }

    public function test_the_module_is_named_not_the_class(): void
    {
        Courier::create(['name' => 'Freddy GLS']);

        $entradas = Livewire::test('audit-logs')->viewData('entries')->values();

        // La clase sí aparece en el HTML, pero como `value` del desplegable de
        // filtro. Lo que no puede salir es en la columna que se lee.
        $this->assertSame('Mensajeros', $entradas[0]['module']);
        $this->assertSame('Freddy GLS', $entradas[0]['record']);
    }

    public function test_it_shows_nothing_from_the_seeder(): void
    {
        // Cargar el maestro no es el cambio de nadie (§4).
        AuditLog::withoutRecording(
            fn () => Merchant::create(['name' => 'Sembrado', 'pickup_route_id' => $this->tres->id]),
        );

        Livewire::test('audit-logs')
            ->assertDontSee('Sembrado')
            ->assertSee('Rutas'); // la ruta del setUp sí se registró
    }

    // --- Filtros -------------------------------------------------------------

    public function test_it_filters_by_module(): void
    {
        Courier::create(['name' => 'Freddy GLS']);
        Merchant::create(['name' => 'Zona Joven', 'pickup_route_id' => $this->tres->id]);

        Livewire::test('audit-logs')
            ->set('moduleFilter', Courier::class)
            ->assertSee('Freddy GLS')
            ->assertDontSee('Zona Joven');
    }

    public function test_it_searches_by_the_record_name(): void
    {
        Merchant::create(['name' => 'COBO FAMILY, S.L.', 'pickup_route_id' => $this->tres->id]);
        Merchant::create(['name' => 'Zona Joven', 'pickup_route_id' => $this->tres->id]);

        // El nombre vive dentro del JSON del volcado, no en una columna.
        Livewire::test('audit-logs')
            ->set('search', 'cobo')
            ->assertSee('COBO FAMILY')
            ->assertDontSee('Zona Joven');
    }

    public function test_it_searches_by_the_author(): void
    {
        Merchant::create(['name' => 'Zona Joven', 'pickup_route_id' => $this->tres->id]);

        Livewire::test('audit-logs')
            ->set('search', 'alberto@panel.local')
            ->assertSee('Zona Joven')
            ->set('search', 'otra@persona.local')
            ->assertDontSee('Zona Joven');
    }

    public function test_it_paginates(): void
    {
        foreach (range(1, 20) as $i) {
            PickupRoute::create(['name' => sprintf('Ruta %02d', $i)]);
        }

        Livewire::test('audit-logs')
            ->assertViewHas('logs', fn ($p) => $p->count() === 15)
            ->assertSee('Siguiente');
    }

    // --- El detalle ----------------------------------------------------------

    public function test_the_detail_shows_field_before_and_after(): void
    {
        $cinco = PickupRoute::create(['name' => '5']);
        $merchant = Merchant::create(['name' => 'COBO FAMILY, S.L.', 'pickup_route_id' => $this->tres->id]);
        $merchant->update(['pickup_route_id' => $cinco->id]);

        $log = $merchant->auditLogs()->first();

        Livewire::test('audit-logs')
            ->call('show', $log->id)
            ->assertSee('Detalle del cambio')
            ->assertSee('Ruta')
            ->assertSee('3')
            ->assertSee('5')
            ->assertDontSee('pickup_route_id')
            ->call('close')
            ->assertSet('viewing', null);
    }

    public function test_the_id_is_left_out_of_the_diff(): void
    {
        // En un alta el volcado trae el registro entero, `id` incluido, y ese
        // dato no le dice nada a nadie.
        $merchant = Merchant::create(['name' => 'Zona Joven', 'pickup_route_id' => $this->tres->id]);
        $log = $merchant->auditLogs()->sole();

        $campos = collect(
            Livewire::test('audit-logs')->call('show', $log->id)->viewData('detail')['changes']
        )->pluck('label')->all();

        $this->assertNotContains('id', $campos);
        $this->assertContains('Nombre', $campos);
    }

    public function test_a_null_is_shown_as_a_dash(): void
    {
        $courier = Courier::create(['name' => 'Freddy GLS']);
        $courier->update(['pickup_route_id' => $this->tres->id]);
        $log = $courier->auditLogs()->first();

        $cambio = collect(
            Livewire::test('audit-logs')->call('show', $log->id)->viewData('detail')['changes']
        )->firstWhere('label', 'Ruta');

        $this->assertSame('—', $cambio['before']);
        $this->assertSame('3', $cambio['after']);
    }

    public function test_the_four_actions_are_labelled_in_spanish(): void
    {
        $pickupRoute = PickupRoute::create(['name' => 'Vallecas']);
        $pickupRoute->delete();
        $pickupRoute->restore();
        $pickupRoute->update(['name' => 'Vallecas Sur']);

        Livewire::test('audit-logs')
            ->assertSee('Alta')
            ->assertSee('Baja')
            ->assertSee('Reactivación')
            ->assertSee('Modificación');
    }

    public function test_the_row_stays_readable_after_the_record_is_gone(): void
    {
        $merchant = Merchant::create(['name' => 'Se borró del todo', 'pickup_route_id' => $this->tres->id]);
        $log = $merchant->auditLogs()->first();
        $merchant->forceDelete();

        // El nombre se lee del propio volcado, no de la relación: por eso la
        // fila sigue siendo legible aunque el registro ya no exista (§4).
        Livewire::test('audit-logs')
            ->assertSee('Se borró del todo')
            ->call('show', $log->id)
            ->assertSee('Se borró del todo');
    }

    public function test_users_are_audited_but_without_their_password(): void
    {
        User::create(['name' => 'Otra persona', 'email' => 'otra@panel.local', 'password' => 'contraseña-larga']);

        Livewire::test('audit-logs')
            ->assertSee('Usuarios')
            ->assertSee('Otra persona')
            ->assertDontSee('password')
            ->assertDontSee('contraseña-larga');
    }
}
