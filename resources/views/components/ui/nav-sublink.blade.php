{{-- Enlace hijo de un `nav-group`. Sin icono a propósito: lo lleva el padre, y
     repetirlo aquí competiría con él en vez de decir que cuelga de él. La
     sangría y la guía vertical del grupo son lo que marca la jerarquía. --}}
@props(['route', 'owns' => null])

@php
    // `owns` deja marcar el enlace también desde sus pantallas de detalle: la
    // jornada de incidencias sigue siendo «Incidencias».
    $activo = request()->routeIs($owns ?? $route);
@endphp

<a href="{{ route($route) }}" wire:navigate
   @if ($activo) aria-current="page" @endif
   {{ $attributes->class([
       'block rounded-lg px-3 py-2 text-sm font-medium transition',
       'bg-brand-500/15 text-white' => $activo,
       'text-slate-400 hover:bg-white/5 hover:text-white' => ! $activo,
   ]) }}>
    {{ $slot }}
</a>
