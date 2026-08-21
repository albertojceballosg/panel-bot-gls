<?php

use App\Support\DatabaseBackup;
use App\Support\PermissionCatalog;
use App\Support\SendsToasts;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Copias de seguridad (CONTEXTO.md §7, fase 7).
 *
 * Dos cosas y ninguna carpeta: **la copia se descarga y la restauración se
 * sube**. El panel no guarda volcados —serían datos del cliente acumulándose en
 * el servidor (§9) y una carpeta más que vigilar—, así que la única copia que
 * existe es la que tú te lleves. Está dicho en la pantalla, porque cambia lo que
 * hay que hacer antes de restaurar.
 *
 * Restaurar es irreversible. No es como dar de baja un comercio, que se
 * reactiva: todo lo escrito después del volcado desaparece, incluida la
 * auditoría que diría que esto pasó. De ahí las dos cautelas:
 *
 * 1. Hay que **escribir la palabra**, no basta con elegir el fichero y pulsar.
 * 2. Después **se cierra la sesión**: las sesiones viven en la base
 *    (`SESSION_DRIVER=database`), así que la tuya deja de existir en cuanto
 *    entra el volcado. Mandar al login es decirlo; quedarse sería fingir.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    use SendsToasts, WithFileUploads;

    /** Lo que hay que escribir para restaurar. */
    public const CONFIRMATION = 'RESTAURAR';

    public $upload = null;

    public string $confirmation = '';

    public bool $confirming = false;

    private ?DatabaseBackup $backups = null;

    private function backups(): DatabaseBackup
    {
        return $this->backups ??= app(DatabaseBackup::class);
    }

    /**
     * La cerradura de esta pantalla, y va **dentro** del componente.
     *
     * El `can:` de la ruta deja entrar, pero a estos dos métodos se llega desde el navegador
     * (§7, fase 12, decisión 2). Era la única pantalla que se lo saltaba, y justamente la que
     * se lleva la base entera del cliente en un fichero (§10): que hoy no se pueda rodear
     * depende de que Livewire vuelva a aplicar el *middleware* de la ruta original en cada
     * petición, que es un detalle de una dependencia y no una decisión nuestra.
     *
     * Aquí no hay un `.view` que separar: entrar en la pantalla ya es poder descargar, así
     * que el permiso es el mismo con el que se entra.
     */
    private function authorizeManage(): void
    {
        $this->authorize(PermissionCatalog::name('backups', PermissionCatalog::MANAGE));
    }

    /** Abre la confirmación, ya con el fichero elegido y comprobado. */
    public function confirmRestore(): void
    {
        $this->authorizeManage();

        $this->validate(
            ['upload' => ['required', 'file', 'max:20480']],
            ['upload.required' => 'Elige el fichero de la copia.', 'upload.max' => 'El fichero no puede pasar de 20 MB.'],
        );

        // Se mira antes de preguntar: hacer escribir RESTAURAR para descubrir
        // después que el fichero era un PDF es hacer trabajar para nada.
        if (! $this->backups()->looksLikeADump($this->upload->getRealPath())) {
            $this->addError('upload', 'Ese fichero no es una copia de esta base: no tiene la forma de un volcado de PostgreSQL.');

            return;
        }

        $this->confirmation = '';
        $this->confirming = true;
    }

    public function cancel(): void
    {
        $this->reset('confirming', 'confirmation');
        $this->resetValidation();
    }

    public function restore()
    {
        $this->authorizeManage();

        if (! $this->confirming || $this->upload === null) {
            return null;
        }

        // La palabra se comprueba en el servidor: el botón deshabilitado del
        // navegador no es una frontera de confianza.
        if ($this->confirmation !== self::CONFIRMATION) {
            $this->addError('confirmation', 'Escribe '.self::CONFIRMATION.' para confirmar.');

            return null;
        }

        try {
            $this->backups()->restore($this->upload->getRealPath());
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());
            $this->cancel();

            return null;
        }

        auth()->logout();
        session()->invalidate();
        session()->regenerateToken();
        session()->flash('ok', 'Base restaurada desde la copia. Vuelve a entrar.');

        return redirect()->route('login');
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        return [
            'hayHerramientas' => $this->backups()->toolsAvailable(),
            'palabra' => self::CONFIRMATION,
        ];
    }
}; ?>

<div>
    <x-ui.page-header title="Copias de seguridad"
                      description="Un volcado de la base entera, con pg_dump. Se descarga en el momento; el panel no guarda ninguno." />

    {{-- Si el contenedor no trae las herramientas, la pantalla no puede hacer
         nada: mejor decirlo entero que dejar que fallen los botones uno a uno. --}}
    @unless ($hayHerramientas)
        <x-ui.alert type="error" class="mb-4">
            <strong>Faltan <code>pg_dump</code> y <code>pg_restore</code> en el contenedor.</strong>
            Reconstruye la imagen con <code>docker compose build app</code> y vuelve a levantarla:
            van en el <code>Dockerfile</code>, y sin ellas esta pantalla no puede ni generar ni restaurar.
        </x-ui.alert>
    @endunless

    <div class="grid gap-4 lg:grid-cols-2">
        <x-ui.card>
            <h2 class="text-base font-semibold text-shell-900">Descargar una copia</h2>

            <p class="mt-1 text-sm text-slate-500">
                Genera el volcado y te lo baja al momento: comercios, rutas, UT, jornadas del bot y el
                historial de auditoría. Guárdalo tú, porque en el servidor no queda nada.
            </p>

            <p class="mt-3 text-xs text-slate-500">
                El fichero lleva la fecha y la hora en el nombre, y son <strong>datos del cliente</strong>:
                trátalo como el maestro, no lo dejes en una carpeta compartida.
            </p>

            {{-- Enlace de verdad y sin `wire:navigate`: es una descarga, no una
                 navegación, y así la sirve el navegador sin pasar por Livewire
                 ni cargarse el volcado entero en memoria. --}}
            <x-ui.button as="a" href="{{ route('backups.download') }}" class="mt-5">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                </svg>
                Descargar copia
            </x-ui.button>
        </x-ui.card>

        <x-ui.card class="border-amber-200 bg-amber-50">
            <h2 class="text-base font-semibold text-amber-900">Restaurar desde una copia</h2>

            <p class="mt-1 text-sm text-amber-900">
                <strong>Sustituye la base entera</strong> por la del fichero. Todo lo escrito después de ese
                volcado —comercios, rutas, jornadas y el propio historial— desaparece, y no hay vuelta atrás:
                si quieres poder deshacerlo, descarga antes una copia de cómo está ahora.
            </p>

            <form wire:submit="confirmRestore" class="mt-4 space-y-3">
                <input type="file" wire:model="upload" accept=".dump"
                       class="block w-full text-sm text-amber-900 file:mr-3 file:rounded-lg file:border-0
                              file:bg-amber-100 file:px-3 file:py-1.5 file:text-sm file:font-medium
                              file:text-amber-900 hover:file:bg-amber-200">

                @error('upload')
                    <p class="text-sm text-rose-700">{{ $message }}</p>
                @enderror

                <p wire:loading wire:target="upload" class="text-xs text-amber-900">Subiendo el fichero…</p>

                <x-ui.button type="submit" variant="secondary" wire:loading.attr="disabled" wire:target="upload">
                    Restaurar desde este fichero
                </x-ui.button>
            </form>
        </x-ui.card>
    </div>

    @if ($confirming)
        <x-ui.modal title="Restaurar la base"
                    description="Vas a sustituir la base entera por la copia que has subido.">
            <div class="space-y-4">
                <p class="text-sm text-slate-600">
                    Se pierde todo lo escrito después de ese volcado, incluido el historial de auditoría que
                    lo contaría. Al terminar tendrás que volver a entrar: tu sesión también vive en la base.
                </p>

                <x-ui.field label="Escribe {{ $palabra }} para confirmar" for="confirmation"
                            :error="$errors->first('confirmation')">
                    <x-ui.input wire:model="confirmation" id="confirmation"
                                :invalid="$errors->has('confirmation')" autofocus autocomplete="off" />
                </x-ui.field>
            </div>

            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="cancel" wire:loading.attr="disabled">Cancelar</x-ui.button>

                <x-ui.button variant="danger" wire:click="restore"
                             wire:loading.attr="disabled" wire:target="restore"
                             class="bg-red-600 text-white shadow-sm hover:bg-red-700 hover:text-white">
                    <span wire:loading.remove wire:target="restore">Restaurar</span>
                    <span wire:loading wire:target="restore">Restaurando…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
