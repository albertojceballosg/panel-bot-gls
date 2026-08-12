<?php

namespace App\Support;

use App\Exceptions\DoubleSubmitException;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Impide que la misma acción se procese dos veces a la vez.
 *
 * Desactivar el botón en el navegador es cosmético: no cubre el doble clic que
 * llega antes de que el JS reaccione, ni dos pestañas, ni una petición
 * reenviada. El cerrojo es atómico y vive en el servidor, que es donde se
 * decide.
 *
 * Se apoya en `Cache::lock`, que sobre el store de base de datos usa la tabla
 * `cache_locks` con una inserción única: dos peticiones simultáneas no pueden
 * cogerlo las dos.
 */
trait PreventsDoubleSubmit
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws DoubleSubmitException si la acción ya está en curso.
     */
    protected function withoutDoubleSubmit(string $action, Closure $callback, int $seconds = 10): mixed
    {
        $lock = Cache::lock($this->doubleSubmitKey($action), $seconds);

        if (! $lock->get()) {
            throw new DoubleSubmitException($action);
        }

        try {
            return $callback();
        } finally {
            // En `finally` para que un fallo dentro no deje la acción bloqueada
            // hasta que expire el cerrojo.
            $lock->release();
        }
    }

    /**
     * Por usuario, o por sesión cuando todavía no hay usuario —el login—, para
     * que el doble envío de uno no bloquee al resto.
     */
    protected function doubleSubmitKey(string $action): string
    {
        $quien = auth()->id() ?? session()->getId();

        return "double-submit:{$action}:{$quien}";
    }
}
