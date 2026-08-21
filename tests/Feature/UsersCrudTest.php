<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/** CRUD de usuarios del panel (CONTEXTO.md §7, fase 8). */
class UsersCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $yo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->yo = User::factory()->create(['name' => 'Quien mira']);
        $this->actingAs($this->yo);
    }

    public function test_it_is_behind_the_session(): void
    {
        auth()->logout();

        $this->get('/users')->assertRedirect('/login');
    }

    public function test_the_page_renders_with_its_layout(): void
    {
        // Petición de verdad y no sólo `Livewire::test`: el enlace nuevo de la
        // barra lateral vive en el layout, que un test de componente no pinta.
        $this->get('/users')
            ->assertOk()
            ->assertSee('Usuarios')
            ->assertSee('Copias de seguridad');
    }

    // --- Alta y edición -----------------------------------------------------

    public function test_it_creates_a_user_that_can_log_in(): void
    {
        Livewire::test('users')
            ->call('create')
            ->set('name', 'Alberto')
            ->set('last_name', 'Ceballos')
            ->set('email', 'alberto@panel.local')
            ->set('password', 'una-contraseña-larga')
            ->set('password_confirmation', 'una-contraseña-larga')
            ->set('role', PermissionCatalog::ROLE_ADMIN)
            ->call('save')
            ->assertHasNoErrors();

        $creado = User::where('email', 'alberto@panel.local')->sole();

        $this->assertSame('Ceballos', $creado->last_name);

        // La cuenta sirve de verdad, que es el único motivo de crearla.
        auth()->logout();
        $this->assertTrue(Auth::attempt([
            'email' => 'alberto@panel.local',
            'password' => 'una-contraseña-larga',
        ]));
    }

    public function test_the_password_is_never_stored_in_the_clear(): void
    {
        Livewire::test('users')
            ->call('create')
            ->set('name', 'Alberto')
            ->set('last_name', 'Ceballos')
            ->set('email', 'alberto@panel.local')
            ->set('password', 'una-contraseña-larga')
            ->set('password_confirmation', 'una-contraseña-larga')
            ->set('role', PermissionCatalog::ROLE_ADMIN)
            ->call('save')
            ->assertHasNoErrors();

        $hash = User::where('email', 'alberto@panel.local')->sole()->password;

        // Y una sola vez: si el componente hasheara además del cast del modelo,
        // el hash del hash no dejaría entrar a nadie.
        $this->assertNotSame('una-contraseña-larga', $hash);
        $this->assertTrue(Hash::check('una-contraseña-larga', $hash));
    }

    public function test_the_last_name_is_required(): void
    {
        Livewire::test('users')
            ->call('create')
            ->set('name', 'Sin apellido')
            ->set('email', 'solo@panel.local')
            ->set('password', 'una-contraseña-larga')
            ->set('password_confirmation', 'una-contraseña-larga')
            ->set('role', PermissionCatalog::ROLE_ADMIN)
            ->call('save')
            ->assertHasErrors(['last_name' => 'required']);

        $this->assertSame(1, User::count());
    }

    public function test_editing_an_old_account_demands_the_missing_last_name(): void
    {
        // La columna es nullable por las cuentas anteriores a ella, pero el
        // formulario exige lo que debería haber: consecuencia aceptada el
        // 14/08/2026 al hacerlo obligatorio.
        $antigua = User::factory()->create(['last_name' => null]);

        Livewire::test('users')
            ->call('edit', $antigua->id)
            ->assertSet('last_name', '')
            ->call('save')
            ->assertHasErrors(['last_name' => 'required']);
    }

    public function test_editing_without_a_password_leaves_the_old_one(): void
    {
        $usuario = User::factory()->create([
            'email' => 'antiguo@panel.local',
            'password' => 'la-de-siempre',
        ]);

        Livewire::test('users')
            ->call('edit', $usuario->id)
            // El formulario nunca trae la contraseña de la base: es un hash.
            ->assertSet('password', '')
            ->set('email', 'nuevo@panel.local')
            ->call('save')
            ->assertHasNoErrors();

        $usuario->refresh();

        $this->assertSame('nuevo@panel.local', $usuario->email);
        $this->assertTrue(Hash::check('la-de-siempre', $usuario->password));
    }

    public function test_editing_with_a_password_replaces_it(): void
    {
        $usuario = User::factory()->create(['password' => 'la-de-siempre']);

        Livewire::test('users')
            ->call('edit', $usuario->id)
            ->set('password', 'la-nueva-larga')
            ->set('password_confirmation', 'la-nueva-larga')
            ->set('role', PermissionCatalog::ROLE_ADMIN)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('la-nueva-larga', $usuario->refresh()->password));
    }

    public function test_it_edits_without_creating_a_second_one(): void
    {
        $usuario = User::factory()->create(['name' => 'Antes']);

        Livewire::test('users')
            ->call('edit', $usuario->id)
            ->set('name', 'Después')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, User::count());
        $this->assertSame('Después', $usuario->refresh()->name);
    }

    // --- Validación ----------------------------------------------------------

    public function test_validation_requires_the_whole_form_on_create(): void
    {
        Livewire::test('users')
            ->call('create')
            ->call('save')
            ->assertHasErrors([
                'name' => 'required',
                'last_name' => 'required',
                'email' => 'required',
                'password' => 'required',
            ]);
    }

    public function test_the_messages_are_in_spanish(): void
    {
        // El panel está entero en castellano: un «The password field
        // confirmation does not match» delata que falta la línea en
        // `lang/es/validation.php`.
        $errores = Livewire::test('users')
            ->call('create')
            ->set('password', 'corta')
            ->set('password_confirmation', 'otra')
            ->set('role', PermissionCatalog::ROLE_ADMIN)
            ->call('save')
            ->errors();

        $this->assertSame('El campo nombre es obligatorio.', $errores->first('name'));
        $this->assertSame('El campo apellido es obligatorio.', $errores->first('last_name'));
        $this->assertSame('El campo contraseña y su repetición no coinciden.', $errores->first('password'));
    }

    public function test_the_password_has_to_be_confirmed(): void
    {
        Livewire::test('users')
            ->call('create')
            ->set('name', 'Alberto')
            ->set('last_name', 'Ceballos')
            ->set('email', 'alberto@panel.local')
            ->set('password', 'una-contraseña-larga')
            ->set('password_confirmation', 'otra-cosa')
            ->set('role', PermissionCatalog::ROLE_ADMIN)
            ->call('save')
            ->assertHasErrors(['password' => 'confirmed']);
    }

    public function test_a_short_password_is_rejected(): void
    {
        Livewire::test('users')
            ->call('create')
            ->set('name', 'Alberto')
            ->set('last_name', 'Ceballos')
            ->set('email', 'alberto@panel.local')
            ->set('password', 'corta')
            ->set('password_confirmation', 'corta')
            ->set('role', PermissionCatalog::ROLE_ADMIN)
            ->call('save')
            ->assertHasErrors(['password']);

        $this->assertSame(1, User::count());
    }

    public function test_the_email_cannot_be_repeated(): void
    {
        User::factory()->create(['email' => 'ocupado@panel.local']);

        Livewire::test('users')
            ->call('create')
            ->set('name', 'Alberto')
            ->set('last_name', 'Ceballos')
            ->set('email', 'ocupado@panel.local')
            ->set('password', 'una-contraseña-larga')
            ->set('password_confirmation', 'una-contraseña-larga')
            ->set('role', PermissionCatalog::ROLE_ADMIN)
            ->call('save')
            ->assertHasErrors(['email' => 'unique']);
    }

    public function test_the_email_of_a_deleted_user_is_freed(): void
    {
        User::factory()->create(['email' => 'sevuelve@panel.local'])->delete();

        // El índice de la base es parcial (§4): dar de baja libera el correo.
        Livewire::test('users')
            ->call('create')
            ->set('name', 'Quien hereda la cuenta')
            ->set('last_name', 'Del que se fue')
            ->set('email', 'sevuelve@panel.local')
            ->set('password', 'una-contraseña-larga')
            ->set('password_confirmation', 'una-contraseña-larga')
            ->set('role', PermissionCatalog::ROLE_ADMIN)
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_editing_does_not_clash_with_itself(): void
    {
        $usuario = User::factory()->create(['email' => 'suyo@panel.local']);

        Livewire::test('users')
            ->call('edit', $usuario->id)
            ->set('name', 'Otro nombre')
            ->call('save')
            ->assertHasNoErrors();
    }

    // --- Baja y reactivación -------------------------------------------------

    public function test_it_deletes_and_restores_a_user(): void
    {
        $usuario = User::factory()->create();

        Livewire::test('users')->call('delete', $usuario->id);
        $this->assertSoftDeleted($usuario);

        Livewire::test('users')->call('restore', $usuario->id);
        $this->assertNull($usuario->refresh()->deleted_at);
    }

    public function test_you_cannot_delete_yourself(): void
    {
        Livewire::test('users')
            ->call('delete', $this->yo->id)
            ->assertDispatched('toast', type: 'error',
                message: 'No puedes darte de baja a ti mismo. Que lo haga otro usuario.');

        $this->assertNotSoftDeleted($this->yo);
    }

    public function test_your_own_row_has_no_delete_button(): void
    {
        // Ofrecer el botón para luego negarlo es una trampa.
        Livewire::test('users')->assertDontSee('confirmDelete('.$this->yo->id.')');
    }

    public function test_the_panel_never_runs_out_of_users(): void
    {
        // No hace falta un guardia aparte: si sólo queda una cuenta, esa cuenta
        // es la de quien está mirando, y la suya no se puede dar de baja.
        Livewire::test('users')
            ->call('delete', $this->yo->id)
            ->assertDispatched('toast', type: 'error',
                message: 'No puedes darte de baja a ti mismo. Que lo haga otro usuario.');

        $this->assertSame(1, User::count());
    }

    public function test_the_guard_is_the_screens_and_not_the_models(): void
    {
        // «A ti mismo» sólo significa algo habiendo sesión: un seeder o una
        // limpieza en tinker tienen que poder borrar a quien haga falta.
        $this->yo->delete();

        $this->assertSoftDeleted($this->yo);
    }

    // --- Listado --------------------------------------------------------------

    public function test_it_searches_by_name_last_name_and_email(): void
    {
        User::factory()->create(['name' => 'Alberto', 'last_name' => 'Ceballos', 'email' => 'ac@panel.local']);
        User::factory()->create(['name' => 'Otra', 'last_name' => 'Persona', 'email' => 'op@panel.local']);

        Livewire::test('users')
            ->set('search', 'Ceballos')
            ->assertSee('Alberto Ceballos')
            ->assertDontSee('Otra Persona');

        Livewire::test('users')
            ->set('search', 'op@panel')
            ->assertSee('Otra Persona')
            ->assertDontSee('Alberto Ceballos');
    }

    public function test_deleted_users_are_hidden_until_asked_for(): void
    {
        User::factory()->create(['name' => 'Se fue', 'last_name' => 'De aquí'])->delete();

        Livewire::test('users')
            ->assertDontSee('Se fue De aquí')
            ->set('showingTrashed', true)
            ->assertSee('Se fue De aquí');
    }

    public function test_the_listing_shows_the_full_name(): void
    {
        User::factory()->create(['name' => 'Alberto', 'last_name' => 'Ceballos']);

        // Y aguanta el que no tiene apellido, sin dejar el espacio colgando.
        User::factory()->create(['name' => 'Escueto', 'last_name' => null]);

        Livewire::test('users')
            ->assertSee('Alberto Ceballos')
            ->assertSee('Escueto');
    }

    // --- Historial -------------------------------------------------------------

    public function test_the_password_never_reaches_the_audit_log(): void
    {
        Livewire::test('users')
            ->call('create')
            ->set('name', 'Alberto')
            ->set('last_name', 'Ceballos')
            ->set('email', 'alberto@panel.local')
            ->set('password', 'una-contraseña-larga')
            ->set('password_confirmation', 'una-contraseña-larga')
            ->set('role', PermissionCatalog::ROLE_ADMIN)
            ->call('save')
            ->assertHasNoErrors();

        // Un alta deja dos entradas desde la fase 12: la del usuario y la del
        // rol, que vive en la tabla pivote y no cabe en el volcado del modelo.
        $alta = User::where('email', 'alberto@panel.local')->sole()
            ->auditLogs()->where('action', AuditAction::Create)->sole();

        // Ni en claro ni hasheada: el historial se lee entero en pantalla.
        $this->assertArrayNotHasKey('password', $alta->after);
        $this->assertStringNotContainsString('una-contraseña-larga', json_encode($alta->after));
    }

    // --- El rol de la cuenta (§7, fase 12) -----------------------------------

    public function test_a_new_account_is_created_with_the_role_it_was_given(): void
    {
        Livewire::test('users')
            ->call('create')
            ->set('name', 'Freddy')
            ->set('last_name', 'GLS')
            ->set('email', 'freddy@panel.local')
            ->set('password', 'una-contraseña-larga')
            ->set('password_confirmation', 'una-contraseña-larga')
            ->set('role', PermissionCatalog::ROLE_OPERATIONS)
            ->call('save')
            ->assertHasNoErrors();

        $creado = User::where('email', 'freddy@panel.local')->sole();

        $this->assertSame(PermissionCatalog::ROLE_OPERATIONS, $creado->roleName());
        $this->assertTrue($creado->can('merchants.manage'));
        $this->assertFalse($creado->can('backups.manage'));
    }

    public function test_the_role_is_required(): void
    {
        Livewire::test('users')
            ->call('create')
            ->set('name', 'Sin rol')
            ->set('last_name', 'Ninguno')
            ->set('email', 'sinrol@panel.local')
            ->set('password', 'una-contraseña-larga')
            ->set('password_confirmation', 'una-contraseña-larga')
            ->call('save')
            ->assertHasErrors(['role' => 'required']);

        $this->assertSame(1, User::count());
    }

    public function test_an_invented_role_does_not_get_through(): void
    {
        Livewire::test('users')
            ->call('create')
            ->set('name', 'Listillo')
            ->set('last_name', 'Del Panel')
            ->set('email', 'listillo@panel.local')
            ->set('password', 'una-contraseña-larga')
            ->set('password_confirmation', 'una-contraseña-larga')
            ->set('role', 'Dios')
            ->call('save')
            ->assertHasErrors(['role']);
    }

    public function test_changing_the_role_is_written_in_the_history(): void
    {
        // El rol vive en la tabla pivote del paquete, así que los eventos de
        // Eloquent no lo ven: sin `recordRoleChange()`, quién le dio el
        // Administrador a quién no quedaría escrito en ninguna parte (§4).
        $otro = User::factory()->role(PermissionCatalog::ROLE_OPERATIONS)->create();

        Livewire::test('users')
            ->call('edit', $otro->id)
            ->set('role', PermissionCatalog::ROLE_ADMIN)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(PermissionCatalog::ROLE_ADMIN, $otro->refresh()->roleName());

        $entrada = $otro->auditLogs()->where('action', AuditAction::Update)->sole();

        $this->assertSame(['role' => PermissionCatalog::ROLE_OPERATIONS], $entrada->before);
        $this->assertSame(['role' => PermissionCatalog::ROLE_ADMIN], $entrada->after);
    }

    public function test_you_cannot_take_your_own_administrator_away(): void
    {
        // Por lo mismo que no puedes darte de baja: es quedarte fuera de tu
        // propio panel, y sin poder volver a entrar a arreglarlo.
        Livewire::test('users')
            ->call('edit', $this->yo->id)
            ->set('role', PermissionCatalog::ROLE_OPERATIONS)
            ->call('save');

        $this->assertSame(PermissionCatalog::ROLE_ADMIN, $this->yo->refresh()->roleName());
    }

    // --- Al Administrador sólo lo reparte y lo toca un Administrador ---------
    //
    // Sin estas reglas `users.manage` **es** el rol de Administrador: quien
    // gestiona cuentas se crea una con ese rol —o le cambia la contraseña a la
    // que ya lo tiene— y sale con las copias, que son la base del cliente (§10).
    // Hoy sólo el Administrador lleva ese permiso; esto existe porque desde la
    // fase 12 los roles los crea el cliente.

    /** Una cuenta que gestiona usuarios sin ser Administrador: el rol a medida del cliente. */
    private function gestorDeCuentas(): User
    {
        $usuario = User::factory()->withoutRole()->create(['name' => 'Gestor']);
        $usuario->givePermissionTo('users.view', 'users.manage');

        return $usuario;
    }

    public function test_you_cannot_change_your_own_role(): void
    {
        // Antes esto sólo cubría **quitarte** el Administrador. El sentido
        // contrario —ponértelo— es por donde se escalaba.
        $gestor = $this->gestorDeCuentas();
        $this->actingAs($gestor);

        Livewire::test('users')
            ->call('edit', $gestor->id)
            ->set('role', PermissionCatalog::ROLE_OPERATIONS)
            ->call('save');

        $this->assertNull($gestor->refresh()->roleName());
    }

    public function test_only_an_administrator_hands_out_the_administrator_role(): void
    {
        $this->actingAs($this->gestorDeCuentas());

        Livewire::test('users')
            ->call('create')
            ->set('name', 'Puerta')
            ->set('last_name', 'Trasera')
            ->set('email', 'puerta@panel.local')
            ->set('password', 'una-contraseña-larga')
            ->set('password_confirmation', 'una-contraseña-larga')
            ->set('role', PermissionCatalog::ROLE_ADMIN)
            ->call('save');

        $this->assertNull(User::where('email', 'puerta@panel.local')->first());
    }

    public function test_only_an_administrator_promotes_someone_to_administrator(): void
    {
        $this->actingAs($this->gestorDeCuentas());
        $otro = User::factory()->role(PermissionCatalog::ROLE_OPERATIONS)->create();

        Livewire::test('users')
            ->call('edit', $otro->id)
            ->set('role', PermissionCatalog::ROLE_ADMIN)
            ->call('save');

        $this->assertSame(PermissionCatalog::ROLE_OPERATIONS, $otro->refresh()->roleName());
    }

    /** Cambiarle la contraseña a un Administrador es entrar con su cuenta. */
    public function test_only_an_administrator_edits_another_administrator(): void
    {
        $this->actingAs($this->gestorDeCuentas());

        Livewire::test('users')
            ->call('edit', $this->yo->id)
            ->set('password', 'la-que-yo-elija')
            ->set('password_confirmation', 'la-que-yo-elija')
            ->call('save');

        $this->assertTrue(Hash::check('password', $this->yo->refresh()->password));
    }

    public function test_only_an_administrator_deletes_or_restores_another_administrator(): void
    {
        $this->actingAs($this->gestorDeCuentas());

        Livewire::test('users')->call('delete', $this->yo->id);
        $this->assertNotSoftDeleted($this->yo->refresh());

        $this->yo->delete();

        Livewire::test('users')->call('restore', $this->yo->id);
        $this->assertSoftDeleted($this->yo->refresh());
    }

    public function test_the_row_of_an_administrator_offers_nothing_to_someone_who_is_not(): void
    {
        // Esconder y no enseñar apagado (§7, fase 12, decisión 3).
        $this->actingAs($this->gestorDeCuentas());

        Livewire::test('users')
            ->assertDontSee('edit('.$this->yo->id.')')
            ->assertDontSee('confirmDelete('.$this->yo->id.')');
    }

    /** Y un Administrador sigue haciendo su trabajo, que es lo que no se puede romper. */
    public function test_an_administrator_still_hands_out_the_role(): void
    {
        $otro = User::factory()->role(PermissionCatalog::ROLE_OPERATIONS)->create();

        Livewire::test('users')
            ->call('edit', $otro->id)
            ->set('role', PermissionCatalog::ROLE_ADMIN)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(PermissionCatalog::ROLE_ADMIN, $otro->refresh()->roleName());
    }

    public function test_the_listing_says_who_is_what(): void
    {
        User::factory()->role(PermissionCatalog::ROLE_OPERATIONS)->create(['name' => 'Freddy']);

        Livewire::test('users')
            ->assertSee(PermissionCatalog::ROLE_ADMIN)
            ->assertSee(PermissionCatalog::ROLE_OPERATIONS);
    }
}
