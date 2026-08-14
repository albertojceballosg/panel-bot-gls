<?php

/*
| Mensajes de validación en castellano.
|
| Laravel sólo trae los ingleses, así que sin este fichero el panel —que está
| entero en castellano— respondía «The name has already been taken». Está aquí
| y no repartido por los componentes para que el mensaje de una misma regla sea
| el mismo en todas las pantallas.
|
| Sólo están las reglas que se usan. Añadir una regla nueva es añadir su línea.
*/

return [

    'boolean' => 'El campo :attribute tiene que ser sí o no.',
    'confirmed' => 'El campo :attribute y su repetición no coinciden.',
    // Sin :attribute: la usa el perfil para autorizar el cambio de contraseña,
    // y ahí lo que falla es la que se ha escrito, no «el campo».
    'current_password' => 'La contraseña actual no es correcta.',
    // :decimal se sustituye por el rango de la regla («0-3»), de ahí el «con».
    'decimal' => 'El campo :attribute tiene que ser un número con :decimal decimales.',
    'email' => 'Eso no parece un correo válido.',
    'exists' => 'La opción elegida no existe.',
    // Sin :attribute: el único sitio que la usa es la subida de una copia, y
    // «el campo fichero tiene que ser un fichero» no le dice nada a nadie.
    'file' => 'Eso no parece un fichero.',
    'integer' => 'El campo :attribute tiene que ser un número entero.',
    'numeric' => 'El campo :attribute tiene que ser un número.',
    'required' => 'El campo :attribute es obligatorio.',
    'string' => 'El campo :attribute tiene que ser texto.',
    'unique' => 'Ya existe un registro con ese :attribute.',

    'gt' => [
        'array' => 'El campo :attribute tiene que tener más de :value elementos.',
        'file' => 'El campo :attribute tiene que ocupar más de :value kilobytes.',
        'numeric' => 'El campo :attribute tiene que ser mayor que :value.',
        'string' => 'El campo :attribute tiene que tener más de :value caracteres.',
    ],

    'max' => [
        'array' => 'El campo :attribute no puede tener más de :max elementos.',
        'file' => 'El campo :attribute no puede ocupar más de :max kilobytes.',
        'numeric' => 'El campo :attribute no puede ser mayor que :max.',
        'string' => 'El campo :attribute no puede tener más de :max caracteres.',
    ],

    'min' => [
        'array' => 'El campo :attribute tiene que tener al menos :min elementos.',
        'file' => 'El campo :attribute tiene que ocupar al menos :min kilobytes.',
        'numeric' => 'El campo :attribute tiene que ser al menos :min.',
        'string' => 'El campo :attribute tiene que tener al menos :min caracteres.',
    ],

    /*
    | Cómo se llaman los campos de cara al usuario. Sin esto los mensajes dirían
    | «El campo pickup_route_id es obligatorio», que no le sirve a nadie.
    */
    'attributes' => [
        'code' => 'código',
        'courier_id' => 'UT',
        'current_password' => 'contraseña actual',
        'email' => 'correo',
        'last_name' => 'apellido',
        'maximum_volume' => 'volumen máximo',
        'name' => 'nombre',
        'password' => 'contraseña',
        'pickup_route_id' => 'ruta',
    ],

];
