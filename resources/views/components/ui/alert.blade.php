{{-- Aviso de resultado. Se usa para los mensajes flash, incluido el de "esta
     ruta todavía tiene comercios", que si no llegaría como excepción cruda. --}}
@props(['type' => 'success'])

@php
    $estilos = [
        'success' => ['bg-emerald-50 text-emerald-800 ring-emerald-200', 'M4.5 12.75l6 6 9-13.5'],
        'error' => ['bg-red-50 text-red-800 ring-red-200', 'M12 9v3.75m0 3.75h.01M10.34 3.94l-7.6 13.17A1.5 1.5 0 004.04 19.5h15.92a1.5 1.5 0 001.3-2.39l-7.6-13.17a1.5 1.5 0 00-2.6 0z'],
    ];

    [$clases, $icono] = $estilos[$type] ?? $estilos['success'];
@endphp

<div {{ $attributes->class('flex items-start gap-2.5 rounded-lg px-4 py-3 text-sm ring-1 ring-inset '.$clases) }}
     role="alert">
    <svg class="mt-0.5 size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icono }}" />
    </svg>
    <span>{{ $slot }}</span>
</div>
