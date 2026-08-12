{{-- Etiqueta + control + error, juntos. Sin esto los tres se repiten en cada
     formulario y el error acaba puesto de distinta forma en cada sitio. --}}
@props([
    'label',
    'for' => null,
    'error' => null,
    'hint' => null,
])

<div {{ $attributes->class('space-y-1.5') }}>
    <label @if ($for) for="{{ $for }}" @endif class="block text-sm font-medium text-slate-700">
        {{ $label }}
    </label>

    {{ $slot }}

    @if ($hint && ! $error)
        <p class="text-xs text-slate-500">{{ $hint }}</p>
    @endif

    @if ($error)
        <p class="text-sm text-red-600">{{ $error }}</p>
    @endif
</div>
