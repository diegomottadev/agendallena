# US-14 · Ver qué está pasando sin llamar a soporte

| | |
| :-- | :-- |
| **Sección** | 6 — Que el cliente arranque solo |
| **Prioridad** | 14 de 14 |
| **Estimación** | **L** |
| **Trazabilidad** | RF-E2 · RNF-01 |
| **Depende de** | [US-03](US-03-maquina-de-estados-conversacional.md) · [US-06](US-06-reservar-y-liberar-sync-inversa.md) · [US-12](US-12-cotizador-parametrico.md) |
| **Bloquea a** | — |

## Historia

> **Como** dueña de PyME que recibe un reclamo de un cliente,
> **quiero** ver en el panel el detalle de esa conversación con su estado y su turno,
> **para** resolverlo yo en dos minutos en lugar de escribirle al proveedor y esperar.

## Criterios de aceptación

**AC-14.1 — Listado de conversaciones**
> **Dado que** ingresé al panel con mi usuario,
> **cuando** abro la lista de chats,
> **entonces** veo únicamente las conversaciones de mi tenant, con estado actual, fecha del turno y monto cotizado.

**AC-14.2 — Aislamiento entre tenants**
> **Dado que** soy usuario del tenant A,
> **cuando** intento abrir por URL directa una conversación del tenant B,
> **entonces** recibo `HTTP 403` y el intento queda registrado.

**AC-14.3 — Enlace al evento del calendario**
> **Dado que** una conversación tiene un turno reservado,
> **cuando** abro su detalle,
> **entonces** veo un enlace directo al evento en Google Calendar que abre en el turno correcto.

**AC-14.4 — Visibilidad del takeover**
> **Dado que** una conversación está en pausa por intervención humana,
> **cuando** la abro en el panel,
> **entonces** veo esa marca junto con el historial completo de mensajes.

## Requisitos técnicos

* Autenticación del panel con Laravel Sanctum y sesión por tenant. Conviene sacarla como historia propia: hoy está enterrada acá y bloquea todo el módulo.
* Aplicar un Global Scope `BelongsToTenant` que fuerce `WHERE tenant_id = ?` en cada consulta de Eloquent (AC-14.1, AC-14.2). **Decisión cerrada a favor de la columna:** RNF-01, el C4 nivel 2, el C4 nivel 3 y el modelo de datos dicen los cuatro `tenant_id`.
* Responder `HTTP 403` y registrar el intento con `tenant_id` de origen y recurso solicitado ante un acceso cruzado por URL directa (AC-14.2).
* Endpoint de listado paginado con estado actual, fecha del turno y monto cotizado, resuelto con un join a `bookings` y sin consultas N+1 (AC-14.1).
* Construir el enlace al evento de Google a partir de `bookings.external_event_id` (AC-14.3).
* Leer la llave `pause:tenant:{tenant_id}:phone:{user_phone}` de Redis para mostrar la marca de intervención humana y su tiempo restante (AC-14.4).
* ⚠️ **AC-14.4 no es construible hoy:** promete "el historial completo de mensajes" y **no existe tabla `messages`**. `conversations` guarda `current_state`, `context_data` y `last_interaction_at`: el estado, no el diálogo. Hace falta una migración con `tenant_id`, `conversation_id`, dirección, contenido, `whatsapp_message_id` y timestamp.
* Descopear el MVP a la lista de solo lectura y diferir la vista de detalle rica. No es una decisión de producto: es lo único construible con el modelo de datos actual.
* ⚠️ Sin definir qué ve cada rol. `users.role` admite `owner`, `admin` y `staff`, y ninguna historia dice qué puede hacer cada uno.

## Estimación de complejidad

**L** — Autenticación, listado, vista de detalle, filtrado por tenant y enlaces externos. Son varias piezas de UI más el trabajo de aislamiento en cada consulta.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ❌ | Depende de tres historias: necesita datos de conversación, de turno y de cotización para tener algo que mostrar. |
| **Negociable** | ✅ | La UI, los filtros y el nivel de detalle están completamente abiertos. |
| **Valiosa** | ✅ | Es lo que hace escalable al negocio: sin panel, cada duda de cada cliente es una hora nuestra. |
| **Estimable** | ⚠️ | **Ya no está bloqueada por multi-tenancy:** RNF-01, los dos niveles del C4 y el modelo de datos dicen los cuatro `tenant_id`. Lo que sí impide estimarla completa es que AC-14.4 no tiene tabla `messages` donde leer. |
| **Pequeña** | ❌ | Autenticación y vista de logs son historias separadas. |
| **Testeable** | ✅ | AC-14.2 en particular es un test de seguridad concreto y automatizable. |

**Veredicto:** cumple 3 de 6.

> **Mejora recomendada:** partir la autenticación del panel de la vista de logs, y descopear el MVP a una lista de solo lectura. La vista de detalle rica puede esperar al primer release posterior sin bloquear el piloto.

## Por qué es urgente (o crítica)

Sin panel, el modelo de tarifa plana de USD 80-150 deja de cerrar apenas pasamos de un puñado de cuentas: el soporte se come el margen. Es también donde se verifica de forma visible el aislamiento multi-tenant del RNF-01 — un tenant viendo datos de otro es un incidente que termina el contrato.

## Qué esperamos aprender

* Cuáles son las tres preguntas que el cliente se hace de verdad al abrir el panel — el insumo para saber si el próximo release necesita métricas o si con los logs alcanza.
* Cuánto baja el volumen de soporte por cliente después de publicarlo.

## De qué depende

* [US-03](US-03-maquina-de-estados-conversacional.md), [US-06](US-06-reservar-y-liberar-sync-inversa.md) y [US-12](US-12-cotizador-parametrico.md) — datos para mostrar.
* Autenticación del panel.
* ~~Decisión abierta: multi-tenancy por columna o por schema.~~ **Cerrada: por columna `tenant_id`**, según RNF-01, C4 L2, C4 L3 y el modelo de datos.
* **Migración faltante:** tabla `messages`, sin la cual AC-14.4 no se construye.
