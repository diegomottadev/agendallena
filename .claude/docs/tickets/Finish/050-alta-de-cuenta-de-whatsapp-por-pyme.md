# T-050 · Alta de cuenta de WhatsApp por PyME

| | |
| :-- | :-- |
| **Prioridad** | 50 |
| **Sprint** | — · antes del segundo cliente pago |
| **Tipo** | Feature |
| **Historia** | ⚠️ Sin historia: sale de una decisión de modelo de negocio del 2026-08-20, no del story map |
| **Story points** | **5** · ⚠️ confianza media — depende de cuánto del alta se automatice |
| **Depende de** | T-002, T-037 |
| **Bloquea a** | El alta del segundo cliente |
| **Pareto** | **NÚCLEO** — no del MVP técnico, sí del MVP comercial |

## Contexto

**Decidido el 2026-08-20: cada PyME tiene su propia cuenta de WhatsApp (WABA) y su propio número.** No se usa una cuenta única del proveedor con un número por cliente adentro.

La consecuencia que este ticket existe para resolver: **las plantillas se aprueban por WABA, no por número.** Con una cuenta por PyME, cada cliente necesita **sus tres plantillas aprobadas en su propia cuenta**. Las que se aprobaron el 2026-08-19 sirven para la cuenta de prueba y para ninguna otra.

Hoy nada de esto existe en el código, y **con un solo piloto no se nota**: `META_WABA_ID` es una variable de entorno global, igual que lo era `META_PHONE_NUMBER_ID` antes del bug de aislamiento que se arregló el 2026-08-20. Es la misma forma de falla, todavía sin escribir.

## Alcance

* El `waba_id` pasa a ser **por tenant**. Va en `integrations.settings`, donde ya vive el `verify_token` de cada PyME.
* Paso de alta que **crea las tres plantillas por API** (`POST /{waba-id}/message_templates`) en la cuenta del cliente nuevo, con los cuerpos exactos de ese documento *(documento borrado por innecesario el 2026-08-21)*.
* Registro del **estado de aprobación** de las plantillas de cada tenant.
* T-037 consulta ese estado antes de intentar el envío.

## Criterios de aceptación

- [ ] El `waba_id` sale de la integración del tenant y **no** de `config('services.meta.waba_id')`. Un test con dos tenants con WABA distintas lo demuestra.
- [ ] Dar de alta un tenant crea sus tres plantillas en **su** cuenta, con los cuerpos y los parámetros posicionales exactos, y los tres botones quick reply de `recordatorio_turno_24h`.
- [ ] El estado de aprobación de cada plantilla queda registrado por tenant y se puede consultar.
- [ ] Si las plantillas de un tenant **no están aprobadas todavía**, T-037 no intenta el envío y **lo registra** — no falla en silencio (RNF-03).
- [ ] Un tenant sin `waba_id` cargado da un error explícito al intentar mandar, no un fallo de Meta indescifrable.

## Fuera de alcance

El **embedded signup** de Meta —el flujo en el que la PyME autoriza desde un popup y su WABA queda vinculada sola— es el camino que hace esto realmente escalable, y es un ticket propio. Acá alcanza con que el alta funcione aunque parte del trámite sea manual.

También queda fuera el borrado o la actualización de plantillas de un cliente existente.

## Notas y riesgos

⚠️ **El tiempo de aprobación de Meta no lo controlamos.** Para plantillas Utility suele ser rápido, pero no hay garantía. Esto es lo que pone en riesgo el objetivo de *"poner en marcha un cliente en menos de una hora"*: la parte nuestra puede ser automática y aun así el cliente esperar. **El piloto tiene que medir cuánto tarda de verdad.**

⚠️ **El alta no es solo plantillas.** Verificación del negocio en Meta, alta del número y su verificación son trámites del cliente. Este ticket cubre lo que es código.

⚠️ Con una cuenta por PyME, **si el cliente se va se lleva su número**. Es la contracara de que sea más fácil de vender.
