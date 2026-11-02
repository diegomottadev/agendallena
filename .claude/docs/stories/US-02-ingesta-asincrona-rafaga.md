# US-02 · Ingesta asíncrona que aguanta la ráfaga

| | |
| :-- | :-- |
| **Sección** | 1 — Habilitar el canal |
| **Prioridad** | 2 de 14 |
| **Estimación** | **M** |
| **Trazabilidad** | RF-A2 · RNF-04 |
| **Depende de** | [US-01](US-01-handshake-webhook-meta.md) |
| **Bloquea a** | [US-03](US-03-maquina-de-estados-conversacional.md) |

## Historia

> **Como** dueña de una PyME con picos de mensajes concentrados,
> **quiero** que ninguna consulta se pierda cuando entran muchas a la vez,
> **para** no perder ventas justo el día que más gente me escribe.

## Criterios de aceptación

**AC-02.1 — Latencia bajo concurrencia**
> **Dado que** llegan 50 webhooks concurrentes,
> **cuando** el endpoint los recibe,
> **entonces** el p95 de respuesta se mantiene por debajo de 200 ms y los 50 mensajes quedan encolados.

**AC-02.2 — Firma inválida rechazada**
> **Dado que** llega un payload con firma HMAC SHA256 inválida,
> **cuando** se procesa,
> **entonces** se responde `HTTP 401`, no se encola nada y queda registro del intento.

**AC-02.3 — Ningún mensaje se pierde con el worker caído**
> **Dado que** el worker de la cola está detenido,
> **cuando** llegan mensajes nuevos,
> **entonces** el endpoint responde `HTTP 200` igual y los jobs quedan en cola, procesándose completos al levantar el worker.

**AC-02.4 — Meta no registra reintentos**
> **Dado que** el sistema estuvo 72 horas en piloto,
> **cuando** se consulta el panel de entregas de Meta,
> **entonces** no hay reintentos ni errores de entrega registrados.

## Requisitos técnicos

* Exponer `POST /api/v1/webhooks/whatsapp` que responda `200 OK` **antes** de procesar nada del payload (AC-02.3).
* Validar la cabecera `X-Hub-Signature-256` calculando HMAC SHA256 sobre el **cuerpo crudo**, con comparación en tiempo constante (AC-02.2).
* Capturar el cuerpo crudo antes de que Laravel lo parsee o normalice: cualquier reserialización cambia el hash y la firma deja de coincidir.
* Encolar `ProcessMessageJob` en Redis con el payload íntegro. Ninguna lógica de negocio dentro del ciclo del request (AC-02.1).
* Configurar la conexión de cola con reintentos y tabla `failed_jobs`, de modo que un worker detenido acumule en vez de descartar (AC-02.3).
* Garantizar idempotencia por el `id` de mensaje de Meta: la plataforma reentrega, y un reintento no puede producir una segunda respuesta al cliente.
* Crear el índice compuesto `conversations(tenant_id, user_phone)`, que es la consulta del camino crítico de cada webhook. ⚠️ **No existe en el DDL** pese a que `04-modelo-de-datos.md` afirma estar diseñado con índices compuestos para consultas de menos de 15 ms.
* Construir un arnés de carga capaz de emitir 50 webhooks concurrentes correctamente firmados (AC-02.1).
* Instrumentar el p95 de latencia del endpoint y contrastar contra el panel de entregas de Meta (AC-02.1, AC-02.4).
* ⚠️ **Decisión abierta:** si la validación HMAC corre en el request o se difiere al worker. Cambia el presupuesto de 200 ms y hoy no está medida.

## Estimación de complejidad

**M** — El endpoint es trivial; el trabajo está en la validación HMAC dentro del presupuesto de latencia, la configuración de colas y el arnés de carga para poder probar AC-02.1.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ⚠️ | Requiere US-01. Sin webhook validado no hay tráfico que ingerir. |
| **Negociable** | ❌ | **Sobre-especifica la solución.** Fija Laravel Queues, Redis y el umbral de 200 ms. Vienen de RF-A2 y RNF-04, pero dejan al equipo sin margen de diseño. |
| **Valiosa** | ✅ | Reformulada desde la PyME: el valor es "no perder consultas en el pico", que sí es visible para el negocio. |
| **Estimable** | ✅ | Sin incógnitas técnicas relevantes. |
| **Pequeña** | ⚠️ | La validación HMAC es un concepto separable del encolado. Podría salir como historia propia. |
| **Testeable** | ✅ | Los cuatro criterios son medibles con instrumentos concretos. |

**Veredicto:** cumple 3 de 6. La observación de fondo es que **esto funciona mejor como spike técnico que como historia**: su propósito real es probar un supuesto de plataforma, no entregar un incremento.

## Por qué es urgente (o crítica)

Meta reintenta y luego **desactiva** las suscripciones que responden lento. Es una falla que no aparece en desarrollo con un mensaje por vez y que se manifiesta justo el día que el cliente tiene volumen real — el peor día posible para nuestra reputación. Riesgo técnico puro, barato de mitigar ahora.

## Qué esperamos aprender

* Cuál es el techo real de mensajes concurrentes de un solo worker.
* Si Redis alcanza como broker o hay que ajustar la configuración de colas.
* Si la validación HMAC entra dentro del presupuesto de 200 ms o conviene moverla al worker.

**Señal de éxito:** p95 < 200 ms con 50 mensajes concurrentes y cero reintentos de Meta en 72 h.

## De qué depende

* [US-01](US-01-handshake-webhook-meta.md) — webhook validado.
* Redis operativo y `POST /api/v1/webhooks/whatsapp`.
* Secreto de aplicación de Meta para validar la firma.
* Script de carga sintética.
