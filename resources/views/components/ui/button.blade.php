{{-- Botón. `variant` decide el peso visual; `as` si es <button> o <a>. --}}
@props([
    'variant' => 'primary',
    'as' => 'button',
])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium '
          . 'transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 '
          . 'disabled:cursor-not-allowed disabled:opacity-50';

    $variants = [
        'primary' => 'bg-brand-500 text-white shadow-sm hover:bg-brand-600 focus-visible:ring-brand-500',
        'secondary' => 'border border-slate-300 bg-white text-slate-700 shadow-sm hover:bg-slate-50 focus-visible:ring-slate-400',
        'ghost' => 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 focus-visible:ring-slate-400',
        'danger' => 'text-red-600 hover:bg-red-50 focus-visible:ring-red-500',
    ];

    $classes = $base.' '.($variants[$variant] ?? $variants['primary']);
@endphp

@if ($as === 'a')
    <a {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $attributes->get('type', 'button') }}" {{ $attributes->class($classes)->except('type') }}>
        {{ $slot }}
    </button>
@endif
