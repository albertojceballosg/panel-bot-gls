{{-- Layout de la parte con sesión. La navegación crece con cada módulo. --}}
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' · '.config('app.name') : config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-100 text-slate-900 antialiased">
    <div class="min-h-full">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-6xl items-center gap-8 px-4 py-3">
                <a href="{{ route('home') }}" class="text-sm font-semibold tracking-tight text-slate-900">
                    {{ config('app.name') }}
                </a>

                <nav class="flex flex-1 items-center gap-1 text-sm">
                    {{-- Los enlaces de rutas, mensajeros y comercios entran con
                         sus módulos. --}}
                </nav>

                <div class="flex items-center gap-3 text-sm">
                    <span class="text-slate-500">{{ auth()->user()->name }}</span>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit"
                                class="rounded-md px-2 py-1 font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900">
                            Salir
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-4 py-8">
            {{ $slot }}
        </main>
    </div>
</body>
</html>
