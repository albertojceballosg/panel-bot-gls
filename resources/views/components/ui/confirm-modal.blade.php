{{-- Confirmación de una acción destructiva.

     El nombre del registro va en el texto a propósito: «¿Dar de baja?» a secas
     no te dice si le diste al botón de la fila que creías. --}}
@props([
    'title' => '¿Seguro?',
    'name' => null,
    'confirmLabel' => 'Dar de baja',
    'confirm',
    'cancel' => 'cancelDelete',
])

<x-ui.modal :title="$title" :close="$cancel" width="max-w-md">
    <div class="flex gap-4">
        <span class="grid size-10 shrink-0 place-items-center rounded-full bg-red-50 text-red-600">
            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.376 1.948 3.376h14.71c1.73 0 2.813-1.874 1.948-3.376L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
            </svg>
        </span>

        <div class="text-sm text-slate-600">
            @if ($name)
                <p>Vas a dar de baja <span class="font-medium text-shell-900">«{{ $name }}»</span>.</p>
            @endif

            <p class="{{ $name ? 'mt-1' : '' }}">{{ $slot }}</p>
        </div>
    </div>

    <x-slot:footer>
        <x-ui.button variant="secondary" wire:click="{{ $cancel }}" wire:loading.attr="disabled">
            Cancelar
        </x-ui.button>

        <x-ui.button variant="danger" wire:click="{{ $confirm }}"
                     wire:loading.attr="disabled" wire:target="{{ $confirm }}"
                     class="bg-red-600 text-white shadow-sm hover:bg-red-700 hover:text-white">
            <span wire:loading.remove wire:target="{{ $confirm }}">{{ $confirmLabel }}</span>
            <span wire:loading wire:target="{{ $confirm }}">Dando de baja…</span>
        </x-ui.button>
    </x-slot:footer>
</x-ui.modal>
