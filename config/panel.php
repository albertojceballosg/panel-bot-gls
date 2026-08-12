<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Usuario inicial
    |--------------------------------------------------------------------------
    |
    | Con el que se entra al panel la primera vez. No hay registro público
    | (CONTEXTO.md §7, fase 3): las cuentas se crean aquí o a mano.
    |
    | Las credenciales viven sólo en el .env — §10: ninguna clave se escribe en
    | el repo, ni como ejemplo. Se leen vía config() y no con env() directo para
    | que el seeder siga funcionando con la configuración cacheada.
    |
    */

    'initial_user' => [
        'name' => env('SEED_USER_NAME', 'Panel'),
        'email' => env('SEED_USER_EMAIL'),
        'password' => env('SEED_USER_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Token de GET /api/rutas
    |--------------------------------------------------------------------------
    |
    | El bot lo manda como `Authorization: Bearer <token>`. Debe coincidir con
    | el RUTAS_TOKEN del .env de bot-gls.
    |
    | Es lo único que protege el endpoint, y el endpoint devuelve el maestro
    | completo del cliente (§10): tratarlo como una contraseña. Sin valor, el
    | middleware corta todas las peticiones — nunca abre.
    |
    */

    'bot_token' => env('RUTAS_TOKEN'),

];
