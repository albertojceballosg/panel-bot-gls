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
    /** @return array<string, int> */
    public function with(): array
    {
        return [
            'merchants' => Merchant::count(),
            'pickupRoutes' => PickupRoute::count(),
            'couriers' => Courier::count(),
        ];
    }
}; ?>

<div>
    <h1 class="text-lg font-semibold tracking-tight text-slate-900">Maestro de rutas</h1>
    <p class="mt-1 text-sm text-slate-500">
        Lo que el bot descarga cada mañana para agrupar los envíos por ruta.
    </p>

    <dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        @foreach ([
            ['Comercios', $merchants],
            ['Rutas', $pickupRoutes],
            ['Mensajeros', $couriers],
        ] as [$label, $total])
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <dt class="text-sm text-slate-500">{{ $label }}</dt>
                <dd class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $total }}</dd>
            </div>
        @endforeach
    </dl>
</div>
