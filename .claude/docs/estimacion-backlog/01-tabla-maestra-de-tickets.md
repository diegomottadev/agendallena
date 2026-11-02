# Tabla maestra de tickets — estimación en story points

Los **49 tickets** que completan el backlog de 26 historias, en orden de prioridad, con la estimación sugerida.

**Escala Fibonacci:** `1` medio día · `2` un día · `3` uno o dos días · `5` tres o cuatro días · `8` una semana *(techo: por encima de 8 hay que partir)*.
**Total: 177 puntos.**

**Leyenda de tipo:** `TRA` trámite externo · `SPK` spike o discovery · `DOC` documentación · `MIG` migración · `ENA` enabler · `FEA` feature · `CAL` calidad.
**Confianza de la estimación:** `A` alta (alcance cerrado) · `M` media (una decisión abierta) · `B` baja (depende de un resultado externo).

---

| # | Ticket | Historia | Tipo | Pts | Conf. | Depende de | Justificación de la estimación |
| :-: | :-- | :-: | :-: | :-: | :-: | :-- | :-- |
| 1 | [Verificación de Meta Business](../tickets/Finish/001-verificacion-meta-business.md) | US-01 | TRA | **1** | A | — | Coordinación y espera. El esfuerzo propio es mínimo; el tiempo de calendario es ajeno y no se puntúa |
| 2 | [Aprobación de plantillas de mensaje](../tickets/Finish/002-aprobacion-de-plantillas.md) | US-10 | TRA | **2** | M | T-001 | Redactar dos plantillas y gestionar rechazos. Meta rechaza por criterios de copy no evidentes: prever dos iteraciones |
| 3 | [Consentimiento de Google y OAuth](../tickets/Finish/003-consentimiento-google-oauth.md) | US-04 | TRA | **2** | A | — | Configuración de proyecto y envío a revisión. Procedimiento documentado |
| 4 | [**Spike:** mensajes salientes de Meta](../tickets/Next-iteraction/004-spike-mensajes-salientes-meta.md) | US-08 | SPK | **3** | A | T-001 | Timebox de 2 días. El punto es acotar el tiempo, no acertar el esfuerzo |
| 5 | [**Spike:** latencia de `freeBusy`](../tickets/Finish/005-spike-latencia-freebusy.md) | US-05 | SPK | **2** | A | T-003 | Timebox de 1 día: medir y documentar |
| 6 | [**Discovery:** catálogos de precios](../tickets/Next-iteraction/006-discovery-catalogos-de-precios.md) | US-12 | SPK | **3** | M | — | Un día de trabajo, cero código. El riesgo es conseguir las listas reales de cuatro negocios |
| 7 | [Cerrar decisiones y escribir RF](../tickets/Finish/007-cerrar-decisiones-y-rf-faltantes.md) | 7 historias | DOC | **3** | A | — | Cinco RF y cinco decisiones. Dos ya están respondidas: solo falta el acta |
| 8 | [Handshake de webhook de Meta](../tickets/Finish/008-handshake-webhook-meta.md) | US-01 | ENA | **3** | A | T-001 | Un endpoint y tres comparaciones. Lo único abierto es dónde guardar el `verify_token` |
| 9 | [**Migración:** índices compuestos](../tickets/Finish/009-migracion-indices-compuestos.md) | US-02 | MIG | **2** | A | — | Cuatro índices y verificar planes de ejecución. Barato ahora, caro con datos |
| 10 | [Ingesta asíncrona con firma HMAC](../tickets/Finish/010-ingesta-asincrona-y-firma-hmac.md) | US-02 | ENA | **5** | M | T-008, T-009 | El endpoint es trivial; el costo está en la firma sobre cuerpo crudo y el arnés de carga concurrente |
| 11 | [Idempotencia y resiliencia de cola](../tickets/Finish/011-idempotencia-y-resiliencia-de-cola.md) | US-02 | ENA | **3** | A | T-010 | Deduplicación por `message_id` y configuración de reintentos |
| 12 | [OAuth 2.0 con Google Calendar](../tickets/Finish/012-oauth-google-calendar.md) | US-04 | FEA | **5** | M | T-003 | Flujo estándar, más shell del panel, encriptado y `state` firmado. Sube a 8 si Outlook entra |
| 13 | [Renovación automática de tokens](../tickets/Finish/013-renovacion-automatica-de-tokens.md) | US-04 | FEA | **3** | A | T-012 | Refresco transparente y reintento único. Alcance cerrado |
| 14 | [**Migración:** `business_settings`](../tickets/Finish/014-migracion-business-settings.md) | US-15 | MIG | **2** | A | — | Una tabla con valores por defecto. Desbloquea seis tickets |
| 15 | [Configuración de agenda](../tickets/Finish/015-configuracion-de-agenda.md) | US-15 | FEA | **5** | A | T-012, T-014 | Pantalla con horarios por día, duración, buffer, zona y validaciones cruzadas |
| 16 | [Configuración de precios](../tickets/Next-iteraction/016-configuracion-de-precios.md) | US-15 | FEA | **3** | M | T-012 | CRUD sobre `quote_rules`. La forma del `parameters_schema` depende de T-006 |
| 17 | [Configuración de mensajes](../tickets/Finish/017-configuracion-de-mensajes.md) | US-15 | FEA | **2** | A | T-014 | Tres textos editables con variables. El ticket más limpio del plan (6/6 INVEST) |
| 18 | [Máquina de estados: núcleo](../tickets/Finish/018-maquina-de-estados-nucleo.md) | US-03 | ENA | **8** | M | T-007, T-010 | **Once** estados con transiciones, locks y persistencia dual. Es el techo de la escala |
| 19 | [Mensajes interactivos de Meta](../tickets/Finish/019-mensajes-interactivos-de-meta.md) | US-03 | ENA | **5** | A | T-018 | Botones, listas, límites de la API y sellado de estado en el `id` |
| 20 | [Logging estructurado](../tickets/Finish/020-logging-estructurado.md) | US-09 | ENA | **2** | A | — | Formato, contexto automático y redacción de secretos |
| 21 | [Bienvenida y menú inicial](../tickets/Finish/021-bienvenida-y-menu-inicial.md) | US-16 | FEA | **3** | A | T-017, T-018, T-019 | Un estado de entrada, botones y palabra de reinicio |
| 22 | [Consulta de disponibilidad](../tickets/Finish/022-consulta-de-disponibilidad.md) | US-05 | FEA | **5** | M | T-005, T-012, T-015 | `freeBusy` más generación de grilla con buffer y conversión horaria. Sube a 8 si hace falta caché |
| 23 | [Lista interactiva de horarios](../tickets/Finish/023-lista-interactiva-de-horarios.md) | US-05 | FEA | **3** | A | T-019, T-022 | Renderizado con paginado dentro del límite de Meta |
| 24 | [Respuesta sin disponibilidad](../tickets/Finish/024-respuesta-sin-disponibilidad.md) | US-19 | FEA | **3** | A | T-015, T-017, T-022 | Tres mensajes y una segunda consulta. El trabajo es distinguir tres vacíos idénticos |
| 25 | [Pausar el bot desde el panel](../tickets/Finish/025-pausar-el-bot-desde-el-panel.md) | US-21 | FEA | **3** | A | T-012, T-018 | Dos endpoints y una llave de Redis ya diseñada. Cubre el riesgo de T-032 |
| 26 | [Creación del evento en Calendar](../tickets/Finish/026-creacion-del-evento-en-calendar.md) | US-06 | FEA | **5** | M | T-022, T-023 | Captura del nombre, creación del evento y persistencia. Depende de la decisión de T-007 |
| 27 | [Sincronización inversa vía *watch*](../tickets/Next-iteraction/027-sincronizacion-inversa-watch.md) | US-06 | FEA | **8** | **B** | T-026 | Endpoint público, idempotencia y desorden. **+5 si las notificaciones resultan poco fiables y hace falta *polling*** |
| 28 | [Renovación de canales *watch*](../tickets/Next-iteraction/028-renovacion-de-canales-watch.md) | US-23 | FEA | **3** | A | T-027 | Tarea programada, dos llamadas y columnas nuevas |
| 29 | [Control de colisión de horario](../tickets/Finish/029-control-de-colision-de-horario.md) | US-18 | FEA | **5** | M | T-022, T-026 | Reserva temporal, re-consulta y relistado. El TTL correcto no se sabe hasta el piloto |
| 30 | [Consistencia del agendamiento](../tickets/Finish/030-consistencia-del-agendamiento.md) | US-22 | FEA | **5** | A | T-026 | Idempotencia hacia Google, reordenamiento y job de conciliación |
| 31 | [Suite transversal de timezones](../tickets/Finish/031-suite-transversal-de-timezones.md) | US-07 | CAL | **3** | A | T-026 | Chica en código, amplia en superficie de verificación. Su entregable es una suite |
| 32 | [Human takeover: detección](../tickets/Next-iteraction/032-human-takeover-deteccion.md) | US-08 | FEA | **8** | **B** | **T-004**, T-018 | **Provisorio.** Vale solo si T-004 confirma que Meta entrega el evento; si no, se cancela y se rediseña |
| 33 | [Fallback ante caídas](../tickets/Finish/033-fallback-ante-caidas.md) | US-09 | FEA | **5** | A | T-017, T-020, T-026 | Tres modos de falla enumerados, mismo patrón, más simuladores de prueba |
| 34 | [Estado de las integraciones](../tickets/Finish/034-estado-de-las-integraciones.md) | US-25 | FEA | **2** | A | T-013, T-033 | Solo lee un dato que ya se escribe. Una consulta y un componente de aviso |
| 35 | [Derivación a una persona](../tickets/Finish/035-derivar-a-una-persona.md) | US-20 | FEA | **3** | A | T-018, T-024, T-025 | Una marca, una lista y el reuso de la pausa. Cierra tres circuitos con una pieza |
| 36 | [**Migración:** registro de asistencia](../tickets/Finish/036-migracion-registro-de-asistencia.md) | US-17 | MIG | **1** | A | — | Una columna. El ticket más barato del plan y el de mayor apalancamiento |
| 37 | [Recordatorio interactivo t-24h](../tickets/Finish/037-recordatorio-t24h.md) | US-10 | FEA | **5** | M | **T-002**, T-009 | Scheduler, plantilla, botones y transiciones. Estimable en esfuerzo, no en calendario |
| 38 | [Registro de asistencia y tasa](../tickets/Finish/038-registro-de-asistencia.md) | US-17 | FEA | **3** | A | T-036, T-037 | Dos botones, una consulta agregada y un join con `notification_logs` |
| 39 | [Recordatorios fallidos](../tickets/Finish/039-recordatorios-fallidos.md) | US-24 | FEA | **3** | A | T-033, T-037 | Una vista y un reintento. El trabajo real es consumir los webhooks de estado |
| 40 | [Motor de cotización seguro](../tickets/Next-iteraction/040-motor-de-cotizacion-seguro.md) | US-12 | FEA | **5** | M | **T-006**, T-016 | El evaluador es chico; el parser seguro con lista blanca es lo que agrega tamaño |
| 41 | [Cotizador en la conversación](../tickets/Next-iteraction/041-cotizador-en-la-conversacion.md) | US-12 | FEA | **3** | A | T-018, T-040 | Recolección de parámetros y dos transiciones |
| 42 | [Re-agendar desde el recordatorio](../tickets/Finish/042-reagendar-desde-el-recordatorio.md) | US-11 | FEA | **5** | A | T-029, T-037 | Reingreso al flujo, orden de creación y borrado, y vigencia ante abandono |
| 43 | [Aviso t-2h *(condicional)*](../tickets/Next-iteraction/043-aviso-t2h-condicional.md) | US-11 | FEA | **3** | **B** | **T-007** | **No estimable hasta escribir el RF.** Los 3 puntos son un marcador de espacio |
| 44 | [Vincular la planilla de Sheets](../tickets/Finish/044-vincular-planilla-de-sheets.md) | US-26 | FEA | **3** | A | T-003, T-012 | Formulario, validación de escritura y creación opcional |
| 45 | [Volcado de leads a la planilla](../tickets/Finish/045-volcado-de-leads-a-sheets.md) | US-13 | FEA | **3** | **B** | **T-007**, T-044 | S una vez escrito el RF. Sin él, "volcar leads" puede significar cualquier cosa |
| 46 | [Autenticación del panel](../tickets/Finish/046-autenticacion-del-panel.md) | US-14 | ENA | **3** | A | — | Sanctum más Global Scope. **Adelantar al sprint 2: bloquea cinco tickets anteriores** |
| 47 | [Panel: listado de conversaciones](../tickets/Finish/047-panel-lista-de-conversaciones.md) | US-14 | FEA | **5** | A | T-041, T-046 | Listado paginado con joins resueltos y enlaces al calendario |
| 48 | [**Migración:** tabla `messages`](../tickets/Finish/048-migracion-tabla-messages.md) | US-14 | MIG | **2** | A | — | Tabla, índice y persistencia desde el worker. **Conviene adelantarla junto con T-018** |
| 49 | [Panel: detalle de conversación](../tickets/Finish/049-panel-detalle-de-conversacion.md) | US-14 | FEA | **5** | A | T-047, T-048 | Vista de detalle con historial paginado |

---

## Totales

### Por bloque de trabajo

| Bloque | Tickets | Puntos | Nota |
| :-- | :-: | :-: | :-- |
| 0 — Habilitación | 7 | **16** | Sin código. Arranca el día 1 en paralelo |
| 1 — Habilitar el canal | 6 | **21** | Carga sana |
| 2 — Fundación de datos y conversación | 7 | **27** | ⚠️ Sobrecargado |
| 3 — Primer contacto y disponibilidad | 5 | **17** | Carga sana |
| 4 — Reservar de verdad | 6 | **29** | ⚠️ Sobrecargado y con el mayor riesgo |
| 5 — Convivir con el equipo humano | 4 | **18** | Carga sana |
| 6 — Demostrar el ROI | 4 | **12** | Tiene lugar libre |
| 7 — Cotizar | 4 | **16** | Carga sana |
| 8 — Autonomía del cliente | 6 | **21** | Carga sana |
| **Total** | **49** | **177** | |

### Por tipo de trabajo

| Tipo | Tickets | Puntos | % |
| :-- | :-: | :-: | :-: |
| Feature | 30 | 122 | 69% |
| Enabler | 7 | 29 | 16% |
| Spike / Discovery | 3 | 8 | 5% |
| Migración | 4 | 7 | 4% |
| Trámite externo | 3 | 5 | 3% |
| Documentación | 1 | 3 | 2% |
| Calidad | 1 | 3 | 2% |

### Por tamaño

| Puntos | Tickets | Subtotal | Lectura |
| :-: | :-: | :-: | :-- |
| 1 | 2 | 2 | Migraciones de una línea con alto apalancamiento |
| 2 | 9 | 18 | El grueso de los enablers y migraciones |
| 3 | 21 | 63 | **La moda del plan.** Buena señal de rebanado |
| 5 | 14 | 70 | Features completas de una sola preocupación |
| 8 | 3 | 24 | T-018, T-027 y T-032. **Concentran el riesgo** |

**Ningún ticket supera los 8 puntos** — el techo autoimpuesto. Que 21 de 49 estén en 3 puntos indica que el rebanado hizo su trabajo: el backlog original tenía 8 historias **L** de 17.
