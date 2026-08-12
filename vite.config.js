import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        // Dentro del contenedor hay que escuchar en todas las interfaces, o el
        // puerto publicado no llega a ninguna parte.
        host: '0.0.0.0',
        port: 5173,

        // Si el 5173 está ocupado, fallar en vez de saltar al 5174: el puerto
        // publicado en docker-compose es fijo, así que moverse en silencio deja
        // la página sin estilos y sin decir por qué.
        strictPort: true,

        // Lo que el NAVEGADOR debe pedir, que no es lo mismo que lo que escucha
        // el contenedor. El plugin de Laravel escribe `public/hot` con
        // `hmr.host ?? server.host ?? la dirección`, así que sin esto anuncia
        // `http://0.0.0.0:5173` y Chrome lo rechaza con ERR_ADDRESS_INVALID:
        // la página carga sin CSS y sin hot reload.
        hmr: {
            host: 'localhost',
        },

        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
