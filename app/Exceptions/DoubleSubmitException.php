<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanzó la misma acción dos veces antes de que la primera terminara.
 *
 * No es un error del usuario: casi siempre es un doble clic o un reintento del
 * navegador. Quien la captura decide si avisar o callar.
 */
class DoubleSubmitException extends RuntimeException
{
    public function __construct(string $action)
    {
        parent::__construct("La acción «{$action}» ya se está procesando.");
    }
}
