<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\Merchant;
use App\Models\PickupRoute;
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

    private function pickupRoute(string $name = '1'): PickupRoute
    {
        return PickupRoute::create(['name' => $name]);
    }

    public function test_the_generated_column_normalizes_case_and_spacing(): void
    {
        $merchant = Merchant::create([
            'name' => "  Cobo   Family,\tS.L. ",
            'pickup_route_id' => $this->pickupRoute()->id,
        ]);

        // El nombre se guarda tal cual (el contrato lo sirve "sin normalizar").
        $this->assertSame("  Cobo   Family,\tS.L. ", $merchant->name);
        $this->assertSame('COBO FAMILY, S.L.', $merchant->refresh()->normalized_name);
    }

    public function test_two_merchants_differing_only_in_case_are_rejected(): void
    {
        $pickupRoute = $this->pickupRoute();
        Merchant::create(['name' => 'Zona Joven', 'pickup_route_id' => $pickupRoute->id]);

        $this->expectException(QueryException::class);
        Merchant::create(['name' => 'ZONA  JOVEN', 'pickup_route_id' => $pickupRoute->id]);
    }

    public function test_the_code_is_unique_but_allows_many_nulls(): void
    {
        $pickupRoute = $this->pickupRoute();

        // Los 11 comercios sin código del maestro (§3) tienen que caber todos.
        Merchant::create(['name' => 'Sin código A', 'pickup_route_id' => $pickupRoute->id]);
        Merchant::create(['name' => 'Sin código B', 'pickup_route_id' => $pickupRoute->id]);
        $this->assertSame(2, Merchant::whereNull('code')->count());

        Merchant::create(['name' => 'Good Id S.L', 'code' => 287, 'pickup_route_id' => $pickupRoute->id]);

        $this->expectException(QueryException::class);
        Merchant::create(['name' => 'Otro cualquiera', 'code' => 287, 'pickup_route_id' => $pickupRoute->id]);
    }

    // --- Lo que motivó meter `routes`: la ruta sobrevive al mensajero --------

    public function test_removing_the_courier_leaves_the_route_and_its_merchants_untouched(): void
    {
        $pickupRoute = $this->pickupRoute('3');
        Courier::create(['name' => 'Freddy GLS', 'pickup_route_id' => $pickupRoute->id]);
        Merchant::create(['name' => 'Cobo Family, S.L.', 'pickup_route_id' => $pickupRoute->id]);

        Courier::where('name', 'Freddy GLS')->delete();

        $this->assertSame(1, $pickupRoute->refresh()->merchants()->count());
        $this->assertNull($pickupRoute->courier);

        // Y entra otro en su lugar sin tocar un solo comercio.
        Courier::create(['name' => 'Nuevo GLS', 'pickup_route_id' => $pickupRoute->id]);
        $this->assertSame('Nuevo GLS', $pickupRoute->refresh()->courier->name);
        $this->assertSame(1, $pickupRoute->merchants()->count());
    }

    public function test_a_route_cannot_have_two_couriers(): void
    {
        $pickupRoute = $this->pickupRoute();
        Courier::create(['name' => 'Benjamin GLS', 'pickup_route_id' => $pickupRoute->id]);

        // Si no, el `mensajero` del contrato quedaría ambiguo (§3).
        $this->expectException(QueryException::class);
        Courier::create(['name' => 'BORJA GONZALEZ', 'pickup_route_id' => $pickupRoute->id]);
    }

    public function test_many_couriers_can_be_left_without_a_route(): void
    {
        Courier::create(['name' => 'Recién llegado']);
        Courier::create(['name' => 'Otro más']);

        $this->assertSame(2, Courier::whereNull('pickup_route_id')->count());
    }

    public function test_deleting_a_route_that_still_has_merchants_is_forbidden(): void
    {
        $pickupRoute = $this->pickupRoute();
        Merchant::create(['name' => 'Bohochique', 'pickup_route_id' => $pickupRoute->id]);

        // Con borrado pasivo la FK no se dispara: lo corta el modelo. Si no,
        // el comercio se quedaría apuntando a una ruta invisible.
        $this->expectException(RuntimeException::class);
        $pickupRoute->delete();
    }

    public function test_an_empty_route_can_be_deleted(): void
    {
        $pickupRoute = $this->pickupRoute();
        $merchant = Merchant::create(['name' => 'Bohochique', 'pickup_route_id' => $pickupRoute->id]);

        $merchant->delete();
        $pickupRoute->delete();

        $this->assertSoftDeleted($pickupRoute);
        $this->assertSame(0, PickupRoute::count());
        $this->assertSame(1, PickupRoute::withTrashed()->count());
    }

    public function test_a_merchants_courier_comes_from_its_route(): void
    {
        $pickupRoute = $this->pickupRoute('5');
        Courier::create(['name' => 'Pepe Rodriguez', 'pickup_route_id' => $pickupRoute->id]);
        $merchant = Merchant::create(['name' => 'Vintax', 'pickup_route_id' => $pickupRoute->id]);

        $this->assertSame('Pepe Rodriguez', $merchant->courier->name);
        $this->assertSame('5', $merchant->pickupRoute->name);
        $this->assertSame(1, Courier::first()->merchants()->count());
    }

    public function test_a_route_without_a_courier_still_has_its_merchants(): void
    {
        $pickupRoute = $this->pickupRoute('6');
        $merchant = Merchant::create(['name' => 'Ledme', 'pickup_route_id' => $pickupRoute->id]);

        $this->assertNull($merchant->courier);
        $this->assertSame(1, $pickupRoute->merchants()->count());
    }

    // --- Borrados pasivos ---------------------------------------------------

    public function test_deleting_a_merchant_removes_it_from_the_master_without_losing_it(): void
    {
        $pickupRoute = $this->pickupRoute();
        $merchant = Merchant::create(['name' => 'Zona Joven', 'pickup_route_id' => $pickupRoute->id]);

        $merchant->delete();

        $this->assertSoftDeleted($merchant);
        $this->assertSame(0, $pickupRoute->merchants()->count());
        $this->assertSame(1, $pickupRoute->merchants()->withTrashed()->count());

        $merchant->restore();
        $this->assertSame(1, $pickupRoute->merchants()->count());
    }

    public function test_the_name_of_a_deleted_merchant_is_freed(): void
    {
        $pickupRoute = $this->pickupRoute();
        Merchant::create(['name' => 'Zona Joven', 'code' => 287, 'pickup_route_id' => $pickupRoute->id])->delete();

        // Con un único normal esto reventaría: la fila borrada seguiría
        // ocupando el nombre y el código. El índice parcial lo evita.
        $nuevo = Merchant::create(['name' => 'Zona Joven', 'code' => 287, 'pickup_route_id' => $pickupRoute->id]);

        $this->assertTrue($nuevo->exists);
        $this->assertSame(1, Merchant::count());
        $this->assertSame(2, Merchant::withTrashed()->count());
    }

    public function test_the_replacement_inherits_the_route_of_the_deleted_courier(): void
    {
        $pickupRoute = $this->pickupRoute('3');
        $saliente = Courier::create(['name' => 'Freddy GLS', 'pickup_route_id' => $pickupRoute->id]);
        Merchant::create(['name' => 'Cobo Family, S.L.', 'pickup_route_id' => $pickupRoute->id]);

        $saliente->delete();

        // Esto es lo que rompería un índice único normal: la fila del saliente
        // seguiría ocupando pickup_route_id y el sustituto no podría entrar.
        $entrante = Courier::create(['name' => 'Nuevo GLS', 'pickup_route_id' => $pickupRoute->id]);

        $this->assertTrue($entrante->exists);
        $this->assertSame('Nuevo GLS', $pickupRoute->refresh()->courier->name);
        $this->assertSame(1, $pickupRoute->merchants()->count());
    }

    public function test_validation_does_not_clash_with_deleted_records(): void
    {
        $pickupRoute = $this->pickupRoute();
        Merchant::create(['name' => 'Zona Joven', 'pickup_route_id' => $pickupRoute->id])->delete();

        $validador = Validator::make(
            ['name' => 'Zona Joven', 'pickup_route_id' => $pickupRoute->id],
            Merchant::rules(),
        );

        $this->assertFalse($validador->fails(), (string) $validador->errors());
    }

    public function test_a_merchant_cannot_be_assigned_to_a_deleted_route(): void
    {
        $pickupRoute = $this->pickupRoute();
        $pickupRoute->delete();

        $validador = Validator::make(
            ['name' => 'Comercio nuevo', 'pickup_route_id' => $pickupRoute->id],
            Merchant::rules(),
        );

        $this->assertTrue($validador->fails());
        $this->assertTrue($validador->errors()->has('pickup_route_id'));
    }

    // --- Validación ---------------------------------------------------------

    public function test_validation_requires_a_name_and_an_existing_route(): void
    {
        $errores = Validator::make(
            ['name' => '', 'pickup_route_id' => 9999],
            Merchant::rules(),
        )->errors();

        $this->assertTrue($errores->has('name'));
        $this->assertTrue($errores->has('pickup_route_id'));
    }

    public function test_validation_catches_the_case_insensitive_duplicate(): void
    {
        $pickupRoute = $this->pickupRoute();
        Merchant::create(['name' => 'Zona Joven', 'pickup_route_id' => $pickupRoute->id]);

        $validador = Validator::make(
            ['name' => 'zona joven', 'pickup_route_id' => $pickupRoute->id],
            Merchant::rules(),
        );

        $this->assertTrue($validador->fails());
        $this->assertSame('Ya existe un comercio con ese nombre.', $validador->errors()->first('name'));
    }

    public function test_editing_does_not_clash_with_itself(): void
    {
        $pickupRoute = $this->pickupRoute();
        $merchant = Merchant::create(['name' => 'Zona Joven', 'code' => 287, 'pickup_route_id' => $pickupRoute->id]);

        $validador = Validator::make(
            ['name' => 'ZONA JOVEN', 'code' => 287, 'pickup_route_id' => $pickupRoute->id],
            Merchant::rules($merchant->id),
        );

        $this->assertFalse($validador->fails(), (string) $validador->errors());
    }

    public function test_validation_prevents_assigning_an_already_taken_route(): void
    {
        $pickupRoute = $this->pickupRoute();
        Courier::create(['name' => 'Benjamin GLS', 'pickup_route_id' => $pickupRoute->id]);

        $validador = Validator::make(
            ['name' => 'BORJA GONZALEZ', 'pickup_route_id' => $pickupRoute->id],
            Courier::rules(),
        );

        $this->assertTrue($validador->fails());
        $this->assertTrue($validador->errors()->has('pickup_route_id'));
    }
}
