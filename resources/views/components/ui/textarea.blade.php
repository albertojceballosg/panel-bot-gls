{{-- Como `input`, pero para prosa. Mismo borde y mismo tratamiento del error:
     si se hiciera con `x-ui.input` y un `is="textarea"` acabaría divergiendo. --}}
@props(['invalid' => false])

@php
    $borde = $invalid
        ? 'border-red-400 focus:border-red-500 focus:ring-red-500'
        : 'border-slate-300 focus:border-brand-500 focus:ring-brand-500';
@endphp

<textarea {{ $attributes->merge(['rows' => 4])->class(
    'block w-full rounded-lg border px-3 py-2 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 '
    .'focus:ring-1 focus:outline-none '.$borde
) }}>{{ $slot }}</textarea>
