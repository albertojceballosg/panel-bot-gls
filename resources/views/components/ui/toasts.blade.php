{{-- Avisos flotantes, al estilo de los *toasts* de TailAdmin. Como el resto del
     lenguaje visual: copiado el aire, no instalada la librería (§5).

     Vive una sola vez en el layout y escucha en `window`, así que cualquier
     pantalla puede lanzar uno con

         $this->dispatch('toast', type: 'success', message: 'Perfil actualizado.');

     Livewire v3 publica sus `dispatch` como eventos del navegador, de ahí que
     baste Alpine para recogerlos: no hace falta que el componente sepa nada del
     toast ni que exista un hueco en su plantilla.

     **Por qué esto y no el `x-ui.alert` de siempre**: el alert vive dentro del
     flujo y empuja la página hacia abajo. En el perfil eso movía el formulario
     justo después de guardar, y el toast dice lo mismo sin tocar el sitio de
     nada. El alert se queda para lo que hay que leer sí o sí —una corrida no
     fiable, una copia que no se pudo restaurar—, que no debe desaparecer solo.

     `aria-live="polite"` y no `assertive`: es una confirmación, no una alarma;
     con assertive el lector de pantalla cortaría lo que estuviera diciendo. --}}
<div x-data="{
        toasts: [],
        siguiente: 1,

        add(detalle) {
            const id = this.siguiente++;
            const type = detalle?.type ?? 'success';

            if (! detalle?.message) {
                return;
            }

            this.toasts.push({ id, type, message: detalle.message });

            // Una confirmación se va sola a los cinco segundos: da tiempo a
            // leer una frase y no obliga a cerrar nada. **Un error se queda**
            // hasta que lo cierres: dice por qué no se hizo lo que pediste, y
            // eso no puede evaporarse mientras miras a otro lado.
            const espera = detalle?.duration ?? (type === 'success' ? 5000 : null);

            if (espera) {
                setTimeout(() => this.remove(id), espera);
            }
        },

        remove(id) {
            this.toasts = this.toasts.filter((t) => t.id !== id);
        },
     }"
     {{-- Lo que llega en la sesión tras una redirección: una descarga que falló,
          la restauración que se lleva por delante la sesión. Ahí no hay evento
          de Livewire que valga —es una carga de página entera—, así que el
          contenedor los recoge al arrancar y los enseña igual que a los demás. --}}
     x-init="@js(array_values(array_filter([
                session('ok') ? ['type' => 'success', 'message' => session('ok')] : null,
                session('error') ? ['type' => 'error', 'message' => session('error')] : null,
             ]))).forEach((t) => add(t))"
     x-on:toast.window="add($event.detail)"
     role="status" aria-live="polite"
     {{-- `pointer-events-none` en el contenedor y `auto` en cada aviso: si no,
          la columna entera se traga los clics de la esquina de la pantalla
          aunque no haya ningún toast dentro. --}}
     class="pointer-events-none fixed top-4 right-4 z-50 flex w-[calc(100%-2rem)] max-w-sm flex-col gap-3 sm:w-full">

    <template x-for="toast in toasts" :key="toast.id">
        <div x-transition:enter="transition duration-200 ease-out"
             x-transition:enter-start="translate-x-4 opacity-0"
             x-transition:enter-end="translate-x-0 opacity-100"
             x-transition:leave="transition duration-150 ease-in"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             {{-- El aviso va teñido del color de lo que dice, no blanco con un
                  filo de color: en la esquina de una pantalla llena de tarjetas
                  blancas, un borde de un píxel no se ve. --}}
             class="pointer-events-auto flex items-start gap-3 rounded-xl border p-4 shadow-lg"
             :class="{
                'border-emerald-300 bg-emerald-50': toast.type === 'success',
                'border-red-300 bg-red-50': toast.type === 'error',
                'border-amber-300 bg-amber-50': toast.type === 'warning',
             }">

            <span class="grid size-8 shrink-0 place-items-center rounded-full text-white"
                  :class="{
                    'bg-emerald-500': toast.type === 'success',
                    'bg-red-500': toast.type === 'error',
                    'bg-amber-500': toast.type === 'warning',
                  }">
                <svg x-show="toast.type === 'success'" class="size-5" fill="none" viewBox="0 0 24 24"
                     stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                </svg>

                <svg x-show="toast.type === 'error'" class="size-5" fill="none" viewBox="0 0 24 24"
                     stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>

                <svg x-show="toast.type === 'warning'" class="size-5" fill="none" viewBox="0 0 24 24"
                     stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                </svg>
            </span>

            <p class="flex-1 pt-1 text-sm font-medium"
               :class="{
                 'text-emerald-900': toast.type === 'success',
                 'text-red-900': toast.type === 'error',
                 'text-amber-900': toast.type === 'warning',
               }"
               x-text="toast.message"></p>

            <button type="button" @click="remove(toast.id)"
                    class="-mt-0.5 -mr-1 rounded-lg p-1.5 transition hover:bg-black/5"
                    :class="{
                      'text-emerald-600 hover:text-emerald-800': toast.type === 'success',
                      'text-red-600 hover:text-red-800': toast.type === 'error',
                      'text-amber-600 hover:text-amber-800': toast.type === 'warning',
                    }">
                <span class="sr-only">Cerrar el aviso</span>
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </template>
</div>
