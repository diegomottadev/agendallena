# T-012 · Conexión OAuth 2.0 con Google Calendar

| | |
| :-- | :-- |
| **Prioridad** | 12 |
| **Sprint** | 1 |
| **Tipo** | Feature |
| **Historia** | [US-04](../../stories/US-04-conectar-google-calendar-oauth.md) |
| **Story points** | 5 |
| **Depende de** | T-003 |
| **Bloquea a** | T-015, T-022, T-034, T-044 |
| **Pareto** | **NÚCLEO** · ola 1 |

## Contexto

Es el primer momento de fricción del cliente y el que más cuesta si falla: sin OAuth resuelto, cada alta consume horas de soporte y el KPI de setup en menos de 60 minutos se cae. **Es además la única historia sin dependencias internas: la mejor oportunidad de paralelizar de todo el backlog.**

## Alcance

* Shell mínimo del panel Vue 3 con la pantalla de integraciones.
* `GET /oauth/google/redirect` y `GET /oauth/google/callback` con `state` firmado que transporte el `tenant_id`.
* Persistencia en `integrations` con tokens encriptados en reposo.
* Indicador de estado de la conexión.

## Criterios de aceptación

- [ ] Desde el panel sin calendario conectado, autorizar en Google devuelve al panel con el indicador en verde y el nombre de la cuenta vinculada *(AC-04.1)*.
- [ ] `access_token` y `refresh_token` están encriptados en la base y no son legibles en texto plano *(AC-04.2)*.
- [ ] Cancelar la pantalla de consentimiento deja el indicador en rojo con un mensaje que explica cómo reintentar *(AC-04.3)*.
- [ ] El `state` es verificado en el callback: una respuesta sin `state` válido se rechaza.
- [ ] La autorización pide `access_type=offline` y obtiene `refresh_token`.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ✅ | ✅ | ✅ | ⚠️ | ✅ | ✅ |

**Corrección aplicada:** US-04 fallaba *Pequeña* porque incluía la renovación de tokens; sale a T-013. **E** queda en ⚠️ hasta que T-007 cierre la decisión de Outlook: si entra, esto deja de ser un cliente y pasa a ser una abstracción de dos proveedores.

## Fuera de alcance

La renovación automática de tokens (T-013) y la vinculación de la planilla (T-044).

## Notas y riesgos

⚠️ Pedir el scope de Sheets en este mismo consentimiento (ya cubierto por T-003): si se pide después, el cliente autoriza dos veces.
