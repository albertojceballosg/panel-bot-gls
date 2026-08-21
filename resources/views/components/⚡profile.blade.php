<?php

use App\Models\User;
use App\Support\SendsToasts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Mi cuenta (CONTEXTO.md §7, fase 10).
 *
 * Es la pantalla de uno mismo, no de administración: se llega desde el menú de
 * la cabecera y **siempre opera sobre `auth()->user()`**, nunca sobre un id que
 * llegue del cliente. Sin eso sería `/users` con otro nombre y con menos
 * comprobaciones.
 *
 * **El correo no se cambia aquí.** Es la credencial con la que se entra: quien
 * lo toca se está cambiando el usuario de acceso, y eso pasa por el maestro de
 * cuentas —`/users`— para que quede como lo que es, un cambio administrativo con
 * su fila en el historial. En esta pantalla se enseña, deshabilitado, porque
 * saber con qué correo has entrado sí es asunto de tu perfil.
 *
 * **Dos formularios y no uno.** Cambiar el nombre y cambiar la contraseña son
 * dos gestos distintos, con distinto riesgo: juntarlos obligaría a escribir la
 * contraseña actual para corregir una tilde del apellido.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    use SendsToasts;

    public string $name = '';

    public string $last_name = '';

    /** La actual, para autorizar el cambio. Nunca se guarda. */
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $this->name = $this->user()->name;
        $this->last_name = $this->user()->last_name ?? '';
    }

    /** Siempre el de la sesión: aquí no hay id que valga. */
    private function user(): User
    {
        return auth()->user();
    }

    public function saveProfile(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
        ]);

        $this->user()->forceFill([
            'name' => $this->name,
            'last_name' => $this->last_name,
        ])->save();

        $this->toast('Perfil actualizado.');
    }

    /**
     * La contraseña actual se pide **aunque la sesión ya esté abierta**: un
     * portátil desatendido bastaría para que otro se quedase con la cuenta, y
     * ese es justo el descuido contra el que sirve.
     *
     * `current_password` es la regla de Laravel: comprueba contra el hash del
     * usuario autenticado, así que no hay que llamar a `Hash::check` a mano ni
     * arriesgarse a compararla mal.
     */
    public function savePassword(): void
    {
        $this->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', Password::min(8), Rule::notIn([$this->current_password])],
        ], [
            // La genérica sería «El valor de contraseña no es válido», que no
            // dice qué pasa.
            'password.not_in' => 'La contraseña nueva tiene que ser distinta de la actual.',
        ]);

        // El cast `hashed` del modelo la cifra: nada de Hash::make aquí, que la
        // hashearía dos veces.
        $this->user()->forceFill(['password' => $this->password])->save();

        $this->closeOtherSessions();

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->toast('Contraseña cambiada. Se han cerrado las demás sesiones de tu cuenta.');
    }

    /**
     * Cierra las sesiones abiertas de esta cuenta que no sean ésta.
     *
     * Sin esto, cambiar la contraseña no echaba a nadie: quien ya estuviera dentro seguía
     * dentro, que contradice el motivo por el que se pide la contraseña actual —el portátil
     * desatendido, arriba—. Cambiarla es justo lo que se hace cuando se sospecha que otro
     * entró, y hasta ahora no servía para echarlo.
     *
     * A mano y no con `Auth::logoutOtherDevices()`: ése re-*hashea* la contraseña y sólo surte
     * efecto con el *middleware* `AuthenticateSession` puesto en todo el panel, que echaría
     * gente por motivos que no son éste. Aquí las sesiones viven en la base
     * (`SESSION_DRIVER=database`, §6), así que se borran las filas y ya está: es lo mismo que
     * hace la restauración de una copia, que se lleva la tabla entera por delante.
     */
    private function closeOtherSessions(): void
    {
        DB::table('sessions')
            ->where('user_id', $this->user()->getKey())
            ->where('id', '!=', session()->getId())
            ->delete();
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        return ['usuario' => $this->user()];
    }
}; ?>

<div>
    <x-ui.page-header title="Mi perfil"
                      description="Tus datos y tu contraseña. Para cambiar el correo o dar de alta a alguien, ve a Usuarios." />

    {{-- El «guardado» no va aquí: sale como aviso flotante desde el layout
         (`ui/toasts`), para no empujar el formulario hacia abajo. --}}
    <div class="space-y-4">
        <x-ui.card>
            <h2 class="text-sm font-semibold text-shell-900">Datos</h2>
            <p class="mt-0.5 text-sm text-slate-500">Es el nombre con el que firmas los cambios en el historial.</p>

            <form wire:submit="saveProfile" class="mt-4 space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field label="Nombre" for="name" :error="$errors->first('name')">
                        <x-ui.input wire:model="name" id="name" :invalid="$errors->has('name')" />
                    </x-ui.field>

                    <x-ui.field label="Apellido" for="last_name" :error="$errors->first('last_name')">
                        <x-ui.input wire:model="last_name" id="last_name" :invalid="$errors->has('last_name')" />
                    </x-ui.field>
                </div>

                {{-- Se enseña porque saber con qué correo has entrado es asunto
                     de tu perfil; deshabilitado porque cambiarlo es cambiarse la
                     credencial de acceso, y eso va por el maestro de cuentas. --}}
                <x-ui.field label="Correo" for="email"
                            hint="Es con lo que entras al panel. No se cambia desde aquí: pídelo en Usuarios.">
                    <x-ui.input id="email" type="email" value="{{ $usuario->email }}" disabled
                                class="cursor-not-allowed bg-slate-50 text-slate-500" />
                </x-ui.field>

                <div class="flex justify-end">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveProfile">
                        <span wire:loading.remove wire:target="saveProfile">Guardar</span>
                        <span wire:loading wire:target="saveProfile">Guardando…</span>
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-sm font-semibold text-shell-900">Contraseña</h2>
            <p class="mt-0.5 text-sm text-slate-500">
                Se pide la actual aunque tengas la sesión abierta: es lo que evita que quien
                se siente en tu sitio se quede con la cuenta.
            </p>

            <form wire:submit="savePassword" class="mt-4 space-y-4">
                <x-ui.field label="Contraseña actual" for="current_password"
                            :error="$errors->first('current_password')">
                    <x-ui.input wire:model="current_password" id="current_password" type="password"
                                :invalid="$errors->has('current_password')" autocomplete="current-password" />
                </x-ui.field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field label="Nueva contraseña" for="password" :error="$errors->first('password')"
                                hint="Mínimo 8 caracteres.">
                        <x-ui.input wire:model="password" id="password" type="password"
                                    :invalid="$errors->has('password')" autocomplete="new-password" />
                    </x-ui.field>

                    <x-ui.field label="Repite la nueva" for="password_confirmation">
                        <x-ui.input wire:model="password_confirmation" id="password_confirmation" type="password"
                                    :invalid="$errors->has('password')" autocomplete="new-password" />
                    </x-ui.field>
                </div>

                <div class="flex justify-end">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="savePassword">
                        <span wire:loading.remove wire:target="savePassword">Cambiar la contraseña</span>
                        <span wire:loading wire:target="savePassword">Cambiando…</span>
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
