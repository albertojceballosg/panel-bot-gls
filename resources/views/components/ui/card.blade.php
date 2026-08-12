{{-- Superficie. Borde suave en vez de sombra fuerte: con muchas tarjetas, las
     sombras compiten entre sí y la pantalla se ensucia. --}}
@props(['padding' => 'p-6'])

<div {{ $attributes->class('rounded-xl border border-slate-200 bg-white shadow-sm '.$padding) }}>
    {{ $slot }}
</div>
