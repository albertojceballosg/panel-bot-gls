<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PickupRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El desplegable con buscador (CONTEXTO.md §5, 20/08/2026).
 *
 * Sustituye al `<select>` nativo en todo el panel. Está escrito a mano con el Alpine que ya
 * trae Livewire, sin Select2 ni Tom Select, así que lo que aquí se fija es el contrato con
 * Livewire —el input oculto que lleva el `wire:model`— y que no queda ningún `<select>`
 * suelto por las pantallas.
 */
class SearchableSelectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    /**
     * El valor lo lleva un input oculto con el `wire:model` del llamante, y **no** el estado
     * de Alpine: si viviera en JS habría dos verdades y un render de Livewire podría dejar el
     * desplegable enseñando lo que ya no es.
     */
    public function test_the_value_travels_in_a_hidden_input_bound_to_livewire(): void
    {
        $ruta = PickupRoute::create(['name' => 'Vallecas']);

        Livewire::test('merchants')
            ->call('create')
            ->set('pickup_route_id', (string) $ruta->id)
            ->assertSeeHtml('type="hidden"')
            ->assertSeeHtml('wire:model="pickup_route_id"')
            ->assertSeeHtml('value="'.$ruta->id.'"');
    }

    /** Las opciones las pinta el servidor, no el JS: así un render nuevo trae la lista nueva. */
    public function test_the_options_are_rendered_by_the_server(): void
    {
        PickupRoute::create(['name' => 'Vallecas']);
        PickupRoute::create(['name' => 'Chamberí']);

        Livewire::test('merchants')
            ->call('create')
            ->assertSeeHtml('data-option')
            ->assertSeeHtml('data-search="Vallecas"')
            ->assertSeeHtml('data-search="Chamberí"');
    }

    /**
     * Las claves numéricas de PHP se vuelven enteros, así que el valor tiene que normalizarse
     * a texto: si no, ni casa con lo que trae Livewire —que es una cadena— ni se resalta la
     * opción elegida, y el `pick()` mandaría un número.
     */
    public function test_a_numeric_key_travels_as_text_and_is_highlighted(): void
    {
        $ruta = PickupRoute::create(['name' => 'Vallecas']);

        Livewire::test('merchants')
            ->call('create')
            ->set('pickup_route_id', (string) $ruta->id)
            ->assertSeeHtml('pick(\''.$ruta->id.'\')')
            ->assertSeeHtml('font-medium text-brand-700');
    }

    /** Con el desplegable cerrado se lee la etiqueta de lo elegido, no su id. */
    public function test_the_closed_control_shows_the_label_of_the_chosen_option(): void
    {
        $ruta = PickupRoute::create(['name' => 'Vallecas']);

        Livewire::test('merchants')
            ->call('create')
            ->assertSee('Elige una ruta')
            ->set('pickup_route_id', (string) $ruta->id)
            ->assertSee('Vallecas');
    }

    /** Y sigue guardando lo que se elige, que es lo único que no puede romperse. */
    public function test_choosing_an_option_still_saves(): void
    {
        $ruta = PickupRoute::create(['name' => 'Vallecas']);

        Livewire::test('merchants')
            ->call('create')
            ->set('name', 'COBO FAMILY, S.L.')
            ->set('pickup_route_id', (string) $ruta->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($ruta->id, Merchant::sole()->pickup_route_id);
    }

    /**
     * Que no quede ningún `<select>` nativo por las pantallas: era la petición: **todos**.
     * Se mira sobre el código y no sobre lo renderizado porque hay desplegables que sólo
     * existen dentro de un modal abierto, y montar cada pantalla en su estado exacto para
     * comprobarlo cuesta más de lo que vale.
     */
    public function test_no_screen_is_left_with_a_plain_select(): void
    {
        $pantallas = glob(resource_path('views/components/*.blade.php'));
        $conSelect = [];

        foreach ($pantallas as $fichero) {
            if (str_contains(file_get_contents($fichero), '<x-ui.select ')) {
                $conSelect[] = basename($fichero);
            }
        }

        $this->assertSame([], $conSelect, 'Estas pantallas siguen con un select sin buscador.');
    }
}
