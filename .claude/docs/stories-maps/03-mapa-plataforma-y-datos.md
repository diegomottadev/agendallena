# Mapa 3 — Plataforma y datos (enablers técnicos)

**Perspectiva:** el sistema como actor. Ningún dueño de PyME pide nada de acá, y sin nada de acá no hay recorrido.
**Fuentes del backbone:** [`02-arquitectura/01-c4-nivel-2-contenedores.md`](../02-arquitectura/01-c4-nivel-2-contenedores.md) · [`02-arquitectura/02-c4-nivel-3-componentes.md`](../02-arquitectura/02-c4-nivel-3-componentes.md) · [`02-arquitectura/03-der-entidad-relacion.md`](../02-arquitectura/03-der-entidad-relacion.md) · [`02-arquitectura/04-modelo-de-datos.md`](../02-arquitectura/04-modelo-de-datos.md) · [`03-flujos/06-secuencia-agenda-cotizacion-e2e.md`](../03-flujos/06-secuencia-agenda-cotizacion-e2e.md)

```
C1 Recibir  → C2 Entender → C3 Calcular → C4 Actuar sobre → C5 Recordar → C6 Aislar   → C7 No       → C8 Persistir
   el evento    en qué       el precio     terceros           en el tiempo    por tenant    romperse
                punto está
```

> Este mapa **no reemplaza** a los otros dos: es la fila de abajo del mismo tablero. Cada actividad se lee como "qué tiene que existir en la plataforma para que los pasos de A y B sean posibles", y cada una se cruza contra tres preguntas: ¿existe el componente en el C4? ¿existe la tabla? ¿existe el índice?

---

## C1 · Recibir el evento

| Capacidad | Componente C4 L3 | Historia | Cobertura | Slice |
| :-- | :-- | :-- | :-: | :-- |
| C1.1 Responder el handshake de Meta | `WebhookCtrl` | [US-01](../stories/US-01-handshake-webhook-meta.md) | ✅ | MVP |
| C1.2 Validar la firma HMAC SHA256 | `AuthMiddleware` | [US-02](../stories/US-02-ingesta-asincrona-rafaga.md) (AC-02.2) | ✅ | MVP |
| C1.3 Responder 200 OK en < 200 ms y encolar | `AuthMiddleware` → `RedisDB` | [US-02](../stories/US-02-ingesta-asincrona-rafaga.md) (AC-02.1) | ✅ | MVP |
| C1.4 No perder mensajes con el worker caído | `RedisDB` | [US-02](../stories/US-02-ingesta-asincrona-rafaga.md) (AC-02.3) | ✅ | MVP |
| C1.5 Recibir las notificaciones *watch* de Google | `WebhookCtrl` | [US-06](../stories/US-06-reservar-y-liberar-sync-inversa.md) (AC-06.2/3) | ✅ | MVP |
| C1.6 Renovar los canales *watch* antes de que caduquen | — | 🕳️ | MVP |

> C1.6 lo señala la propia US-06 en "de qué depende" (*"renovación programada de los canales de escucha, que caducan"*), pero no tiene criterio de aceptación ni figura como componente en el C4. Un canal caducado no falla ruidosamente: simplemente deja de avisar, y los turnos borrados a mano dejan de liberarse en silencio. Es el modo de falla más difícil de detectar de todo el MVP.

---

## C2 · Entender en qué punto está la conversación

| Capacidad | Componente C4 L3 | Historia | Cobertura | Slice |
| :-- | :-- | :-- | :-: | :-- |
| C2.1 Resolver el estado actual por teléfono y tenant | `StateMachine` + Redis `session:` | [US-03](../stories/US-03-maquina-de-estados-conversacional.md) | ✅ | MVP |
| C2.2 Transicionar de forma determinista | `StateMachine` | [US-03](../stories/US-03-maquina-de-estados-conversacional.md) | ⚠️ | MVP |
| C2.3 Aislar conversaciones concurrentes | `EloquentRepo` | [US-03](../stories/US-03-maquina-de-estados-conversacional.md) (AC-03.3) | ✅ | MVP |
| C2.4 Expirar la sesión por inactividad | — | 🕳️ [H-07](05-huecos-y-trazabilidad.md#h-07) | MVP |
| C2.5 Verificar la pausa humana antes de responder | `HumanDetector` | [US-08](../stories/US-08-pausa-intervencion-humana.md) | ⚠️ | MVP |
| C2.6 **Escribir** la pausa humana al detectar el mensaje manual | — *(no existe en el C4)* | 🕳️ [H-06](05-huecos-y-trazabilidad.md#h-06) | MVP |

> **C2.2 — la máquina de estados que US-03 estima no es la que el sistema necesita.** RF-A3 declara cuatro estados (`IDLE`, `GATHERING_PARAMS`, `SELECTING_SLOT`, `BOOKED`) y US-03 estima **L** sobre esos cuatro. El catálogo de [`05-estados-flujo-conversacional.md`](../03-flujos/05-estados-flujo-conversacional.md) declara **once**, sumando `CALCULATING_QUOTE`, `SLOT_SELECTED`, `CONFIRMED`, `RESCHEDULED`, `CANCELLED`, `PAUSED_HUMAN` y `ERROR_FALLBACK`. La estimación más grande del backlog está calculada sobre el 36% de la superficie real. Ver [C-02](05-huecos-y-trazabilidad.md#c-02).
>
> **C2.6 es el hueco arquitectónico que US-08 ya denuncia y sigue abierto:** el C4 nivel 3 tiene el `HumanDetector` que *lee* la pausa en Redis, y ningún componente que la *escriba*. No es un olvido de diagrama: es que nadie sabe todavía si Meta entrega el evento que la dispararía.

### Decisión que estos documentos cierran

> **"¿El estado de la conversación vive en MySQL o en Redis?"** figura como decisión abierta bloqueante en [`03-user-stories-mvp.md`](../01-producto/03-user-stories-mvp.md) y como falla de *Estimable* en US-03. **Los flujos ya la responden, y responden "las dos":**
>
> > *"Persistencia Principal: tabla `conversations` (`current_state`, `context_data`, `last_interaction_at`). Cache & Locks de Estado: Redis key `session:tenant:{tenant_id}:phone:{user_phone}` para resolución ultrarrápida durante la ingesta."*
> > — [`05-estados-flujo-conversacional.md`](../03-flujos/05-estados-flujo-conversacional.md), sección 1
>
> MySQL es la fuente de verdad, Redis es caché de lectura. El C4 nivel 2 (`Redis (Queues, State, Cache)`) y el modelo de datos (`conversations.current_state`) son consistentes con eso. **La decisión no está abierta: está escrita en un documento que el backlog no leyó.** Basta con declararla cerrada para que US-03 pase a ser estimable.

---

## C3 · Calcular el precio

| Capacidad | Componente C4 L3 | Historia | Cobertura | Slice |
| :-- | :-- | :-- | :-: | :-- |
| C3.1 Evaluar la fórmula JSON del tenant | `QuoteEngine` | [US-12](../stories/US-12-cotizador-parametrico.md) (AC-12.1) | ✅ | MVP |
| C3.2 Validar entradas y rangos | `QuoteEngine` | [US-12](../stories/US-12-cotizador-parametrico.md) (AC-12.2/4) | ✅ | MVP |
| C3.3 Evaluar la fórmula **sin ejecutar código arbitrario** | — | 🕳️ | MVP |

> C3.3 no está en ninguna historia ni en ningún requisito. `quote_rules.formula_expression VARCHAR(255)` es una expresión cargada por el tenant que el motor tiene que evaluar; si esa evaluación termina en un `eval()`, un tenant puede ejecutar código en el servidor de todos los demás. US-12 pide *"validación estricta de entradas"*, que es otra cosa: valida lo que escribe el cliente final, no lo que escribe el tenant. Es una historia S y un incidente de los que terminan un contrato.

---

## C4 · Actuar sobre terceros

| Capacidad | Componente C4 L3 | Historia | Cobertura | Slice |
| :-- | :-- | :-- | :-: | :-- |
| C4.1 OAuth 2.0 con Google, tokens encriptados y renovados | `CalendarAdapter` | [US-04](../stories/US-04-conectar-google-calendar-oauth.md) | ✅ | MVP |
| C4.2 Consultar `freeBusy` dentro del presupuesto de 1,5 s | `CalendarAdapter` | [US-05](../stories/US-05-horarios-realmente-libres.md) | ⚠️ | MVP |
| C4.3 Crear y borrar eventos | `CalendarAdapter` | [US-06](../stories/US-06-reservar-y-liberar-sync-inversa.md) · [US-10](../stories/US-10-recordatorio-t24h.md) | ✅ | MVP |
| C4.4 Enviar mensajes y plantillas por Meta | `MetaAdapter` | [US-10](../stories/US-10-recordatorio-t24h.md) · [US-16](../stories/US-16-primer-contacto-bienvenida.md) | ✅ | MVP |
| C4.5 Agregar filas a Google Sheets | `SheetsAdapter` | [US-13](../stories/US-13-leads-a-google-sheets.md) | ⚠️ *sin RF* | MVP |
| C4.6 Mantener consistencia entre Google, MySQL y Sheets | — | 🕳️ [H-04](05-huecos-y-trazabilidad.md#h-04) | MVP |
| C4.7 Abstraer el proveedor de calendario (Google / Outlook) | — | 🕳️ *decisión abierta* | v2 |

> **C4.2 es `parcial` por medición, no por alcance.** RNF-04 fija el presupuesto punta a punta en 1,5 s y US-05 declara la latencia real de `freeBusy` como incógnita. Si `freeBusy` no entra, la respuesta no es optimizar código: es cachear disponibilidad, y eso cambia el diseño de C4.2 y de C1.5 a la vez. Es el spike de factibilidad que falta.
>
> **C4.6 es [H-04](05-huecos-y-trazabilidad.md#h-04) visto desde la plataforma:** el bloque `critical Agendamiento Atomico` de la secuencia e2e no es atómico. Tres sistemas, ningún mecanismo de compensación, y la falla deja un evento huérfano en el calendario del cliente.

---

## C5 · Recordar en el tiempo

| Capacidad | Componente C4 L3 | Historia | Cobertura | Slice |
| :-- | :-- | :-- | :-: | :-- |
| C5.1 Correr cada 10 minutos y detectar la ventana t-24h | `SchedulerService` | [US-10](../stories/US-10-recordatorio-t24h.md) (AC-10.1) | ✅ | MVP |
| C5.2 No duplicar ni enviar fuera de ventana | `SchedulerService` + `notification_logs` | [US-10](../stories/US-10-recordatorio-t24h.md) (AC-10.4) | ✅ | MVP |
| C5.3 Detectar la ventana t-2h | `SchedulerService` *(dibujado)* | [US-11](../stories/US-11-reagendar-y-aviso-t2h.md) (AC-11.4) | ⚠️ *sin RF* | MVP condicionado |
| C5.4 Registrar el resultado de cada envío | `notification_logs.status` | — | 🕳️ [H-08](05-huecos-y-trazabilidad.md#h-08) | MVP |
| C5.5 Consultar turnos próximos sin escanear la tabla | — | 🔴 [H-15](05-huecos-y-trazabilidad.md#h-15) | MVP |

> **C5.5 es el hueco de índices.** El scheduler corre cada 10 minutos y pregunta *"turnos en ventana t-24h con status `scheduled`"* (secuencia e2e, fase 5). Esa consulta filtra por `tenant_id`, `start_time` y `status`. El DDL de `bookings` tiene la PK y tres claves foráneas; **no tiene ningún índice compuesto**. Y el propio documento de modelo de datos afirma estar *"diseñado con índices compuestos específicos para garantizar tiempos de consulta inferiores a 15 ms"*. La afirmación y el DDL no coinciden. Lo mismo en `conversations`, que se consulta por `(tenant_id, user_phone)` en cada webhook —el camino crítico del RNF-04— y solo tiene índice por la FK `tenant_id`.

---

## C6 · Aislar por tenant

| Capacidad | Componente C4 L3 | Historia | Cobertura | Slice |
| :-- | :-- | :-- | :-: | :-- |
| C6.1 Forzar `WHERE tenant_id = ?` en toda consulta | `EloquentRepo` (Global Scopes) | [US-14](../stories/US-14-panel-logs-y-estado.md) (AC-14.2) | ✅ | MVP |
| C6.2 Resolver el tenant desde el webhook entrante | `AuthMiddleware` (`TenantScope`) | [US-01](../stories/US-01-handshake-webhook-meta.md) (AC-01.3) | ✅ | MVP |
| C6.3 Aislar también las llaves de Redis | Redis `session:` / `pause:` con `tenant_id` | [US-08](../stories/US-08-pausa-intervencion-humana.md) | ✅ | MVP |
| C6.4 Permitir la misma persona en más de un tenant | `users.email` UNIQUE global | — | 🕳️ [H-14](05-huecos-y-trazabilidad.md#h-14) | v2 |
| C6.5 Diferenciar permisos por rol | `users.role` | — | 🕳️ [H-13](05-huecos-y-trazabilidad.md#h-13) | v2 |

### Segunda decisión que estos documentos cierran

> **"¿Multi-tenancy por columna `tenant_id` o por schema?"** figura como decisión abierta bloqueante y como falla de *Estimable* en US-14, que atribuye la opción "schema por tenant" al C4 nivel 2. **Contra los archivos actuales, esa contradicción no existe:**
>
> | Documento | Qué dice |
> | :-- | :-- |
> | RNF-01 del PRD | *"Aislamiento lógico … mediante la columna discriminadora `tenant_id`"* |
> | C4 nivel 2 | `MySQL 8 (Multi-tenant with tenant_id)` |
> | C4 nivel 3 | `Eloquent Repositories (Aislamiento con tenant_id)` |
> | Modelo de datos | *"Base de datos compartida con discriminador lógico (`tenant_id`)"* + `tenant_id` en las 6 tablas |
>
> Los cuatro documentos dicen lo mismo. **La decisión está cerrada a favor de la columna**; declararlo por escrito destraba la estimación de US-14 y, con ella, la de todo el módulo de panel.

---

## C7 · No romperse

| Capacidad | Componente C4 L3 | Historia | Cobertura | Slice |
| :-- | :-- | :-- | :-: | :-- |
| C7.1 Mensaje de cortesía ante 5xx de Google | *(transversal)* | [US-09](../stories/US-09-fallback-ante-caidas.md) (AC-09.1) | ✅ | MVP |
| C7.2 Backoff exponencial ante rate limit de Meta | `MetaAdapter` | [US-09](../stories/US-09-fallback-ante-caidas.md) (AC-09.3) | ✅ | MVP |
| C7.3 Marcar la integración como vencida | `integrations.status='expired'` | [US-09](../stories/US-09-fallback-ante-caidas.md) (AC-09.2) | ⚠️ | MVP |
| C7.4 Ningún camino termina en silencio | *(transversal)* | [US-09](../stories/US-09-fallback-ante-caidas.md) (AC-09.4) | ✅ | MVP |
| C7.5 Logging estructurado con `tenant_id` | — *(sin tabla ni destino definido)* | [US-09](../stories/US-09-fallback-ante-caidas.md) | ⚠️ | MVP |

> C7.5 aparece en tres historias como dependencia (US-01 "queda registro del intento", US-09 "excepción registrada con `tenant_id`, timestamp e integración", US-14 AC-14.2 "el intento queda registrado") y **no tiene ni tabla ni componente ni destino**. No es bloqueante —Laravel escribe a archivo por defecto— pero conviene decidirlo antes de que tres historias lo asuman de tres maneras distintas.

---

## C8 · Persistir

Cruce de cada tabla del modelo de datos contra las historias que la consumen:

| Tabla | Historias que la usan | Estado | Falta |
| :-- | :-- | :-: | :-- |
| `tenants` | US-01, US-07, US-15 (AC-15.3) | ✅ | — |
| `users` | US-14 | ⚠️ | `UNIQUE(email)` global en vez de `(tenant_id, email)`; roles sin historia |
| `integrations` | US-04, US-09, US-13 | ✅ | — |
| `quote_rules` | US-12, US-15 (AC-15.4) | ✅ | — |
| `conversations` | US-03, US-08, US-14 | ⚠️ | Sin índice `(tenant_id, user_phone)`; sin historial de mensajes |
| `bookings` | US-06, US-10, US-11, US-14, US-17 | 🔴 | `status` sin `attended`/`no_show`; sin índice `(tenant_id, start_time, status)` |
| `notification_logs` | US-10, US-11, US-17 (AC-17.4) | ⚠️ | Nadie lee `status='failed'` |
| **`business_settings`** *(no existe)* | US-05, US-09, US-15, US-16 | 🔴 | Horarios, duración, buffer y textos no tienen tabla |
| **`messages`** *(no existe)* | US-14 (AC-14.4) | 🔴 | El historial de conversación no tiene tabla |

> **Tres de los cinco huecos bloqueantes del proyecto son una línea de migración cada uno** ([H-09](05-huecos-y-trazabilidad.md#h-09), [H-10](05-huecos-y-trazabilidad.md#h-10), [H-11](05-huecos-y-trazabilidad.md#h-11)). Eso es una buena noticia y una mala: son baratos de arreglar, y llevan meses siendo invisibles porque nadie cruzó el backlog contra el DDL. El costo no está en la migración sino en cuánto se estimó sobre un modelo que no soportaba lo que las historias prometían.

---

## Resumen de la plataforma

| Actividad | Capacidades | Cubiertas | Parciales | Huecos |
| :-- | :-: | :-: | :-: | :-: |
| C1 Recibir el evento | 6 | 5 | — | 1 |
| C2 Entender el estado | 6 | 2 | 2 | 2 |
| C3 Calcular el precio | 3 | 2 | — | 1 |
| C4 Actuar sobre terceros | 7 | 3 | 2 | 2 |
| C5 Recordar en el tiempo | 5 | 2 | 1 | 2 (1 🔴) |
| C6 Aislar por tenant | 5 | 3 | — | 2 |
| C7 No romperse | 5 | 3 | 2 | — |
| C8 Persistir | 9 tablas | 3 | 3 | 3 🔴 |
| **Total** | **46** | **23** | **10** | **13** |

**Lo que este mapa aporta y los otros dos no pueden:** dos decisiones que el backlog declara abiertas y bloqueantes **ya están respondidas** en los documentos de arquitectura y flujos (persistencia del estado y estrategia multi-tenant). Destrabarlas es media hora de escritura y convierte a US-03 y US-14 —dos de las cuatro historias no estimables— en estimables. Es el retorno más alto por hora de todo este ejercicio.
