<?php

namespace App\Support;

/**
 * Avisos flotantes desde una pantalla (`components/ui/toasts`).
 *
 * Un trait de tres líneas y no un `session()->flash()` suelto en cada
 * componente: así el nombre del evento y la forma de su carga están escritos en
 * un solo sitio, y el contenedor del layout puede cambiar sin recorrer diez
 * pantallas.
 *
 * `success` se va solo a los cinco segundos; `error` se queda hasta que lo
 * cierras, porque dice por qué **no** se hizo lo que pediste.
 */
trait SendsToasts
{
    protected function toast(string $message, string $type = 'success'): void
    {
        $this->dispatch('toast', type: $type, message: $message);
    }

    protected function toastError(string $message): void
    {
        $this->toast($message, 'error');
    }
}
