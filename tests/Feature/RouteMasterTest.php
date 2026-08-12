<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Tests\TestCase;

/**
 * Los guardarraíles del modelo de datos (CONTEXTO.md §4).
 *
 * No sirven de adorno: el panel no puede romper al bot, pero sí puede hacerle
 * producir un informe equivocado en silencio (§3), y estos invariantes son lo
 * que lo impide.
 */
class RouteMasterTest extends TestCase
{
    use RefreshDatabase;

    private function route(string $name = '1'): Route
    {
        return Route::create(['name' => $name]);
    }

    public function test_the_generated_column_normalizes_case_and_spacing(): void
    {
        $merchant = Merchant::create([
            'name' => "  Cobo   Family,\tS.L. ",
            'route_id' => $this->route()->id,
        ]);

        // El nombre se guarda tal cual (el contrato lo sirve "sin normalizar").
        $this->assertSame("  Cobo   Family,\tS.L. ", $merchant->name);
        $this->assertSame('COBO FAMILY, S.L.', $merchant->refresh()->normalized_name);
    }

    public function test_two_merchants_differing_only_in_case_are_rejected(): void
    {
        $route = $this->route();
        Merchant::create(['name' => 'Zona Joven', 'route_id' => $route->id]);

        $this->expectException(QueryException::class);
        Merchant::create(['name' => 'ZONA  JOVEN', 'route_id' => $route->id]);
    }

    public function test_the_code_is_unique_but_allows_many_nulls(): void
    {
        $route = $this->route();

        // Los 11 comercios sin código del maestro (§3) tienen que caber todos.
        Merchant::create(['name' => 'Sin código A', 'route_id' => $route->id]);
        Merchant::create(['name' => 'Sin código B', 'route_id' => $route->id]);
        $this->assertSame(2, Merchant::whereNull('code')->count());

        Merchant::create(['name' => 'Good Id S.L', 'code' => 287, 'route_id' => $route->id]);

        $this->expectException(QueryException::class);
        Merchant::create(['name' => 'Otro cualquiera', 'code' => 287, 'route_id' => $route->id]);
    }

    // --- Lo que motivó meter `routes`: la ruta sobrevive al mensajero --------

    public function test_removing_the_courier_leaves_the_route_and_its_merchants_untouched(): void
    {
        $route = $this->route('3');
        Courier::create(['name' => 'Freddy GLS', 'route_id' => $route->id]);
        Merchant::create(['name' => 'Cobo Family, S.L.', 'route_id' => $route->id]);

        Courier::where('name', 'Freddy GLS')->delete();

        $this->assertSame(1, $route->refresh()->merchants()->count());
        $this->assertNull($route->courier);

        // Y entra otro en su lugar sin tocar un solo comercio.
        Courier::create(['name' => 'Nuevo GLS', 'route_id' => $route->id]);
        $this->assertSame('Nuevo GLS', $route->refresh()->courier->name);
        $this->assertSame(1, $route->merchants()->count());
    }

    public function test_a_route_cannot_have_two_couriers(): void
    {
        $route = $this->route();
        Courier::create(['name' => 'Benjamin GLS', 'route_id' => $route->id]);

        // Si no, el `mensajero` del contrato quedaría ambiguo (§3).
        $this->expectException(QueryException::class);
        Courier::create(['name' => 'BORJA GONZALEZ', 'route_id' => $route->id]);
    }

    public function test_many_couriers_can_be_left_without_a_route(): void
    {
        Courier::create(['name' => 'Recién llegado']);
        Courier::create(['name' => 'Otro más']);

        $this->assertSame(2, Courier::whereNull('route_id')->count());
    }

    public function test_deleting_a_route_that_still_has_merchants_is_forbidden(): void
    {
        $route = $this->route();
        Merchant::create(['name' => 'Bohochique', 'route_id' => $route->id]);

        // Con borrado pasivo la FK no se dispara: lo corta el modelo. Si no,
        // el comercio se quedaría apuntando a una ruta invisible.
        $this->expectException(RuntimeException::class);
        $route->delete();
    }

    public function test_an_empty_route_can_be_deleted(): void
    {
        $route = $this->route();
        $merchant = Merchant::create(['name' => 'Bohochique', 'route_id' => $route->id]);

        $merchant->delete();
        $route->delete();

        $this->assertSoftDeleted($route);
        $this->assertSame(0, Route::count());
        $this->assertSame(1, Route::withTrashed()->count());
    }

    public function test_a_merchants_courier_comes_from_its_route(): void
    {
        $route = $this->route('5');
        Courier::create(['name' => 'Pepe Rodriguez', 'route_id' => $route->id]);
        $merchant = Merchant::create(['name' => 'Vintax', 'route_id' => $route->id]);

        $this->assertSame('Pepe Rodriguez', $merchant->courier->name);
        $this->assertSame('5', $merchant->route->name);
        $this->assertSame(1, Courier::first()->merchants()->count());
    }

    public function test_a_route_without_a_courier_still_has_its_merchants(): void
    {
        $route = $this->route('6');
        $merchant = Merchant::create(['name' => 'Ledme', 'route_id' => $route->id]);

        $this->assertNull($merchant->courier);
        $this->assertSame(1, $route->merchants()->count());
    }

    // --- Borrados pasivos ---------------------------------------------------

    public function test_deleting_a_merchant_removes_it_from_the_master_without_losing_it(): void
    {
        $route = $this->route();
        $merchant = Merchant::create(['name' => 'Zona Joven', 'route_id' => $route->id]);

        $merchant->delete();

        $this->assertSoftDeleted($merchant);
        $this->assertSame(0, $route->merchants()->count());
        $this->assertSame(1, $route->merchants()->withTrashed()->count());

        $merchant->restore();
        $this->assertSame(1, $route->merchants()->count());
    }

    public function test_the_name_of_a_deleted_merchant_is_freed(): void
    {
        $route = $this->route();
        Merchant::create(['name' => 'Zona Joven', 'code' => 287, 'route_id' => $route->id])->delete();

        // Con un único normal esto reventaría: la fila borrada seguiría
        // ocupando el nombre y el código. El índice parcial lo evita.
        $nuevo = Merchant::create(['name' => 'Zona Joven', 'code' => 287, 'route_id' => $route->id]);

        $this->assertTrue($nuevo->exists);
        $this->assertSame(1, Merchant::count());
        $this->assertSame(2, Merchant::withTrashed()->count());
    }

    public function test_the_replacement_inherits_the_route_of_the_deleted_courier(): void
    {
        $route = $this->route('3');
        $saliente = Courier::create(['name' => 'Freddy GLS', 'route_id' => $route->id]);
        Merchant::create(['name' => 'Cobo Family, S.L.', 'route_id' => $route->id]);

        $saliente->delete();

        // Esto es lo que rompería un índice único normal: la fila del saliente
        // seguiría ocupando route_id y el sustituto no podría entrar.
        $entrante = Courier::create(['name' => 'Nuevo GLS', 'route_id' => $route->id]);

        $this->assertTrue($entrante->exists);
        $this->assertSame('Nuevo GLS', $route->refresh()->courier->name);
        $this->assertSame(1, $route->merchants()->count());
    }

    public function test_validation_does_not_clash_with_deleted_records(): void
    {
        $route = $this->route();
        Merchant::create(['name' => 'Zona Joven', 'route_id' => $route->id])->delete();

        $validador = Validator::make(
            ['name' => 'Zona Joven', 'route_id' => $route->id],
            Merchant::rules(),
        );

        $this->assertFalse($validador->fails(), (string) $validador->errors());
    }

    public function test_a_merchant_cannot_be_assigned_to_a_deleted_route(): void
    {
        $route = $this->route();
        $route->delete();

        $validador = Validator::make(
            ['name' => 'Comercio nuevo', 'route_id' => $route->id],
            Merchant::rules(),
        );

        $this->assertTrue($validador->fails());
        $this->assertTrue($validador->errors()->has('route_id'));
    }

    // --- Validación ---------------------------------------------------------

    public function test_validation_requires_a_name_and_an_existing_route(): void
    {
        $errores = Validator::make(
            ['name' => '', 'route_id' => 9999],
            Merchant::rules(),
        )->errors();

        $this->assertTrue($errores->has('name'));
        $this->assertTrue($errores->has('route_id'));
    }

    public function test_validation_catches_the_case_insensitive_duplicate(): void
    {
        $route = $this->route();
        Merchant::create(['name' => 'Zona Joven', 'route_id' => $route->id]);

        $validador = Validator::make(
            ['name' => 'zona joven', 'route_id' => $route->id],
            Merchant::rules(),
        );

        $this->assertTrue($validador->fails());
        $this->assertSame('Ya existe un comercio con ese nombre.', $validador->errors()->first('name'));
    }

    public function test_editing_does_not_clash_with_itself(): void
    {
        $route = $this->route();
        $merchant = Merchant::create(['name' => 'Zona Joven', 'code' => 287, 'route_id' => $route->id]);

        $validador = Validator::make(
            ['name' => 'ZONA JOVEN', 'code' => 287, 'route_id' => $route->id],
            Merchant::rules($merchant->id),
        );

        $this->assertFalse($validador->fails(), (string) $validador->errors());
    }

    public function test_validation_prevents_assigning_an_already_taken_route(): void
    {
        $route = $this->route();
        Courier::create(['name' => 'Benjamin GLS', 'route_id' => $route->id]);

        $validador = Validator::make(
            ['name' => 'BORJA GONZALEZ', 'route_id' => $route->id],
            Courier::rules(),
        );

        $this->assertTrue($validador->fails());
        $this->assertTrue($validador->errors()->has('route_id'));
    }
}
