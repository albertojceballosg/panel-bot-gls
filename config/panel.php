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

    'usuario_inicial' => [
        'nombre' => env('SEED_USER_NAME', 'Panel'),
        'email' => env('SEED_USER_EMAIL'),
        'password' => env('SEED_USER_PASSWORD'),
    ],

];
