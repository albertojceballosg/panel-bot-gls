<?php

use App\Models\Courier;
use App\Models\Merchant;
use App\Models\PickupRoute;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Portada del panel. Deliberadamente escueta: §5 descarta el dashboard con
 * gráficas. Sirve para ver de un vistazo que el maestro está donde debe.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    /** @return array<string, mixed> */
    public function with(): array
    {
        return [
            'totales' => [
                ['Comercios', Merchant::count(), 'Los que el bot cruza contra el portal'],
                ['Rutas', PickupRoute::count(), 'Grupos por los que se reparten'],
                ['UT', Courier::count(), 'Quién conduce cada una hoy'],
            ],
        ];
    }
}; ?>

<div>
    <x-ui.page-header title="Maestro de rutas"
                      description="Lo que el bot descarga cada mañana para agrupar los envíos por ruta y detectar incidencias." />

    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        @foreach ($totales as [$label, $total, $detalle])
            <x-ui.card>
                <dt class="text-sm font-medium text-slate-500">{{ $label }}</dt>
                <dd class="mt-2 text-3xl font-semibold tracking-tight tabular-nums text-shell-900">{{ $total }}</dd>
                <p class="mt-1 text-xs text-slate-400">{{ $detalle }}</p>
            </x-ui.card>
        @endforeach
    </dl>
</div>
