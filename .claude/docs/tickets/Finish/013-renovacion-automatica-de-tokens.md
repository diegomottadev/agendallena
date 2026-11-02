# T-013 · Renovación automática de tokens y marca de vencimiento

| | |
| :-- | :-- |
| **Prioridad** | 13 |
| **Sprint** | 1 |
| **Tipo** | Feature |
| **Historia** | [US-04](../../stories/US-04-conectar-google-calendar-oauth.md) |
| **Story points** | 3 |
| **Depende de** | T-012 |
| **Bloquea a** | T-034 |
| **Pareto** | **NÚCLEO** · ola 1 |

## Contexto

Un `access_token` de Google dura una hora. Sin renovación transparente, el producto deja de funcionar cada mañana y el dueño tiene que reconectar a mano — lo que convierte el KPI de setup en un costo recurrente.

## Alcance

* Renovación con `refresh_token` al detectar expiración, transparente para el usuario.
* Reintento de la operación original una sola vez tras renovar.
* Marca de `integrations.status='expired'` cuando el refresh falla de forma definitiva.

## Criterios de aceptación

- [ ] Con el `access_token` vencido, la consulta al calendario lo renueva sin intervención del usuario y la operación se completa *(AC-04.4)*.
- [ ] La operación original se reintenta exactamente una vez tras la renovación: no hay bucle de reintento.
- [ ] Ante `invalid_grant`, la integración queda en `status='expired'` y se deja de intentar.
- [ ] El `refresh_token` nuevo, si Google lo rota, reemplaza al anterior encriptado.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ✅ | ✅ | ✅ | ✅ | ✅ |

**Corrección aplicada:** era el AC-04.4 que hacía fallar *Pequeña* en US-04. Como ticket propio es estimable, chico y testeable de forma aislada forzando la expiración del token.

## Fuera de alcance

Mostrar el estado vencido en el panel: T-034. Reconectar la cuenta: v2.

## Notas y riesgos

La marca `status='expired'` que escribe este ticket es el insumo de AC-09.2 y de T-034. Si no se escribe acá, esos dos quedan sin dato que leer.
