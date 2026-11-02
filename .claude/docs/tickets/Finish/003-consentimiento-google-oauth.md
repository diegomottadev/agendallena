# T-003 · Pantalla de consentimiento de Google y credenciales OAuth

| | |
| :-- | :-- |
| **Prioridad** | 3 |
| **Sprint** | 0 — Habilitación |
| **Tipo** | Trámite externo |
| **Historia** | [US-04](../../stories/US-04-conectar-google-calendar-oauth.md) · [US-26](../../stories/US-26-vincular-mi-planilla.md) |
| **Story points** | 2 |
| **Depende de** | — |
| **Bloquea a** | T-012 |
| **Pareto** | **NÚCLEO** · ola 0 |

## Contexto

El flujo OAuth no se puede probar sin un proyecto de Google Cloud con la pantalla de consentimiento aprobada. La revisión de Google tiene su propia demora, y los scopes de calendario son sensibles.

## Alcance

* Crear el proyecto en Google Cloud y habilitar las APIs de Calendar y Sheets.
* Configurar la pantalla de consentimiento y enviarla a revisión.
* Solicitar los scopes `calendar.events`, `calendar.readonly` y `spreadsheets` **en el mismo consentimiento**.
* Generar las credenciales OAuth 2.0 y registrar las URIs de redirección.

## Criterios de aceptación

- [ ] El proyecto tiene habilitadas las APIs de Calendar y Sheets.
- [ ] La pantalla de consentimiento está aprobada para los tres scopes.
- [ ] Las credenciales OAuth están cargadas en el gestor de secretos, no en el repositorio.
- [ ] Las URIs de redirección de los entornos de desarrollo y producción están registradas.

## Fuera de alcance

El scope de Drive: solo haría falta para un selector visual de planillas, que T-044 descarta a propósito.

## Notas y riesgos

⚠️ Pedir los tres scopes juntos es deliberado: si el de Sheets se pide después, el cliente tiene que autorizar dos veces y el KPI de setup en menos de 60 minutos se resiente.
