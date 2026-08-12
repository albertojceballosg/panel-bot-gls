<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\Merchant;
use App\Models\PickupRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * CRUD de comercios (CONTEXTO.md §7, fase 3, módulo 4).
 *
 * Es el maestro que consume el bot: cada fila decide a qué ruta se asigna un
 * envío y, por tanto, si se marca como incidencia.
 */
class MerchantsCrudTest extends TestCase
{
    use RefreshDatabase;

    private PickupRoute $ruta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->ruta = PickupRoute::create(['name' => '3']);
    }

    private function merchant(string $name, ?int $code = null, ?PickupRoute $ruta = null): Merchant
    {
        return Merchant::create([
            'name' => $name,
            'code' => $code,
            'pickup_route_id' => ($ruta ?? $this->ruta)->id,
        ]);
    }

    public function test_it_is_behind_the_session(): void
    {
        auth()->logout();

        $this->get('/merchants')->assertRedirect('/login');
    }

    // --- Alta y edición -----------------------------------------------------

    public function test_it_creates_a_merchant(): void
    {
        Livewire::test('merchants')
            ->call('create')
            ->set('name', 'COBO FAMILY, S.L.')
            ->set('code', '287')
            ->set('pickup_route_id', (string) $this->ruta->id)
            ->call('save')
            ->assertHasNoErrors();

        $merchant = Merchant::sole();
        $this->assertSame('COBO FAMILY, S.L.', $merchant->name);
        // Entero de verdad: el contrato lo sirve como número (§3).
        $this->assertSame(287, $merchant->code);
    }

    public function test_a_merchant_without_a_code_is_saved_as_null(): void
    {
        // 11 de los 93 no lo tienen (§3). Un input vacío llega como '', y la
        // regla es `nullable|integer`: sin normalizar, '' reventaría.
        Livewire::test('merchants')
            ->call('create')
            ->set('name', 'Sin código')
            ->set('code', '')
            ->set('pickup_route_id', (string) $this->ruta->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(Merchant::sole()->code);
    }

    public function test_it_edits_without_creating_a_second_one(): void
    {
        $merchant = $this->merchant('Zona Joven');

        Livewire::test('merchants')
            ->call('edit', $merchant->id)
            ->assertSet('name', 'Zona Joven')
            ->set('name', 'Zona Joven S.L.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Zona Joven S.L.', $merchant->refresh()->name);
        $this->assertSame(1, Merchant::count());
    }

    public function test_moving_a_merchant_to_another_route_is_the_point_of_all_this(): void
    {
        $cinco = PickupRoute::create(['name' => '5']);
        $merchant = $this->merchant('COBO FAMILY, S.L.');

        Livewire::test('merchants')
            ->call('edit', $merchant->id)
            ->set('pickup_route_id', (string) $cinco->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($cinco->id, $merchant->refresh()->pickup_route_id);

        // Y queda registrado quién lo movió (§4, historial).
        $log = $merchant->auditLogs()->first();
        $this->assertSame(['pickup_route_id'], array_keys($log->after));
        $this->assertSame(auth()->user()->email, $log->user_email);
    }

    // --- Validación ---------------------------------------------------------

    public function test_the_route_is_required(): void
    {
        Livewire::test('merchants')
            ->call('create')
            ->set('name', 'Sin ruta')
            ->set('pickup_route_id', '')
            ->call('save')
            ->assertHasErrors('pickup_route_id');

        $this->assertSame(0, Merchant::count());
    }

    public function test_it_rejects_a_duplicate_name_even_in_another_case(): void
    {
        $this->merchant('Zona Joven');

        // El índice va sobre `normalized_name` (§4); la regla comprueba contra
        // esa columna, no contra `name`, o se colaría.
        Livewire::test('merchants')
            ->call('create')
            ->set('name', 'ZONA  JOVEN')
            ->set('pickup_route_id', (string) $this->ruta->id)
            ->call('save')
            ->assertHasErrors('name');

        $this->assertSame(1, Merchant::count());
    }

    public function test_it_rejects_a_duplicate_code(): void
    {
        $this->merchant('Good Id S.L', 287);

        Livewire::test('merchants')
            ->call('create')
            ->set('name', 'Otro cualquiera')
            ->set('code', '287')
            ->set('pickup_route_id', (string) $this->ruta->id)
            ->call('save')
            ->assertHasErrors('code');
    }

    public function test_many_merchants_can_have_no_code(): void
    {
        $this->merchant('Sin código A');

        Livewire::test('merchants')
            ->call('create')
            ->set('name', 'Sin código B')
            ->set('code', '')
            ->set('pickup_route_id', (string) $this->ruta->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, Merchant::whereNull('code')->count());
    }

    public function test_the_messages_are_in_spanish(): void
    {
        $errores = Livewire::test('merchants')
            ->call('create')
            ->set('name', '')
            ->call('save')
            ->errors();

        $this->assertSame('El campo nombre es obligatorio.', $errores->first('name'));
        $this->assertSame('El campo ruta es obligatorio.', $errores->first('pickup_route_id'));
    }

    // --- Búsqueda y filtro ---------------------------------------------------

    public function test_it_searches_by_name(): void
    {
        $this->merchant('COBO FAMILY, S.L.');
        $this->merchant('Zona Joven');

        Livewire::test('merchants')
            ->set('search', 'cobo')
            ->assertSee('COBO FAMILY')
            ->assertDontSee('Zona Joven');
    }

    public function test_it_searches_by_code_too(): void
    {
        // Es lo que el cliente tiene delante cuando mira el portal.
        $this->merchant('Good Id S.L', 287);
        $this->merchant('Otro', 999);

        Livewire::test('merchants')
            ->set('search', '287')
            ->assertSee('Good Id S.L')
            ->assertDontSee('Otro');
    }

    public function test_it_filters_by_route(): void
    {
        $cinco = PickupRoute::create(['name' => '5']);
        $this->merchant('De la tres');
        $this->merchant('De la cinco', null, $cinco);

        Livewire::test('merchants')
            ->set('routeFilter', (string) $cinco->id)
            ->assertSee('De la cinco')
            ->assertDontSee('De la tres');
    }

    public function test_it_shows_ten_rows_per_page(): void
    {
        foreach (range(1, 25) as $i) {
            $this->merchant(sprintf('Comercio %02d', $i));
        }

        Livewire::test('merchants')
            ->assertViewHas('merchants', fn ($p) => $p->count() === 10 && $p->total() === 25);
    }

    public function test_filtering_sends_you_back_to_the_first_page(): void
    {
        foreach (range(1, 25) as $i) {
            $this->merchant(sprintf('Comercio %02d', $i));
        }

        Livewire::test('merchants')
            ->call('gotoPage', 3)
            ->set('routeFilter', (string) $this->ruta->id)
            ->assertViewHas('merchants', fn ($p) => $p->currentPage() === 1);
    }

    public function test_the_paginator_is_previous_next_not_numbered(): void
    {
        // Con 93 comercios el numerado sacaba diez botones que se comían el pie
        // de la tabla. Livewire ignora `Paginator::defaultView`, así que la
        // vista se impone desde `CrudScreen::paginationView()`.
        foreach (range(1, 25) as $i) {
            $this->merchant(sprintf('Comercio %02d', $i));
        }

        Livewire::test('merchants')
            ->assertSee('Anterior')
            ->assertSee('Siguiente')
            ->assertSee('1 / 3')
            // Ni los textos en inglés de Laravel ni la tira numerada.
            ->assertDontSee('Showing')
            ->assertDontSee('results');
    }

    public function test_previous_and_next_move_between_pages(): void
    {
        foreach (range(1, 25) as $i) {
            $this->merchant(sprintf('Comercio %02d', $i));
        }

        Livewire::test('merchants')
            ->call('nextPage')
            ->assertViewHas('merchants', fn ($p) => $p->currentPage() === 2)
            ->call('previousPage')
            ->assertViewHas('merchants', fn ($p) => $p->currentPage() === 1);
    }

    // --- La tabla enseña lo derivado -----------------------------------------

    public function test_the_list_shows_the_courier_that_comes_from_the_route(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'pickup_route_id' => $this->ruta->id]);
        $this->merchant('COBO FAMILY, S.L.');

        // El mensajero no es una FK del comercio: sale de la ruta (§4).
        Livewire::test('merchants')->assertSee('Freddy GLS');
    }

    // --- Baja ----------------------------------------------------------------

    public function test_the_trash_icon_only_opens_a_confirmation(): void
    {
        $merchant = $this->merchant('Zona Joven');

        Livewire::test('merchants')
            ->call('confirmDelete', $merchant->id)
            ->assertSee('Zona Joven')
            ->assertSee('quedarán sin evaluar');

        $this->assertNotSoftDeleted($merchant);
    }

    public function test_a_deleted_merchant_leaves_the_master(): void
    {
        $merchant = $this->merchant('Zona Joven');

        Livewire::test('merchants')->call('delete', $merchant->id);

        $this->assertSoftDeleted($merchant);
        $this->assertSame(0, Merchant::count());
    }

    public function test_it_can_be_brought_back(): void
    {
        $merchant = $this->merchant('Zona Joven');

        Livewire::test('merchants')->call('delete', $merchant->id);
        Livewire::test('merchants')->call('restore', $merchant->id);

        $this->assertNotSoftDeleted($merchant->refresh());
    }

    // --- Doble envío ---------------------------------------------------------

    public function test_saving_twice_does_not_create_two_merchants(): void
    {
        Livewire::test('merchants')
            ->call('create')
            ->set('name', 'Zona Joven')
            ->set('pickup_route_id', (string) $this->ruta->id)
            ->call('save')
            ->call('save');

        $this->assertSame(1, Merchant::where('name', 'Zona Joven')->count());
    }
}
