{{-- Grupo plegable de la barra lateral: la fila es pulsable y despliega sus
     hijos.

     Nace abierto si estás dentro de una de sus pantallas. No es cosmético:
     `wire:navigate` reconstruye la página entera y el estado de Alpine no
     sobrevive al salto, así que sin esto el grupo se cerraría justo al llegar
     a la pantalla que acabas de abrir desde él.

     Recibe las rutas de sus hijos en vez de mirarlas él: la lista vive en el
     array del layout y así añadir una pantalla sigue siendo una línea. --}}
@props(['label', 'routes' => []])

@php
    $dentro = collect($routes)->contains(fn (string $ruta) => request()->routeIs($ruta));
@endphp

<div x-data="{ abierto: @js($dentro) }">
    <button type="button" @click="abierto = ! abierto"
            x-bind:aria-expanded="abierto ? 'true' : 'false'"
            {{ $attributes->class([
                'group flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition',
                'text-white' => $dentro,
                'text-slate-400 hover:bg-white/5 hover:text-white' => ! $dentro,
            ]) }}>
        <span class="{{ $dentro ? 'text-brand-400' : 'text-slate-500 group-hover:text-slate-300' }}">
            {{ $icon }}
        </span>

        <span class="flex-1 text-left">{{ $label }}</span>

        {{-- El chevron es la única pista de que esto se pliega, así que gira
             para decir en qué estado está. --}}
        <svg class="size-4 shrink-0 text-slate-500 transition-transform duration-200"
             x-bind:class="abierto && 'rotate-90'"
             fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
        </svg>
    </button>

    {{-- La guía vertical a la izquierda es lo que dice que estos enlaces
         cuelgan del de arriba y no son hermanos suyos. --}}
    <div x-show="abierto" x-cloak class="mt-1 ml-6 space-y-1 border-l border-white/10 pl-3">
        {{ $slot }}
    </div>
</div>
