<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PickupRoute;
use App\Models\Setting;
use App\Models\User;
use App\Support\PermissionCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Roles y permisos (CONTEXTO.md §7, fase 12).
 *
 * Lo que se fija aquí es que **el permiso de ver no se convierte en uno de
 * escribir**: la ruta es la puerta, pero a un método de Livewire se llega desde
 * el navegador sin pasar por el Blade que esconde el botón.
 */
class RolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    /** Una cuenta de Operaciones, que es la que tiene los permisos recortados. */
    private function operaciones(): User
    {
        return User::factory()->role(PermissionCatalog::ROLE_OPERATIONS)->create();
    }

    // --- El catálogo y su seeder ---------------------------------------------

    public function test_the_seeder_creates_every_permission_of_the_catalogue(): void
    {
        foreach (array_keys(PermissionCatalog::all()) as $permiso) {
            $this->assertTrue(
                Permission::where('name', $permiso)->where('guard_name', 'web')->exists(),
                "Falta el permiso {$permiso}.",
            );
        }

        $this->assertSame(
            count(PermissionCatalog::all()),
            Role::findByName(PermissionCatalog::ROLE_ADMIN)->permissions()->count(),
        );
    }

    public function test_operations_does_not_touch_the_system(): void
    {
        $rol = Role::findByName(PermissionCatalog::ROLE_OPERATIONS);

        // Las cuentas y las copias son el permiso más fuerte del panel (§10):
        // quien descarga una copia se lleva la base entera del cliente.
        foreach (['users.view', 'users.manage', 'backups.manage', 'settings.manage'] as $permiso) {
            $this->assertFalse($rol->hasPermissionTo($permiso), "Operaciones no debería tener {$permiso}.");
        }

        foreach (['merchants.manage', 'incidents.manage', 'settings.view'] as $permiso) {
            $this->assertTrue($rol->hasPermissionTo($permiso), "Operaciones debería tener {$permiso}.");
        }
    }

    public function test_an_account_without_a_role_is_left_at_the_door(): void
    {
        $this->actingAs(User::factory()->withoutRole()->create());

        // La portada y el perfil no piden permiso: quien entra al panel los
        // tiene por definición.
        $this->get('/')->assertOk();
        $this->get('/profile')->assertOk();

        foreach (['/merchants', '/incidents', '/capacity-calendar', '/users', '/backups'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_the_seeder_leaves_the_accounts_that_had_no_role_as_administrators(): void
    {
        // Antes de esto el panel no tenía roles y cualquiera entraba a todo: si
        // el despliegue las dejara sin rol, echaría al equipo entero.
        $heredera = User::factory()->withoutRole()->create();
        $operaciones = $this->operaciones();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(PermissionCatalog::ROLE_ADMIN, $heredera->refresh()->roleName());

        // Y no le devuelve el Administrador a quien alguien acaba de bajar.
        $this->assertSame(PermissionCatalog::ROLE_OPERATIONS, $operaciones->refresh()->roleName());
    }

    // --- La puerta de cada pantalla ------------------------------------------

    public function test_operations_gets_into_the_master_and_the_incidents(): void
    {
        $this->actingAs($this->operaciones());

        foreach (['/pickup-routes', '/couriers', '/merchants', '/audit-logs',
            '/incidents', '/capacity-calendar', '/settings/capacity-calendar'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_operations_is_kept_out_of_users_and_backups(): void
    {
        $this->actingAs($this->operaciones());

        $this->get('/users')->assertForbidden();
        $this->get('/backups')->assertForbidden();
        $this->get('/backups/download')->assertForbidden();
    }

    public function test_the_sidebar_only_offers_what_the_account_can_open(): void
    {
        $this->actingAs($this->operaciones());

        // Esconder y no enseñar apagado: un enlace que existe se pulsa igual, y
        // la respuesta sería un 403 que parece un fallo del panel.
        $this->get('/')
            ->assertOk()
            ->assertSee('Comercios')
            ->assertDontSee('Copias de seguridad')
            ->assertDontSee('>Usuarios<', escape: false);
    }

    // --- La cerradura de dentro ----------------------------------------------

    /** Una cuenta que sólo puede mirar los comercios, sin rol de por medio. */
    private function soloVeComercios(): User
    {
        $usuario = User::factory()->withoutRole()->create();
        $usuario->givePermissionTo('merchants.view');

        return $usuario;
    }

    public function test_a_read_only_account_cannot_write_through_livewire(): void
    {
        $this->actingAs($this->soloVeComercios());

        $ruta = PickupRoute::create(['name' => 'Ruta 3']);
        $comercio = Merchant::create(['name' => 'COBO FAMILY, S.L.', 'pickup_route_id' => $ruta->id]);

        // Cada una es una llamada que el navegador puede hacer aunque el Blade
        // no pinte el botón.
        Livewire::test('merchants')->call('create')->assertForbidden();
        Livewire::test('merchants')->call('edit', $comercio->id)->assertForbidden();
        Livewire::test('merchants')->call('confirmDelete', $comercio->id)->assertForbidden();
        Livewire::test('merchants')->call('delete', $comercio->id)->assertForbidden();
        Livewire::test('merchants')->call('save')->assertForbidden();

        $this->assertNotSoftDeleted($comercio);
    }

    public function test_a_read_only_account_is_not_offered_the_buttons(): void
    {
        $this->actingAs($this->soloVeComercios());

        $ruta = PickupRoute::create(['name' => 'Ruta 3']);
        Merchant::create(['name' => 'COBO FAMILY, S.L.', 'pickup_route_id' => $ruta->id]);

        $this->get('/merchants')
            ->assertOk()
            ->assertSee('COBO FAMILY, S.L.')
            ->assertDontSee('Nuevo comercio')
            ->assertDontSee('Dar de baja');
    }

    public function test_operations_can_still_write_in_the_master(): void
    {
        $this->actingAs($this->operaciones());

        Livewire::test('pickup-routes')
            ->call('create')
            ->set('name', 'Ruta 9')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(PickupRoute::where('name', 'Ruta 9')->exists());
    }

    // --- Configuraciones: se mira pero no se toca ----------------------------

    public function test_operations_sees_the_settings_read_only(): void
    {
        $this->actingAs($this->operaciones());

        $this->get('/settings/capacity-calendar')
            ->assertOk()
            ->assertSee('Sólo lectura')
            ->assertDontSee('>Guardar<', escape: false);

        Livewire::test('settings', ['module' => 'capacity-calendar'])
            ->set('values.minimum_percent', '10')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, Setting::where('module', 'capacity-calendar')->count());
    }

    public function test_an_administrator_still_saves_the_settings(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('settings', ['module' => 'capacity-calendar'])
            ->set('values.minimum_percent', '60')
            ->set('values.optimal_percent', '85')
            ->set('values.bad_color', '#dc2626')
            ->set('values.warning_color', '#d97706')
            ->set('values.good_color', '#16a34a')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('60', Setting::where('key', 'minimum_percent')->sole()->value);
    }
}
