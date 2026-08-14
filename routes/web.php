<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
| El panel. Todo detrás de sesión: no hay ninguna pantalla pública.
|
| Ojo, `GET /api/rutas` no vive aquí a propósito (routes/api.php): el endpoint
| que consume el bot no debe depender de sesión ni de CSRF (CONTEXTO.md §2).
*/

Route::middleware('guest')->group(function () {
    Route::livewire('/login', 'login')->name('login');
});

Route::middleware('auth')->group(function () {
    Route::livewire('/', 'home')->name('home');
    Route::livewire('/pickup-routes', 'pickup-routes')->name('pickup-routes');
    Route::livewire('/couriers', 'couriers')->name('couriers');
    Route::livewire('/merchants', 'merchants')->name('merchants');
    Route::livewire('/audit-logs', 'audit-logs')->name('audit-logs');

    // Operaciones: lo que sube el bot, no lo que mantiene el cliente.
    Route::livewire('/incidents', 'incidents')->name('incidents');

    // La fecha en la URL y no el id: es la clave natural de una jornada
    // (`incident_runs.run_date` es único) y hace el enlace legible.
    Route::livewire('/incidents/{date}', 'incident-run')->name('incident-run');

    // La semana va en la query (`?semana=`) y no en el path: es un filtro con
    // valor por defecto —la semana en curso—, no otra pantalla.
    Route::livewire('/capacity-calendar', 'capacity-calendar')->name('capacity-calendar');

    // POST y no GET: un enlace de salida se puede disparar desde fuera, o lo
    // precarga el navegador.
    Route::post('/logout', function (Request $request) {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});
