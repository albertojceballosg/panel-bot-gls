<?php

use App\Exceptions\DoubleSubmitException;
use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCatalog;
use App\Support\PreventsDoubleSubmit;
use App\Support\SendsToasts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Maestro de roles (CONTEXTO.md §7, fase 12).
 *
 * Dos maestros en una pantalla porque son la misma pregunta mirada desde los
 * dos lados: **arriba los roles** —qué lleva cada uno— y **abajo los permisos**
 * que hay para repartir.
 *
 * **Los permisos del código no se tocan.** `PermissionCatalog` siembra los que
 * `routes/web.php` y las pantallas comprueban por su nombre: renombrar
 * `merchants.manage` desde aquí dejaría esa comprobación mirando a un permiso
 * que ya no existe, y nadie podría entrar. Se pueden crear otros y repartirlos,
 * pero **un permiso que ningún código comprueba no abre ninguna puerta**: sirve
 * para preparar el terreno de una pantalla que viene, no para inventar
 * capacidades. El que se crea aquí se le da al Administrador en el acto, que es
 * el rol que se define como «todos».
 *
 * No usa `CrudScreen`: ese trait está hecho sobre `SoftDeletes` —baja pasiva,
 * reactivación, «ver dados de baja»— y un rol se borra de verdad o no se borra.
 * Lo que sí se reutiliza es lo que no depende de eso: los avisos y el cerrojo de
 * doble envío.
 *
 * **El Administrador no se toca.** Es el rol que se define como «todos los
 * permisos» (lo resincroniza el seeder en cada despliegue) y es la única vuelta
 * atrás si alguien se equivoca configurando los demás: dejar que se edite o se
 * borre es dejar que el panel se cierre con la llave dentro.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    use PreventsDoubleSubmit, SendsToasts;

    public ?int $editing = null;

    public bool $showingForm = false;

    public ?int $confirmingDeletion = null;

    public string $name = '';

    /** @var list<string> Los permisos marcados, por su nombre (`módulo.acción`). */
    public array $permissions = [];

    // --- El maestro de permisos, en la misma pantalla -----------------------

    public ?int $permissionEditing = null;

    public bool $showingPermissionForm = false;

    public ?int $confirmingPermissionDeletion = null;

    public string $permissionName = '';

    public string $permissionDescription = '';

    // --- Permisos de la propia pantalla -------------------------------------

    protected function authorizeManage(): void
    {
        $this->authorize(PermissionCatalog::name('roles', PermissionCatalog::MANAGE));
    }

    public function canManage(): bool
    {
        return (bool) auth()->user()?->can(
            PermissionCatalog::name('roles', PermissionCatalog::MANAGE)
        );
    }

    // --- Formulario ---------------------------------------------------------

    public function create(): void
    {
        $this->authorizeManage();

        $this->reset('editing', 'name', 'permissions');
        $this->resetValidation();
        $this->showingForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeManage();

        $rol = Role::with('permissions')->findOrFail($id);

        if ($this->isProtected($rol)) {
            $this->toastError('El rol '.$rol->name.' no se edita: lleva siempre todos los permisos.');

            return;
        }

        $this->editing = $rol->id;
        $this->name = $rol->name;
        $this->permissions = $rol->permissions->pluck('name')->all();
        $this->resetValidation();
        $this->showingForm = true;
    }

    public function cancel(): void
    {
        $this->reset('editing', 'showingForm', 'name', 'permissions');
        $this->resetValidation();
    }

    /**
     * Guarda el rol y sus permisos.
     *
     * Todo dentro de la misma transacción, historial incluido: si algo falla no
     * puede quedar el rol con un nombre nuevo y los permisos de antes.
     */
    public function save(): void
    {
        $this->authorizeManage();

        $this->validate([
            'name' => [
                'required', 'string', 'max:50',
                Rule::unique('roles', 'name')->ignore($this->editing),
            ],

            // Un rol sin permisos es legítimo —sirve para dejar una cuenta
            // aparcada sin borrarla—, pero lo que llegue tiene que existir de
            // verdad: la lista la fija el catálogo, no el navegador.
            'permissions' => ['array'],
            'permissions.*' => [Rule::exists('permissions', 'name')],
        ], attributes: [
            'name' => 'nombre',
            'permissions' => 'permisos',
        ]);

        $rol = $this->editing === null ? null : Role::with('permissions')->findOrFail($this->editing);

        if ($rol !== null && $this->isProtected($rol)) {
            $this->toastError('El rol '.$rol->name.' no se edita: lleva siempre todos los permisos.');

            return;
        }

        // Quitarle a tu propio rol la llave de esta pantalla es quedarte fuera
        // de ella con la sesión abierta, y sin poder volver a entrar a
        // arreglarlo. Es la misma red que la de «no puedes darte de baja».
        $mio = PermissionCatalog::name('roles', PermissionCatalog::MANAGE);

        if ($rol !== null
            && auth()->user()->hasRole($rol->name)
            && ! in_array($mio, $this->permissions, true)) {
            $this->toastError('No puedes quitarle a tu propio rol el permiso de gestionar roles. Que lo haga otro usuario.');

            return;
        }

        $editando = $this->editing !== null;

        try {
            $this->withoutDoubleSubmit('roles:save', fn () => DB::transaction(function () use ($rol) {
                $rol ??= new Role(['guard_name' => 'web']);

                $antes = $rol->exists ? $rol->permissions->pluck('name')->all() : [];

                $rol->name = $this->name;
                $rol->save();

                $rol->syncPermissions($this->permissions);

                // Los permisos viven en la pivote y los eventos de Eloquent no
                // los ven: el rastro lo escribe el modelo (§4).
                $rol->recordPermissionChange($antes, $this->permissions);
            }));
        } catch (DoubleSubmitException) {
            // El primer envío está guardando esto mismo: callar es mejor que
            // asustar con un error por algo que va a salir bien.
            return;
        }

        $this->cancel();
        $this->toast($editando ? 'Rol actualizado.' : 'Rol creado.');
    }

    // --- Borrado ------------------------------------------------------------

    public function confirmDelete(int $id): void
    {
        $this->authorizeManage();

        $this->confirmingDeletion = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeletion = null;
    }

    public function deletionTarget(): ?Role
    {
        return $this->confirmingDeletion === null
            ? null
            : Role::withCount('users')->find($this->confirmingDeletion);
    }

    /**
     * Borra el rol. **De verdad**, no en pasivo: un rol dado de baja seguiría
     * en la pivote de quien lo tuviera, y «tiene un rol que no existe» no es un
     * estado que ninguna pantalla sepa contar.
     *
     * Por eso no se borra uno con cuentas: dejarlas sin rol las echa del panel
     * sin decírselo a nadie. Primero se les cambia el rol, como con las rutas
     * que no se dan de baja con comercios vivos (§4).
     */
    public function delete(int $id): void
    {
        $this->authorizeManage();

        $rol = Role::withCount('users')->findOrFail($id);
        $this->confirmingDeletion = null;

        if ($this->isProtected($rol)) {
            $this->toastError('El rol '.$rol->name.' no se borra: es el que puede con todo, y sin él no hay vuelta atrás.');

            return;
        }

        if ($rol->users_count > 0) {
            $this->toastError(sprintf(
                'El rol %s lo tienen %d %s. Cámbiales el rol antes de borrarlo.',
                $rol->name,
                $rol->users_count,
                $rol->users_count === 1 ? 'cuenta' : 'cuentas',
            ));

            return;
        }

        DB::transaction(fn () => $rol->delete());

        $this->toast('Rol borrado.');
    }

    // --- Permisos: alta, edición y borrado ----------------------------------

    public function createPermission(): void
    {
        $this->authorizeManage();

        $this->reset('permissionEditing', 'permissionName', 'permissionDescription');
        $this->resetValidation();
        $this->showingPermissionForm = true;
    }

    public function editPermission(int $id): void
    {
        $this->authorizeManage();

        $permiso = Permission::findOrFail($id);

        if ($this->isFromCode($permiso)) {
            $this->toastError($this->avisoDelCodigo($permiso));

            return;
        }

        $this->permissionEditing = $permiso->id;
        $this->permissionName = $permiso->name;
        $this->permissionDescription = $permiso->description ?? '';
        $this->resetValidation();
        $this->showingPermissionForm = true;
    }

    public function cancelPermission(): void
    {
        $this->reset('permissionEditing', 'showingPermissionForm', 'permissionName', 'permissionDescription');
        $this->resetValidation();
    }

    public function savePermission(): void
    {
        $this->authorizeManage();

        $this->validate([
            'permissionName' => [
                'required', 'string', 'max:100',

                // La forma importa: la pantalla agrupa por lo que va antes del
                // punto, y `can:` lo lee tal cual desde la ruta. Un nombre con
                // espacios o mayúsculas sería un permiso imposible de escribir
                // en el sitio donde hay que escribirlo.
                'regex:/^[a-z0-9-]+\.[a-z0-9-]+$/',
                Rule::unique('permissions', 'name')->ignore($this->permissionEditing),
            ],
            'permissionDescription' => ['nullable', 'string', 'max:255'],
        ], [
            'permissionName.regex' => 'El nombre va como «modulo.accion»: en minúsculas, sin espacios y con un punto.',
        ], [
            'permissionName' => 'nombre',
            'permissionDescription' => 'descripción',
        ]);

        $permiso = $this->permissionEditing === null
            ? null
            : Permission::findOrFail($this->permissionEditing);

        if ($permiso !== null && $this->isFromCode($permiso)) {
            $this->toastError($this->avisoDelCodigo($permiso));

            return;
        }

        $editando = $this->permissionEditing !== null;

        try {
            $this->withoutDoubleSubmit('permissions:save', fn () => DB::transaction(function () use ($permiso) {
                $permiso ??= new Permission(['guard_name' => 'web']);

                $permiso->name = $this->permissionName;
                $permiso->description = $this->permissionDescription === '' ? null : $this->permissionDescription;

                $nuevo = ! $permiso->exists;
                $permiso->save();

                // Al Administrador le entra en el acto: es el rol que se define
                // como «todos los permisos», y si esperase al próximo despliegue
                // habría un rato en el que ese «todos» sería mentira.
                if ($nuevo) {
                    Role::findByName(PermissionCatalog::ROLE_ADMIN)->givePermissionTo($permiso);
                }
            }));
        } catch (DoubleSubmitException) {
            return;
        }

        $this->cancelPermission();
        $this->toast($editando ? 'Permiso actualizado.' : 'Permiso creado.');
    }

    public function confirmPermissionDelete(int $id): void
    {
        $this->authorizeManage();

        $this->confirmingPermissionDeletion = $id;
    }

    public function cancelPermissionDelete(): void
    {
        $this->confirmingPermissionDeletion = null;
    }

    public function permissionDeletionTarget(): ?Permission
    {
        return $this->confirmingPermissionDeletion === null
            ? null
            : Permission::withCount('roles')->find($this->confirmingPermissionDeletion);
    }

    /**
     * Borra un permiso creado a mano. Los roles que lo tuvieran lo pierden, que
     * es lo que se espera: el permiso deja de existir para todo el mundo.
     */
    public function deletePermission(int $id): void
    {
        $this->authorizeManage();

        $permiso = Permission::findOrFail($id);
        $this->confirmingPermissionDeletion = null;

        if ($this->isFromCode($permiso)) {
            $this->toastError($this->avisoDelCodigo($permiso));

            return;
        }

        DB::transaction(fn () => $permiso->delete());

        $this->toast('Permiso borrado.');
    }

    /** Si lo siembra `PermissionCatalog`: entonces hay código que lo comprueba. */
    private function isFromCode(Permission $permiso): bool
    {
        return array_key_exists($permiso->name, PermissionCatalog::all());
    }

    private function avisoDelCodigo(Permission $permiso): string
    {
        return 'El permiso '.$permiso->name.' lo define el código y no se toca desde aquí: '
            .'hay pantallas que lo comprueban por su nombre.';
    }

    /** El Administrador: ni se edita ni se borra. Ver la cabecera. */
    private function isProtected(Role $rol): bool
    {
        return $rol->name === PermissionCatalog::ROLE_ADMIN;
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        // Los de la base y no los del catálogo: aquí están también los creados
        // desde la pantalla, que es de lo que va toda esta mitad.
        $permisos = Permission::query()
            ->withCount('roles')
            ->orderBy('name')
            ->get();

        return [
            // `withCount('users')` es lo que permite decir a quién afecta cada
            // rol sin una consulta por fila.
            'roles' => Role::query()
                ->with('permissions')
                ->withCount('users')
                ->orderBy('name')
                ->get(),

            'permisos' => $permisos,

            // Agrupados por lo que va antes del punto, con el nombre visible del
            // módulo cuando el catálogo lo conoce: es como se leen y como se
            // marcan en el formulario de un rol.
            'grupos' => $permisos->groupBy(fn (Permission $permiso) => $permiso->module()),
            'etiqueta' => fn (string $modulo) => PermissionCatalog::moduleLabel($modulo),

            'total' => $permisos->count(),
            'protegido' => PermissionCatalog::ROLE_ADMIN,

            // Los que siembra el código: ni se renombran ni se borran.
            'delCodigo' => array_keys(PermissionCatalog::all()),
        ];
    }
}; ?>

<div>
    <x-ui.page-header title="Roles y permisos"
                      description="Qué puede hacer cada cuenta. Los permisos los define el código; aquí se decide quién lleva cuáles.">
        <x-slot:actions>
            @if ($this->canManage())
                <x-ui.button wire:click="create">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Nuevo rol
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card padding="p-0" class="mb-6">
        <div class="overflow-x-auto">
            <table class="w-full min-w-2xl text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-xs tracking-wider text-slate-500 uppercase">
                        <th class="px-6 py-3 font-semibold">Rol</th>
                        <th class="px-6 py-3 font-semibold">Permisos</th>
                        <th class="px-6 py-3 text-right font-semibold">Cuentas</th>
                        <th class="px-6 py-3"><span class="sr-only">Acciones</span></th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100">
                    @foreach ($roles as $rol)
                        @php $esProtegido = $rol->name === $protegido; @endphp

                        <tr wire:key="rol-{{ $rol->id }}" class="hover:bg-slate-50/75">
                            <td class="px-6 py-3">
                                <span class="font-medium text-shell-900">{{ $rol->name }}</span>

                                @if ($esProtegido)
                                    <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500"
                                          title="Lleva siempre todos los permisos, también los de los módulos que se añadan">
                                        siempre todo
                                    </span>
                                @endif
                            </td>

                            {{-- Cuántos de cuántos, y de qué módulos: la lista
                                 entera aquí no se lee, y «12 permisos» a secas
                                 no dice de qué. --}}
                            <td class="px-6 py-3 text-slate-600">
                                <span class="tabular-nums">{{ $rol->permissions->count() }}</span>
                                <span class="text-slate-400">de {{ $total }}</span>

                                @if ($rol->permissions->isNotEmpty())
                                    <span class="mt-1 block text-xs text-slate-500">
                                        {{ $rol->permissions
                                            ->map(fn ($permiso) => $etiqueta($permiso->module()))
                                            ->unique()
                                            ->sort()
                                            ->join(', ', ' y ') }}
                                    </span>
                                @else
                                    <span class="mt-1 block text-xs text-amber-700">no puede entrar a ninguna pantalla</span>
                                @endif
                            </td>

                            <td class="px-6 py-3 text-right tabular-nums text-slate-600">
                                {{ $rol->users_count }}
                            </td>

                            <td class="px-6 py-3">
                                <div class="flex justify-end gap-1">
                                    @if ($this->canManage() && ! $esProtegido)
                                        <x-ui.icon-button label="Editar"
                                                          wire:click="edit({{ $rol->id }})"
                                                          wire:loading.attr="disabled">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                      d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                                            </svg>
                                        </x-ui.icon-button>

                                        <x-ui.icon-button label="Borrar" variant="danger"
                                                          wire:click="confirmDelete({{ $rol->id }})"
                                                          wire:loading.attr="disabled">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                      d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                            </svg>
                                        </x-ui.icon-button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="border-t border-slate-200 px-6 py-3 text-xs text-slate-500">
            El rol de cada cuenta se elige en
            <a href="{{ route('users') }}" wire:navigate class="font-medium underline underline-offset-2">Usuarios</a>.
            Un rol con cuentas no se puede borrar: cámbiaselo antes, o se quedarían fuera del panel sin saber por qué.
        </div>
    </x-ui.card>

    {{-- El otro maestro: lo que hay para repartir. Los del código salen
         marcados y sin botones, porque su nombre está escrito en las pantallas
         que los comprueban. --}}
    <x-ui.card padding="p-0">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-6 py-4">
            <div>
                <h2 class="text-sm font-semibold text-shell-900">Permisos</h2>
                <p class="mt-0.5 text-sm text-slate-500">
                    Lo que se puede repartir entre los roles. Los que trae el código son los que comprueban
                    las pantallas; uno creado aquí no abre ninguna puerta hasta que haya código que lo mire.
                </p>
            </div>

            @if ($this->canManage())
                <x-ui.button variant="secondary" wire:click="createPermission">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Nuevo permiso
                </x-ui.button>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-2xl text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-xs tracking-wider text-slate-500 uppercase">
                        <th class="px-6 py-3 font-semibold">Permiso</th>
                        <th class="px-6 py-3 font-semibold">Qué permite</th>
                        <th class="px-6 py-3 text-right font-semibold">Roles</th>
                        <th class="px-6 py-3"><span class="sr-only">Acciones</span></th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100">
                    @foreach ($grupos as $modulo => $delModulo)
                        {{-- Una fila de separación por módulo: la lista entera
                             seguida son treinta claves que se parecen mucho. --}}
                        <tr class="bg-slate-50/75">
                            <td colspan="4" class="px-6 py-1.5 text-xs font-semibold tracking-wider text-slate-500 uppercase">
                                {{ $etiqueta($modulo) }}
                            </td>
                        </tr>

                        @foreach ($delModulo as $permiso)
                            @php $esDelCodigo = in_array($permiso->name, $delCodigo, true); @endphp

                            <tr wire:key="permiso-{{ $permiso->id }}" class="hover:bg-slate-50/75">
                                <td class="px-6 py-3">
                                    <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-700">
                                        {{ $permiso->name }}
                                    </code>

                                    @if ($esDelCodigo)
                                        <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500"
                                              title="Hay pantallas que lo comprueban por su nombre: renombrarlo las dejaría cerradas">
                                            del código
                                        </span>
                                    @endif
                                </td>

                                <td class="px-6 py-3 text-slate-600">
                                    {{ $permiso->description ?: '—' }}
                                </td>

                                <td class="px-6 py-3 text-right tabular-nums text-slate-600">
                                    {{ $permiso->roles_count }}
                                </td>

                                <td class="px-6 py-3">
                                    <div class="flex justify-end gap-1">
                                        @if ($this->canManage() && ! $esDelCodigo)
                                            <x-ui.icon-button label="Editar"
                                                              wire:click="editPermission({{ $permiso->id }})"
                                                              wire:loading.attr="disabled">
                                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                          d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                                                </svg>
                                            </x-ui.icon-button>

                                            <x-ui.icon-button label="Borrar" variant="danger"
                                                              wire:click="confirmPermissionDelete({{ $permiso->id }})"
                                                              wire:loading.attr="disabled">
                                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                          d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                </svg>
                                            </x-ui.icon-button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="border-t border-slate-200 px-6 py-3 text-xs text-slate-500">
            {{ $total }} {{ $total === 1 ? 'permiso' : 'permisos' }}. Un permiso creado aquí entra al
            <strong>{{ $protegido }}</strong> en el acto —es el rol que lleva todo— y se reparte a los demás
            editándolos arriba.
        </div>
    </x-ui.card>

    @if ($showingForm)
        <x-ui.modal width="max-w-2xl"
                    :title="$editing ? 'Editar rol' : 'Nuevo rol'"
                    description="Marca lo que puede hacer. Sin marcar nada, la cuenta entra al panel y no ve ninguna pantalla.">
            <form wire:submit="save" id="form-rol" class="space-y-4">
                <x-ui.field label="Nombre" for="name" :error="$errors->first('name')"
                            hint="Es lo que se elige en la ficha de cada usuario.">
                    <x-ui.input wire:model="name" id="name" :invalid="$errors->has('name')" autofocus />
                </x-ui.field>

                <div>
                    <p class="text-sm font-medium text-slate-700">Permisos</p>

                    @error('permissions.*')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror

                    <div class="mt-2 grid gap-x-8 gap-y-4 sm:grid-cols-2">
                        @foreach ($grupos as $modulo => $delModulo)
                            <fieldset wire:key="grupo-{{ $modulo }}">
                                <legend class="text-xs font-semibold tracking-wider text-slate-500 uppercase">
                                    {{ $etiqueta($modulo) }}
                                </legend>

                                @foreach ($delModulo as $permiso)
                                    <label class="mt-1.5 flex gap-2 text-sm text-slate-600">
                                        <input type="checkbox" wire:model="permissions" value="{{ $permiso->name }}"
                                               class="mt-0.5 size-4 shrink-0 rounded border-slate-300 text-brand-500 focus:ring-brand-500">
                                        {{-- Sin descripción se enseña la clave: es
                                             lo único que se sabe de él, y dejar el
                                             hueco en blanco no marcaría nada. --}}
                                        <span>{{ $permiso->description ?: $permiso->name }}</span>
                                    </label>
                                @endforeach
                            </fieldset>
                        @endforeach
                    </div>
                </div>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="cancel" wire:loading.attr="disabled">
                    Cancelar
                </x-ui.button>

                <x-ui.button type="submit" form="form-rol"
                             wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $editing ? 'Guardar' : 'Crear' }}</span>
                    <span wire:loading wire:target="save">Guardando…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($confirmingDeletion && $objetivo = $this->deletionTarget())
        <x-ui.confirm-modal title="Borrar el rol" :name="$objetivo->name"
                            confirm-label="Borrar"
                            confirm="delete({{ $confirmingDeletion }})">
            Se borra de verdad, no se da de baja: un rol no se reactiva. Las cuentas que lo tengan
            hay que cambiarlas antes.
        </x-ui.confirm-modal>
    @endif

    @if ($showingPermissionForm)
        <x-ui.modal :title="$permissionEditing ? 'Editar permiso' : 'Nuevo permiso'"
                    description="Sirve para preparar el terreno de una pantalla que viene: hasta que haya código que lo compruebe, no abre ninguna puerta."
                    close="cancelPermission">
            <form wire:submit="savePermission" id="form-permiso" class="space-y-4">
                <x-ui.field label="Nombre" for="permissionName" :error="$errors->first('permissionName')"
                            hint="Va como «modulo.accion», en minúsculas: es lo que se escribe en la ruta que lo comprueba.">
                    <x-ui.input wire:model="permissionName" id="permissionName"
                                :invalid="$errors->has('permissionName')"
                                placeholder="informes.view" autofocus />
                </x-ui.field>

                <x-ui.field label="Qué permite" for="permissionDescription"
                            :error="$errors->first('permissionDescription')"
                            hint="En castellano. Es lo que se lee al repartirlo entre los roles.">
                    <x-ui.input wire:model="permissionDescription" id="permissionDescription"
                                :invalid="$errors->has('permissionDescription')"
                                placeholder="Ver los informes mensuales" />
                </x-ui.field>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="cancelPermission" wire:loading.attr="disabled">
                    Cancelar
                </x-ui.button>

                <x-ui.button type="submit" form="form-permiso"
                             wire:loading.attr="disabled" wire:target="savePermission">
                    <span wire:loading.remove wire:target="savePermission">{{ $permissionEditing ? 'Guardar' : 'Crear' }}</span>
                    <span wire:loading wire:target="savePermission">Guardando…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($confirmingPermissionDeletion && $objetivoPermiso = $this->permissionDeletionTarget())
        <x-ui.confirm-modal title="Borrar el permiso" :name="$objetivoPermiso->name"
                            confirm-label="Borrar"
                            confirm="deletePermission({{ $confirmingPermissionDeletion }})"
                            cancel="cancelPermissionDelete">
            @if ($objetivoPermiso->roles_count > 0)
                Lo tienen {{ $objetivoPermiso->roles_count }}
                {{ $objetivoPermiso->roles_count === 1 ? 'rol, que lo perderá' : 'roles, que lo perderán' }}.
            @else
                No lo tiene ningún rol.
            @endif
            Deja de existir para todo el mundo.
        </x-ui.confirm-modal>
    @endif
</div>
