{{-- Entrada de la barra lateral. El estado activo se decide aquí y no en el
     layout, para que añadir un módulo sea una línea y no un `if` repetido. --}}
@props(['route'])

@php
    $activo = request()->routeIs($route);
@endphp

<a href="{{ route($route) }}" wire:navigate
   @if ($activo) aria-current="page" @endif
   {{ $attributes->class([
       'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition',
       'bg-brand-500/15 text-white' => $activo,
       'text-slate-400 hover:bg-white/5 hover:text-white' => ! $activo,
   ]) }}>
    <span class="{{ $activo ? 'text-brand-400' : 'text-slate-500 group-hover:text-slate-300' }}">
        {{ $icon }}
    </span>
    {{ $slot }}
</a>
