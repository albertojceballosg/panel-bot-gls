{{-- Botón de sólo icono.

     `label` es obligatorio: al quitar el texto visible, sin él la acción queda
     muda para un lector de pantalla y sin pista al pasar el ratón. --}}
@props([
    'label',
    'variant' => 'neutral',
])

@php
    $variants = [
        'neutral' => 'text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus-visible:ring-slate-400',
        'danger' => 'text-slate-500 hover:bg-red-50 hover:text-red-600 focus-visible:ring-red-500',
    ];
@endphp

<button type="button" title="{{ $label }}"
        {{ $attributes->class(
            'inline-flex size-8 items-center justify-center rounded-lg transition '
            .'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 '
            .'disabled:cursor-not-allowed disabled:opacity-40 '
            .($variants[$variant] ?? $variants['neutral'])
        ) }}>
    <span class="sr-only">{{ $label }}</span>
    {{ $slot }}
</button>
