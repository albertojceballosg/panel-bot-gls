{{-- Paginador del panel.

     Anterior/siguiente en vez de numerado: con 93 comercios salían diez botones
     que se comían el pie de la tabla entero. Y colores suaves, porque esto es
     navegación secundaria — el peso visual se lo lleva la tabla, no su pie. --}}
@php
    $base = 'inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition';
    $activo = $base.' border-slate-300 bg-white text-slate-700 shadow-sm hover:bg-slate-50 hover:text-slate-900';
    $inerte = $base.' cursor-not-allowed border-slate-200 bg-slate-50 text-slate-300';
@endphp

@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}"
         class="flex items-center gap-2">

        <div class="flex items-center gap-2">
            @if ($paginator->onFirstPage())
                <span class="{{ $inerte }}" aria-disabled="true">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                    </svg>
                    Anterior
                </span>
            @else
                <button type="button" wire:click="previousPage" wire:loading.attr="disabled" class="{{ $activo }}">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                    </svg>
                    Anterior
                </button>
            @endif

            <span class="px-1 text-sm text-slate-400 tabular-nums">
                {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}
            </span>

            @if ($paginator->hasMorePages())
                <button type="button" wire:click="nextPage" wire:loading.attr="disabled" class="{{ $activo }}">
                    Siguiente
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                    </svg>
                </button>
            @else
                <span class="{{ $inerte }}" aria-disabled="true">
                    Siguiente
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                    </svg>
                </span>
            @endif
        </div>
    </nav>
@endif
