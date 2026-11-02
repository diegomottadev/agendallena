---
fecha: 2026-08-05
objetivo: Generar user stories del MVP en formato INVEST/BDD incluyendo los requisitos técnicos como una sección más dentro de cada historia
estado: aprobado
---

## Pedido original

c:\Users\ACER\Documents\Develop\agendallena\.claude\prompts\generar-user-stories-mvp.md adapta tmb para que agregenecesitarían necesitarían Dadas las historias de usuario como un parte mas de la descripcion de la hisotira de usuario qué requisitos técnicos se necesitarían?

## Prompt

# Prompt: Generar user stories del MVP con requisitos técnicos (Agile & INVEST)

**Rol que asume el modelo:** Senior Product Owner y Strategist de Producto (Metodologías Ágiles, Scrum, Lean Startup, Product Discovery) con criterio de arquitectura de software.
**Entregable:** [N] user stories priorizadas por riesgo, en formato clásico (Como/Quiero/Para) con Criterios de Aceptación BDD, **Requisitos Técnicos**, Estimación y Evaluación INVEST.

**Antes de usarlo:** reemplazá `[N]` por la cantidad de historias que querés (ej. `10`).

---

## Prompt

Actuá como un Senior Product Owner y Strategist de Producto con amplia experiencia en metodologías ágiles, Scrum, Lean Startup y arquitectura de software.

### CONTEXTO DEL PRODUCTO Y MVP

Estamos construyendo un SaaS Multi-Tenant llamado **WhatsApp-First Scheduling & Quoting Middleware** diseñado para PyMEs latinoamericanas de 3 a 20 empleados (clínicas, distribuidoras B2B, inmobiliarias, servicios de mantenimiento).

* **Propuesta de valor:** Cotizar y agendar el 100% de las consultas de WhatsApp en menos de 15 segundos sin obligar al equipo a cambiar sus herramientas (siguen usando Google Calendar y Google Sheets).
* **Stack Técnico:** Laravel 11+ (Queues + Redis + State Machine), Vue.js 3 (Dashboard Admin), Meta Cloud API (WhatsApp Business API oficial), Google Calendar API (OAuth 2.0 FreeBusy + Sync).
* **Alcance del MVP (Release 1.0):**
  1. Handshake y validación de Webhook de Meta.
  2. Ingesta asíncrona de mensajes y Máquina de Estados en Laravel.
  3. Pausa automática por intervención humana (Human Takeover) por 60 min si el operador responde desde la app móvil.
  4. Cotizador paramétrico simple basado en fórmulas JSON.
  5. Sincronización OAuth 2.0 bidireccional con Google Calendar (consulta de disponibilidad en tiempo real + reserva).
  6. Manejo estricto de zonas horarias locales (Timezones).
  7. Recordatorios automáticos interactivos a t-24h y t-2h (Confirmar / Re-agendar / Cancelar).
  8. Volcado automático de leads a Google Sheets API.
  9. Mensajes de Fallback Graceful ante caídas de APIs externas.

---

### INSTRUCCIONES DE GENERACIÓN

A partir de la descripción del producto, generá [N] User Stories para el MVP que cumplan estrictamente con los criterios INVEST.

#### 1. Formato de las Historias
Para cada historia debés incluir exactamente la siguiente estructura, **en este orden**:
* **Título descriptivo**
* **Historia:** Formato "Como [rol], quiero [acción], para [beneficio]".
* **Criterios de Aceptación:** Al menos 3 criterios en formato BDD (*Dado que / Cuando / Entonces*).
* **Requisitos Técnicos:** ver sección 2.
* **Estimación de complejidad:** (S / M / L).
* **Evaluación INVEST:** Breve evaluación de cómo la historia cumple con los criterios (Independiente, Negociable, Valiosa, Estimable, Pequeña, Testeable).

#### 2. Requisitos Técnicos (parte obligatoria de cada historia)
Respondé, dentro de cada historia, la pregunta: **¿qué requisitos técnicos se necesitarían para poder implementarla?**

Reglas para esta sección:
* Derivá los requisitos **de la propia historia y de sus criterios de aceptación**, no de una lista genérica de buenas prácticas: si un requisito no es exigido por lo que la historia promete, no lo incluyas.
* Escribí cada requisito como una viñeta accionable y verificable, empezando por un verbo (ej. "Exponer endpoint `POST /webhook/meta` que responda el challenge de verificación en menos de 5s").
* Sé concreto respecto del stack declarado (Laravel 11+, Queues + Redis, State Machine, Vue.js 3, Meta Cloud API, Google Calendar API OAuth 2.0, Google Sheets API): nombrá endpoints, jobs, colas, tablas/entidades, scopes de OAuth, eventos y estados cuando corresponda.
* Cubrí, **solo cuando la historia lo requiera**, estas dimensiones: modelo de datos y migraciones; endpoints/contratos de API; integraciones y credenciales externas (scopes, tokens, webhooks); procesamiento asíncrono (jobs, colas, reintentos, idempotencia); estados y transiciones de la máquina de estados; multi-tenancy y aislamiento de datos; seguridad (firma de webhooks, almacenamiento de secretos, permisos); manejo de zonas horarias; observabilidad (logs, métricas, alertas); performance o límites de rate limiting de las APIs externas; configuración/feature flags.
* Marcá explícitamente con `⚠️` cualquier requisito que sea un **hueco o decisión abierta** (información que falta, definición de producto pendiente o incertidumbre técnica), en vez de inventar el detalle.
* Mantené la trazabilidad: cuando un requisito exista por un criterio de aceptación puntual, referenciálo (ej. "(CA-2)").

#### 3. Notas de Aprendizaje y Riesgo (Lean Startup)
En cada historia, incluí de forma detallada estas 3 notas para alinearnos con Product Discovery:
* **Por qué es urgente (o crítica):** Qué supuesto de riesgo mitiga o por qué bloquea el aprendizaje comercial.
* **Qué esperamos aprender:** Qué hipótesis de negocio/técnica validamos o qué métrica medimos.
* **De qué depende:** Dependencias técnicas (APIs, webhooks, colas) o funcionales previas.

#### 4. Organización y Priorización del Entregable
* **Agrupación:** Agrupá las historias en secciones temáticas con títulos descriptivos y una frase introductoria por sección que deje claro el orden lógico de aprendizaje (ej: Validar la Ingesta -> Validar la Integración -> Validar la Retención).
* **Priorización Final:** Después de generar las historias, sugerí un orden de priorización exacto para el MVP y justificá tu decisión basándote en la necesidad de validar nuestros supuestos lo antes posible.

### RESTRICCIONES

* Los **Requisitos Técnicos son parte de la descripción de cada historia**, no un anexo ni un documento separado: no agregues una sección global de arquitectura al final.
* No conviertas los requisitos técnicos en criterios de aceptación ni dupliques su contenido: los criterios describen comportamiento observable; los requisitos describen lo que hay que construir o disponer para lograrlo.
* No inventes datos del negocio, credenciales, límites de APIs ni decisiones de producto que no estén en el contexto: marcalos con `⚠️` como hueco.
* Respetá el stack declarado; si una historia exigiera una pieza fuera de ese stack, señalalo con `⚠️` en lugar de asumirla.
* Escribí todo el entregable en español rioplatense, con lenguaje técnico preciso.

## Supuestos

- "Como una parte más de la descripción de la historia" se interpretó como una **sección fija dentro de cada historia** (después de los Criterios de Aceptación y antes de la Estimación), no como un documento aparte ni como texto embebido en la frase Como/Quiero/Para.
- Se mantuvieron intactos el contexto de producto, el formato original y la sección de Notas Lean Startup; lo único agregado es la sección de Requisitos Técnicos y las restricciones que la delimitan (y la renumeración que eso obliga).
- El marcador `⚠️` para huecos y decisiones abiertas es una convención agregada para evitar que el modelo invente detalle técnico ausente del contexto.

## Resultado

no ejecutado
