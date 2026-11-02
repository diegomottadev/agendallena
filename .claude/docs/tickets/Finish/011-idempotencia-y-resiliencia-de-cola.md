# T-011 · Idempotencia y resiliencia de la cola de ingesta

| | |
| :-- | :-- |
| **Prioridad** | 11 |
| **Sprint** | 1 |
| **Tipo** | Enabler |
| **Historia** | [US-02](../../stories/US-02-ingesta-asincrona-rafaga.md) |
| **Story points** | 3 |
| **Depende de** | T-010 |
| **Bloquea a** | — |
| **Pareto** | **NÚCLEO** · ola 1 |

## Contexto

Meta reentrega los webhooks que no recibieron `200` a tiempo. Sin idempotencia, cada reentrega produce una segunda respuesta al cliente final: el bot le habla dos veces al mismo mensaje, que es exactamente lo que hace quedar mal a la PyME.

## Alcance

* Deduplicación por el `id` de mensaje de Meta antes de procesar el job.
* Configuración de reintentos, backoff y tabla `failed_jobs`.
* Verificación del comportamiento con el worker detenido.
* Monitoreo del panel de entregas de Meta durante el piloto.

## Criterios de aceptación

- [ ] Con el worker detenido, el endpoint sigue respondiendo `200` y los jobs se procesan completos al levantarlo *(AC-02.3)*.
- [ ] Una reentrega del mismo `message_id` no produce una segunda respuesta al cliente.
- [ ] Los jobs que agotan reintentos quedan en `failed_jobs` con su payload y su causa.
- [ ] Tras 72 h de piloto, el panel de entregas de Meta no registra reintentos ni errores *(AC-02.4)*.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ✅ | ✅ | ✅ | ✅ | ✅ |

**Corrección aplicada:** al separarlo de T-010, este ticket recupera *Negociable* —el mecanismo de deduplicación queda abierto— que en US-02 estaba en ❌ por prescripción de stack. **I** en ⚠️ es inevitable: no hay qué deduplicar sin ingesta.

## Fuera de alcance

Idempotencia de las escrituras a Google, que es T-030.

## Notas y riesgos

AC-02.4 solo se puede verificar con 72 horas de tráfico real: el ticket se cierra en el sprint, este criterio se confirma en el piloto.
