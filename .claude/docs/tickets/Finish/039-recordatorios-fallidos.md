# T-039 · Recordatorios fallidos: detección y reintento

| | |
| :-- | :-- |
| **Prioridad** | 39 |
| **Sprint** | 6 |
| **Tipo** | Feature |
| **Historia** | [US-24](../../stories/US-24-recordatorio-que-no-salio.md) |
| **Story points** | 3 |
| **Depende de** | T-033, T-037 |
| **Bloquea a** | — |
| **Pareto** | **DIFERIDO** — fuera del MVP recortado |

> **Fuera del núcleo Pareto — DIFERIDO.** Necesita volumen para justificarse: en un piloto, un recordatorio no entregado se ve en el log estructurado de T-020. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Contexto

`notification_logs.status` admite `'failed'` desde el primer día: **el modelo de datos previó que un recordatorio puede no salir y después nadie escribió qué pasa entonces.**

Importa más de lo que su tamaño sugiere porque contamina la medición: si un porcentaje de los recordatorios nunca salió, el número de T-038 no dice nada — no sabemos si el recordatorio no funciona o si no se envió.

## Alcance

* Consumo de los webhooks de estado de mensaje de Meta.
* Registro del resultado real del envío y su motivo.
* Lista de recordatorios fallidos de turnos futuros.
* Reintento manual dentro de la ventana útil.

## Criterios de aceptación

- [ ] Agotados los reintentos, el registro queda como fallido con motivo y hora, y el turno **no** se marca como recordado *(AC-24.1)*.
- [ ] Los recordatorios fallidos de turnos futuros aparecen agrupados en el panel, con turno, cliente y tiempo restante *(AC-24.2)*.
- [ ] Con más de dos horas hasta el turno, el reintento manual reenvía y refleja el resultado *(AC-24.3)*.
- [ ] Un recordatorio de 24 horas **no** se reintenta cuando el turno ya está a menos de dos horas *(AC-24.4)*.
- [ ] Los estados `sent`, `delivered`, `read` y `failed` se distinguen a partir de los webhooks de Meta.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ✅ | ✅ | ✅ | ✅ | ✅ |

**5 de 6.** Alcance cerrado por los cuatro criterios. El trabajo real no es la vista sino consumir los webhooks de estado de Meta, que hoy ninguna historia escucha aunque el enum los anticipe.

## Fuera de alcance

El aviso activo al dueño por WhatsApp, sujeto a la misma decisión abierta que T-035.

## Notas y riesgos

⚠️ **`notification_logs` no tiene columna para el motivo del fallo**, solo para el estado. Agregarla en este ticket.

⚠️ Si no se marca el turno como recordado ante un fallo, revisar que el único de T-009 no impida el reintento.
