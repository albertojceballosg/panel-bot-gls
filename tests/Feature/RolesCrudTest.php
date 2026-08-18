<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Maestro de roles (CONTEXTO.md §7, fase 12). */
class RolesCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $yo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->yo = User::factory()->create(['name' => 'Quien manda']);
        $this->actingAs($this->yo);
    }

    private function rol(string $nombre): ?Role
    {
        return Role::where('name', $nombre)->first();
    }

    // --- La puerta ----------------------------------------------------------

    public function test_it_is_behind_the_session(): void
    {
        auth()->logout();

        $this->get('/roles')->assertRedirect('/login');
    }

    public function test_operations_does_not_get_in(): void
    {
        // Quien puede tocar los roles puede dárselo todo a sí mismo: va con las
        // cuentas y las copias.
        $this->actingAs(User::factory()->role(PermissionCatalog::ROLE_OPERATIONS)->create());

        $this->get('/roles')->assertForbidden();
    }

    public function test_the_page_renders_both_masters(): void
    {
        $this->get('/roles')
            ->assertOk()
            ->assertSee(PermissionCatalog::ROLE_ADMIN)
            ->assertSee(PermissionCatalog::ROLE_OPERATIONS)
            // Los permisos, que desde el 18/08/2026 son una tabla y no una
            // leyenda: se pueden crear, editar y borrar.
            ->assertSee('backups.manage')
            ->assertSee('Descargar y restaurar copias de la base')
            ->assertSee('Nuevo permiso')
            ->assertDontSee('Los permisos que existen');
    }

    // --- Alta ---------------------------------------------------------------

    public function test_it_creates_a_role_with_the_permissions_it_was_given(): void
    {
        Livewire::test('roles')
            ->call('create')
            ->set('name', 'Consulta')
            ->set('permissions', ['merchants.view', 'incidents.view'])
            ->call('save')
            ->assertHasNoErrors();

        $rol = $this->rol('Consulta');

        $this->assertNotNull($rol);
        $this->assertEqualsCanonicalizing(
            ['merchants.view', 'incidents.view'],
            $rol->permissions->pluck('name')->all(),
        );

        // Y sirve para lo que dice que sirve.
        $mirona = User::factory()->role('Consulta')->create();

        $this->assertTrue($mirona->can('merchants.view'));
        $this->assertFalse($mirona->can('merchants.manage'));
    }

    public function test_a_role_without_permissions_is_allowed(): void
    {
        // Sirve para aparcar una cuenta sin borrarla. La pantalla lo dice en la
        // fila, para que no parezca un rol a medio configurar.
        Livewire::test('roles')
            ->call('create')
            ->set('name', 'Aparcado')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('no puede entrar a ninguna pantalla');

        $this->assertSame(0, $this->rol('Aparcado')->permissions->count());
    }

    public function test_the_name_is_required_and_unique(): void
    {
        Livewire::test('roles')
            ->call('create')
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required']);

        Livewire::test('roles')
            ->call('create')
            ->set('name', PermissionCatalog::ROLE_OPERATIONS)
            ->call('save')
            ->assertHasErrors(['name' => 'unique']);
    }

    public function test_an_invented_permission_does_not_get_through(): void
    {
        // La lista la fija el catálogo, no el navegador: un permiso que nadie
        // comprueba no abre ninguna puerta, pero sí ensucia todos los roles.
        Livewire::test('roles')
            ->call('create')
            ->set('name', 'Listillo')
            ->set('permissions', ['todo.absoluto'])
            ->call('save')
            ->assertHasErrors(['permissions.0']);

        $this->assertNull($this->rol('Listillo'));
    }

    // --- Edición ------------------------------------------------------------

    public function test_it_changes_the_permissions_of_a_role_and_writes_it_down(): void
    {
        $operaciones = $this->rol(PermissionCatalog::ROLE_OPERATIONS);

        Livewire::test('roles')
            ->call('edit', $operaciones->id)
            ->set('permissions', ['incidents.view'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['incidents.view'], $operaciones->refresh()->permissions->pluck('name')->all());

        // El cambio de permisos vive en la pivote y los eventos de Eloquent no
        // lo ven: lo escribe `Role::recordPermissionChange()` (§4).
        $entrada = $operaciones->auditLogs()->where('action', AuditAction::Update)->sole();

        $this->assertStringContainsString('merchants.manage', $entrada->before['permissions']);
        $this->assertSame(['permissions' => 'incidents.view'], $entrada->after);
    }

    public function test_the_seeder_does_not_undo_a_change_made_by_hand(): void
    {
        $operaciones = $this->rol(PermissionCatalog::ROLE_OPERATIONS);

        Livewire::test('roles')
            ->call('edit', $operaciones->id)
            ->set('permissions', ['incidents.view'])
            ->call('save')
            ->assertHasNoErrors();

        $this->seed(RolesAndPermissionsSeeder::class);

        // Sólo el Administrador se resincroniza; el resto son ya cosa del
        // cliente desde que hay pantalla.
        $this->assertSame(['incidents.view'], $operaciones->refresh()->permissions->pluck('name')->all());
        $this->assertSame(
            count(PermissionCatalog::all()),
            $this->rol(PermissionCatalog::ROLE_ADMIN)->permissions()->count(),
        );
    }

    public function test_the_administrator_role_is_not_edited(): void
    {
        $admin = $this->rol(PermissionCatalog::ROLE_ADMIN);

        Livewire::test('roles')
            ->call('edit', $admin->id)
            ->assertSet('showingForm', false)
            ->set('editing', $admin->id)
            ->set('name', 'Administrador de mentira')
            ->set('permissions', [])
            ->call('save');

        // Ni por el formulario ni llamando a `save` con el id puesto a mano.
        $this->assertSame(PermissionCatalog::ROLE_ADMIN, $admin->refresh()->name);
        $this->assertSame(count(PermissionCatalog::all()), $admin->permissions()->count());
    }

    public function test_you_cannot_take_the_key_of_this_screen_away_from_your_own_role(): void
    {
        // La misma red que «no puedes darte de baja»: quedarte fuera de esta
        // pantalla con la sesión abierta y sin poder volver a entrar.
        $mio = Role::create(['name' => 'Casi admin', 'guard_name' => 'web']);
        $mio->syncPermissions(['roles.view', 'roles.manage', 'users.view']);
        $this->yo->syncRoles([$mio->name]);

        Livewire::test('roles')
            ->call('edit', $mio->id)
            ->set('permissions', ['roles.view'])
            ->call('save');

        $this->assertContains('roles.manage', $mio->refresh()->permissions->pluck('name')->all());
    }

    // --- Borrado ------------------------------------------------------------

    public function test_it_deletes_a_role_nobody_has(): void
    {
        $rol = Role::create(['name' => 'De prueba', 'guard_name' => 'web']);

        Livewire::test('roles')->call('delete', $rol->id);

        $this->assertNull($this->rol('De prueba'));
    }

    public function test_a_role_with_accounts_is_not_deleted(): void
    {
        // Borrarlo dejaría a esas cuentas sin rol, o sea fuera del panel, y sin
        // decírselo a nadie.
        $operaciones = $this->rol(PermissionCatalog::ROLE_OPERATIONS);
        User::factory()->role($operaciones->name)->create();

        Livewire::test('roles')
            ->call('delete', $operaciones->id)
            ->assertDispatched('toast', type: 'error');

        $this->assertNotNull($this->rol(PermissionCatalog::ROLE_OPERATIONS));
    }

    public function test_the_administrator_role_is_not_deleted(): void
    {
        $admin = $this->rol(PermissionCatalog::ROLE_ADMIN);

        Livewire::test('roles')
            ->call('delete', $admin->id)
            ->assertDispatched('toast', type: 'error');

        $this->assertNotNull($this->rol(PermissionCatalog::ROLE_ADMIN));
    }

    // --- Lo que ve quien sólo mira ------------------------------------------

    public function test_a_read_only_account_cannot_write_through_livewire(): void
    {
        $mirona = User::factory()->withoutRole()->create();
        $mirona->givePermissionTo('roles.view');
        $this->actingAs($mirona);

        $rol = $this->rol(PermissionCatalog::ROLE_OPERATIONS);

        Livewire::test('roles')->call('create')->assertForbidden();
        Livewire::test('roles')->call('edit', $rol->id)->assertForbidden();
        Livewire::test('roles')->call('confirmDelete', $rol->id)->assertForbidden();
        Livewire::test('roles')->call('delete', $rol->id)->assertForbidden();
        Livewire::test('roles')->call('save')->assertForbidden();

        $this->get('/roles')->assertOk()->assertDontSee('Nuevo rol');
    }

    // --- El puente con el maestro de usuarios --------------------------------

    public function test_a_new_role_can_be_given_to_an_account(): void
    {
        Livewire::test('roles')
            ->call('create')
            ->set('name', 'Consulta')
            ->set('permissions', ['incidents.view'])
            ->call('save')
            ->assertHasNoErrors();

        // El desplegable de usuarios sale de la tabla, no de una lista fija.
        Livewire::test('users')->call('create')->assertSee('Consulta');

        $otro = User::factory()->create();

        Livewire::test('users')
            ->call('edit', $otro->id)
            ->set('role', 'Consulta')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Consulta', $otro->refresh()->roleName());
    }

    // --- El maestro de permisos ---------------------------------------------

    public function test_it_creates_a_permission_and_the_administrator_gets_it_at_once(): void
    {
        Livewire::test('roles')
            ->call('createPermission')
            ->set('permissionName', 'informes.view')
            ->set('permissionDescription', 'Ver los informes mensuales')
            ->call('savePermission')
            ->assertHasNoErrors();

        $permiso = Permission::where('name', 'informes.view')->sole();

        $this->assertSame('Ver los informes mensuales', $permiso->description);

        // El Administrador se define como «todos»: si esperase al próximo
        // despliegue, habría un rato en el que ese «todos» sería mentira.
        $this->assertTrue($this->rol(PermissionCatalog::ROLE_ADMIN)->hasPermissionTo($permiso));
        $this->assertTrue($this->yo->can('informes.view'));
    }

    public function test_the_name_of_a_permission_has_to_look_like_one(): void
    {
        // La pantalla agrupa por lo que va antes del punto y `can:` lo lee tal
        // cual desde la ruta: un nombre con espacios sería inescribible ahí.
        Livewire::test('roles')
            ->call('createPermission')
            ->set('permissionName', 'Ver informes')
            ->call('savePermission')
            ->assertHasErrors(['permissionName' => 'regex']);

        Livewire::test('roles')
            ->call('createPermission')
            ->set('permissionName', 'merchants.view')
            ->call('savePermission')
            ->assertHasErrors(['permissionName' => 'unique']);

        Livewire::test('roles')
            ->call('createPermission')
            ->set('permissionName', '')
            ->call('savePermission')
            ->assertHasErrors(['permissionName' => 'required']);
    }

    public function test_a_permission_of_the_code_is_not_renamed_nor_deleted(): void
    {
        // Su nombre está escrito en `routes/web.php` y en las pantallas:
        // renombrarlo dejaría esa comprobación mirando a algo que no existe.
        $delCodigo = Permission::where('name', 'merchants.manage')->sole();

        Livewire::test('roles')
            ->call('editPermission', $delCodigo->id)
            ->assertSet('showingPermissionForm', false)
            ->set('permissionEditing', $delCodigo->id)
            ->set('permissionName', 'merchants.otracosa')
            ->call('savePermission');

        Livewire::test('roles')
            ->call('deletePermission', $delCodigo->id)
            ->assertDispatched('toast', type: 'error');

        $this->assertSame('merchants.manage', $delCodigo->refresh()->name);
        $this->assertTrue(Permission::where('name', 'merchants.manage')->exists());
    }

    public function test_it_edits_and_deletes_a_permission_of_its_own(): void
    {
        Livewire::test('roles')
            ->call('createPermission')
            ->set('permissionName', 'informes.view')
            ->set('permissionDescription', 'Ver los informes')
            ->call('savePermission')
            ->assertHasNoErrors();

        $permiso = Permission::where('name', 'informes.view')->sole();

        Livewire::test('roles')
            ->call('editPermission', $permiso->id)
            ->assertSet('permissionDescription', 'Ver los informes')
            ->set('permissionName', 'informes.manage')
            ->set('permissionDescription', 'Emitir los informes')
            ->call('savePermission')
            ->assertHasNoErrors();

        $this->assertSame('informes.manage', $permiso->refresh()->name);

        // Crear o borrar un permiso cambia lo que la aplicación puede
        // comprobar: queda en el historial como todo lo demás (§4).
        $this->assertSame(AuditAction::Create, $permiso->auditLogs()->get()->last()->action);

        Livewire::test('roles')->call('deletePermission', $permiso->id);

        $this->assertNull(Permission::where('name', 'informes.manage')->first());
    }

    public function test_a_deleted_permission_disappears_from_the_roles_that_had_it(): void
    {
        Livewire::test('roles')
            ->call('createPermission')
            ->set('permissionName', 'informes.view')
            ->call('savePermission')
            ->assertHasNoErrors();

        $permiso = Permission::where('name', 'informes.view')->sole();
        $rol = Role::create(['name' => 'Informes', 'guard_name' => 'web']);
        $rol->syncPermissions(['informes.view']);

        Livewire::test('roles')->call('deletePermission', $permiso->id);

        $this->assertSame(0, $rol->refresh()->permissions()->count());
    }

    public function test_a_permission_created_by_hand_can_be_given_to_a_role(): void
    {
        Livewire::test('roles')
            ->call('createPermission')
            ->set('permissionName', 'informes.view')
            ->set('permissionDescription', 'Ver los informes mensuales')
            ->call('savePermission')
            ->assertHasNoErrors();

        // El formulario del rol se pinta contra la base, no contra el catálogo:
        // si no, un permiso creado a mano no se podría marcar en ninguna parte.
        Livewire::test('roles')
            ->call('create')
            ->assertSee('Ver los informes mensuales')
            ->set('name', 'Informes')
            ->set('permissions', ['informes.view'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['informes.view'], $this->rol('Informes')->permissions->pluck('name')->all());
    }

    public function test_the_seeder_does_not_take_a_hand_made_permission_from_the_administrator(): void
    {
        Livewire::test('roles')
            ->call('createPermission')
            ->set('permissionName', 'informes.view')
            ->call('savePermission')
            ->assertHasNoErrors();

        $this->seed(RolesAndPermissionsSeeder::class);

        // El Administrador se resincroniza en cada despliegue: con la lista del
        // catálogo, esa pasada le quitaría lo creado desde la pantalla.
        $this->assertTrue($this->rol(PermissionCatalog::ROLE_ADMIN)->hasPermissionTo('informes.view'));
    }

    public function test_a_read_only_account_cannot_touch_the_permissions(): void
    {
        $mirona = User::factory()->withoutRole()->create();
        $mirona->givePermissionTo('roles.view');
        $this->actingAs($mirona);

        $permiso = Permission::where('name', 'merchants.view')->sole();

        Livewire::test('roles')->call('createPermission')->assertForbidden();
        Livewire::test('roles')->call('editPermission', $permiso->id)->assertForbidden();
        Livewire::test('roles')->call('confirmPermissionDelete', $permiso->id)->assertForbidden();
        Livewire::test('roles')->call('deletePermission', $permiso->id)->assertForbidden();
        Livewire::test('roles')->call('savePermission')->assertForbidden();

        $this->get('/roles')->assertOk()->assertDontSee('Nuevo permiso');
    }
}
