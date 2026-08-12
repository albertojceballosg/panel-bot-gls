{{-- Estado vacío. Una tabla vacía sin explicación parece una pantalla rota;
     con una frase, parece una pantalla que espera algo. --}}
@props(['title', 'description' => null])

<div {{ $attributes->class('px-6 py-14 text-center') }}>
    <p class="text-sm font-medium text-slate-900">{{ $title }}</p>

    @if ($description)
        <p class="mx-auto mt-1 max-w-sm text-sm text-slate-500">{{ $description }}</p>
    @endif

    @isset($actions)
        <div class="mt-5 flex justify-center gap-2">{{ $actions }}</div>
    @endisset
</div>
