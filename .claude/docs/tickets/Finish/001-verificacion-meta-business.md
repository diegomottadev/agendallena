# T-001 · Verificación de Meta Business y alta del número

| | |
| :-- | :-- |
| **Prioridad** | 1 |
| **Sprint** | 0 — Habilitación |
| **Tipo** | Trámite externo |
| **Historia** | [US-01](../../stories/US-01-handshake-webhook-meta.md) |
| **Story points** | 1 *(esfuerzo propio; el tiempo de calendario es ajeno)* |
| **Depende de** | — |
| **Bloquea a** | T-008 y, en cascada, todo el MVP |
| **Pareto** | **NÚCLEO** · ola 0 |

## Contexto

Sin cuenta de Meta Business verificada y número de WhatsApp Business aprobado no hay canal, y sin canal no hay nada testeable. Es el único riesgo del plan que no se resuelve con talento sino con un permiso de un tercero.

## Alcance

* Crear y verificar la cuenta de Meta Business (documentación de la empresa).
* Dar de alta el número de WhatsApp Business y completar su aprobación.
* Registrar la app en el portal de desarrolladores y obtener el secreto de aplicación para la firma HMAC.
* Registrar el dominio con HTTPS y certificado válido.

## Criterios de aceptación

- [ ] La cuenta de Meta Business figura como verificada en el portal.
- [ ] El número de WhatsApp Business está aprobado y asociado a la app.
- [ ] El secreto de aplicación está cargado en el gestor de secretos del entorno, no en el repositorio.
- [ ] El dominio responde por HTTPS con certificado válido y sin redirecciones.

## Fuera de alcance

Cualquier código. Este ticket es coordinación y espera.

## Notas y riesgos

⚠️ **La demora es impredecible: de días a semanas.** Arranca el día 1 en paralelo con todo lo demás. Si Meta rechaza la cuenta, el plan entero cambia y conviene saberlo en la semana 1 y no en la 6.
