{{-- El control en sí. Va aparte de `field` para poder meter dentro un select o
     un textarea con el mismo aspecto sin duplicar el bloque de la etiqueta. --}}
@props(['invalid' => false])

@php
    $borde = $invalid
        ? 'border-red-400 focus:border-red-500 focus:ring-red-500'
        : 'border-slate-300 focus:border-brand-500 focus:ring-brand-500';
@endphp

<input {{ $attributes->class(
    'block w-full rounded-lg border px-3 py-2 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 '
    .'focus:ring-1 focus:outline-none '.$borde
) }}>
