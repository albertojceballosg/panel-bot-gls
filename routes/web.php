<?php

use App\Support\DatabaseBackup;
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

/*
| Cada pantalla declara aquí el permiso con el que se entra (§7, fase 12). Se
| usa el `can:` de Laravel y no el `permission:` del paquete porque
| `spatie/laravel-permission` registra los permisos en el Gate: así la
| comprobación es la del framework y no hay un alias más que recordar.
|
| **Es la puerta, no la única cerradura**: dentro de cada pantalla las acciones
| que escriben vuelven a comprobar su `.manage`, porque un permiso de ver no
| puede convertirse en uno de escribir por llegar a un método de Livewire.
|
| `home` y `profile` se quedan sin permiso a propósito: son la portada y la
| cuenta de uno mismo, y quien entra al panel tiene las dos por definición.
*/
Route::middleware('auth')->group(function () {
    Route::livewire('/', 'home')->name('home');
    Route::livewire('/pickup-routes', 'pickup-routes')->name('pickup-routes')
        ->middleware('can:pickup-routes.view');
    Route::livewire('/couriers', 'couriers')->name('couriers')
        ->middleware('can:couriers.view');
    Route::livewire('/merchants', 'merchants')->name('merchants')
        ->middleware('can:merchants.view');
    Route::livewire('/audit-logs', 'audit-logs')->name('audit-logs')
        ->middleware('can:audit-logs.view');

    // Operaciones: lo que sube el bot, no lo que mantiene el cliente.
    Route::livewire('/incidents', 'incidents')->name('incidents')
        ->middleware('can:incidents.view');

    // La fecha en la URL y no el id: es la clave natural de una jornada
    // (`incident_runs.run_date` es único) y hace el enlace legible.
    Route::livewire('/incidents/{date}', 'incident-run')->name('incident-run')
        ->middleware('can:incidents.view');

    // La semana va en la query (`?semana=`) y no en el path: es un filtro con
    // valor por defecto —la semana en curso—, no otra pantalla.
    Route::livewire('/capacity-calendar', 'capacity-calendar')->name('capacity-calendar')
        ->middleware('can:capacity-calendar.view');

    // La cuenta de uno mismo. Fuera de la barra lateral a propósito: se llega
    // desde el menú de la cabecera, que es donde se busca «mi cuenta».
    Route::livewire('/profile', 'profile')->name('profile');

    // Configuraciones: los parámetros con los que trabajan otras pantallas.
    // El módulo va en el path y no en la query porque cada uno es una pantalla
    // distinta, no un filtro sobre la misma; los válidos los decide
    // `SettingsCatalog`, y el resto es un 404.
    Route::livewire('/settings/{module}', 'settings')->name('settings')
        ->middleware('can:settings.view');

    // Los gastos cuelgan de Configuraciones en el menú, pero son una pantalla
    // suya y no un módulo de `/settings/{module}`: aquello es clave/valor y
    // esto es un maestro con altas y bajas. De ahí el permiso propio.
    Route::livewire('/expenses', 'expenses')->name('expenses')
        ->middleware('can:expenses.view');

    // Donde está el dinero: lo que cuesta cada ruta al mes. Mismo permiso que
    // el catálogo —son las dos mitades de lo mismo— y el mes va en el estado
    // del componente y no en la URL, porque es un filtro con valor por defecto.
    Route::livewire('/route-expenses', 'route-expenses')->name('route-expenses')
        ->middleware('can:expenses.view');

    // Sistema: mantenimiento del panel, no del maestro.
    Route::livewire('/roles', 'roles')->name('roles')
        ->middleware('can:roles.view');
    Route::livewire('/users', 'users')->name('users')
        ->middleware('can:users.view');
    Route::livewire('/backups', 'backups')->name('backups')
        ->middleware('can:backups.manage');

    // El volcado se genera y se manda al navegador en la misma petición. Ruta
    // normal y no acción de Livewire: una descarga la sirve el navegador, y
    // pasarla por Livewire obligaría a cargar el fichero entero en memoria.
    //
    // `deleteFileAfterSend` es la mitad importante: el temporal se borra al
    // terminar el envío, así que en el servidor no queda ninguna copia con los
    // datos del cliente dentro (§9).
    Route::get('/backups/download', function (DatabaseBackup $backups) {
        try {
            $ruta = $backups->dump();
        } catch (RuntimeException $e) {
            return redirect()->route('backups')->with('error', $e->getMessage());
        }

        return response()->download($ruta, $backups->filename())->deleteFileAfterSend();
    })->middleware('can:backups.manage')->name('backups.download');

    // POST y no GET: un enlace de salida se puede disparar desde fuera, o lo
    // precarga el navegador.
    Route::post('/logout', function (Request $request) {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});
