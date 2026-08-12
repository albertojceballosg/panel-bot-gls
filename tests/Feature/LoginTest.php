<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La entrada al panel (CONTEXTO.md §7, fase 3).
 *
 * Sin registro público ni recuperación de contraseña: son ~5 usuarios internos
 * y las cuentas se siembran desde el `.env`.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'una-contraseña-larga';

    private function user(array $atributos = []): User
    {
        return User::factory()->create($atributos + [
            'email' => 'alguien@panel.local',
            'password' => self::PASSWORD,
        ]);
    }

    // --- Acceso -------------------------------------------------------------

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        // No hay ni una pantalla pública.
        $this->get('/')->assertRedirect('/login');
    }

    public function test_the_login_page_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Entrar');
    }

    public function test_someone_already_logged_in_does_not_see_the_login_page(): void
    {
        $this->actingAs($this->user())->get('/login')->assertRedirect('/');
    }

    public function test_the_right_credentials_get_you_in(): void
    {
        $user = $this->user();

        Livewire::test('login')
            ->set('email', 'alguien@panel.local')
            ->set('password', self::PASSWORD)
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_wrong_password_does_not(): void
    {
        $this->user();

        Livewire::test('login')
            ->set('email', 'alguien@panel.local')
            ->set('password', 'no-es-esta')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_error_does_not_reveal_whether_the_account_exists(): void
    {
        $this->user();

        $inexistente = Livewire::test('login')
            ->set('email', 'nadie@panel.local')
            ->set('password', 'lo-que-sea')
            ->call('login')
            ->errors()->first('email');

        $existeMalaClave = Livewire::test('login')
            ->set('email', 'alguien@panel.local')
            ->set('password', 'no-es-esta')
            ->call('login')
            ->errors()->first('email');

        // Si los mensajes difiriesen, el formulario diría qué correos tienen
        // cuenta a quien pruebe uno por uno.
        $this->assertSame($inexistente, $existeMalaClave);
    }

    public function test_the_form_asks_for_both_fields(): void
    {
        Livewire::test('login')->call('login')->assertHasErrors(['email', 'password']);
    }

    // --- Borrado pasivo -----------------------------------------------------

    public function test_a_deleted_user_cannot_get_in(): void
    {
        $this->user()->delete();

        Livewire::test('login')
            ->set('email', 'alguien@panel.local')
            ->set('password', self::PASSWORD)
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    // --- Fuerza bruta -------------------------------------------------------

    public function test_it_stops_after_five_failed_attempts(): void
    {
        $this->user();

        foreach (range(1, 5) as $ignored) {
            Livewire::test('login')
                ->set('email', 'alguien@panel.local')
                ->set('password', 'no-es-esta')
                ->call('login');
        }

        // Y a partir de ahí ni siquiera la buena entra, que es el punto.
        $componente = Livewire::test('login')
            ->set('email', 'alguien@panel.local')
            ->set('password', self::PASSWORD)
            ->call('login');

        $this->assertStringContainsString('Demasiados intentos', $componente->errors()->first('email'));
        $this->assertGuest();
    }

    public function test_getting_in_clears_the_counter(): void
    {
        $this->user();

        Livewire::test('login')
            ->set('email', 'alguien@panel.local')
            ->set('password', 'no-es-esta')
            ->call('login');

        Livewire::test('login')
            ->set('email', 'alguien@panel.local')
            ->set('password', self::PASSWORD)
            ->call('login');

        $this->assertSame(0, RateLimiter::attempts('login|alguien@panel.local|127.0.0.1'));
    }

    // --- Salir ---------------------------------------------------------------

    public function test_logging_out_ends_the_session(): void
    {
        $this->actingAs($this->user());

        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_you_cannot_be_logged_out_with_a_link(): void
    {
        // POST y no GET: un GET lo dispara cualquier enlace de fuera, o lo
        // precarga el navegador.
        $this->actingAs($this->user());

        $this->get('/logout')->assertMethodNotAllowed();
        $this->assertTrue(Auth::check());
    }
}
