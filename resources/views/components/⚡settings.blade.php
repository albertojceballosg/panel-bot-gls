<?php

use App\Exceptions\DoubleSubmitException;
use App\Models\Setting;
use App\Support\PreventsDoubleSubmit;
use App\Support\SendsToasts;
use App\Support\PermissionCatalog;
use App\Support\SettingsCatalog;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Configuraciones de un módulo (CONTEXTO.md §7, fase 11).
 *
 * **Una sola pantalla para todos los módulos**, y no una por cada uno: lo que
 * cambia entre ellos son los parámetros, y eso ya está declarado en
 * `SettingsCatalog`. Añadir la configuración de otra pantalla es una entrada en
 * el catálogo — ni ruta, ni componente, ni migración.
 *
 * Los valores viven en `$values`, un mapa clave → texto. Texto también para los
 * porcentajes: un `<input>` devuelve cadenas, y convertir en el borde en vez de
 * por dentro evita que un campo vacío se lea como un cero.
 *
 * **Nada nace con un valor por defecto**: un módulo sin configurar enseña el
 * formulario en blanco y avisa en su propia pantalla. Ver `Setting::missing()`.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    use PreventsDoubleSubmit, SendsToasts;

    public string $module = '';

    /** @var array<string, string> */
    public array $values = [];

    public function mount(string $module): void
    {
        abort_unless(SettingsCatalog::has($module), 404);

        $this->module = $module;
        $this->values = Setting::for($module);
    }

    /**
     * Guarda los parámetros del módulo.
     *
     * **El cerrojo no es adorno aquí.** `Setting::store` escribe una fila por
     * parámetro y cada una deja su entrada de auditoría; dos envíos a la vez
     * pueden intercalarse y dejar el historial contando un cambio que nadie
     * hizo — «óptimo: 70 → 80» y «óptimo: 80 → 70» seguidos. Desactivar el
     * botón en el navegador (`wire:loading.attr`) no cubre el doble clic que
     * llega antes de que reaccione el JS, ni dos pestañas abiertas a la vez.
     *
     * La transacción es la otra mitad: si falla el tercer parámetro, no pueden
     * quedar guardados los dos primeros. Es lo mismo que hace `CrudScreen`.
     *
     * El cerrojo lleva el módulo en la clave para que configurar el calendario
     * no bloquee al que está tocando el análisis de incidencias.
     */
    public function save(): void
    {
        // El `can:` de la ruta sólo deja entrar a mirar: escribir es otro
        // permiso, y a este método se llega desde el navegador (§7, fase 12).
        $this->authorize(PermissionCatalog::name('settings', PermissionCatalog::MANAGE));

        $this->validate(
            SettingsCatalog::rules($this->module, 'values.'),
            attributes: SettingsCatalog::labels($this->module, 'values.'),
        );

        try {
            $this->withoutDoubleSubmit(
                "settings:{$this->module}",
                fn () => DB::transaction(fn () => Setting::store($this->module, $this->values)),
            );
        } catch (DoubleSubmitException) {
            // El primer envío está guardando esto mismo. Se calla en vez de
            // avisar de un error, porque va a salir bien — pero **sin** el
            // toast de éxito: anunciarlo dos veces diría que se guardó dos
            // veces, que es justo lo que no ha pasado.
            return;
        }

        $this->toast('Configuración guardada.');
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        return [
            'definicion' => SettingsCatalog::module($this->module),

            // Quien sólo tiene `settings.view` ve los parámetros como están,
            // pero no puede tocarlos: es lo que hace falta para entender por
            // qué una pantalla pinta lo que pinta.
            'puedeGestionar' => (bool) auth()->user()?->can(
                PermissionCatalog::name('settings', PermissionCatalog::MANAGE)
            ),
        ];
    }
}; ?>

<div>
    <x-ui.page-header :title="'Configuración · '.$definicion['label']"
                      :description="$definicion['description'] ?? ''" />

    <form wire:submit="save" class="space-y-4">
        {{-- Sin `settings.manage` la pantalla se mira y no se toca. El aviso
             delante, porque un formulario apagado sin explicación parece roto. --}}
        @unless ($puedeGestionar)
            <x-ui.alert type="warning">
                <span>
                    <strong>Sólo lectura.</strong> Puedes ver con qué parámetros trabaja este módulo,
                    pero cambiarlos es cosa de un administrador.
                </span>
            </x-ui.alert>
        @endunless

        {{-- Un `fieldset` deshabilitado apaga todos sus campos de una vez: sin
             él habría que acordarse de cada `<input>` nuevo. --}}
        <fieldset class="space-y-4" @disabled(! $puedeGestionar)>
            @php
                $porcentajes = collect($definicion['fields'])->where('type', \App\Support\SettingsCatalog::TYPE_PERCENT);
                $colores = collect($definicion['fields'])->where('type', \App\Support\SettingsCatalog::TYPE_COLOR);
                $minutos = collect($definicion['fields'])->where('type', \App\Support\SettingsCatalog::TYPE_MINUTES);
                $dias = collect($definicion['fields'])->where('type', \App\Support\SettingsCatalog::TYPE_DAYS);
            @endphp

            @if ($minutos->isNotEmpty())
                <x-ui.card>
                    <h2 class="text-sm font-semibold text-shell-900">Ventana horaria</h2>
                    <p class="mt-0.5 text-sm text-slate-500">
                        El bot lo aplica en su siguiente corrida. Las jornadas ya analizadas conservan
                        el valor con el que se calcularon: cambiar esto no reescribe el pasado.
                    </p>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        @foreach ($minutos as $key => $campo)
                            <x-ui.field :label="$campo['label']" :for="$key" :hint="$campo['hint'] ?? null"
                                        :error="$errors->first('values.'.$key)">
                                {{-- Sin `max`, igual que el catálogo: ver TYPE_MINUTES. --}}
                                <div class="relative">
                                    <x-ui.input wire:model.live.debounce.400ms="values.{{ $key }}" :id="$key"
                                                type="number" min="1" step="1" class="pr-12"
                                                :invalid="$errors->has('values.'.$key)" />
                                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-slate-400">
                                        min
                                    </span>
                                </div>
                            </x-ui.field>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif

            @if ($dias->isNotEmpty())
                <x-ui.card>
                    <h2 class="text-sm font-semibold text-shell-900">Ventana de búsqueda</h2>
                    <p class="mt-0.5 text-sm text-slate-500">
                        El bot lo aplica en su siguiente corrida. Las jornadas ya analizadas conservan
                        la ganancia que se les encontró: cambiar esto no vuelve a buscarla.
                    </p>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        @foreach ($dias as $key => $campo)
                            <x-ui.field :label="$campo['label']" :for="$key" :hint="$campo['hint'] ?? null"
                                        :error="$errors->first('values.'.$key)">
                                {{-- `min="0"` y no `min="1"`: cero es «sólo el día que se
                                     analiza», que es una elección válida. Y `max="30"`, que
                                     aquí sí lo hay porque cada día es otro listado de 4 MB
                                     que pedirle a Envexpress. Las dos cosas están también en
                                     el catálogo: esto es la comodidad del navegador, no la
                                     validación — ver TYPE_DAYS. --}}
                                <div class="relative">
                                    <x-ui.input wire:model.live.debounce.400ms="values.{{ $key }}" :id="$key"
                                                type="number" min="0" max="30" step="1" class="pr-14"
                                                :invalid="$errors->has('values.'.$key)" />
                                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-slate-400">
                                        días
                                    </span>
                                </div>
                            </x-ui.field>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif

            @if ($porcentajes->isNotEmpty())
                <x-ui.card>
                    <h2 class="text-sm font-semibold text-shell-900">Umbrales</h2>
                    <p class="mt-0.5 text-sm text-slate-500">
                        En porcentaje del volumen esperado. Parten el día en tres tramos: malo, justo y bueno.
                    </p>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        @foreach ($porcentajes as $key => $campo)
                            <x-ui.field :label="$campo['label']" :for="$key" :hint="$campo['hint'] ?? null"
                                        :error="$errors->first('values.'.$key)">
                                {{-- El `%` dentro del campo y no en la etiqueta: así
                                     se ve al leer el número, que es cuando importa. --}}
                                <div class="relative">
                                    <x-ui.input wire:model.live.debounce.400ms="values.{{ $key }}" :id="$key"
                                                type="number" min="1" max="100" step="1" class="pr-8"
                                                :invalid="$errors->has('values.'.$key)" />
                                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-slate-400">
                                        %
                                    </span>
                                </div>
                            </x-ui.field>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif

            @if ($colores->isNotEmpty())
                <x-ui.card>
                    <h2 class="text-sm font-semibold text-shell-900">Colores</h2>
                    <p class="mt-0.5 text-sm text-slate-500">
                        De qué color se pinta la cifra según el tramo en el que caiga.
                    </p>

                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        @foreach ($colores as $key => $campo)
                            <x-ui.field :label="$campo['label']" :for="$key" :hint="$campo['hint'] ?? null"
                                        :error="$errors->first('values.'.$key)">
                                {{-- El selector y el hexadecimal, atados a la misma
                                     propiedad: se elige con el ratón o se pega el
                                     color corporativo, que es como llegan de verdad. --}}
                                <div class="flex items-center gap-2">
                                    <input type="color" wire:model.live="values.{{ $key }}" :id="$key"
                                           aria-label="{{ $campo['label'] }}"
                                           class="size-9 shrink-0 cursor-pointer rounded-lg border border-slate-300 bg-white p-1">

                                    <x-ui.input wire:model.live.debounce.400ms="values.{{ $key }}"
                                                class="font-mono uppercase" maxlength="7"
                                                :invalid="$errors->has('values.'.$key)" />
                                </div>
                            </x-ui.field>
                        @endforeach
                    </div>

                    {{-- La prueba de que lo elegido se lee. Un verde y un ámbar que
                         sobre blanco no se distinguen sólo se ven aquí. --}}
                    <div class="mt-5 border-t border-slate-100 pt-4">
                        <p class="text-xs font-medium tracking-wide text-slate-500 uppercase">Cómo se verá</p>

                        <div class="mt-2 flex flex-wrap gap-6">
                            @foreach ([
                                ['bad_color', 'minimum_percent', 'Por debajo del mínimo', -15],
                                ['warning_color', 'minimum_percent', 'Entre el mínimo y el óptimo', 5],
                                ['good_color', 'optimal_percent', 'Del óptimo para arriba', 8],
                            ] as [$color, $umbral, $texto, $desvio])
                                @php
                                    // Sin umbral no hay cifra que enseñar: un «0 %»
                                    // con el color puesto haría creer que ya está
                                    // configurado.
                                    $hayUmbral = ($values[$umbral] ?? '') !== '';
                                    $muestra = $hayUmbral
                                        ? max(0, min(150, (int) $values[$umbral] + $desvio)).' %'
                                        : '—';
                                @endphp

                                <div>
                                    <p class="text-xl font-semibold tabular-nums"
                                       @style(['color: '.$values[$color] => preg_match('/^#[0-9a-fA-F]{6}$/', $values[$color] ?? '')])>
                                        {{ $muestra }}
                                    </p>
                                    <p class="text-xs text-slate-500">{{ $texto }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </x-ui.card>
            @endif

        </fieldset>

        @if ($puedeGestionar)
            <div class="flex justify-end">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Guardar</span>
                    <span wire:loading wire:target="save">Guardando…</span>
                </x-ui.button>
            </div>
        @endif
    </form>
</div>
