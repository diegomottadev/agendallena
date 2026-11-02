# T-045 · Volcado de leads a la planilla

| | |
| :-- | :-- |
| **Prioridad** | 45 |
| **Sprint** | 8 |
| **Tipo** | Feature |
| **Historia** | [US-13](../../stories/US-13-leads-a-google-sheets.md) |
| **Story points** | 3 |
| **Depende de** | **T-007** (RF faltante), T-018, T-044 |
| **Bloquea a** | — |
| **Pareto** | **DIFERIDO** — fuera del MVP recortado |

> **Fuera del núcleo Pareto — DIFERIDO.** ⚠️ **Diferirlo contradice CA-02 tal como está escrito**, que exige que el turno aparezca en Google Calendar *y* Google Sheets. Hay que elegir en T-007: reescribir CA-02 acotándolo a Calendar, o subir T-044 + T-045 (6 pts) al núcleo. **No se puede dejar así.** Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Contexto

Es la prueba literal de "sin obligarte a cambiar de herramientas", que es la mitad de la propuesta de valor y el diferencial frente a los CRMs que la PyME ya rechazó.

Tiene una anomalía de proceso que vale señalar: **existe un criterio de aceptación en el PRD (CA-02) que valida una funcionalidad que ningún requisito define.**

## Alcance

* Alta del lead con `values.append` al capturar los datos.
* Actualización de la fila existente al confirmar la reserva.
* Job de cola con reintentos, fuera del camino del chat.
* Referencia estable de fila para el upsert.

## Criterios de aceptación

- [ ] Capturados los datos del cliente, se agrega una fila con fecha, teléfono, nombre, monto cotizado y estado *(AC-13.1)*.
- [ ] Cuando ese mismo lead reserva, **su fila existente se actualiza** con la fecha del turno y el enlace al evento, sin crear una fila nueva *(AC-13.2)*.
- [ ] Con Google Sheets devolviendo error, se reintenta en segundo plano y la conversación con el cliente **continúa sin interrupción** *(AC-13.3)*.
- [ ] El lead se escribe con el nombre completo, según la decisión de captura que fije T-007.
- [ ] Completado el flujo desde un teléfono de prueba, el lead aparece en la planilla del tenant *(CA-02)*.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ✅ | ⚠️ | ⚠️ | ✅ | ⚠️ |

**Corrección parcial.** US-13 fallaba *Estimable* por no tener RF; T-007 lo escribe, y hasta entonces **E** y **T** siguen en ⚠️ porque los criterios de arriba son una propuesta y no una definición acordada. **V** en ⚠️ es honesto: la propia historia duda de su valor —puede ser un pilar o algo que se pide en la demo y después nadie abre.

## Fuera de alcance

La pantalla de vinculación (T-044) y cualquier sincronización bidireccional con la planilla.

## Notas y riesgos

⚠️ **AC-13.1 escribe el nombre al salir de `GATHERING_PARAMS`**, y según los flujos el nombre se pide recién en `SLOT_SELECTED`. Si vale esa versión, cada lead sale con el campo vacío. Bloqueado por la decisión de T-007.

⚠️ No hay columna donde persistir la referencia de fila para el upsert de AC-13.2.
