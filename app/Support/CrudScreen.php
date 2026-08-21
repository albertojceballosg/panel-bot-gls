<?php

namespace App\Support;

use App\Exceptions\DoubleSubmitException;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;
use RuntimeException;

/**
 * Lo que comparten las pantallas de mantenimiento del maestro.
 *
 * Extraído **después** de escribir dos CRUD, no antes: con un solo ejemplo se
 * habría adivinado mal qué sube y qué se queda. Aquí vive lo mecánico —estado
 * del formulario, paginación, baja y reactivación, cerrojo y transacción— y en
 * cada pantalla se queda lo que de verdad cambia: las reglas de validación, los
 * campos y la consulta del listado.
 */
trait CrudScreen
{
    use PreventsDoubleSubmit, SendsToasts, WithPagination;

    /**
     * El registro que se está editando, o null en un alta.
     *
     * `#[Locked]`: lo pone `edit()` y lo limpia `cancel()`, y **nada de la vista lo escribe**.
     * Sin el candado llega del navegador como cualquier otra propiedad pública, y entonces
     * `save()` guardaría sobre otra fila, o —peor— sobre ninguna: con un id que no existe, el
     * `findOr(...)` de las pantallas crea un registro nuevo en silencio en vez de editar.
     */
    #[Locked]
    public ?int $editing = null;

    public bool $showingForm = false;

    public string $search = '';

    /** Los dados de baja se ocultan por defecto: son la excepción, no la norma. */
    public bool $showingTrashed = false;

    /** Id pendiente de confirmar antes de darse de baja. Lo pone `confirmDelete()`. */
    #[Locked]
    public ?int $confirmingDeletion = null;

    /** @return class-string<Model> */
    abstract protected function model(): string;

    /**
     * Campos del formulario, para limpiarlos al abrir y al cerrar.
     *
     * @return list<string>
     */
    abstract protected function formFields(): array;

    /** Vuelca el registro en el formulario al editar. */
    abstract protected function fillForm(Model $record): void;

    /** Cómo se llama esto de cara al usuario: «ruta», «mensajero»… */
    abstract protected function label(): string;

    /**
     * A qué módulo del catálogo de permisos pertenece esta pantalla (§7, fase
     * 12): de ahí sale el `.manage` que exige toda escritura.
     */
    abstract protected function permissionModule(): string;

    // --- Permisos -----------------------------------------------------------

    /**
     * La cerradura de todo lo que escribe.
     *
     * Va aquí y no sólo en el Blade porque esconder un botón no protege nada:
     * los métodos de un componente de Livewire se pueden llamar desde el
     * navegador, y el `can:` de la ruta sólo dice que se puede *entrar*.
     */
    protected function authorizeManage(): void
    {
        $this->authorize(PermissionCatalog::name($this->permissionModule(), PermissionCatalog::MANAGE));
    }

    /** Si esta cuenta puede escribir aquí. Para decidir qué se ofrece en pantalla. */
    public function canManage(): bool
    {
        return (bool) auth()->user()?->can(
            PermissionCatalog::name($this->permissionModule(), PermissionCatalog::MANAGE)
        );
    }

    /**
     * Si el nombre es femenino. En castellano no basta con interpolar el
     * sustantivo: «Ruta dado de baja» no lo dice nadie.
     */
    protected function feminine(): bool
    {
        return false;
    }

    // --- Paginación ---------------------------------------------------------

    /**
     * El paginador del panel, en vez del numerado de Livewire.
     *
     * Va aquí y no en `Paginator::defaultView()`: Livewire no lo mira. Su
     * `WithPagination` resuelve la vista consultando este método del componente,
     * y si no existe se queda con `livewire::tailwind`.
     */
    public function paginationView(): string
    {
        return 'vendor.pagination.panel';
    }

    /** Si no, al filtrar te quedas en una página que ya no existe. */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedShowingTrashed(): void
    {
        $this->resetPage();
    }

    /**
     * El término para un `ilike`, con los comodines del usuario escapados: si
     * no, buscar «100%» traería cualquier cosa que empiece por 100.
     */
    protected function likeTerm(): string
    {
        return '%'.addcslashes(trim($this->search), '%_\\').'%';
    }

    // --- Formulario ---------------------------------------------------------

    public function create(): void
    {
        $this->authorizeManage();

        $this->reset('editing', ...$this->formFields());
        $this->resetValidation();
        $this->showingForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeManage();

        $record = $this->model()::withTrashed()->findOrFail($id);

        $this->editing = $record->getKey();
        $this->fillForm($record);
        $this->resetValidation();
        $this->showingForm = true;
    }

    public function cancel(): void
    {
        $this->reset('editing', 'showingForm', ...$this->formFields());
        $this->resetValidation();
    }

    // --- Baja y reactivación ------------------------------------------------

    /**
     * Abre la confirmación. Dar de baja es destructivo de cara al usuario
     * —aunque por dentro sea reversible— y un icono es fácil de pulsar sin
     * querer, sobre todo pegado al de editar.
     */
    public function confirmDelete(int $id): void
    {
        $this->authorizeManage();

        $this->confirmingDeletion = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeletion = null;
    }

    /** El registro que se está a punto de dar de baja, para nombrarlo. */
    public function deletionTarget(): ?Model
    {
        return $this->confirmingDeletion === null
            ? null
            : $this->model()::find($this->confirmingDeletion);
    }

    public function delete(int $id): void
    {
        $this->authorizeManage();

        $hecho = $this->transactionally(
            $this->lockKey('delete', $id),
            fn () => $this->model()::findOrFail($id)->delete(),
        );

        $this->confirmingDeletion = null;

        if ($hecho) {
            $this->flashDone('dada de baja', 'dado de baja');
        }
    }

    public function restore(int $id): void
    {
        $this->authorizeManage();

        $hecho = $this->transactionally(
            $this->lockKey('restore', $id),
            fn () => $this->model()::onlyTrashed()->findOrFail($id)->restore(),
        );

        if ($hecho) {
            $this->flashDone('reactivada', 'reactivado');
        }
    }

    // --- El envoltorio que usan todas las escrituras ------------------------

    /**
     * Cerrojo de doble envío por fuera, transacción por dentro.
     *
     * La transacción envuelve también la escritura del historial, que se
     * dispara en un evento del modelo: si algo falla no queda ni la fila ni un
     * registro de auditoría huérfano (§4).
     *
     * Devuelve `false` si la acción ya estaba en curso, para que quien llama no
     * enseñe un mensaje de éxito por algo que no ha hecho.
     */
    protected function transactionally(string $action, Closure $callback): bool
    {
        try {
            $this->withoutDoubleSubmit($action, fn () => DB::transaction($callback));

            return true;
        } catch (DoubleSubmitException) {
            // El primer envío está haciendo esto mismo. Callar es mejor que
            // asustar con un error por algo que va a salir bien.
            return false;
        } catch (RuntimeException $e) {
            // Un modelo que se niega por una regla de negocio: `PickupRoute` no
            // se deja dar de baja con comercios vivos (§4). Mejor un aviso
            // legible que una excepción en pantalla. Va como toast de error,
            // que no se cierra solo: explica qué hacer antes de reintentarlo.
            $this->toastError($e->getMessage());

            return false;
        }
    }

    /** «Ruta dada de baja», «Mensajero dado de baja». */
    protected function flashDone(string $femenino, string $masculino): void
    {
        $this->toast(sprintf(
            '%s %s.',
            ucfirst($this->label()),
            $this->feminine() ? $femenino : $masculino,
        ));
    }

    protected function lockKey(string $action, int|string $id = ''): string
    {
        return class_basename($this->model()).":{$action}:{$id}";
    }
}
