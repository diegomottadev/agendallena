# T-048 · Migración: tabla `messages`

| | |
| :-- | :-- |
| **Prioridad** | 48 |
| **Sprint** | 8 |
| **Tipo** | Migración |
| **Historia** | habilita [US-14](../../stories/US-14-panel-logs-y-estado.md) (AC-14.4) |
| **Story points** | 2 |
| **Depende de** | — |
| **Bloquea a** | T-049 |
| **Pareto** | **DIFERIDO** — fuera del MVP recortado |

> **Fuera del núcleo Pareto — DIFERIDO.** Nadie lee esos datos sin T-049, y para diagnosticar está T-020. ⚠️ El argumento de adelantarla sigue en pie —el historial se acumula con el tiempo y son 2 pts—: si se decide adelantarla, el núcleo pasa a 109 pts. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Contexto

AC-14.4 promete *"el historial completo de mensajes"* y **no existe tabla de mensajes**. `conversations` guarda `current_state`, `context_data JSON` y `last_interaction_at`: el estado de la conversación, no la conversación.

Afecta además a T-032: AC-08.1 dice que el mensaje recibido durante una pausa "queda registrado para que lo vea yo", y hoy no hay dónde registrarlo.

## Alcance

* Migración `messages` con `tenant_id`, `conversation_id`, dirección, contenido, `whatsapp_message_id` y timestamp.
* Índice por `(tenant_id, conversation_id, created_at)`.
* Persistencia de entrantes y salientes desde el worker.
* Política de retención.

## Criterios de aceptación

- [ ] La tabla existe con `tenant_id`, su foránea a `conversations` y el Global Scope aplicado.
- [ ] Cada mensaje entrante y saliente queda persistido con su dirección y su identificador de Meta.
- [ ] Un mensaje recibido durante una pausa humana queda registrado aunque el bot no responda *(AC-08.1)*.
- [ ] La consulta del historial de una conversación usa el índice y no escanea la tabla.
- [ ] La política de retención está definida y documentada.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ✅ | ⚠️ | ⚠️ | ✅ | ✅ | ✅ |

**Enabler.** Se separó de T-049 para que la migración y la persistencia puedan entrar antes que la vista: cuanto antes se empiecen a guardar los mensajes, más historial habrá cuando el detalle exista.

## Fuera de alcance

La vista de detalle (T-049).

## Notas y riesgos

⚠️ **Es la tabla de mayor crecimiento del sistema** y la única que guarda contenido de conversaciones de terceros. Definir retención y borrado antes de encenderla, no después.

⚠️ Conviene adelantarla: si se implementa junto con T-018, el historial existe desde el primer piloto en vez de desde el sprint 8.
