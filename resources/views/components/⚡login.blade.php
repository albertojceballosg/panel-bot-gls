<?php

use App\Exceptions\DoubleSubmitException;
use App\Support\PreventsDoubleSubmit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Entrada al panel (CONTEXTO.md §7, fase 3).
 *
 * No hay registro público ni recuperación de contraseña: son ~5 usuarios
 * internos y las cuentas las crea `InitialUserSeeder` desde el `.env` (§10).
 * Volver a pasar el seeder con otra clave es la forma de recuperar el acceso.
 */
new #[Layout('components.layouts.guest')] class extends Component
{
    use PreventsDoubleSubmit;

    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        // Validación en el servidor, antes que nada.
        $this->validate();

        try {
            $this->withoutDoubleSubmit('login', fn () => $this->attempt());
        } catch (DoubleSubmitException) {
            // El primer envío ya está comprobando estas credenciales. Sin esto,
            // un doble clic gastaría dos de los cinco intentos permitidos.
            return;
        }
    }

    protected function attempt(): void
    {
        $this->ensureNotThrottled();

        // Un usuario dado de baja no entra: el proveedor de Eloquent consulta
        // el modelo y arrastra el scope de SoftDeletes (§4).
        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            // Un solo mensaje para "no existe" y "contraseña incorrecta": decir
            // cuál de los dos falla confirma qué correos tienen cuenta.
            throw ValidationException::withMessages([
                'email' => 'Esas credenciales no son correctas.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        // Contra la fijación de sesión: el id de sesión de antes de entrar no
        // puede seguir siendo válido después.
        session()->regenerate();

        $this->redirectIntended(route('home'), navigate: true);
    }

    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'email.required' => 'Escribe tu correo.',
            'email.email' => 'Eso no parece un correo.',
            'password.required' => 'Escribe tu contraseña.',
        ];
    }

    /**
     * Sin esto, el formulario es una puerta abierta a probar contraseñas a
     * ritmo de máquina. Cinco intentos por minuto y correo.
     */
    protected function ensureNotThrottled(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), maxAttempts: 5)) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => sprintf(
                'Demasiados intentos. Prueba de nuevo en %d segundos.',
                RateLimiter::availableIn($this->throttleKey()),
            ),
        ]);
    }

    /** Por correo e IP, para que nadie pueda bloquear la cuenta de otro. */
    protected function throttleKey(): string
    {
        return 'login|'.mb_strtolower($this->email).'|'.request()->ip();
    }
}; ?>

<div class="w-full max-w-sm">
    <div class="mb-8 text-center">
        <div class="mx-auto mb-4 grid size-11 place-items-center rounded-xl bg-brand-500 text-sm font-bold text-white">
            GLS
        </div>
        <h1 class="text-xl font-semibold tracking-tight text-shell-900">{{ config('app.name') }}</h1>
        <p class="mt-1 text-sm text-slate-500">Maestro de rutas de recogida</p>
    </div>

    {{-- Lo que trae quien llega expulsado: hoy, la restauración de una copia,
         que se lleva por delante la sesión (§7, fase 7). --}}
    @if (session('ok'))
        <x-ui.alert type="success" class="mb-4">{{ session('ok') }}</x-ui.alert>
    @endif

    <x-ui.card>
        <form wire:submit="login" class="space-y-4">
            <x-ui.field label="Correo" for="email" :error="$errors->first('email')">
                <x-ui.input wire:model="email" id="email" type="email" autocomplete="username"
                            :invalid="$errors->has('email')" autofocus />
            </x-ui.field>

            <x-ui.field label="Contraseña" for="password" :error="$errors->first('password')">
                <x-ui.input wire:model="password" id="password" type="password" autocomplete="current-password"
                            :invalid="$errors->has('password')" />
            </x-ui.field>

            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input wire:model="remember" type="checkbox"
                       class="size-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500">
                Mantener la sesión abierta
            </label>

            {{-- Desactivado mientras `login` está en vuelo. Es la mitad cómoda;
                 la que de verdad impide el doble envío es el cerrojo del
                 servidor, porque el clic puede llegar antes que el JS. --}}
            <x-ui.button type="submit" class="w-full"
                         wire:loading.attr="disabled" wire:target="login">
                <span wire:loading.remove wire:target="login">Entrar</span>
                <span wire:loading wire:target="login">Entrando…</span>
            </x-ui.button>
        </form>
    </x-ui.card>
</div>
