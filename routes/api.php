<?php

use App\Http\Controllers\Api\RouteMasterController;
use App\Http\Middleware\VerifyBotToken;
use Illuminate\Support\Facades\Route;

/*
| El maestro que consume el bot (CONTEXTO.md §2 y §3).
|
| Va aquí y no en web.php a propósito: el grupo `api` no arrastra sesión ni
| CSRF, y este endpoint no debe depender de nada de la interfaz. Es el producto;
| el panel es la forma de alimentarlo.
|
| La URL se queda en castellano: `GET /api/rutas` es parte del contrato, y
| cambiarla obligaría a tocar el RUTAS_URL del bot.
*/
Route::middleware(VerifyBotToken::class)
    ->get('/rutas', RouteMasterController::class)
    ->name('api.route-master');
