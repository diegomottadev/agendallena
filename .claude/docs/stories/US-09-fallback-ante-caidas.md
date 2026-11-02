# US-09 · Nunca dejar un chat colgado

| | |
| :-- | :-- |
| **Sección** | 3 — Convivir con el equipo humano |
| **Prioridad** | 9 de 14 |
| **Estimación** | **M** |
| **Trazabilidad** | RNF-03 · CA-06 |
| **Depende de** | [US-06](US-06-reservar-y-liberar-sync-inversa.md) · [US-08](US-08-pausa-intervencion-humana.md) |
| **Bloquea a** | [US-24](US-24-recordatorio-que-no-salio.md) · [US-25](US-25-estado-de-las-integraciones.md) |

## Historia

> **Como** cliente final que está reservando un turno,
> **quiero** recibir una respuesta amable si el sistema falla,
> **para** saber que alguien va a atenderme en lugar de quedar hablándole a una pared.

## Criterios de aceptación

**AC-09.1 — Caída de la API de Google**
> **Dado que** la API de Google Calendar devuelve un error 5xx,
> **cuando** intento reservar,
> **entonces** recibo el mensaje de cortesía configurado por el tenant y la excepción queda registrada con `tenant_id`, timestamp e integración afectada.

**AC-09.2 — Credenciales revocadas**
> **Dado que** el `refresh_token` del tenant fue revocado,
> **cuando** el sistema intenta consultar disponibilidad,
> **entonces** envía el mensaje de fallback al cliente y marca en el panel que hay que reconectar la cuenta.

**AC-09.3 — Rate limit de Meta**
> **Dado que** Meta responde con límite de tasa excedido,
> **cuando** se intenta enviar un mensaje,
> **entonces** se reintenta con backoff exponencial y solo tras agotar los reintentos se registra el fallo definitivo.

**AC-09.4 — Ninguna conversación queda muda**
> **Dado que** ocurre cualquiera de los fallos anteriores,
> **cuando** se resuelve el turno de conversación,
> **entonces** el cliente siempre recibe algún mensaje: no existe camino que termine en silencio.

## Requisitos técnicos

* Envolver cada llamada saliente a Google y a Meta en un manejador que capture 5xx, timeouts y credenciales revocadas y devuelva un resultado tipado, en vez de dejar propagar la excepción hasta matar el job (AC-09.1).
* Leer el texto de cortesía de la configuración del tenant, no de una constante. ⚠️ **No hay tabla de configuración** donde alojarlo; ver [US-15](US-15-configurar-reglas-del-negocio.md).
* Implementar backoff exponencial con jitter y tope de reintentos en el `MetaAdapter` ante `429`, registrando el fallo definitivo solo al agotarlos (AC-09.3).
* Marcar `integrations.status='expired'` ante `invalid_grant` de Google y dejar de intentar hasta la reconexión (AC-09.2). La vista que lo muestra es [US-25](US-25-estado-de-las-integraciones.md).
* Garantizar que todo camino de salida del worker termine enviando un mensaje o encolando el fallback: ninguna rama puede terminar en `return` silencioso (AC-09.4).
* Emitir log estructurado con `tenant_id`, timestamp, integración afectada, código de error y `message_id`, de forma consultable para el conteo de excepciones por integración (AC-09.1). ⚠️ El destino y el formato del log no están definidos en ningún documento, y tres historias lo asumen.
* Construir simuladores de falla para las pruebas: credenciales revocadas, `5xx` forzado de Calendar y `429` de Meta (AC-09.1 a AC-09.3).
* Transicionar la conversación a `ERROR_FALLBACK` y definir desde qué estado se retoma cuando el tercero vuelve.
* ⚠️ Sin definir si el fallback escala activamente a un operador o solo informa al cliente final. Ver [US-20](US-20-derivar-a-una-persona.md).

## Estimación de complejidad

**M** — Los modos de falla están acotados por los criterios y el patrón de manejo es el mismo para todos. El trabajo está en cubrirlos de forma exhaustiva y en el logging estructurado.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ⚠️ | Necesita que existan los caminos que pueden fallar: US-06 y US-08. |
| **Negociable** | ✅ | El mensaje, los reintentos y la política de escalamiento quedan abiertos. |
| **Valiosa** | ✅ | Es la condición para poner esto en un número real con clientes reales. |
| **Estimable** | ✅ | Al enumerar los modos de falla en los criterios, el alcance quedó cerrado. |
| **Pequeña** | ⚠️ | Antes era ilimitada ("cualquier excepción"); los cuatro criterios le pusieron borde, pero sigue tocando varias integraciones. |
| **Testeable** | ✅ | Cada modo de falla se simula: revocar credenciales, forzar 5xx, saturar el rate limit. |

**Veredicto:** cumple 4 de 6. Mejoró respecto de la versión anterior justamente porque enumerar los fallos concretos convirtió un alcance abierto en uno estimable.

## Por qué es urgente (o crítica)

Un chat que se queda mudo es una venta perdida y una queja garantizada. La resiliencia no es una mejora posterior: es la condición del piloto. Sin esto, cualquier caída de un tercero se lee como "el sistema no anda".

## Qué esperamos aprender

* Con qué frecuencia real fallan Meta y Google en producción.
* Si el mensaje de cortesía alcanza o hace falta escalar de forma activa al operador.
* Dónde poner el esfuerzo de robustez, según el conteo de excepciones por integración.

## De qué depende

* [US-06](US-06-reservar-y-liberar-sync-inversa.md) y [US-08](US-08-pausa-intervencion-humana.md).
* Logging estructurado con `tenant_id`.
* Plantilla de fallback configurable por tenant.
