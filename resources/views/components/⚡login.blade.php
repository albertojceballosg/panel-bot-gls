<?php

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
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate();

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
        <h1 class="text-xl font-semibold tracking-tight text-slate-900">{{ config('app.name') }}</h1>
        <p class="mt-1 text-sm text-slate-500">Maestro de rutas de recogida</p>
    </div>

    <form wire:submit="login" class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <div>
            <label for="email" class="block text-sm font-medium text-slate-700">Correo</label>
            <input wire:model="email" id="email" type="email" autocomplete="username" autofocus
                   class="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm
                          focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none">
            @error('email')
                <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="mt-4">
            <label for="password" class="block text-sm font-medium text-slate-700">Contraseña</label>
            <input wire:model="password" id="password" type="password" autocomplete="current-password"
                   class="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm
                          focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none">
            @error('password')
                <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <label class="mt-4 flex items-center gap-2 text-sm text-slate-600">
            <input wire:model="remember" type="checkbox"
                   class="size-4 rounded border-slate-300 text-slate-900 focus:ring-slate-900">
            Mantener la sesión abierta
        </label>

        <button type="submit"
                class="mt-6 w-full rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white transition
                       hover:bg-slate-800 focus:ring-2 focus:ring-slate-900 focus:ring-offset-2 focus:outline-none
                       disabled:opacity-60">
            <span wire:loading.remove wire:target="login">Entrar</span>
            <span wire:loading wire:target="login">Entrando…</span>
        </button>
    </form>
</div>
