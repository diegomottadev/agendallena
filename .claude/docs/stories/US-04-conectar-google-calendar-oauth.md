# US-04 · Conectar Google Calendar en un clic

| | |
| :-- | :-- |
| **Sección** | 2 — Probar la promesa central |
| **Prioridad** | 4 de 14 |
| **Estimación** | **M** |
| **Trazabilidad** | RF-B1 · RF-E1 |
| **Depende de** | — (solo dependencias externas) |
| **Bloquea a** | [US-05](US-05-horarios-realmente-libres.md) · [US-15](US-15-configurar-reglas-del-negocio.md) · [US-25](US-25-estado-de-las-integraciones.md) · [US-26](US-26-vincular-mi-planilla.md) |

## Historia

> **Como** dueña de PyME que acaba de contratar el servicio,
> **quiero** conectar mi cuenta de Google en un solo clic y ver confirmado que quedó vinculada,
> **para** empezar a recibir turnos hoy mismo sin depender de que un técnico me configure nada.

## Criterios de aceptación

**AC-04.1 — Vinculación exitosa**
> **Dado que** estoy en el panel sin calendario conectado,
> **cuando** hago clic en "Conectar Google Calendar" y autorizo en la pantalla de Google,
> **entonces** vuelvo al panel con el indicador en verde y el nombre de la cuenta vinculada a la vista.

**AC-04.2 — Tokens encriptados en reposo**
> **Dado que** la vinculación se completó,
> **cuando** se inspecciona la base de datos,
> **entonces** `access_token` y `refresh_token` están encriptados y no son legibles en texto plano.

**AC-04.3 — Consentimiento cancelado**
> **Dado que** cancelo la pantalla de consentimiento de Google,
> **cuando** vuelvo al panel,
> **entonces** el indicador sigue en rojo con un mensaje que explica que la conexión no se completó y cómo reintentar.

**AC-04.4 — Renovación automática de token**
> **Dado que** el `access_token` está vencido,
> **cuando** el sistema necesita consultar el calendario,
> **entonces** lo renueva con el `refresh_token` sin intervención del usuario y la operación se completa.

## Requisitos técnicos

* Registrar el proyecto en Google Cloud con la pantalla de consentimiento aprobada, solicitando los scopes `calendar.events` y `calendar.readonly` — y `spreadsheets` en el mismo consentimiento, para no obligar a autorizar dos veces (ver [US-13](US-13-leads-a-google-sheets.md)).
* Implementar `GET /oauth/google/redirect` y `GET /oauth/google/callback`, con parámetro `state` firmado que transporte el `tenant_id` y prevenga CSRF.
* Pedir `access_type=offline` y `prompt=consent`: sin eso Google no devuelve `refresh_token` y AC-04.4 es imposible.
* Persistir en `integrations` con `provider='google_calendar'`, encriptando `access_token` y `refresh_token` en reposo con la clave de la aplicación (AC-04.2).
* Renovar el token de forma transparente al detectar expiración y reintentar la operación original una sola vez (AC-04.4).
* Marcar `integrations.status='expired'` cuando el refresh devuelva `invalid_grant`, que es el insumo de AC-09.2 y de la vista de [US-25](US-25-estado-de-las-integraciones.md).
* Exponer el estado de la conexión y el `account_identifier` al panel para el indicador en verde o en rojo (AC-04.1, AC-04.3).
* Shell mínimo del panel Vue 3 con autenticación, hoy enterrada dentro de [US-14](US-14-panel-logs-y-estado.md).
* ⚠️ **Decisión abierta — Outlook:** si entra al MVP, esto deja de ser un cliente de un proveedor y pasa a ser una abstracción de dos, con su propio flujo OAuth y su propio modelo de disponibilidad. El `provider ENUM` del modelo de datos ya contempla `outlook_calendar`; el PRD no lo menciona ni para incluirlo ni para excluirlo.

## Estimación de complejidad

**M** — El flujo OAuth es estándar, pero el encriptado en reposo, la renovación de tokens y el indicador de estado en el panel suman tres piezas separadas.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ✅ | Sin dependencias internas. Arrancable el día 1 en paralelo con US-01 — es la mejor oportunidad de paralelizar de todo el backlog. |
| **Negociable** | ✅ | El *cómo* del flujo y de la UI queda abierto. |
| **Valiosa** | ✅ | Autonomía de setup, medible contra el KPI de menos de 60 minutos. |
| **Estimable** | ⚠️ | **La decisión de Outlook cambia el alcance.** Si entra, esto deja de ser un proveedor y pasa a ser una abstracción de dos. |
| **Pequeña** | ⚠️ | AC-04.4 (renovación de tokens) es separable y podría ser historia propia. |
| **Testeable** | ✅ | Los cuatro criterios son verificables de punta a punta. |

**Veredicto:** cumple 4 de 6, la mejor puntuación del backlog junto con US-01. Es la candidata natural para arrancar en paralelo.

## Por qué es urgente (o crítica)

Es el primer momento de fricción del cliente y el que más nos cuesta si falla: sin OAuth resuelto, cada alta consume horas nuestras de soporte y el KPI de setup se cae. También destraba US-05 y US-06, que no se pueden probar sin un token real.

## Qué esperamos aprender

* Cuánta gente completa el flujo de OAuth sin ayuda.
* Con qué frecuencia se vencen o revocan los `refresh_token` en la práctica — el dato que define si necesitamos re-autorización proactiva antes de escalar.

## De qué depende

* Proyecto en Google Cloud con la pantalla de consentimiento aprobada.
* Credenciales OAuth 2.0 y encriptado en reposo.
* Shell mínimo del panel Vue 3.

> Conviene pedir el alcance de Google Sheets en este mismo consentimiento (ver [US-13](US-13-leads-a-google-sheets.md)) para no obligar al cliente a autorizar dos veces.
