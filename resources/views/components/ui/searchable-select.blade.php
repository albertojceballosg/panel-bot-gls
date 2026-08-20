{{-- Desplegable con buscador. Sustituye al `<select>` nativo en todo el panel desde el
     20/08/2026: con 93 comercios y el maestro entero delante, elegir a base de scroll no es
     elegir.

     **Sin librería** (§5): son ~40 líneas de Alpine —que ya viene con Livewire— contra
     Select2, que arrastra jQuery, o Tom Select, que hay que marcar con `wire:ignore` y
     re-sincronizar a mano cada vez que Livewire vuelve a pintar.

     El valor **no vive en Alpine**: se escribe en un input oculto que lleva el `wire:model`
     del llamante, y la etiqueta que se lee con el desplegable cerrado la pinta el servidor a
     partir de ese mismo valor. Así no hay dos verdades que puedan discrepar tras un render.

     Uso:

         <x-ui.searchable-select wire:model.live="pickup_route_id"
                                 :options="$pickupRoutes->pluck('name', 'id')->all()"
                                 :value="$pickup_route_id"
                                 placeholder="Elige una ruta…" /> --}}
@props([
    // [valor => etiqueta], en el orden en que se pintan.
    'options' => [],
    // El valor actual. Se pasa aparte porque el componente sólo conoce el nombre de la
    // propiedad de Livewire, no lo que vale.
    'value' => '',
    'placeholder' => 'Elige una opción…',
    'searchPlaceholder' => 'Buscar…',
    'invalid' => false,
    'id' => null,
])

@php
    // A pares y no a array asociativo: PHP convierte a entero toda clave numérica, así que un
    // id acabaría comparándose con `===` contra la cadena que trae Livewire y nunca casaría —
    // la opción elegida no se resaltaba y el valor viajaba como número.
    $opciones = collect($options)
        ->map(fn ($label, $key) => ['value' => (string) $key, 'label' => (string) $label])
        ->values();

    $actual = (string) ($value ?? '');
    $elegida = $opciones->firstWhere('value', $actual)['label'] ?? null;

    $borde = $invalid
        ? 'border-red-400 focus:border-red-500 focus:ring-red-500'
        : 'border-slate-300 focus:border-brand-500 focus:ring-brand-500';
@endphp

<div x-data="searchableSelect" @click.outside="close()" @keydown.escape.stop="close()"
     {{ $attributes->except(['wire:model', 'wire:model.live', 'wire:model.blur'])->class('relative') }}>

    {{-- Lo que ve Livewire. Oculto, pero un `<input>` de verdad: el `wire:model` del llamante
         se le pega tal cual, así que funcionan igual el diferido, el `.live` y el `.blur`. --}}
    <input type="hidden" x-ref="input" value="{{ $actual }}"
           {{ $attributes->whereStartsWith('wire:model') }}>

    <button type="button" x-ref="button" @click="toggle()"
            @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
            @if ($id) id="{{ $id }}" @endif
            role="combobox" aria-haspopup="listbox" :aria-expanded="open"
            class="flex w-full items-center justify-between gap-2 rounded-lg border bg-white px-3 py-2 text-left text-sm shadow-sm focus:ring-1 focus:outline-none {{ $borde }}">
        <span @class([
            'truncate',
            'text-slate-900' => $elegida !== null,
            // Sin elegir se lee como un marcador de posición y no como una opción más.
            'text-slate-400' => $elegida === null,
        ])>{{ $elegida ?? $placeholder }}</span>

        <svg class="size-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24"
             stroke-width="2" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
        </svg>
    </button>

    <div x-show="open" x-cloak x-transition.opacity.duration.100ms
         class="absolute z-30 mt-1 w-full overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg">

        <div class="border-b border-slate-100 p-2">
            <input type="text" x-ref="query" x-model="query" @input="filter()"
                   @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
                   @keydown.enter.prevent="choose()" @keydown.tab="close()"
                   placeholder="{{ $searchPlaceholder }}" aria-label="{{ $searchPlaceholder }}"
                   class="block w-full rounded-md border border-slate-200 px-2.5 py-1.5 text-sm text-slate-900 placeholder:text-slate-400 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 focus:outline-none">
        </div>

        {{-- Las opciones las pinta Blade y Alpine sólo las esconde: si la lista viviera en JS
             habría que re-sincronizarla en cada render de Livewire. --}}
        <ul x-ref="list" role="listbox" class="max-h-60 overflow-y-auto py-1 text-sm">
            @if ($placeholder !== null)
                <li data-option data-search="{{ $placeholder }}" role="option"
                    @click="pick('')"
                    class="cursor-pointer px-3 py-1.5 text-slate-400 hover:bg-slate-100">
                    {{ $placeholder }}
                </li>
            @endif

            @foreach ($opciones as $opcion)
                <li data-option data-search="{{ $opcion['label'] }}" role="option"
                    @click="pick(@js($opcion['value']))"
                    @class([
                        'cursor-pointer px-3 py-1.5 hover:bg-slate-100',
                        'font-medium text-brand-700' => $opcion['value'] === $actual,
                        'text-slate-700' => $opcion['value'] !== $actual,
                    ])>
                    {{ $opcion['label'] }}
                </li>
            @endforeach

            <li x-ref="empty" hidden class="px-3 py-2 text-slate-400">Nada coincide</li>
        </ul>
    </div>
</div>
