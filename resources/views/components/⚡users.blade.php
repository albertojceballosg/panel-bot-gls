<?php

use App\Models\Role;
use App\Models\User;
use App\Support\CrudScreen;
use App\Support\PermissionCatalog;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * CRUD de usuarios del panel (CONTEXTO.md §7, fase 8).
 *
 * No es maestro del cliente: son las cuentas que entran a la aplicación, y por
 * eso cuelga de «Sistema» y no de la lista de arriba.
 *
 * **La contraseña nunca sale de la base.** El formulario la pide en blanco
 * siempre: al editar, dejarla vacía significa «déjala como está». Enseñar la
 * actual es imposible —es un hash— y pedirla en cada cambio de correo invita a
 * poner una floja para salir del paso.
 *
 * **Una cuenta, un rol** (§7, fase 12). El paquete admite varios, pero con dos
 * roles «Administrador + Operaciones» no dice nada que no diga ya
 * «Administrador», y una lista de casillas invita a combinaciones que nadie ha
 * pensado. Tampoco puedes **cambiarte el rol** a ti mismo, por lo mismo que no
 * puedes darte de baja: quitártelo es quedarte fuera de tu propio panel, y
 * ponértelo es dártelo todo sin que nadie lo apruebe. Que lo haga otra cuenta.
 *
 * Y **al Administrador sólo lo reparte y lo toca un Administrador** — ver
 * `amAdministrator()`, que es lo que impide que `users.manage` sea en la
 * práctica el rol de Administrador.
 *
 * **Nadie se da de baja a sí mismo.** Eso va aquí y no en el modelo, a
 * diferencia de las reglas de `PickupRoute`: «a ti mismo» sólo significa algo
 * habiendo sesión, y un seeder o una limpieza en tinker tienen que poder borrar
 * a quien haga falta.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    // El `delete` del trait se renombra para poder envolverlo: es lo mismo que
    // llamar a `parent::delete()`, que con un trait no existe.
    use CrudScreen {
        delete as private deleteRecord;
        restore as private restoreRecord;
    }

    public const POR_PAGINA = 10;

    public string $name = '';

    /**
     * Obligatorio en el formulario, aunque la columna sea nullable: las cuentas
     * anteriores a ella no lo tienen, y editar una de esas obliga a rellenarlo.
     */
    public string $last_name = '';

    public string $email = '';

    /** El rol de la cuenta. Vacío es «todavía no has elegido», y no valida. */
    public string $role = '';

    /** Vacía al editar significa «no la toques». Nunca se rellena desde la base. */
    public string $password = '';

    public string $password_confirmation = '';

    protected function model(): string
    {
        return User::class;
    }

    protected function formFields(): array
    {
        return ['name', 'last_name', 'email', 'role', 'password', 'password_confirmation'];
    }

    protected function fillForm($record): void
    {
        $this->name = $record->name;
        $this->last_name = $record->last_name ?? '';
        $this->email = $record->email;
        $this->role = $record->roleName() ?? '';

        // La contraseña se queda en blanco a propósito: ver la cabecera.
        $this->password = '';
        $this->password_confirmation = '';
    }

    protected function label(): string
    {
        return 'usuario';
    }

    protected function permissionModule(): string
    {
        return 'users';
    }

    public function with(): array
    {
        return [
            // Los roles se traen con el listado: sin esto, pintar la columna
            // sería una consulta por fila.
            'users' => User::query()
                ->with('roles')
                ->when($this->showingTrashed, fn ($q) => $q->withTrashed())
                ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                    ->where('name', 'ilike', $this->likeTerm())
                    ->orWhere('last_name', 'ilike', $this->likeTerm())
                    ->orWhere('email', 'ilike', $this->likeTerm())))
                ->orderBy('name')
                ->orderBy('last_name')
                ->paginate(self::POR_PAGINA),

            // El desplegable sale de la tabla: los dos del catálogo y los que
            // haya creado el cliente en Roles y permisos (§7, fase 12).
            'roles' => Role::orderBy('name')->pluck('name'),
        ];
    }

    /**
     * Darte de baja a ti mismo te echa a la calle en el mismo clic, con la
     * sesión viva hasta que algo la mire. La fila propia ni siquiera enseña el
     * botón; esto es la red por si la llamada llega igual.
     *
     * De paso es lo que garantiza que **siempre quede alguien dentro**: si sólo
     * hay una cuenta, esa cuenta es la tuya, así que no hay forma de cerrar el
     * panel con la llave puesta.
     */
    public function delete(int $id): void
    {
        // El permiso, antes que las dos reglas de abajo: a quien no puede escribir aquí se le
        // niega, no se le explica cómo tendría que hacerlo. `deleteRecord` lo comprueba también
        // —es de `CrudScreen`—, pero para entonces ya se habría contestado con un aviso.
        $this->authorizeManage();

        if ($id === auth()->id()) {
            $this->confirmingDeletion = null;
            $this->toastError('No puedes darte de baja a ti mismo. Que lo haga otro usuario.');

            return;
        }

        // Y al Administrador sólo lo da de baja otro Administrador: ver
        // `amAdministrator()`. Aquí no se gana acceso, se lo quita a quien lo
        // tiene todo, que es la otra mitad del mismo problema.
        if (! $this->amAdministrator()
            && User::withTrashed()->find($id)?->roleName() === PermissionCatalog::ROLE_ADMIN) {
            $this->confirmingDeletion = null;
            $this->toastError('Sólo un Administrador puede dar de baja a otro Administrador.');

            return;
        }

        $this->deleteRecord($id);
    }

    public function restore(int $id): void
    {
        $this->authorizeManage();

        if (! $this->amAdministrator()
            && User::onlyTrashed()->find($id)?->roleName() === PermissionCatalog::ROLE_ADMIN) {
            $this->toastError('Sólo un Administrador puede reactivar la cuenta de otro Administrador.');

            return;
        }

        $this->restoreRecord($id);
    }

    /**
     * Si esta cuenta puede tocar la de ese usuario. Es lo que decide qué botones
     * lleva su fila: un botón que existe se pulsa, y la respuesta sería un aviso
     * que parece un fallo del panel (§7, fase 12, decisión 3).
     */
    public function canManageAccount(User $user): bool
    {
        return $this->canManage()
            && ($this->amAdministrator() || $user->roleName() !== PermissionCatalog::ROLE_ADMIN);
    }

    /**
     * Si quien está mirando es Administrador.
     *
     * De aquí cuelgan las tres reglas de abajo, y todas dicen lo mismo: **al
     * Administrador sólo lo reparte y lo toca un Administrador**.
     *
     * Sin ellas, `users.manage` **es** el rol de Administrador aunque el
     * catálogo diga otra cosa. Quien gestiona cuentas puede crearse una con ese
     * rol, o cambiarle la contraseña a la que ya lo tiene y entrar con ella; y
     * con el Administrador vienen las copias de seguridad, que son la base
     * entera del cliente en un fichero (§10).
     *
     * Hoy no cambian nada para quien las usa, porque sólo el Administrador lleva
     * `users.manage`. Existen porque **desde la fase 12 los roles los crea el
     * cliente**, y una regla de este peso no puede sostenerse en cómo esté
     * repartido el catálogo hoy. Es la misma idea que ya protege «Roles y
     * permisos»: quien puede tocarlos puede dárselo todo a sí mismo (§7, fase 12).
     */
    private function amAdministrator(): bool
    {
        return auth()->user()->roleName() === PermissionCatalog::ROLE_ADMIN;
    }

    public function save(): void
    {
        // Esconder el botón no basta: a este método se llega desde el navegador.
        $this->authorizeManage();

        $this->validate(User::rules($this->editing));

        // Nadie se cambia el rol a sí mismo.
        //
        // Hasta el 21/08/2026 esto sólo impedía **quitarte** el Administrador
        // —quedarte fuera de tu propio panel sin poder volver a entrar—, y el
        // sentido contrario quedaba abierto: con `users.manage` bastaba con
        // editarte y elegir. No vale con nombrar al Administrador, porque los
        // roles los crea el cliente y otro cualquiera puede llevar dentro las
        // copias de seguridad. Que te lo cambie otro, como la baja.
        if ($this->editing === auth()->id() && $this->role !== auth()->user()->roleName()) {
            $this->toastError('No puedes cambiarte el rol a ti mismo. Que lo haga otro usuario.');

            return;
        }

        $editando = $this->editing !== null;

        // Y las otras dos mitades de la misma regla: ver `amAdministrator()`.
        // Repartir el Administrador, y tocar a quien ya lo tiene —cambiarle la
        // contraseña es entrar con su cuenta—.
        if (! $this->amAdministrator()) {
            if ($this->role === PermissionCatalog::ROLE_ADMIN) {
                $this->toastError('Sólo un Administrador puede dar el rol de Administrador.');

                return;
            }

            if ($editando && User::withTrashed()->find($this->editing)?->roleName() === PermissionCatalog::ROLE_ADMIN) {
                $this->toastError('Sólo un Administrador puede editar la cuenta de otro Administrador.');

                return;
            }
        }

        $hecho = $this->transactionally($this->lockKey('save'), function () {
            $usuario = User::withTrashed()->findOr($this->editing ?? 0, fn () => new User);

            $usuario->fill([
                'name' => $this->name,
                'last_name' => $this->last_name,
                'email' => $this->email,
            ]);

            // Sólo si se ha escrito una. El cast `hashed` del modelo la cifra;
            // aquí no se llama a Hash::make para no hacerlo dos veces.
            if ($this->password !== '') {
                $usuario->password = $this->password;
            }

            $anterior = $usuario->exists ? $usuario->roleName() : null;

            $guardado = $usuario->save();

            // Dentro de la transacción y después del `save()`: una cuenta nueva
            // no tiene id al que colgarle el rol hasta que existe, y si el rol
            // fallara no puede quedar el usuario a medias.
            $usuario->syncRoles([$this->role]);

            // El rol vive en la tabla pivote del paquete, así que los eventos
            // de Eloquent no lo ven: el historial lo escribe el modelo (§4).
            $usuario->recordRoleChange($anterior, $this->role);

            return $guardado;
        });

        if (! $hecho) {
            return;
        }

        $this->cancel();
        $this->toast($editando ? 'Usuario actualizado.' : 'Usuario creado.');
    }
}; ?>

<div>
    <x-ui.page-header title="Usuarios"
                      description="Las cuentas que entran al panel. No son el maestro del cliente: aquí no hay rutas ni comercios.">
        <x-slot:actions>
            {{-- Sin permiso de escritura, la pantalla se lee y ya (§7, fase 12). --}}
            @if ($this->canManage())
                <x-ui.button wire:click="create">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Nuevo usuario
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card padding="p-0">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-6 py-3">
            <div class="relative min-w-64 flex-1">
                <svg class="pointer-events-none absolute top-2.5 left-3 size-4 text-slate-400"
                     fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z" />
                </svg>
                <x-ui.input wire:model.live.debounce.300ms="search" class="pl-9"
                            placeholder="Buscar por nombre, apellido o correo…" aria-label="Buscar" />
            </div>

            <label class="flex items-center gap-2 text-sm whitespace-nowrap text-slate-600">
                <input type="checkbox" wire:model.live="showingTrashed"
                       class="size-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500">
                Ver dados de baja
            </label>
        </div>

        @if ($users->isEmpty())
            <x-ui.empty-state :title="$search !== '' ? 'Ningún usuario coincide' : 'Todavía no hay usuarios'"
                              :description="$search !== ''
                                  ? 'Prueba con otro nombre o correo.'
                                  : 'Crea la primera cuenta para entrar al panel.'">
                <x-slot:actions>
                    @if ($search !== '')
                        <x-ui.button variant="secondary" wire:click="$set('search', '')">Quitar el filtro</x-ui.button>
                    @elseif ($this->canManage())
                        <x-ui.button wire:click="create">Nuevo usuario</x-ui.button>
                    @endif
                </x-slot:actions>
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-2xl text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-xs tracking-wider text-slate-500 uppercase">
                            <th class="px-6 py-3 font-semibold">Usuario</th>
                            <th class="px-6 py-3 font-semibold">Correo</th>
                            <th class="px-6 py-3 font-semibold">Rol</th>
                            <th class="px-6 py-3 font-semibold">Alta</th>
                            <th class="px-6 py-3"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($users as $user)
                            <tr wire:key="usuario-{{ $user->id }}" class="hover:bg-slate-50/75">
                                <td class="px-6 py-3">
                                    <span class="font-medium text-shell-900">{{ $user->fullName() }}</span>

                                    {{-- Quién eres tú, para que el botón de baja
                                         que no va a funcionar se entienda antes
                                         de pulsarlo. --}}
                                    @if ($user->is(auth()->user()))
                                        <span class="ml-2 rounded-full bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-700">
                                            tú
                                        </span>
                                    @endif

                                    @if ($user->trashed())
                                        <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">
                                            dado de baja
                                        </span>
                                    @endif
                                </td>

                                <td class="px-6 py-3 text-slate-600">{{ $user->email }}</td>

                                {{-- Una cuenta sin rol no puede pasar de la
                                     portada: hay que verlo desde el listado. --}}
                                <td class="px-6 py-3">
                                    @if ($user->roleName())
                                        <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">
                                            {{ $user->roleName() }}
                                        </span>
                                    @else
                                        <span class="text-xs text-amber-700">sin rol</span>
                                    @endif
                                </td>

                                <td class="px-6 py-3 text-slate-500">
                                    {{ $user->created_at?->translatedFormat('j M Y') ?? '—' }}
                                </td>

                                <td class="px-6 py-3">
                                    <div class="flex justify-end gap-1">
                                        @if ($this->canManageAccount($user))
                                            @if ($user->trashed())
                                                <x-ui.icon-button label="Reactivar"
                                                                  wire:click="restore({{ $user->id }})"
                                                                  wire:loading.attr="disabled">
                                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                              d="M16.023 9.348h4.992V4.356M2.985 19.644v-4.992h4.992M19.5 9a7.5 7.5 0 00-13.02-3.02L2.985 9m0 6a7.5 7.5 0 0013.02 3.02l3.495-3.02" />
                                                    </svg>
                                                </x-ui.icon-button>
                                            @else
                                                <x-ui.icon-button label="Editar"
                                                                  wire:click="edit({{ $user->id }})"
                                                                  wire:loading.attr="disabled">
                                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                              d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                                                    </svg>
                                                </x-ui.icon-button>

                                                {{-- El de la propia sesión no lleva
                                                     botón: el modelo lo prohíbe, y
                                                     ofrecerlo para luego negarlo es
                                                     una trampa. --}}
                                                @unless ($user->is(auth()->user()))
                                                    <x-ui.icon-button label="Dar de baja" variant="danger"
                                                                      wire:click="confirmDelete({{ $user->id }})"
                                                                      wire:loading.attr="disabled">
                                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                  d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                        </svg>
                                                    </x-ui.icon-button>
                                                @endunless
                                            @endif
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-6 py-3">
                <p class="text-sm text-slate-500">
                    {{ $users->total() }} {{ $users->total() === 1 ? 'usuario' : 'usuarios' }}
                </p>

                {{ $users->links() }}
            </div>
        @endif
    </x-ui.card>

    @if ($showingForm)
        {{-- Cinco campos: con el ancho de siempre la ficha salía en una tira
             larga que no cabe de una vez en un portátil. --}}
        <x-ui.modal width="max-w-2xl"
                    :title="$editing ? 'Editar usuario' : 'Nuevo usuario'"
                    :description="$editing
                        ? 'Deja la contraseña vacía para dejarla como está.'
                        : 'Con estas credenciales entrará al panel.'">
            <form wire:submit="save" id="form-usuario" class="space-y-4">
                {{-- Nombre y apellido van juntos porque son una sola cosa, y las
                     dos contraseñas porque se comparan. En móvil se apilan: dos
                     columnas en 380 px no son dos columnas. --}}
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field label="Nombre" for="name" :error="$errors->first('name')">
                        <x-ui.input wire:model="name" id="name" :invalid="$errors->has('name')" autofocus />
                    </x-ui.field>

                    <x-ui.field label="Apellido" for="last_name" :error="$errors->first('last_name')">
                        <x-ui.input wire:model="last_name" id="last_name" :invalid="$errors->has('last_name')" />
                    </x-ui.field>
                </div>

                <x-ui.field label="Correo" for="email" :error="$errors->first('email')"
                            hint="Es con lo que entra al panel.">
                    <x-ui.input wire:model="email" id="email" type="email"
                                :invalid="$errors->has('email')" autocomplete="off" />
                </x-ui.field>

                <x-ui.field label="Rol" for="role" :error="$errors->first('role')"
                            hint="Decide a qué pantallas entra y qué puede tocar en ellas.">
                    <x-ui.searchable-select wire:model="role" id="role"
                                            :invalid="$errors->has('role')"
                                            :options="collect($roles)->mapWithKeys(fn ($rol) => [$rol => $rol])->all()"
                                            :value="$role"
                                            placeholder="Elige un rol…"
                                            search-placeholder="Buscar un rol…" />
                </x-ui.field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field :label="$editing ? 'Nueva contraseña' : 'Contraseña'" for="password"
                                :error="$errors->first('password')"
                                :hint="$editing
                                    ? 'Vacía, se queda como está. Mínimo 8 caracteres.'
                                    : 'Mínimo 8 caracteres.'">
                        <x-ui.input wire:model="password" id="password" type="password"
                                    :invalid="$errors->has('password')" autocomplete="new-password"
                                    :placeholder="$editing ? 'Sin cambios' : ''" />
                    </x-ui.field>

                    <x-ui.field label="Repite la contraseña" for="password_confirmation">
                        <x-ui.input wire:model="password_confirmation" id="password_confirmation" type="password"
                                    :invalid="$errors->has('password')" autocomplete="new-password" />
                    </x-ui.field>
                </div>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="cancel" wire:loading.attr="disabled">
                    Cancelar
                </x-ui.button>

                <x-ui.button type="submit" form="form-usuario"
                             wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $editing ? 'Guardar' : 'Crear' }}</span>
                    <span wire:loading wire:target="save">Guardando…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($confirmingDeletion && $objetivo = $this->deletionTarget())
        <x-ui.confirm-modal title="Dar de baja el usuario" :name="$objetivo->fullName()"
                            confirm="delete({{ $confirmingDeletion }})">
            Dejará de poder entrar al panel, pero lo que hizo sigue firmado con su nombre
            en el historial. Podrás reactivarlo cuando quieras.
        </x-ui.confirm-modal>
    @endif

</div>
