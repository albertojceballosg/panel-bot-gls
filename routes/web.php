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

    // POST y no GET: un enlace de salida se puede disparar desde fuera, o lo
    // precarga el navegador.
    Route::post('/logout', function (Request $request) {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});
