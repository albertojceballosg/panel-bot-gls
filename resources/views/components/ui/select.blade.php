{{-- Desplegable, con el mismo aspecto que `input` para que un formulario mixto
     no parezca dos formularios. --}}
@props(['invalid' => false])

@php
    $borde = $invalid
        ? 'border-red-400 focus:border-red-500 focus:ring-red-500'
        : 'border-slate-300 focus:border-brand-500 focus:ring-brand-500';
@endphp

<select {{ $attributes->class(
    'block w-full rounded-lg border bg-white px-3 py-2 text-sm text-slate-900 shadow-sm '
    .'focus:ring-1 focus:outline-none '.$borde
) }}>
    {{ $slot }}
</select>
