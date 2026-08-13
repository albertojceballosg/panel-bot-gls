<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\Merchant;
use App\Models\PickupRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El contrato de `GET /api/rutas` (CONTEXTO.md §3).
 *
 * **Este test no se relaja para que pase un refactor.** Su trabajo es exacta-
 * mente ese: que la forma de la respuesta no se mueva sin que alguien lo decida
 * a propósito, porque moverla rompe el repo del bot.
 */
class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'un-token-de-prueba-cualquiera';

    protected function setUp(): void
    {
        parent::setUp();
        config(['panel.bot_token' => self::TOKEN]);
    }

    private function ask(?string $token = self::TOKEN): TestResponse
    {
        return $this->getJson('/api/rutas', $token === null ? [] : [
            'Authorization' => "Bearer {$token}",
        ]);
    }

    /** Un comercio con su ruta y, si se pide, el mensajero que la conduce. */
    private function merchant(string $name, string $routeName, ?string $courier, ?int $code = null): Merchant
    {
        $pickupRoute = PickupRoute::firstOrCreate(['name' => $routeName]);

        if ($courier !== null) {
            Courier::firstOrCreate(['name' => $courier], ['pickup_route_id' => $pickupRoute->id]);
        }

        return Merchant::create(['name' => $name, 'code' => $code, 'pickup_route_id' => $pickupRoute->id]);
    }

    // --- Autenticación ------------------------------------------------------

    public function test_without_a_token_it_answers_401(): void
    {
        $this->ask(token: null)->assertUnauthorized();
    }

    public function test_with_the_wrong_token_it_answers_401(): void
    {
        $this->ask('un-token-que-no-es')->assertUnauthorized();
    }

    public function test_an_unconfigured_token_does_not_open_the_endpoint(): void
    {
        // Un despliegue que se olvide del RUTAS_TOKEN no puede dejar el maestro
        // del cliente al aire. Cerrado por defecto (§10).
        config(['panel.bot_token' => null]);

        $this->ask(token: null)->assertUnauthorized();
        $this->ask('')->assertUnauthorized();
        $this->ask('lo-que-sea')->assertUnauthorized();
    }

    public function test_with_the_right_token_it_answers_200(): void
    {
        $this->merchant('3COR CREATIONS SLU', '1', 'Benjamin GLS');

        $this->ask()->assertOk();
    }

    // --- Forma de la respuesta ----------------------------------------------

    public function test_the_json_shape_is_the_one_in_the_contract(): void
    {
        $merchant = $this->merchant('3COR CREATIONS SLU', '1', 'Benjamin GLS', code: 287);

        $this->ask()
            ->assertOk()
            ->assertJsonStructure([
                'generado',
                'comercios' => [['id', 'nombre', 'ruta_id', 'ruta', 'mensajero', 'codigo']],
            ])
            ->assertJsonPath('comercios.0.id', $merchant->id)
            ->assertJsonPath('comercios.0.nombre', '3COR CREATIONS SLU')
            ->assertJsonPath('comercios.0.ruta_id', $merchant->pickup_route_id)
            ->assertJsonPath('comercios.0.ruta', '1')
            ->assertJsonPath('comercios.0.mensajero', 'Benjamin GLS')
            ->assertJsonPath('comercios.0.codigo', 287);
    }

    public function test_the_keys_stay_in_spanish_even_though_the_code_is_english(): void
    {
        // Son el contrato, no nombres nuestros: `name`/`route`/`courier`/`code`
        // aquí romperían al bot.
        $this->merchant('3COR CREATIONS SLU', '1', 'Benjamin GLS');

        $this->assertSame(
            ['id', 'nombre', 'ruta_id', 'ruta', 'mensajero', 'codigo'],
            array_keys($this->ask()->json('comercios.0')),
        );
    }

    public function test_the_ids_are_integers_and_identify_the_entities(): void
    {
        // El `id` es identidad, no adorno: el bot lo devuelve en cada incidencia
        // para que sobreviva a que alguien renombre la ruta o el comercio.
        $uno = $this->merchant('Comercio uno', 'Vallecas', 'Pepe Rodriguez');
        $dos = $this->merchant('Comercio dos', 'Vallecas', null);

        $merchants = collect($this->ask()->json('comercios'))->keyBy('nombre');

        $this->assertIsInt($merchants['Comercio uno']['id']);
        $this->assertIsInt($merchants['Comercio uno']['ruta_id']);
        $this->assertNotSame($merchants['Comercio uno']['id'], $merchants['Comercio dos']['id']);

        // Dos comercios de la misma ruta comparten `ruta_id`. Es lo que permite
        // al panel agrupar incidencias por ruta sin comparar nombres.
        $this->assertSame(
            $merchants['Comercio uno']['ruta_id'],
            $merchants['Comercio dos']['ruta_id'],
        );
        $this->assertSame($uno->pickup_route_id, $dos->pickup_route_id);
    }

    public function test_renaming_a_route_does_not_change_its_id(): void
    {
        // El motivo de existir del `ruta_id`: el nombre es una etiqueta que el
        // cliente cambia desde el panel, la identidad no se mueve.
        $this->merchant('3COR CREATIONS SLU', '1', 'Benjamin GLS');
        $antes = $this->ask()->json('comercios.0');

        PickupRoute::where('name', '1')->firstOrFail()->update(['name' => 'Centro']);
        $despues = $this->ask()->json('comercios.0');

        $this->assertSame($antes['ruta_id'], $despues['ruta_id']);
        $this->assertSame('1', $antes['ruta']);
        $this->assertSame('Centro', $despues['ruta']);
    }

    public function test_the_code_is_an_integer_or_null(): void
    {
        $this->merchant('Good Id S.L', '1', 'Benjamin GLS', code: 287);
        $this->merchant('COBO FAMILY, S.L.', '3', 'Freddy GLS');

        $merchants = collect($this->ask()->json('comercios'))->keyBy('nombre');

        // Entero de verdad, no la cadena "287": el bot lo usa para cruzar.
        $this->assertSame(287, $merchants['Good Id S.L']['codigo']);
        $this->assertNull($merchants['COBO FAMILY, S.L.']['codigo']);
    }

    public function test_the_courier_is_null_when_the_route_has_no_driver(): void
    {
        $this->merchant('LEDME', '6', courier: null);

        $this->ask()->assertOk()->assertJsonPath('comercios.0.mensajero', null);
    }

    public function test_generado_is_iso8601_with_a_timezone(): void
    {
        $this->merchant('3COR CREATIONS SLU', '1', 'Benjamin GLS');

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $this->ask()->json('generado'),
        );
        $this->assertSame('Europe/Madrid', config('app.timezone'));
    }

    public function test_the_name_is_served_unnormalized(): void
    {
        // Lo normaliza el bot (§3). Si lo normalizásemos aquí, la lógica
        // quedaría duplicada en dos repos y se separarían con el tiempo.
        $this->merchant("LIANCHUN HONG, S.L.\t- LOOKAT", '3', 'Freddy GLS');

        $this->ask()->assertJsonPath('comercios.0.nombre', "LIANCHUN HONG, S.L.\t- LOOKAT");
    }

    // --- Qué entra y qué no en la lista -------------------------------------

    public function test_it_always_returns_the_complete_list(): void
    {
        // Nunca altas y bajas incrementales (§3, regla 1): con deltas, un
        // mensaje perdido deja el maestro mal para siempre y sin rastro.
        foreach (range(1, 5) as $i) {
            $this->merchant("Comercio {$i}", '1', $i === 1 ? 'Benjamin GLS' : null);
        }

        $this->ask()->assertJsonCount(5, 'comercios');
    }

    public function test_deleted_merchants_are_left_out(): void
    {
        $this->merchant('Vivo', '1', 'Benjamin GLS');
        $this->merchant('Dado de baja', '1', null)->delete();

        $this->ask()
            ->assertJsonCount(1, 'comercios')
            ->assertJsonPath('comercios.0.nombre', 'Vivo');
    }

    // --- Independencia de la interfaz ---------------------------------------

    public function test_it_does_not_depend_on_session_or_csrf(): void
    {
        // §2: el endpoint es el producto y no puede depender de nada de la UI.
        $this->merchant('3COR CREATIONS SLU', '1', 'Benjamin GLS');

        $this->assertGuest();
        $this->ask()->assertOk()->assertHeaderMissing('Set-Cookie');
    }

    public function test_it_does_not_run_one_query_per_merchant(): void
    {
        // El maestro son 93 filas y crece. Sin eager loading serían ~187
        // consultas por petición, y el bot la hace una vez al día contra un
        // panel que puede estar en otra máquina.
        foreach (range(1, 10) as $i) {
            $this->merchant("Comercio {$i}", (string) $i, "Mensajero {$i}");
        }

        DB::enableQueryLog();
        $this->ask()->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(6, $queries, "Se esperaban pocas consultas y se hicieron {$queries}.");
    }
}
