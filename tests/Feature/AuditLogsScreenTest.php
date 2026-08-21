<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Courier;
use App\Models\Expense;
use App\Models\Merchant;
use App\Models\PickupRoute;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        $this->assertSame('UT', $entradas[0]['module']);
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

        // El filtro es la clave del módulo y no el nombre de la clase desde el
        // 21/08/2026: los gastos son dos tablas del mismo módulo y salían dos
        // veces en el desplegable.
        Livewire::test('audit-logs')
            ->set('moduleFilter', 'couriers')
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

    // --- Auditoría no es la puerta de atrás de otro módulo -------------------
    //
    // Aquí se lee el volcado entero de cada cambio, así que sin filtrar por
    // permiso `audit-logs.view` enseña lo que la cuenta no puede ver en su
    // propia pantalla. El caso real es Operaciones, que tiene el historial y no
    // tiene ni usuarios ni roles (§7, fase 12).

    /** El rol de fábrica que sí entra a Auditoría y no a Usuarios. */
    private function operaciones(): User
    {
        return User::factory()->role(PermissionCatalog::ROLE_OPERATIONS)->create(['name' => 'De operaciones']);
    }

    public function test_it_hides_the_changes_of_a_module_the_account_cannot_see(): void
    {
        User::create(['name' => 'Otra persona', 'email' => 'otra@panel.local', 'password' => 'contraseña-larga']);
        Merchant::create(['name' => 'Zona Joven', 'pickup_route_id' => $this->tres->id]);

        $this->actingAs($this->operaciones());

        Livewire::test('audit-logs')
            // Lo suyo lo sigue viendo.
            ->assertSee('Zona Joven')
            // Y lo que no es suyo, no: ni la cuenta ni su correo.
            ->assertDontSee('Otra persona')
            ->assertDontSee('otra@panel.local');
    }

    /** El cambio que más importa esconder: quién le dio el Administrador a quién. */
    public function test_a_role_change_does_not_leak_through_the_history(): void
    {
        $otro = User::factory()->role(PermissionCatalog::ROLE_OPERATIONS)->create(['name' => 'Ascendido']);
        $otro->recordRoleChange(PermissionCatalog::ROLE_OPERATIONS, PermissionCatalog::ROLE_ADMIN);

        $this->actingAs($this->operaciones());

        Livewire::test('audit-logs')->assertDontSee('Ascendido');
    }

    public function test_the_module_filter_only_offers_what_the_account_can_see(): void
    {
        $this->actingAs($this->operaciones());

        Livewire::test('audit-logs')
            ->assertViewHas('modules', fn (array $m) => array_key_exists('merchants', $m)
                && ! array_key_exists('users', $m)
                && ! array_key_exists('roles', $m));
    }

    /** El id llega del cliente: pedir el detalle por su número tampoco vale. */
    public function test_the_detail_of_a_hidden_change_is_not_reachable_by_its_id(): void
    {
        $ajena = User::create(['name' => 'Otra persona', 'email' => 'otra@panel.local', 'password' => 'contraseña-larga']);
        $log = $ajena->auditLogs()->sole();

        $this->actingAs($this->operaciones());

        // Igual que un registro que no existe: en una petición de verdad el
        // manejador de Laravel lo convierte en 404.
        $this->expectException(ModelNotFoundException::class);

        Livewire::test('audit-logs')->call('show', $log->id);
    }

    /** Y el Administrador lo sigue viendo todo, que es de lo que sirve el historial. */
    public function test_an_administrator_still_sees_every_module(): void
    {
        User::create(['name' => 'Otra persona', 'email' => 'otra@panel.local', 'password' => 'contraseña-larga']);
        $concepto = Expense::create(['name' => 'Gasolina']);

        Livewire::test('audit-logs')
            ->assertSee('Otra persona')
            // Y de paso los gastos, que hasta ahora salían con el nombre de la
            // clase porque el presentador tenía su propia lista a medias.
            ->assertSee('Gasolina')
            ->assertSee('Gastos');

        $this->assertSame('expenses', PermissionCatalog::auditables()[$concepto::class]);
    }
}
