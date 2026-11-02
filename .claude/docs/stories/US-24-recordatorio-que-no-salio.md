# US-24 · Enterarme de que un recordatorio no llegó

| | |
| :-- | :-- |
| **Sección** | 4 — Demostrar el ROI que vendemos |
| **Prioridad** | Sprint 6, pegada a [US-10](US-10-recordatorio-t24h.md) ([slices](../stories-maps/04-slices-y-secuencia.md)) |
| **Estimación** | **S** |
| **Trazabilidad** | **sin RF** · `notification_logs.status` admite `'failed'` y nadie lo lee |
| **Origen** | Hueco [H-08](../stories-maps/05-huecos-y-trazabilidad.md#h-08) del story map |
| **Depende de** | [US-10](US-10-recordatorio-t24h.md) · [US-09](US-09-fallback-ante-caidas.md) |
| **Bloquea a** | — *(pero sin ella, el número de US-10 no es interpretable)* |

## Historia

> **Como** dueña de PyME,
> **quiero** enterarme si un recordatorio no le llegó a mi cliente,
> **para** llamarlo yo antes de que el turno se pierda.

## Criterios de aceptación

**AC-24.1 — El fallo queda registrado**
> **Dado que** el envío de un recordatorio agota sus reintentos,
> **cuando** se resuelve el intento,
> **entonces** el registro queda en estado fallido con el motivo y la hora, y no se marca el turno como recordado.

**AC-24.2 — Pendientes visibles**
> **Dado que** hay recordatorios fallidos de turnos que todavía no ocurrieron,
> **cuando** entro al panel,
> **entonces** los veo agrupados, con el turno, el cliente y cuánto falta para la cita.

**AC-24.3 — Reintento manual dentro de la ventana útil**
> **Dado que** un recordatorio falló y al turno le faltan más de dos horas,
> **cuando** presiono reintentar,
> **entonces** se vuelve a enviar y el resultado se refleja en el registro.

**AC-24.4 — No reintentar lo inútil**
> **Dado que** un recordatorio de 24 horas falló y el turno ya está a menos de dos horas,
> **cuando** corre el reintento automático,
> **entonces** no se envía: un aviso de "mañana tenés turno" llegando dos horas antes desinforma más de lo que ayuda.

## Requisitos técnicos

* Escribir el resultado real del envío en `notification_logs.status`, distinguiendo `sent`, `delivered`, `read` y `failed` a partir de los webhooks de estado de Meta (AC-24.1).
* Registrar el motivo del fallo junto al estado. ⚠️ `notification_logs` no tiene columna para el error; hoy solo guarda el estado.
* Consulta de recordatorios fallidos de turnos futuros, ordenados por proximidad del turno (AC-24.2), sostenida por el índice `bookings(tenant_id, start_time, status)`.
* Endpoint de reintento manual idempotente, que reuse el envío de [US-10](US-10-recordatorio-t24h.md) y respete el candado contra duplicados (AC-24.3).
* Definir la ventana de utilidad de cada tipo de aviso y descartar los reintentos fuera de ella (AC-24.4).
* No marcar el turno como recordado si el envío falló: de lo contrario el candado de AC-10.4 impide para siempre un segundo intento (AC-24.1).
* Consumir los eventos de estado de mensaje (`statuses`), que hoy ninguna historia consume aunque el enum de `notification_logs` los anticipe. ⚠️ **Corregido el 2026-08-21: no hay nada que suscribir.** `statuses` no es un campo de webhook — el campo es `messages`, y entrega los dos arrays en el mismo cambio. Ya llegaban; se descartaban en el código.
* ⚠️ **Decisión abierta — el aviso al dueño es pasivo o activo.** El panel exige que alguien lo abra; avisarle por WhatsApp consume una plantilla aprobada por Meta. Mismo dilema que [US-20](US-20-derivar-a-una-persona.md), y conviene resolverlo una sola vez para las dos.

## Estimación de complejidad

**S** — Una consulta, una vista y un botón. El trabajo real es consumir los webhooks de estado de Meta, que hoy nadie escucha.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ⚠️ | Necesita los recordatorios de US-10 y el manejo de errores de US-09. |
| **Negociable** | ✅ | La ventana de utilidad, el canal del aviso y la profundidad del detalle están abiertos. |
| **Valiosa** | ✅ | El ROI que vendemos depende de que ese mensaje llegue. Un recordatorio que no sale es un no-show que pagamos nosotros con credibilidad. |
| **Estimable** | ✅ | Alcance cerrado por los cuatro criterios. |
| **Pequeña** | ✅ | Uno o dos días. |
| **Testeable** | ✅ | Se prueba forzando un fallo de envío y observando el pendiente en el panel. |

**Veredicto:** cumple 5 de 6.

## Por qué es urgente (o crítica)

**El modelo de datos ya anticipó este caso y ninguna historia lo cerró.** `notification_logs.status` admite `'failed'` desde el primer día: alguien previó que un recordatorio puede no salir, y después nadie escribió qué pasa entonces.

Importa más de lo que su tamaño sugiere porque contamina la medición. [US-10](US-10-recordatorio-t24h.md) se compromete a una asistencia ≥ 92% y [US-17](US-17-registro-de-asistencia.md) la mide, pero si un porcentaje de los recordatorios nunca salió, **el número resultante no dice nada**: no sabemos si el recordatorio no funciona o si no se envió. Es la diferencia entre medir un experimento y medir ruido.

Y del lado del cliente el costo es directo: un turno que se pierde por un mensaje que no salió es exactamente el problema que vinimos a resolver.

## Qué esperamos aprender

* Cuál es la tasa real de fallo de envío de Meta en producción — dato que hoy es pura suposición y que define si esto es una vista de contingencia o una funcionalidad de uso diario.
* Si el reintento manual recupera turnos o llega tarde. Si llega tarde, el aviso tiene que ser activo y no pasivo.

**Señal de éxito:** ningún recordatorio fallido sin detectar durante los pilotos, y tasa de fallo conocida y estable.

## De qué depende

* [US-10](US-10-recordatorio-t24h.md) — los envíos que pueden fallar.
* [US-09](US-09-fallback-ante-caidas.md) — la política de reintentos con backoff.
* Webhooks de estado de mensaje de Meta.
* Vista mínima de panel.
