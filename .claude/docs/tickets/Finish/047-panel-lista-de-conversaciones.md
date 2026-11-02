# T-047 · Panel: listado de conversaciones

| | |
| :-- | :-- |
| **Prioridad** | 47 |
| **Sprint** | 8 |
| **Tipo** | Feature |
| **Historia** | [US-14](../../stories/US-14-panel-logs-y-estado.md) |
| **Story points** | 5 |
| **Depende de** | T-018, T-026, T-041, T-046 |
| **Bloquea a** | — |
| **Pareto** | **DIFERIDO** — fuera del MVP recortado |

> **Fuera del núcleo Pareto — DIFERIDO.** El dueño ve sus turnos en su propio Google Calendar, que es exactamente la promesa de «sin cambiar herramientas». Se retoma cuando haya más de un operador por PyME. ⚠️ Hasta entonces T-025 y T-038 quedan con pantallas mínimas propias: es deuda declarada, no ahorro. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Contexto

Sin panel, el modelo de tarifa plana de USD 80-150 deja de cerrar apenas se pasa de un puñado de cuentas: el soporte se come el margen. Tres pilotos se atienden con soporte humano; el cuarto ya no.

Es también donde se verifica de forma visible el aislamiento multi-tenant del RNF-01: un tenant viendo datos de otro es un incidente que termina el contrato.

## Alcance

* Listado paginado de conversaciones del tenant.
* Estado actual, fecha del turno y monto cotizado por fila.
* Enlace directo al evento en Google Calendar.
* Marca de intervención humana con tiempo restante.

## Criterios de aceptación

- [ ] Se ven **únicamente** las conversaciones del propio tenant, con estado actual, fecha del turno y monto cotizado *(AC-14.1)*.
- [ ] Una conversación con turno reservado ofrece un enlace que abre el evento correcto en Google Calendar *(AC-14.3)*.
- [ ] Una conversación en pausa por intervención humana aparece marcada, con el tiempo restante *(AC-14.4, parcial)*.
- [ ] El listado no ejecuta consultas N+1: los datos de turno y cotización vienen resueltos.
- [ ] La paginación sostiene un volumen de conversaciones de piloto sin degradarse.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ❌ | ✅ | ✅ | ✅ | ✅ | ✅ |

**Corrección aplicada:** US-14 fallaba *Pequeña* juntando autenticación, listado y detalle. Se partió en tres —T-046, este y T-049— tal como la historia recomienda, y se descopeó el MVP a **lista de solo lectura**. **I** sigue en ❌: necesita datos de conversación, turno y cotización para tener algo que mostrar; es la más dependiente del plan y por eso va al final.

## Fuera de alcance

El detalle con historial de mensajes (T-049), que **no es construible** sin la tabla de T-048.

## Notas y riesgos

El descope a lista de solo lectura no es una decisión de producto: es lo único construible con el modelo de datos actual.
