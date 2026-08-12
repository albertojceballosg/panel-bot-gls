{{-- Layout para quien todavía no ha entrado: sin navegación ni nada que tocar. --}}
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-100 text-slate-900 antialiased">
    <main class="flex min-h-full items-center justify-center px-4 py-12">
        {{ $slot }}
    </main>
</body>
</html>
