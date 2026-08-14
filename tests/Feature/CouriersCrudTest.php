<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\Merchant;
use App\Models\PickupRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** CRUD de mensajeros (CONTEXTO.md §7, fase 3, módulo 3). */
class CouriersCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_it_is_behind_the_session(): void
    {
        auth()->logout();

        $this->get('/couriers')->assertRedirect('/login');
    }

    // --- Alta y edición -----------------------------------------------------

    public function test_it_creates_a_courier_with_a_route(): void
    {
        $pickupRoute = PickupRoute::create(['name' => '3']);

        Livewire::test('couriers')
            ->call('create')
            ->set('name', 'Freddy GLS')
            ->set('pickup_route_id', (string) $pickupRoute->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($pickupRoute->id, Courier::sole()->pickup_route_id);
    }

    public function test_a_courier_can_be_left_without_a_route(): void
    {
        Livewire::test('couriers')
            ->call('create')
            ->set('name', 'Recién llegado')
            ->set('pickup_route_id', '')
            ->call('save')
            ->assertHasNoErrors();

        // '' es "sin ruta", y en la base tiene que ser NULL, no 0.
        $this->assertNull(Courier::sole()->pickup_route_id);
    }

    public function test_it_edits_without_creating_a_second_one(): void
    {
        $courier = Courier::create(['name' => 'Freddy GLS']);

        Livewire::test('couriers')
            ->call('edit', $courier->id)
            ->assertSet('name', 'Freddy GLS')
            ->set('name', 'Freddy Rodríguez')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Freddy Rodríguez', $courier->refresh()->name);
        $this->assertSame(1, Courier::count());
    }

    // --- Volumen máximo de la furgoneta (§4) ---------------------------------

    public function test_it_stores_the_maximum_volume(): void
    {
        Livewire::test('couriers')
            ->call('create')
            ->set('name', 'Freddy GLS')
            ->set('maximum_volume', '12.5')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(12.5, Courier::sole()->maximum_volume);
    }

    public function test_an_empty_volume_is_unknown_and_not_zero(): void
    {
        Livewire::test('couriers')
            ->call('create')
            ->set('name', 'Freddy GLS')
            ->set('maximum_volume', '')
            ->call('save')
            ->assertHasNoErrors();

        // Cero diría "no cabe nada"; lo que pasa es que no se sabe.
        $this->assertNull(Courier::sole()->maximum_volume);
    }

    public function test_the_form_shows_the_volume_without_padding_zeros(): void
    {
        $courier = Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 12]);

        Livewire::test('couriers')
            ->call('edit', $courier->id)
            ->assertSet('maximum_volume', '12');
    }

    public function test_it_rejects_a_volume_that_is_not_a_positive_number(): void
    {
        foreach (['0', '-3', 'grande', '1.2345'] as $valor) {
            Livewire::test('couriers')
                ->call('create')
                ->set('name', "UT {$valor}")
                ->set('maximum_volume', $valor)
                ->call('save')
                ->assertHasErrors('maximum_volume');
        }

        $this->assertSame(0, Courier::count());
    }

    // --- Una ruta, un mensajero (§4) ----------------------------------------

    public function test_the_dropdown_only_offers_free_routes(): void
    {
        $libre = PickupRoute::create(['name' => 'Libre']);
        $ocupada = PickupRoute::create(['name' => 'Ocupada']);
        Courier::create(['name' => 'Quien la lleva', 'pickup_route_id' => $ocupada->id]);

        Livewire::test('couriers')
            ->call('create')
            ->assertViewHas('availableRoutes', fn ($rutas) => $rutas->pluck('name')->all() === ['Libre']);
    }

    public function test_editing_still_offers_your_own_route(): void
    {
        $suya = PickupRoute::create(['name' => 'Suya']);
        $courier = Courier::create(['name' => 'Freddy GLS', 'pickup_route_id' => $suya->id]);

        // Si no, al abrir el formulario el desplegable no tendría su propia
        // ruta y guardar la perdería.
        Livewire::test('couriers')
            ->call('edit', $courier->id)
            ->assertViewHas('availableRoutes', fn ($rutas) => $rutas->contains('name', 'Suya'))
            ->assertSet('pickup_route_id', (string) $suya->id);
    }

    public function test_the_server_rejects_a_route_already_taken(): void
    {
        $ocupada = PickupRoute::create(['name' => 'Ocupada']);
        Courier::create(['name' => 'Quien la lleva', 'pickup_route_id' => $ocupada->id]);

        // El desplegable no la ofrece, pero la validación tiene que cortarlo
        // igual: el navegador no es una frontera de confianza.
        Livewire::test('couriers')
            ->call('create')
            ->set('name', 'Otro')
            ->set('pickup_route_id', (string) $ocupada->id)
            ->call('save')
            ->assertHasErrors('pickup_route_id');

        $this->assertSame(1, Courier::count());
    }

    public function test_it_rejects_a_duplicate_name(): void
    {
        Courier::create(['name' => 'Freddy GLS']);

        Livewire::test('couriers')
            ->call('create')
            ->set('name', 'Freddy GLS')
            ->call('save')
            ->assertHasErrors('name');
    }

    public function test_the_name_is_required_in_spanish(): void
    {
        $errores = Livewire::test('couriers')
            ->call('create')
            ->set('name', '')
            ->call('save')
            ->errors();

        $this->assertSame('El campo nombre es obligatorio.', $errores->first('name'));
    }

    // --- Baja: lo que motivó el modelo (§4) ---------------------------------

    public function test_deleting_a_courier_leaves_the_route_and_its_merchants_alone(): void
    {
        $pickupRoute = PickupRoute::create(['name' => '3']);
        $courier = Courier::create(['name' => 'Freddy GLS', 'pickup_route_id' => $pickupRoute->id]);
        Merchant::create(['name' => 'Cobo Family, S.L.', 'pickup_route_id' => $pickupRoute->id]);

        Livewire::test('couriers')->call('delete', $courier->id);

        $this->assertSoftDeleted($courier);
        $this->assertSame(1, $pickupRoute->refresh()->merchants()->count());
        $this->assertNull($pickupRoute->courier);
    }

    public function test_the_replacement_can_take_the_route_of_the_deleted_one(): void
    {
        $pickupRoute = PickupRoute::create(['name' => '3']);
        Courier::create(['name' => 'Freddy GLS', 'pickup_route_id' => $pickupRoute->id])->delete();

        // El índice único es parcial (§4), así que la ruta queda libre en
        // cuanto el saliente se da de baja.
        Livewire::test('couriers')
            ->call('create')
            ->set('name', 'Nuevo GLS')
            ->set('pickup_route_id', (string) $pickupRoute->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Nuevo GLS', $pickupRoute->refresh()->courier->name);
    }

    public function test_it_can_be_brought_back(): void
    {
        $courier = Courier::create(['name' => 'Freddy GLS']);

        Livewire::test('couriers')->call('delete', $courier->id);
        $this->assertSoftDeleted($courier);

        Livewire::test('couriers')->call('restore', $courier->id);
        $this->assertNotSoftDeleted($courier->refresh());
    }

    public function test_the_message_agrees_in_gender(): void
    {
        $courier = Courier::create(['name' => 'Freddy GLS']);

        // El trait es compartido con rutas, que es femenino: «Mensajero dada de
        // baja» sería el fallo si no se distinguiera.
        // El aviso viaja como toast desde el 14/08/2026: se comprueba el
        // evento, que es lo que la pantalla emite de verdad.
        Livewire::test('couriers')
            ->call('delete', $courier->id)
            ->assertDispatched('toast', message: 'UT dada de baja.');
    }

    // --- Confirmación de la baja ---------------------------------------------

    public function test_the_trash_icon_only_opens_a_confirmation(): void
    {
        $courier = Courier::create(['name' => 'Freddy GLS']);

        Livewire::test('couriers')
            ->call('confirmDelete', $courier->id)
            ->assertSet('confirmingDeletion', $courier->id)
            ->assertSee('Freddy GLS');

        $this->assertNotSoftDeleted($courier);
    }

    public function test_cancelling_the_confirmation_deletes_nothing(): void
    {
        $courier = Courier::create(['name' => 'Freddy GLS']);

        Livewire::test('couriers')
            ->call('confirmDelete', $courier->id)
            ->call('cancelDelete');

        $this->assertNotSoftDeleted($courier);
    }

    // --- Filtro y paginación -------------------------------------------------

    public function test_it_filters_by_courier_and_by_route(): void
    {
        $tres = PickupRoute::create(['name' => 'Vallecas']);
        Courier::create(['name' => 'Freddy GLS', 'pickup_route_id' => $tres->id]);
        Courier::create(['name' => 'Otra persona']);

        Livewire::test('couriers')
            ->set('search', 'freddy')
            ->assertSee('Freddy GLS')
            ->assertDontSee('Otra persona')
            ->set('search', 'vallecas')
            ->assertSee('Freddy GLS')
            ->assertDontSee('Otra persona');
    }

    public function test_it_shows_ten_rows_per_page(): void
    {
        foreach (range(1, 25) as $i) {
            Courier::create(['name' => sprintf('Mensajero %02d', $i)]);
        }

        Livewire::test('couriers')
            ->assertViewHas('couriers', fn ($p) => $p->count() === 10 && $p->total() === 25);
    }

    // --- Doble envío ---------------------------------------------------------

    public function test_saving_twice_does_not_create_two_couriers(): void
    {
        Livewire::test('couriers')
            ->call('create')
            ->set('name', 'Freddy GLS')
            ->call('save')
            ->call('save');

        $this->assertSame(1, Courier::where('name', 'Freddy GLS')->count());
    }
}
