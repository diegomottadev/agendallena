# US-01 · Handshake de webhook de Meta

| | |
| :-- | :-- |
| **Sección** | 1 — Habilitar el canal |
| **Prioridad** | 1 de 14 |
| **Estimación** | **S** |
| **Trazabilidad** | RF-A1 · CA-01 |
| **Depende de** | — (solo dependencias externas) |
| **Bloquea a** | [US-02](US-02-ingesta-asincrona-rafaga.md) y, en cascada, todo el MVP |

## Historia

> **Como** responsable del alta de un tenant nuevo,
> **quiero** que nuestra URL responda correctamente el desafío de verificación de Meta al primer intento,
> **para** cerrar el alta en la misma sesión con el cliente en línea, sin agendar una segunda reunión técnica.

## Criterios de aceptación

**AC-01.1 — Handshake exitoso**
> **Dado que** un tenant tiene su propio `verify_token` configurado,
> **cuando** Meta envía un `GET` con `hub.mode=subscribe`, el `hub.verify_token` correcto y un `hub.challenge`,
> **entonces** el sistema responde `HTTP 200` con el valor exacto del `hub.challenge` en texto plano, sin comillas ni envoltorio JSON.

**AC-01.2 — Token inválido rechazado**
> **Dado que** llega un `GET` con un `verify_token` que no corresponde a ningún tenant,
> **cuando** se procesa la petición,
> **entonces** el sistema responde `HTTP 403` y no devuelve el `challenge`.

**AC-01.3 — Aislamiento entre tenants**
> **Dado que** los tenants A y B tienen tokens distintos,
> **cuando** se intenta validar la suscripción de A usando el token de B,
> **entonces** la validación falla, y el token de cada tenant solo habilita su propia suscripción.

**AC-01.4 — Validación en el portal de Meta**
> **Dado que** la URL fue registrada en el portal de desarrolladores de Meta,
> **cuando** el administrador ejecuta la verificación,
> **entonces** la URL queda validada en verde sin reintentos manuales.

## Requisitos técnicos

* Exponer `GET /api/v1/webhooks/whatsapp` que devuelva el `hub.challenge` con `Content-Type: text/plain`, sin envoltorio JSON ni comillas (AC-01.1).
* Resolver el tenant a partir del `hub.verify_token` recibido, sin ningún token compartido entre tenants (AC-01.3).
* Persistir un `verify_token` por tenant, encriptado en reposo. ⚠️ **No hay dónde:** `integrations` guarda `access_token` y `refresh_token`, pero el `verify_token` de Meta no tiene columna declarada. Candidato natural: `integrations.settings` con `provider='meta_whatsapp'`, sin decidir.
* Responder `HTTP 403` sin cuerpo ni `challenge` ante un token no reconocido (AC-01.2).
* Excluir la ruta del middleware de CSRF y de autenticación de sesión: Meta llega sin cookie ni token.
* Servir el endpoint sobre HTTPS con certificado válido y **sin redirecciones** — Meta no sigue redirects durante la verificación (AC-01.4).
* Registrar cada intento fallido con timestamp, IP y token recibido. ⚠️ El destino del log estructurado no está definido en ningún documento.
* ⚠️ **Decisión abierta:** quién carga el `verify_token` y desde dónde. Ninguna historia construye esa pantalla; durante los pilotos sería un comando `artisan` o un seeder.

## Estimación de complejidad

**S** — Un endpoint, tres comparaciones de strings y una respuesta en texto plano. El trabajo real no es código sino la coordinación con el portal de Meta.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ✅ | No depende de ningún desarrollo interno previo. Es la única historia arrancable en el día 1 junto con US-04. |
| **Negociable** | ⚠️ | El protocolo lo fija Meta: no hay margen sobre el *qué*. Sí lo hay sobre cómo se resuelve el token por tenant. |
| **Valiosa** | ⚠️ | **Es un enabler.** Ningún dueño de PyME obtiene valor de un handshake. El valor es interno: acorta el tiempo de alta y destraba todo lo demás. |
| **Estimable** | ✅ | Protocolo documentado y cerrado. Sin incógnitas. |
| **Pequeña** | ✅ | Entra holgada en un sprint. |
| **Testeable** | ✅ | Binaria: la URL valida o no valida. |

**Veredicto:** cumple 4 de 6. Las dos observaciones son inherentes a una historia de habilitación técnica, no defectos corregibles. Conviene etiquetarla **Enabler** en vez de forzarle un beneficio de usuario final.

## Por qué es urgente (o crítica)

Es el candado de entrada. Sin la URL validada en verde no existe ingesta, ni pruebas, ni piloto, ni demo comercial. Además consume tiempo calendario ajeno: si falla, el ciclo de reintento con Meta agrega días. Bloquea el 100% del aprendizaje posterior.

## Qué esperamos aprender

Si nuestro esquema multi-tenant puede resolver el `hub.verify_token` por tenant sin exponer un token compartido entre clientes, y cuánto tarda realmente el alta punta a punta — input directo para el KPI de setup en menos de 60 minutos.

## De qué depende

* Cuenta de Meta Business verificada.
* Número de WhatsApp Business aprobado.
* Dominio con HTTPS y certificado válido.
* Endpoint `GET /api/v1/webhooks/whatsapp`.
