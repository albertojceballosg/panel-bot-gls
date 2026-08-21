<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PickupRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** CRUD de rutas (CONTEXTO.md §7, fase 3, módulo 2). */
class PickupRoutesCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function merchantIn(PickupRoute $pickupRoute): Merchant
    {
        return Merchant::create(['name' => 'Zona Joven', 'pickup_route_id' => $pickupRoute->id]);
    }

    // --- Acceso -------------------------------------------------------------

    public function test_it_is_behind_the_session(): void
    {
        auth()->logout();

        $this->get('/pickup-routes')->assertRedirect('/login');
    }

    public function test_the_page_lists_the_routes_with_their_courier_and_totals(): void
    {
        $pickupRoute = PickupRoute::create(['name' => '3']);
        $pickupRoute->courier()->create(['name' => 'Freddy GLS']);
        $this->merchantIn($pickupRoute);

        Livewire::test('pickup-routes')
            ->assertOk()
            ->assertSee('3')
            ->assertSee('Freddy GLS');
    }

    // --- Alta y edición -----------------------------------------------------

    public function test_it_creates_a_route(): void
    {
        Livewire::test('pickup-routes')
            ->call('create')
            ->set('name', 'Vallecas')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, PickupRoute::where('name', 'Vallecas')->count());
    }

    public function test_it_edits_a_route_without_creating_a_second_one(): void
    {
        $pickupRoute = PickupRoute::create(['name' => '6']);

        Livewire::test('pickup-routes')
            ->call('edit', $pickupRoute->id)
            ->assertSet('name', '6')
            ->set('name', 'Vallecas')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Vallecas', $pickupRoute->refresh()->name);
        $this->assertSame(1, PickupRoute::count());
    }

    public function test_the_name_is_required(): void
    {
        Livewire::test('pickup-routes')
            ->call('create')
            ->set('name', '')
            ->call('save')
            ->assertHasErrors('name');
    }

    public function test_it_rejects_a_duplicate_name(): void
    {
        PickupRoute::create(['name' => '1']);

        Livewire::test('pickup-routes')
            ->call('create')
            ->set('name', '1')
            ->call('save')
            ->assertHasErrors('name');

        $this->assertSame(1, PickupRoute::count());
    }

    public function test_renaming_a_route_does_not_clash_with_itself(): void
    {
        $pickupRoute = PickupRoute::create(['name' => '1']);

        Livewire::test('pickup-routes')
            ->call('edit', $pickupRoute->id)
            ->set('name', '1')
            ->call('save')
            ->assertHasNoErrors();
    }

    // --- Baja ---------------------------------------------------------------

    public function test_a_route_with_merchants_cannot_be_deleted_and_says_why(): void
    {
        $pickupRoute = PickupRoute::create(['name' => '3']);
        $this->merchantIn($pickupRoute);

        // El modelo lanza una excepción (§4); la pantalla la convierte en un
        // mensaje legible en vez de en un error de servidor. Se comprueba sobre
        // lo renderizado, que es lo que ve quien usa el panel.
        Livewire::test('pickup-routes')
            ->call('delete', $pickupRoute->id)
            ->assertDispatched('toast', fn ($nombre, $params) => $params['type'] === 'error'
                && str_contains($params['message'], 'todavía tiene 1 comercio.'));

        $this->assertNotSoftDeleted($pickupRoute);
    }

    public function test_the_refusal_message_agrees_in_number(): void
    {
        $pickupRoute = PickupRoute::create(['name' => '3']);
        Merchant::create(['name' => 'Uno', 'pickup_route_id' => $pickupRoute->id]);
        Merchant::create(['name' => 'Dos', 'pickup_route_id' => $pickupRoute->id]);

        Livewire::test('pickup-routes')
            ->call('delete', $pickupRoute->id)
            ->assertDispatched('toast', fn ($nombre, $params) => str_contains($params['message'], 'todavía tiene 2 comercios')
                && str_contains($params['message'], 'Muévelos'));
    }

    public function test_an_empty_route_can_be_deleted_and_brought_back(): void
    {
        $pickupRoute = PickupRoute::create(['name' => '6']);

        Livewire::test('pickup-routes')->call('delete', $pickupRoute->id);
        $this->assertSoftDeleted($pickupRoute);

        Livewire::test('pickup-routes')->call('restore', $pickupRoute->id);
        $this->assertNotSoftDeleted($pickupRoute->refresh());
    }

    public function test_deleted_routes_are_hidden_unless_you_ask_for_them(): void
    {
        PickupRoute::create(['name' => 'Viva']);
        PickupRoute::create(['name' => 'Retirada'])->delete();

        Livewire::test('pickup-routes')
            ->assertSee('Viva')
            ->assertDontSee('Retirada')
            ->set('showingTrashed', true)
            ->assertSee('Retirada');
    }

    // --- El recuento lleva a los comercios (20/08/2026) -----------------------

    /**
     * La pregunta que sigue a ver «21 comercios» es cuáles. Enlaza a la pantalla de
     * comercios con el filtro puesto en la URL, en vez de abrir aquí una lista que no deja
     * hacer nada con ellos.
     */
    public function test_the_merchant_count_links_to_the_merchants_of_that_route(): void
    {
        $pickupRoute = PickupRoute::create(['name' => '3']);
        $this->merchantIn($pickupRoute);

        Livewire::test('pickup-routes')
            ->assertSeeHtml('href="'.route('merchants', ['ruta' => $pickupRoute->id]).'"');
    }

    /** Con cero comercios no hay nada al otro lado, así que es sólo un número. */
    public function test_a_route_without_merchants_has_no_link(): void
    {
        $pickupRoute = PickupRoute::create(['name' => '6']);

        Livewire::test('pickup-routes')
            ->assertDontSeeHtml('href="'.route('merchants', ['ruta' => $pickupRoute->id]).'"');
    }

    /**
     * Y sin `merchants.view` tampoco: el permiso de ver rutas no puede convertirse en uno de
     * ver comercios por pulsar una cifra, y enlazar a un 403 parece un fallo del panel.
     */
    public function test_an_account_that_cannot_see_merchants_gets_no_link(): void
    {
        $pickupRoute = PickupRoute::create(['name' => '3']);
        $this->merchantIn($pickupRoute);

        $usuario = User::factory()->withoutRole()->create();
        $usuario->givePermissionTo('pickup-routes.view');
        $this->actingAs($usuario);

        Livewire::test('pickup-routes')
            ->assertSee('1')
            ->assertDontSeeHtml('href="'.route('merchants', ['ruta' => $pickupRoute->id]).'"');
    }

    // --- Historial ----------------------------------------------------------

    public function test_the_changes_reach_the_history_with_an_author(): void
    {
        Livewire::test('pickup-routes')
            ->call('create')
            ->set('name', 'Vallecas')
            ->call('save');

        $log = PickupRoute::where('name', 'Vallecas')->sole()->auditLogs()->sole();

        $this->assertSame(auth()->user()->email, $log->user_email);
    }

    // --- Filtro y paginación --------------------------------------------------

    public function test_it_shows_ten_rows_per_page(): void
    {
        foreach (range(1, 25) as $i) {
            PickupRoute::create(['name' => sprintf('Ruta %02d', $i)]);
        }

        Livewire::test('pickup-routes')
            ->assertViewHas('pickupRoutes', fn ($p) => $p->count() === 10 && $p->total() === 25)
            ->assertSee('Ruta 01')
            ->assertDontSee('Ruta 11');
    }

    public function test_it_filters_by_route_name(): void
    {
        PickupRoute::create(['name' => 'Vallecas']);
        PickupRoute::create(['name' => 'Chamberí']);

        Livewire::test('pickup-routes')
            ->set('search', 'valle')
            ->assertSee('Vallecas')
            ->assertDontSee('Chamberí');
    }

    public function test_it_filters_by_courier_name_too(): void
    {
        $conFreddy = PickupRoute::create(['name' => '3']);
        $conFreddy->courier()->create(['name' => 'Freddy GLS']);
        PickupRoute::create(['name' => '9'])->courier()->create(['name' => 'Otra persona']);

        Livewire::test('pickup-routes')
            ->set('search', 'freddy')
            ->assertSee('Freddy GLS')
            ->assertDontSee('Otra persona');
    }

    public function test_the_filter_escapes_wildcards(): void
    {
        PickupRoute::create(['name' => 'Cien']);
        PickupRoute::create(['name' => '100%']);

        // Sin escapar, «100%» sería un comodín y traería también «Cien».
        Livewire::test('pickup-routes')
            ->set('search', '100%')
            ->assertSee('100%')
            ->assertDontSee('Cien');
    }

    public function test_filtering_sends_you_back_to_the_first_page(): void
    {
        foreach (range(1, 25) as $i) {
            PickupRoute::create(['name' => sprintf('Ruta %02d', $i)]);
        }

        Livewire::test('pickup-routes')
            ->call('gotoPage', 3)
            ->assertViewHas('pickupRoutes', fn ($p) => $p->currentPage() === 3)
            ->set('search', 'Ruta 0')
            ->assertViewHas('pickupRoutes', fn ($p) => $p->currentPage() === 1);
    }

    // --- Confirmación de la baja ---------------------------------------------

    public function test_the_trash_icon_only_opens_a_confirmation(): void
    {
        $pickupRoute = PickupRoute::create(['name' => '6']);

        // Un icono es fácil de pulsar sin querer, y va pegado al de editar.
        Livewire::test('pickup-routes')
            ->call('confirmDelete', $pickupRoute->id)
            ->assertSet('confirmingDeletion', $pickupRoute->id)
            ->assertSee('Vas a dar de baja');

        $this->assertNotSoftDeleted($pickupRoute);
    }

    public function test_the_confirmation_names_the_record(): void
    {
        $pickupRoute = PickupRoute::create(['name' => 'Vallecas']);

        // «¿Dar de baja?» a secas no te dice si acertaste de fila.
        Livewire::test('pickup-routes')
            ->call('confirmDelete', $pickupRoute->id)
            ->assertSee('Vallecas');
    }

    public function test_cancelling_the_confirmation_deletes_nothing(): void
    {
        $pickupRoute = PickupRoute::create(['name' => '6']);

        Livewire::test('pickup-routes')
            ->call('confirmDelete', $pickupRoute->id)
            ->call('cancelDelete')
            ->assertSet('confirmingDeletion', null);

        $this->assertNotSoftDeleted($pickupRoute);
    }

    public function test_confirming_does_delete_and_closes_the_dialog(): void
    {
        $pickupRoute = PickupRoute::create(['name' => '6']);

        Livewire::test('pickup-routes')
            ->call('confirmDelete', $pickupRoute->id)
            ->call('delete', $pickupRoute->id)
            ->assertSet('confirmingDeletion', null);

        $this->assertSoftDeleted($pickupRoute);
    }

    // --- Idioma ---------------------------------------------------------------

    public function test_the_validation_messages_are_in_spanish(): void
    {
        // El panel está entero en castellano; Laravel sólo trae los mensajes en
        // inglés, así que sin lang/es/validation.php decía «The name has
        // already been taken».
        $errores = Livewire::test('pickup-routes')
            ->call('create')
            ->set('name', '')
            ->call('save')
            ->errors();

        $this->assertSame('El campo nombre es obligatorio.', $errores->first('name'));
    }

    // --- Transacción ----------------------------------------------------------

    public function test_the_save_runs_inside_a_transaction(): void
    {
        // Se mira desde dentro del evento del modelo, que es donde también se
        // escribe el historial: así queda probado que ambos van juntos y que un
        // fallo no deja la fila sin su registro de auditoría (§4).
        $nivel = null;
        PickupRoute::created(function () use (&$nivel) {
            $nivel = DB::transactionLevel();
        });

        Livewire::test('pickup-routes')
            ->call('create')
            ->set('name', 'Vallecas')
            ->call('save');

        $this->assertNotNull($nivel, 'No llegó a crearse la ruta.');
        $this->assertGreaterThan(0, $nivel, 'El guardado corrió fuera de una transacción.');
    }

    // --- La puerta y la cerradura (§7, fase 12) ------------------------------
    //
    // La ruta deja entrar; a los métodos del componente se llega desde el navegador aunque el
    // Blade no pinte el botón. Las dos cosas se prueban aquí, en la pantalla, y no sólo en
    // `RolesAndPermissionsTest`: allí se fija el reparto de roles, y esto es de esta pantalla.

    /** Una cuenta que sólo puede mirar esta pantalla, sin rol de por medio. */
    private function soloLectura(): User
    {
        $usuario = User::factory()->withoutRole()->create();
        $usuario->givePermissionTo('pickup-routes.view');

        return $usuario;
    }

    public function test_the_screen_is_behind_its_permission(): void
    {
        $this->actingAs(User::factory()->withoutRole()->create());

        $this->get('/pickup-routes')->assertForbidden();
    }

    public function test_a_read_only_account_cannot_write_through_livewire(): void
    {
        $ruta = PickupRoute::create(['name' => 'Vallecas']);

        $this->actingAs($this->soloLectura());

        Livewire::test('pickup-routes')->call('create')->assertForbidden();
        Livewire::test('pickup-routes')->call('edit', $ruta->id)->assertForbidden();
        Livewire::test('pickup-routes')->call('confirmDelete', $ruta->id)->assertForbidden();
        Livewire::test('pickup-routes')->call('delete', $ruta->id)->assertForbidden();
        Livewire::test('pickup-routes')->call('restore', $ruta->id)->assertForbidden();
        Livewire::test('pickup-routes')->call('save')->assertForbidden();

        $this->assertNotSoftDeleted($ruta);
    }
}
