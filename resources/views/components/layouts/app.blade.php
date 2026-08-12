{{-- Layout de la parte con sesión: barra lateral fija y contenido a la derecha.

     Los enlaces se declaran una sola vez, en $modulos: añadir un módulo es una
     línea, no tocar el marcado. Los iconos van en SVG dentro del propio Blade
     porque una librería de iconos sería una dependencia (§5). --}}
@php
    $modulos = [
        ['route' => 'home', 'label' => 'Inicio', 'icon' => 'M2.25 12l8.955-8.955a1.125 1.125 0 011.59 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75'],
        ['route' => 'pickup-routes', 'label' => 'Rutas', 'icon' => 'M9 6.75V15m6-6v8.25m.503 3.498l4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 00-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0z'],
        ['route' => 'couriers', 'label' => 'Mensajeros', 'icon' => 'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z'],
        ['route' => 'merchants', 'label' => 'Comercios', 'icon' => 'M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349m-16.5 11.65V9.35m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.016a3.001 3.001 0 003.75.614m-16.5 0a3.004 3.004 0 01-.621-4.72L4.318 3.44A1.5 1.5 0 015.378 3h13.243a1.5 1.5 0 011.06.44l1.19 1.189a3 3 0 01-.621 4.72m-13.5 8.65h3.75a.75.75 0 00.75-.75V13.5a.75.75 0 00-.75-.75H6.75a.75.75 0 00-.75.75v3.75c0 .415.336.75.75.75z'],
        ['route' => 'audit-logs', 'label' => 'Auditoría', 'icon' => 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z'],
    ];
@endphp

<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · '.config('app.name') : config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-100 text-slate-900 antialiased">
<div x-data="{ abierta: false }" class="min-h-full">

    {{-- Velo para móvil: al tocarlo se cierra la barra. --}}
    <div x-show="abierta" x-cloak @click="abierta = false"
         class="fixed inset-0 z-20 bg-shell-950/50 lg:hidden"></div>

    <aside x-bind:class="abierta ? 'translate-x-0' : '-translate-x-full'"
           class="fixed inset-y-0 left-0 z-30 flex w-64 flex-col bg-shell-900 transition-transform duration-200
                  lg:translate-x-0">
        <div class="flex h-16 shrink-0 items-center gap-2.5 px-5">
            <span class="grid size-8 place-items-center rounded-lg bg-brand-500 text-sm font-bold text-white">
                GLS
            </span>
            <span class="text-sm font-semibold text-white">{{ config('app.name') }}</span>
        </div>

        <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
            <p class="px-3 pb-2 text-xs font-semibold tracking-wider text-slate-500 uppercase">Maestro</p>

            @foreach ($modulos as $modulo)
                <x-ui.nav-link :route="$modulo['route']">
                    <x-slot:icon>
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $modulo['icon'] }}" />
                        </svg>
                    </x-slot:icon>
                    {{ $modulo['label'] }}
                </x-ui.nav-link>
            @endforeach
        </nav>

        <div class="border-t border-white/10 p-3">
            <div class="flex items-center gap-3 rounded-lg px-3 py-2">
                <span class="grid size-8 shrink-0 place-items-center rounded-full bg-white/10 text-xs font-semibold text-white">
                    {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-white">{{ auth()->user()->name }}</p>
                    <p class="truncate text-xs text-slate-400">{{ auth()->user()->email }}</p>
                </div>
            </div>

            <form method="POST" action="{{ route('logout') }}" class="mt-1">
                @csrf
                <button type="submit"
                        class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-400
                               transition hover:bg-white/5 hover:text-white">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
                    </svg>
                    Salir
                </button>
            </form>
        </div>
    </aside>

    <div class="lg:pl-64">
        <header class="sticky top-0 z-10 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/90 px-4
                       backdrop-blur lg:px-8">
            <button type="button" @click="abierta = true"
                    class="-ml-1 rounded-lg p-2 text-slate-500 hover:bg-slate-100 lg:hidden">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                </svg>
            </button>

            <p class="text-sm font-medium text-slate-500">Maestro de rutas de recogida</p>
        </header>

        <main class="px-4 py-8 lg:px-8">
            <div class="mx-auto max-w-5xl">{{ $slot }}</div>
        </main>
    </div>
</div>
</body>
</html>
