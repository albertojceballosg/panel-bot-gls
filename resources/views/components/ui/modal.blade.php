{{-- Diálogo. Se renderiza sólo cuando el componente lo pide, así que el estado
     vive en Livewire y aquí sólo está la presentación.

     `close` es el método del componente que lo cierra: por defecto `cancel`,
     configurable para no atar la primitiva a un nombre concreto. --}}
@props([
    'title',
    'description' => null,
    'close' => 'cancel',
])

<div class="fixed inset-0 z-40 overflow-y-auto"
     role="dialog" aria-modal="true" aria-labelledby="modal-title"
     x-data
     x-on:keydown.escape.window="$wire.{{ $close }}()">

    {{-- Velo. Al pulsarlo se cierra, como se espera de un diálogo. --}}
    <div class="fixed inset-0 bg-shell-950/40 backdrop-blur-[1px]"
         wire:click="{{ $close }}" aria-hidden="true"></div>

    <div class="flex min-h-full items-end justify-center p-4 sm:items-center">
        <div {{ $attributes->class('relative w-full max-w-lg rounded-xl bg-white shadow-xl ring-1 ring-slate-900/5') }}>
            <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-4">
                <div>
                    <h2 id="modal-title" class="text-base font-semibold text-shell-900">{{ $title }}</h2>

                    @if ($description)
                        <p class="mt-0.5 text-sm text-slate-500">{{ $description }}</p>
                    @endif
                </div>

                <button type="button" wire:click="{{ $close }}"
                        class="-mr-1.5 -mt-0.5 rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">
                    <span class="sr-only">Cerrar</span>
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="px-6 py-5">{{ $slot }}</div>

            @isset($footer)
                <div class="flex justify-end gap-2 rounded-b-xl border-t border-slate-200 bg-slate-50 px-6 py-4">
                    {{ $footer }}
                </div>
            @endisset
        </div>
    </div>
</div>
