<?php

namespace Tests\Feature;

use App\Models\User;
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
            ->call('save')
            ->assertHasNoErrors();

        $alta = User::where('email', 'alberto@panel.local')->sole()->auditLogs()->sole();

        // Ni en claro ni hasheada: el historial se lee entero en pantalla.
        $this->assertArrayNotHasKey('password', $alta->after);
        $this->assertStringNotContainsString('una-contraseña-larga', json_encode($alta->after));
    }
}
