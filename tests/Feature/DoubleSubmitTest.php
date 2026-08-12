<?php

namespace Tests\Feature;

use App\Exceptions\DoubleSubmitException;
use App\Models\PickupRoute;
use App\Models\User;
use App\Support\PreventsDoubleSubmit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El cerrojo de doble envío (CONTEXTO.md §7, fase 3).
 *
 * Desactivar el botón en el navegador es cosmético: no cubre el doble clic que
 * llega antes de que reaccione el JS, ni dos pestañas, ni una petición
 * reenviada. Esto comprueba la mitad que sí lo impide, la del servidor.
 */
class DoubleSubmitTest extends TestCase
{
    use RefreshDatabase;

    /** Un objeto cualquiera que use el trait, para probarlo aislado. */
    private function guardia(): object
    {
        return new class
        {
            use PreventsDoubleSubmit {
                withoutDoubleSubmit as public;
            }
        };
    }

    public function test_the_second_call_is_rejected_while_the_first_is_running(): void
    {
        $guardia = $this->guardia();
        $veces = 0;

        $this->expectException(DoubleSubmitException::class);

        $guardia->withoutDoubleSubmit('prueba', function () use ($guardia, &$veces) {
            $veces++;

            // Reentrada desde dentro: simula la segunda petición llegando
            // mientras la primera todavía no ha terminado.
            $guardia->withoutDoubleSubmit('prueba', fn () => $veces++);
        });
    }

    public function test_the_lock_is_released_even_when_the_action_blows_up(): void
    {
        $guardia = $this->guardia();

        try {
            $guardia->withoutDoubleSubmit('prueba', fn () => throw new \RuntimeException('vaya'));
        } catch (\RuntimeException) {
            // esperado
        }

        // Si el `finally` no soltara el cerrojo, esto fallaría y la acción
        // quedaría bloqueada hasta que expirase.
        $this->assertSame('ok', $guardia->withoutDoubleSubmit('prueba', fn () => 'ok'));
    }

    public function test_two_users_do_not_block_each_other(): void
    {
        $guardia = $this->guardia();

        $this->actingAs(User::factory()->create());
        $primero = $guardia->withoutDoubleSubmit('prueba', fn () => 'primero');

        $this->actingAs(User::factory()->create());
        $segundo = $guardia->withoutDoubleSubmit('prueba', fn () => 'segundo');

        $this->assertSame('primero', $primero);
        $this->assertSame('segundo', $segundo);
    }

    // --- En la pantalla ------------------------------------------------------

    public function test_saving_twice_does_not_create_two_routes(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pickup-routes')
            ->call('create')
            ->set('name', 'Vallecas')
            ->call('save')
            ->call('save');

        // El segundo envío no encuentra `editing`, así que sin cerrojo crearía
        // una segunda ruta con el mismo nombre — o chocaría con el índice.
        $this->assertSame(1, PickupRoute::where('name', 'Vallecas')->count());
    }
}
