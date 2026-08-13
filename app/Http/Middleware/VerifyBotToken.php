<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer estático contra `config('panel.bot_token')` (CONTEXTO.md §5).
 *
 * No hay Sanctum a propósito: hay un único consumidor, el bot. Tokens en base
 * de datos, revocables y con scopes, sería infraestructura sin caso de uso.
 */
class VerifyBotToken
{
    private string $route = 'la API del bot';

    public function handle(Request $request, Closure $next): Response
    {
        // Guarda a qué endpoint iba, para que el log lo diga. Antes estaba
        // escrito a mano como "GET /api/rutas" y dejó de ser cierto en cuanto
        // el middleware pasó a proteger también el POST de incidencias.
        $this->route = $request->method().' /'.$request->path();

        $expected = config('panel.bot_token');

        // Sin token configurado se corta todo. Sin esto, un despliegue que se
        // olvide del RUTAS_TOKEN dejaría el maestro completo del cliente
        // abierto a cualquiera: `hash_equals('', '')` es true, así que una
        // petición sin cabecera pasaría. Cerrado por defecto, nunca abierto.
        if (blank($expected)) {
            return $this->reject('el endpoint no tiene token configurado');
        }

        $received = $request->bearerToken();

        // hash_equals compara en tiempo constante: una comparación normal
        // termina antes cuanto antes falle, y eso deja adivinar el token
        // carácter a carácter midiendo lo que tarda la respuesta.
        if ($received === null || ! hash_equals($expected, $received)) {
            return $this->reject('token ausente o incorrecto');
        }

        return $next($request);
    }

    /**
     * El motivo se registra en el log, pero la respuesta es siempre la misma:
     * distinguir "no hay token configurado" de "el token no cuadra" ayudaría a
     * afinar el ataque. Del token no se escribe nada, ni un prefijo (§10).
     */
    private function reject(string $reason): Response
    {
        Log::warning("{$this->route} rechazado: {$reason}.");

        return response()
            ->json(['error' => 'No autorizado'], Response::HTTP_UNAUTHORIZED)
            ->header('WWW-Authenticate', 'Bearer');
    }
}
