# T-008 · Handshake de webhook de Meta

| | |
| :-- | :-- |
| **Prioridad** | 8 |
| **Sprint** | 1 |
| **Tipo** | Enabler |
| **Historia** | [US-01](../../stories/US-01-handshake-webhook-meta.md) |
| **Story points** | 3 |
| **Depende de** | T-001 |
| **Bloquea a** | T-010 y, en cascada, todo el MVP |
| **Pareto** | **NÚCLEO** · ola 1 |

## Contexto

Es el candado de entrada: sin la URL validada en verde en el portal de Meta no existe ingesta, ni pruebas, ni piloto, ni demo comercial.

## Alcance

* Endpoint `GET /api/v1/webhooks/whatsapp` que responda el `hub.challenge`.
* Resolución del tenant a partir del `hub.verify_token`, sin token compartido.
* Persistencia del `verify_token` por tenant, encriptado.
* Exclusión de la ruta del middleware de CSRF y de sesión.

## Criterios de aceptación

- [ ] Con `hub.mode=subscribe`, `verify_token` correcto y `hub.challenge`, responde `200` con el valor exacto del challenge en `text/plain`, sin comillas ni JSON *(AC-01.1)*.
- [ ] Con un `verify_token` que no corresponde a ningún tenant, responde `403` y no devuelve el challenge *(AC-01.2)*.
- [ ] El token del tenant A no valida la suscripción del tenant B *(AC-01.3)*.
- [ ] La URL queda validada en verde en el portal de Meta sin reintentos manuales *(AC-01.4)*.
- [ ] Los `verify_token` están encriptados en reposo y no aparecen en logs.
- [ ] Cada intento fallido queda registrado con timestamp, IP y token recibido.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ✅ | ⚠️ | ⚠️ | ✅ | ✅ | ✅ |

**N/V:** el protocolo lo fija Meta y ningún dueño de PyME obtiene valor de un handshake. Son propiedades inherentes a un enabler técnico, no defectos corregibles — **etiquetarlo Enabler** en vez de fabricarle un beneficio de usuario final. Arrancable el día 1 junto con T-012.

## Fuera de alcance

La ingesta de mensajes (`POST`), que es T-010. La pantalla donde el tenant carga sus credenciales, que es v2.

## Notas y riesgos

⚠️ **Sin columna definida para el `verify_token`.** `integrations` guarda `access_token` y `refresh_token` y no contempla este caso. Resolver dentro de este ticket: candidato `integrations.settings` con `provider='meta_whatsapp'`.

⚠️ Meta no sigue redirecciones durante la verificación: la URL tiene que responder directo por HTTPS.
