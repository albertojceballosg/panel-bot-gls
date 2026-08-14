<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/** Mi perfil (CONTEXTO.md §7, fase 10). */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $yo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->yo = User::factory()->create([
            'name' => 'Alberto',
            'last_name' => 'Ceballos',
            'email' => 'alberto@panel.local',
            'password' => 'la-de-siempre',
        ]);

        $this->actingAs($this->yo);
    }

    public function test_it_is_behind_the_session(): void
    {
        auth()->logout();

        $this->get('/profile')->assertRedirect('/login');
    }

    public function test_it_opens_with_your_own_data(): void
    {
        Livewire::test('profile')
            ->assertSet('name', 'Alberto')
            ->assertSet('last_name', 'Ceballos')
            // El correo se enseña, aunque no se pueda tocar.
            ->assertSee('alberto@panel.local');
    }

    public function test_the_account_menu_links_to_it(): void
    {
        // Se llega desde la cabecera y no desde la barra lateral: es «mi
        // cuenta», no un módulo del maestro.
        $this->get('/')->assertOk()->assertSee('Mi perfil');
    }

    // --- Datos ----------------------------------------------------------------

    public function test_it_changes_your_name_and_last_name(): void
    {
        Livewire::test('profile')
            ->set('name', 'Alberto José')
            ->set('last_name', 'Ceballos G.')
            ->call('saveProfile')
            ->assertHasNoErrors()
            // El «guardado» sale como aviso flotante (`ui/toasts`), no como un
            // alert que empuje el formulario hacia abajo.
            ->assertDispatched('toast', type: 'success', message: 'Perfil actualizado.');

        $this->yo->refresh();

        $this->assertSame('Alberto José', $this->yo->name);
        $this->assertSame('Ceballos G.', $this->yo->last_name);
    }

    public function test_a_failed_save_does_not_announce_success(): void
    {
        Livewire::test('profile')
            ->set('name', '')
            ->call('saveProfile')
            ->assertNotDispatched('toast');
    }

    public function test_the_layout_carries_the_toast_container(): void
    {
        // Vive una sola vez en el layout: sin él, el `dispatch` no lo recoge
        // nadie y el usuario guarda sin ver confirmación ninguna.
        $this->get('/profile')->assertOk()->assertSee('x-on:toast.window', escape: false);
    }

    public function test_the_name_and_the_last_name_are_required(): void
    {
        Livewire::test('profile')
            ->set('name', '')
            ->set('last_name', '')
            ->call('saveProfile')
            ->assertHasErrors(['name' => 'required', 'last_name' => 'required']);
    }

    public function test_the_email_cannot_be_changed_from_here(): void
    {
        // No es que el formulario lo esconda: es que el componente no tiene
        // dónde recibirlo. Cambiar la credencial de acceso va por `/users`.
        $componente = Livewire::test('profile');

        $this->assertFalse(property_exists($componente->instance(), 'email'));

        $componente->set('name', 'Otro')->call('saveProfile')->assertHasNoErrors();

        $this->assertSame('alberto@panel.local', $this->yo->refresh()->email);
    }

    public function test_changing_the_profile_is_recorded_in_the_history(): void
    {
        Livewire::test('profile')->set('name', 'Alberto José')->call('saveProfile');

        // `User` es Auditable: el cambio queda firmado como cualquier otro (§4).
        $cambio = $this->yo->auditLogs()->first();

        $this->assertSame('Alberto José', $cambio->after['name']);
        $this->assertArrayNotHasKey('password', $cambio->after);
    }

    // --- Contraseña ------------------------------------------------------------

    public function test_it_changes_the_password(): void
    {
        Livewire::test('profile')
            ->set('current_password', 'la-de-siempre')
            ->set('password', 'la-nueva-larga')
            ->set('password_confirmation', 'la-nueva-larga')
            ->call('savePassword')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: 'Contraseña cambiada.')
            // No se queda escrita en el formulario después de guardar.
            ->assertSet('current_password', '')
            ->assertSet('password', '');

        auth()->logout();

        $this->assertTrue(Auth::attempt([
            'email' => 'alberto@panel.local',
            'password' => 'la-nueva-larga',
        ]));
    }

    public function test_it_is_stored_hashed_and_only_once(): void
    {
        Livewire::test('profile')
            ->set('current_password', 'la-de-siempre')
            ->set('password', 'la-nueva-larga')
            ->set('password_confirmation', 'la-nueva-larga')
            ->call('savePassword');

        $hash = $this->yo->refresh()->password;

        $this->assertNotSame('la-nueva-larga', $hash);
        $this->assertTrue(Hash::check('la-nueva-larga', $hash));
    }

    public function test_the_current_password_is_required_even_with_the_session_open(): void
    {
        // Un portátil desatendido bastaría para quedarse con la cuenta.
        Livewire::test('profile')
            ->set('password', 'la-nueva-larga')
            ->set('password_confirmation', 'la-nueva-larga')
            ->call('savePassword')
            ->assertHasErrors(['current_password' => 'required']);

        $this->assertTrue(Hash::check('la-de-siempre', $this->yo->refresh()->password));
    }

    public function test_a_wrong_current_password_changes_nothing(): void
    {
        $errores = Livewire::test('profile')
            ->set('current_password', 'no-es-esta')
            ->set('password', 'la-nueva-larga')
            ->set('password_confirmation', 'la-nueva-larga')
            ->call('savePassword')
            ->assertHasErrors(['current_password'])
            ->errors();

        $this->assertSame('La contraseña actual no es correcta.', $errores->first('current_password'));
        $this->assertTrue(Hash::check('la-de-siempre', $this->yo->refresh()->password));
    }

    public function test_the_new_password_has_to_be_confirmed_and_long_enough(): void
    {
        Livewire::test('profile')
            ->set('current_password', 'la-de-siempre')
            ->set('password', 'corta')
            ->set('password_confirmation', 'otra')
            ->call('savePassword')
            ->assertHasErrors(['password']);

        $this->assertTrue(Hash::check('la-de-siempre', $this->yo->refresh()->password));
    }

    public function test_the_new_password_cannot_be_the_old_one(): void
    {
        Livewire::test('profile')
            ->set('current_password', 'la-de-siempre')
            ->set('password', 'la-de-siempre')
            ->set('password_confirmation', 'la-de-siempre')
            ->call('savePassword')
            ->assertHasErrors(['password']);
    }

    public function test_the_password_does_not_reach_the_history(): void
    {
        Livewire::test('profile')
            ->set('current_password', 'la-de-siempre')
            ->set('password', 'la-nueva-larga')
            ->set('password_confirmation', 'la-nueva-larga')
            ->call('savePassword');

        // El historial se lee entero en pantalla: ni en claro ni hasheada.
        foreach ($this->yo->auditLogs as $entrada) {
            $this->assertStringNotContainsString('la-nueva-larga', json_encode($entrada->after));
            $this->assertArrayNotHasKey('password', $entrada->after ?? []);
        }
    }

    public function test_it_only_ever_touches_your_own_account(): void
    {
        $otro = User::factory()->create(['name' => 'Otra persona']);

        Livewire::test('profile')->set('name', 'Alberto José')->call('saveProfile');

        $this->assertSame('Otra persona', $otro->refresh()->name);
    }
}
