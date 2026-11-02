# T-020 · Logging estructurado con `tenant_id`

| | |
| :-- | :-- |
| **Prioridad** | 20 |
| **Sprint** | 2 |
| **Tipo** | Enabler |
| **Historia** | asumido por [US-01](../../stories/US-01-handshake-webhook-meta.md), [US-09](../../stories/US-09-fallback-ante-caidas.md), [US-14](../../stories/US-14-panel-logs-y-estado.md) |
| **Story points** | 2 |
| **Depende de** | — |
| **Bloquea a** | T-033 |
| **Pareto** | **NÚCLEO** · ola 1 |

## Contexto

Tres historias lo dan por hecho —"queda registro del intento", "la excepción queda registrada con `tenant_id`", "el intento queda registrado"— y **ningún documento define destino, formato ni retención**. Sin una definición común, cada historia lo implementa a su manera y el conteo de excepciones por integración que US-09 quiere aprender queda ilegible.

## Alcance

* Formato estructurado en JSON con campos fijos: `tenant_id`, timestamp, canal, integración, severidad, código y correlación.
* Inyección automática del `tenant_id` del contexto en cada entrada.
* Definición de destino y retención.
* Redacción de secretos: tokens y firmas nunca en el log.

## Criterios de aceptación

- [ ] Toda entrada emitida desde un contexto con tenant incluye su `tenant_id` sin que el llamador tenga que pasarlo.
- [ ] Ningún `access_token`, `refresh_token`, `verify_token` ni firma HMAC aparece en el log, ni siquiera parcialmente.
- [ ] Es posible contar excepciones por integración y por tenant en un rango de fechas con una sola consulta.
- [ ] El destino y la retención están documentados.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ✅ | ✅ | ⚠️ | ✅ | ✅ | ✅ |

**V** en ⚠️: es infraestructura, y como todo enabler su valor es indirecto. Se separó de US-09 porque tres historias lo consumen y ninguna debería ser dueña de definirlo.

## Fuera de alcance

Alertas y paneles de monitoreo. Este ticket produce datos consultables, no observabilidad activa.

## Notas y riesgos

⚠️ La redacción de secretos importa más de lo habitual acá: en multi-tenancy por columna, un token filtrado en un log compartido es acceso a la cuenta de Google de un cliente.
