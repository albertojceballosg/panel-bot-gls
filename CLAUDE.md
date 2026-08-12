# CLAUDE.md

**Lee [`CONTEXTO.md`](CONTEXTO.md) antes de tocar nada.** Contiene el negocio, el contrato
con el bot, el modelo de datos, las decisiones ya tomadas y las fases. Es autosuficiente:
no hace falta abrir otro repositorio.

## Reglas de este repo

1. **Todo corre en Docker.** No hay PHP, Composer ni Node en el host. Los comandos van por
   `docker compose exec app …` (o `run --rm` si los servicios están parados). Nunca sugieras
   instalar un toolchain en la máquina.
2. **No añadas dependencias.** El stack es Laravel + Livewire + Tailwind y punto. `CONTEXTO.md`
   §5 lista lo que ya se descartó y por qué (TailAdmin, Filament, Flux, Sanctum, librerías de
   Excel). Si crees que hace falta una nueva, plantéalo antes de instalarla.
3. **`GET /api/rutas` es el producto.** No debe depender de sesión, layout ni Livewire. Su
   forma está fijada por el contrato de §3 y **cambiarla obliga a tocar el repo del bot**.
   El test de contrato no se relaja para que pase un refactor.
4. **Nunca versiones datos del cliente.** Nombres, códigos y direcciones de comercios son
   confidenciales: ni el `rutas.xlsx` ni el CSV derivado entran en git (§9). Tampoco escribas
   claves en documentos, ni como ejemplo.
5. **Actualiza `CONTEXTO.md` en el mismo commit** en que cierres una fase o cambies una
   decisión de arquitectura. Si envejece, deja de servir.

## Escribir código

- Los comentarios y la documentación, en **castellano**, como el resto del repo.
- Blade y Tailwind a mano; nada de librerías de componentes.
- Antes de dar algo por terminado, **verifícalo ejecutándolo** (`php artisan test`, una
  petición real al endpoint), no por inspección del código.
