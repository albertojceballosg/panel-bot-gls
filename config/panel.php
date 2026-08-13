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
    | Token de la API del bot
    |--------------------------------------------------------------------------
    |
    | El bot lo manda como `Authorization: Bearer <token>` en las dos
    | direcciones: `GET /api/rutas` y `POST /api/incidencias`. Un solo token
    | porque hay un solo consumidor; debe coincidir con el RUTAS_TOKEN y el
    | PANEL_TOKEN del .env de bot-gls.
    |
    | Es lo único que protege los endpoints, y uno de ellos devuelve el maestro
    | completo del cliente (§10): tratarlo como una contraseña. Sin valor, el
    | middleware corta todas las peticiones — nunca abre.
    |
    */

    'bot_token' => env('RUTAS_TOKEN'),

];
