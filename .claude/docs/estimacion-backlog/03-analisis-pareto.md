# Análisis Pareto del backlog — MVP Release 1.0

Aplicación del principio 80/20 a los [49 tickets](../tickets/README.md), midiendo el valor con las [26 historias](../stories/) y sin re-estimar un solo punto: los story points salen de la [tabla maestra](01-tabla-maestra-de-tickets.md).

**Resultado: 31 tickets · 107 pts de núcleo, contra 177 pts del plan completo. 70 puntos afuera, 40% menos.**

---

## La promesa que se cobra

> **Un cliente escribe por WhatsApp, elige un horario que está realmente libre y el turno queda en el Google Calendar de la PyME — y a 24 horas recibe un recordatorio que lo hace venir.**

Es la lectura operativa de la UVP del [lean canvas](../01-producto/01-lean-canvas.md) ("Cotizá y agendá el 100% en menos de 15 segundos sin cambiar herramientas") cruzada con el propósito único que declara el [PRD](../01-producto/02-prd.md) §1: *"demostrar ROI en la primera llamada comercial eliminando el ausentismo y agendando citas en menos de 15 segundos"*.

De los tres KPIs de impacto del PRD §2, dos se sostienen sobre esa frase —respuesta < 15 s y asistencia ≥ 92%— y el tercero (conversión ≥ 35%) es su consecuencia. **Todo el análisis se mide contra ella.**

⚠️ La frase deja el **cotizador afuera de la promesa mínima** aunque la UVP lo nombre primero. Es la decisión más discutible de este documento y está justificada abajo, en [Lo diferido](#lo-diferido-y-lo-recortado).

---

## Cómo se midió

### Las dos escalas

| Escala | Definición | Fuente |
| :-- | :-- | :-- |
| **Impacto** | Qué parte de la promesa se cae si el ticket no está | Historia de origen y sus AC. No se infiere del título del ticket |
| **Esfuerzo** | Los story points ya estimados | [Tabla maestra](01-tabla-maestra-de-tickets.md). **No se recalcularon** |

### Las cuatro reglas que evitan un recorte ingenuo

1. **Regla de los enablers.** Un ticket `Enabler`, `Migración`, `Spike` o `Trámite` no tiene valor de usuario propio: **hereda el impacto máximo de lo que desbloquea** (campo `Bloquea a`). Sin esta regla, Pareto recorta los cimientos —T-009, T-014, T-036 son 5 puntos entre los tres y sostienen catorce tickets— y el resultado es basura.

2. **Regla del camino crítico.** Un ticket barato que desbloquea muchos puntos vale más que su estimación. Caso testigo ya documentado en `CLAUDE.md`: **16 pts del bloque 0 eliminan hasta 50 pts de incertidumbre.** Y la cadena que no se paraleliza —T-008 → T-010 → T-018 → T-022 → T-026, 26 pts— es irreducible: **es la razón por la que el núcleo son 107 puntos y no 40.**

3. **Piso de calidad (de la persona Pareto).** Seguridad, corrección del camino principal y pérdida de datos **no son diferibles**. Firma HMAC (T-010), idempotencia de ingesta (T-011), aislamiento por `tenant_id` (T-046) y consistencia del agendamiento (T-030) entran al núcleo aunque ninguno "entregue valor de usuario".

4. **Cierre de dependencias.** Ningún ticket del núcleo puede depender de un ticket que quedó afuera. Se verificaron las 31 cabeceras `Depende de`; los dos casos donde el cierre se rompía están resueltos y declarados en [Riesgos](#bloqueos-y-riesgos-del-recorte).

### Las tres categorías

| Categoría | Significado |
| :-: | :-- |
| **NÚCLEO** | Sin él la promesa no se cumple. 31 tickets · 107 pts |
| **DIFERIDO** | Valor real, pero el producto es demostrable y vendible sin él. 15 tickets · 56 pts |
| **RECORTADO** | El costo no se paga con el valor que entrega hoy. Se replantea, no se pospone. 3 tickets · 14 pts |

---

## Veredicto

| | Tickets | Puntos | % del plan |
| :-- | :-: | :-: | :-: |
| **NÚCLEO** | 31 | **107** | 60% |
| DIFERIDO | 15 | 56 | 32% |
| RECORTADO | 3 | 14 | 8% |
| **Total** | 49 | 177 | |

**Qué queda afuera, en una línea:** la sincronización inversa con Calendar, el human takeover automático, el cotizador completo, el volcado a Sheets, el re-agendamiento y tres cuartos del panel.

**Qué se conserva intacto:** el circuito completo `WhatsApp → estado → disponibilidad real → evento en Calendar → recordatorio t-24h → tasa de asistencia`, más los cuatro criterios del piso de calidad.

Tres lecturas que conviene no perder:

- **El 60% no es un fracaso del recorte, es la forma del producto.** Un middleware entre dos APIs de terceros tiene un piso de habilitación —canal, OAuth, máquina de estados, mensajes interactivos— que suma 26 pts en cadena y del que no se puede recortar nada sin dejar de tener producto. Un núcleo de 40 puntos sería una mentira.
- **El ahorro real está concentrado en pocos tickets.** T-027 + T-028 + T-032 + T-040/041 + T-047/049 son **46 de los 70 puntos** que salen. Seis decisiones producen dos tercios del recorte.
- **Los 107 son el techo, no el piso.** Los recortes internos del [paso micro](#recortes-dentro-de-cada-ticket-del-núcleo) comprimen varios tickets del núcleo por debajo de su estimación actual — T-018, T-015, T-024, T-029, T-030 y T-033 son los candidatos claros. **No se re-estimaron acá a propósito**: la suma conservadora es la que se puede comprometer.

---

## Clasificación de los 49 tickets

En el orden de prioridad vigente de [`tickets/README.md`](../tickets/README.md).

| # | Ticket | Historia | Pts | Categoría | Por qué |
| :-: | :-- | :-- | :-: | :-: | :-- |
| 1 | [Verificación de Meta Business](../tickets/Finish/001-verificacion-meta-business.md) | US-01 | 1 | **NÚCLEO** | Sin número habilitado no hay producto. Bloquea todo en cascada |
| 2 | [Aprobación de plantillas](../tickets/Finish/002-aprobacion-de-plantillas.md) | US-10 | 2 | **NÚCLEO** | Sin plantilla aprobada no hay recordatorio, que es la mitad de la promesa. El plazo es de terceros: arranca el día 1 |
| 3 | [Consentimiento de Google y OAuth](../tickets/Finish/003-consentimiento-google-oauth.md) | US-04 | 2 | **NÚCLEO** | Habilita T-012 y T-005. Trámite con plazo ajeno |
| 4 | [**Spike:** mensajes salientes de Meta](../tickets/Next-iteraction/004-spike-mensajes-salientes-meta.md) | US-08 | 3 | RECORTADO | Solo existe para decidir T-032, que sale. Sin T-032, el spike no tiene pregunta que responder |
| 5 | [**Spike:** latencia de `freeBusy`](../tickets/Finish/005-spike-latencia-freebusy.md) | US-05 | 2 | **NÚCLEO** | 2 pts que protegen el KPI de 15 s y evitan que T-022 pase de 5 a 8 por caché. Mejor relación del plan |
| 6 | [**Discovery:** catálogos de precios](../tickets/Next-iteraction/006-discovery-catalogos-de-precios.md) | US-12 | 3 | DIFERIDO | Cae con el cotizador |
| 7 | [Cerrar decisiones y escribir RF](../tickets/Finish/007-cerrar-decisiones-y-rf-faltantes.md) | 7 historias | 3 | **NÚCLEO** | Media jornada de escritura que destraba T-018 y baja la incertidumbre de todo el plan. Regla del camino crítico en estado puro |
| 8 | [Handshake de webhook](../tickets/Finish/008-handshake-webhook-meta.md) | US-01 | 3 | **NÚCLEO** | RF-A1 y CA-01. Sin esto Meta no entrega nada |
| 9 | [**Migración:** índices compuestos](../tickets/Finish/009-migracion-indices-compuestos.md) | US-02 | 2 | **NÚCLEO** | 2 pts ahora, caro con datos. Sostiene T-010, T-037 y T-038 |
| 10 | [Ingesta asíncrona con HMAC](../tickets/Finish/010-ingesta-asincrona-y-firma-hmac.md) | US-02 | 5 | **NÚCLEO** | RF-A2. La firma es piso de seguridad, no es negociable |
| 11 | [Idempotencia y resiliencia de cola](../tickets/Finish/011-idempotencia-y-resiliencia-de-cola.md) | US-02 | 3 | **NÚCLEO** | Meta reintenta webhooks. Sin deduplicar, un reintento produce un turno duplicado: pérdida de datos |
| 12 | [OAuth 2.0 con Google Calendar](../tickets/Finish/012-oauth-google-calendar.md) | US-04 | 5 | **NÚCLEO** | RF-B1. "Sin cambiar tus herramientas" empieza acá |
| 13 | [Renovación automática de tokens](../tickets/Finish/013-renovacion-automatica-de-tokens.md) | US-04 | 3 | **NÚCLEO** | El `access_token` vence en una hora. Sin renovación, el producto funciona un rato y se cae |
| 14 | [**Migración:** `business_settings`](../tickets/Finish/014-migracion-business-settings.md) | US-15 | 2 | **NÚCLEO** | 2 pts que desbloquean seis tickets y traen los textos por defecto. Hueco bloqueante de `CLAUDE.md` |
| 15 | [Configuración de agenda](../tickets/Finish/015-configuracion-de-agenda.md) | US-15 | 5 | **NÚCLEO** | T-022 no puede generar una grilla sin estos datos. Entra recortado |
| 16 | [Configuración de precios](../tickets/Next-iteraction/016-configuracion-de-precios.md) | US-15 | 3 | DIFERIDO | Cae con el cotizador |
| 17 | [Configuración de mensajes](../tickets/Finish/017-configuracion-de-mensajes.md) | US-15 | 2 | **NÚCLEO** | 2 pts que cierran la dependencia de T-021, T-024 y T-033. Más barato que sustituirlo tres veces |
| 18 | [Máquina de estados: núcleo](../tickets/Finish/018-maquina-de-estados-nucleo.md) | US-03 | 8 | **NÚCLEO** | RF-A3. Es el producto. Entra recortado en cantidad de estados |
| 19 | [Mensajes interactivos de Meta](../tickets/Finish/019-mensajes-interactivos-de-meta.md) | US-03 | 5 | **NÚCLEO** | RF-A5. "Elegir horario en 15 segundos" es una List Message; escribir texto libre no cumple el KPI |
| 20 | [Logging estructurado](../tickets/Finish/020-logging-estructurado.md) | US-09 | 2 | **NÚCLEO** | RNF-03 exige registrar. 2 pts que hacen diagnosticable el piloto: sin esto, un fallo en un cliente real es una anécdota |
| 21 | [Bienvenida y menú inicial](../tickets/Finish/021-bienvenida-y-menu-inicial.md) | US-16 | 3 | **NÚCLEO** | Es el primer contacto y el punto donde se mide el KPI de 15 s (AC-16.1) |
| 22 | [Consulta de disponibilidad](../tickets/Finish/022-consulta-de-disponibilidad.md) | US-05 | 5 | **NÚCLEO** | RF-B2. El corazón técnico de la promesa |
| 23 | [Lista interactiva de horarios](../tickets/Finish/023-lista-interactiva-de-horarios.md) | US-05 | 3 | **NÚCLEO** | Sin renderizado, la disponibilidad no llega al cliente |
| 24 | [Respuesta sin disponibilidad](../tickets/Finish/024-respuesta-sin-disponibilidad.md) | US-19 | 3 | **NÚCLEO** | Una agenda llena es rutina, no excepción. Un bot mudo ahí rompe la confianza en el primer contacto. Entra recortado |
| 25 | [Pausar el bot desde el panel](../tickets/Finish/025-pausar-el-bot-desde-el-panel.md) | US-21 | 3 | **NÚCLEO** | **3 pts que sustituyen los 11 de T-004 + T-032.** El mejor cambio de la relación valor/esfuerzo del plan |
| 26 | [Creación del evento en Calendar](../tickets/Finish/026-creacion-del-evento-en-calendar.md) | US-06 | 5 | **NÚCLEO** | RF-B3 y CA-02. Es el momento exacto en que el producto entrega lo que se cobra |
| 27 | [Sincronización inversa vía *watch*](../tickets/Next-iteraction/027-sincronizacion-inversa-watch.md) | US-06 | 8 | DIFERIDO | **La disponibilidad ya se consulta en vivo con `freeBusy` en cada conversación**: los cambios manuales del calendario se ven igual. El *watch* solo mantiene alineada nuestra tabla `bookings`. 8 pts y la única confianza `B` del sprint 4 |
| 28 | [Renovación de canales *watch*](../tickets/Next-iteraction/028-renovacion-de-canales-watch.md) | US-23 | 3 | DIFERIDO | No hay canales que renovar |
| 29 | [Control de colisión de horario](../tickets/Finish/029-control-de-colision-de-horario.md) | US-18 | 5 | **NÚCLEO** | Doble reserva = trabajo manual para la PyME, que es justo lo que vinimos a eliminar. Entra recortado |
| 30 | [Consistencia del agendamiento](../tickets/Finish/030-consistencia-del-agendamiento.md) | US-22 | 5 | **NÚCLEO** | Piso de calidad: evento huérfano o confirmación de un turno que no existe. Entra recortado |
| 31 | [Suite transversal de timezones](../tickets/Finish/031-suite-transversal-de-timezones.md) | US-07 | 3 | **NÚCLEO** | RNF-02 y CA-04. Un turno con una hora de corrimiento en un piloto de LatAm liquida la venta. 3 pts |
| 32 | [Human takeover: detección](../tickets/Next-iteraction/032-human-takeover-deteccion.md) | US-08 | 8 | RECORTADO | 8 pts, confianza `B`, INVEST 2/6 y viabilidad sujeta a un spike. **T-025 cubre el mismo riesgo por 3.** Se replantea cuando el piloto muestre cuánto interviene el equipo a mano |
| 33 | [Fallback ante caídas](../tickets/Finish/033-fallback-ante-caidas.md) | US-09 | 5 | **NÚCLEO** | RNF-03 y CA-06. Un chat colgado es el peor fallo visible del producto. Entra recortado |
| 34 | [Estado de las integraciones](../tickets/Finish/034-estado-de-las-integraciones.md) | US-25 | 2 | DIFERIDO | T-013 ya marca `status='expired'`. Mostrarlo en el panel es conveniencia, no promesa |
| 35 | [Derivación a una persona](../tickets/Finish/035-derivar-a-una-persona.md) | US-20 | 3 | DIFERIDO | La válvula de escape existe entre T-025 y T-033. Esta es la versión pulida |
| 36 | [**Migración:** registro de asistencia](../tickets/Finish/036-migracion-registro-de-asistencia.md) | US-17 | 1 | **NÚCLEO** | 1 punto. Habilita el KPI de ausentismo, que es el argumento de venta entero |
| 37 | [Recordatorio interactivo t-24h](../tickets/Finish/037-recordatorio-t24h.md) | US-10 | 5 | **NÚCLEO** | RF-D1 y CA-05. La otra mitad de la promesa: asistencia ≥ 92% |
| 38 | [Registro de asistencia y tasa](../tickets/Finish/038-registro-de-asistencia.md) | US-17 | 3 | **NÚCLEO** | **Sin este número no hay "demostrar ROI en la primera llamada".** El recordatorio sin la métrica es una funcionalidad sin argumento |
| 39 | [Recordatorios fallidos](../tickets/Finish/039-recordatorios-fallidos.md) | US-24 | 3 | DIFERIDO | Necesita volumen para justificarse. Con un piloto, el fallo se ve en el log de T-020 |
| 40 | [Motor de cotización seguro](../tickets/Next-iteraction/040-motor-de-cotizacion-seguro.md) | US-12 | 5 | DIFERIDO | Ver [nota sobre el cotizador](#el-recorte-más-discutible-el-cotizador) |
| 41 | [Cotizador en la conversación](../tickets/Next-iteraction/041-cotizador-en-la-conversacion.md) | US-12 | 3 | DIFERIDO | Ídem |
| 42 | [Re-agendar desde el recordatorio](../tickets/Finish/042-reagendar-desde-el-recordatorio.md) | US-11 | 5 | DIFERIDO | Confirmar y Cancelar ya bajan el ausentismo. Re-agendar reingresa al flujo entero por 5 pts |
| 43 | [Aviso t-2h *(condicional)*](../tickets/Next-iteraction/043-aviso-t2h-condicional.md) | US-11 | 3 | RECORTADO | Sin RF, confianza `B`, INVEST 2/6. El propio README recomienda **probarlo a mano una semana**: si no mueve la asistencia, se cae sin costo |
| 44 | [Vincular la planilla de Sheets](../tickets/Finish/044-vincular-planilla-de-sheets.md) | US-26 | 3 | DIFERIDO | Cae con el volcado |
| 45 | [Volcado de leads a la planilla](../tickets/Finish/045-volcado-de-leads-a-sheets.md) | US-13 | 3 | DIFERIDO | ⚠️ Contradice CA-02 tal como está escrito hoy. Ver [Riesgos](#bloqueos-y-riesgos-del-recorte) |
| 46 | [Autenticación del panel](../tickets/Finish/046-autenticacion-del-panel.md) | US-14 | 3 | **NÚCLEO** | RNF-01. Un panel sin Global Scope filtra datos entre PyMEs. Piso de seguridad. **Se adelanta**, como ya recomendaba el README |
| 47 | [Panel: listado de conversaciones](../tickets/Finish/047-panel-lista-de-conversaciones.md) | US-14 | 5 | DIFERIDO | El dueño ve sus turnos en su propio Google Calendar — que es exactamente la promesa de "sin cambiar herramientas" |
| 48 | [**Migración:** tabla `messages`](../tickets/Finish/048-migracion-tabla-messages.md) | US-14 | 2 | DIFERIDO | Nadie lee esos datos sin T-049. Para diagnosticar está T-020 |
| 49 | [Panel: detalle de conversación](../tickets/Finish/049-panel-detalle-de-conversacion.md) | US-14 | 5 | DIFERIDO | Cae con el listado |

---

## El núcleo, en orden de ejecución

Secuencia derivada de los campos `Depende de`. **Las olas no son sprints**: son cortes de dependencia. Dentro de cada una, todo se puede paralelizar.

### Ola 0 — Trámites y decisiones · 10 pts · *sin código, día 1*

| Ticket | Pts | Habilita |
| :-- | :-: | :-- |
| T-001 Verificación de Meta Business | 1 | T-002, T-008 |
| T-003 Consentimiento de Google | 2 | T-005, T-012 |
| T-007 Cerrar decisiones y escribir RF | 3 | T-018 |
| T-002 Aprobación de plantillas *(← T-001)* | 2 | T-037 |
| T-005 Spike de latencia de `freeBusy` *(← T-003)* | 2 | T-022 |

**Acumulado: 10 pts.** Ninguno produce código y los tres primeros arrancan el mismo día.

### Ola 1 — Canal, calendario y cimientos · 28 pts

| Ticket | Pts | Nota |
| :-- | :-: | :-- |
| T-008 Handshake de webhook | 3 | ← T-001 |
| T-009 Migración de índices compuestos | 2 | sin dependencias |
| T-014 Migración `business_settings` | 2 | sin dependencias |
| T-020 Logging estructurado | 2 | sin dependencias |
| T-046 Autenticación del panel | 3 | sin dependencias — **adelantado** desde la posición 46 |
| T-010 Ingesta asíncrona con HMAC | 5 | ← T-008, T-009 |
| T-011 Idempotencia de la cola | 3 | ← T-010 |
| T-012 OAuth con Google Calendar | 5 | ← T-003 |
| T-013 Renovación de tokens | 3 | ← T-012 |

**Acumulado: 38 pts.** Cuatro tickets sin dependencias sostienen la paralelización de esta ola.

### Ola 2 — Configuración y conversación · 20 pts

| Ticket | Pts | Nota |
| :-- | :-: | :-- |
| T-015 Configuración de agenda | 5 | ← T-012, T-014 |
| T-017 Configuración de mensajes | 2 | ← T-014 |
| T-018 Máquina de estados: núcleo | 8 | ← T-007, T-010 |
| T-019 Mensajes interactivos | 5 | ← T-018 |

**Acumulado: 58 pts.** ⚠️ T-018 → T-019 son 13 pts en serie: el cuello de botella del plan.

### Ola 3 — Primer contacto y disponibilidad · 17 pts

| Ticket | Pts | Nota |
| :-- | :-: | :-- |
| T-021 Bienvenida y menú inicial | 3 | ← T-017, T-018, T-019 |
| T-022 Consulta de disponibilidad | 5 | ← T-005, T-012, T-015 |
| T-023 Lista interactiva de horarios | 3 | ← T-019, T-022 |
| T-024 Respuesta sin disponibilidad | 3 | ← T-015, T-017, T-022 |
| T-025 Pausar el bot desde el panel | 3 | ← T-012, T-018, **T-046** |

**Acumulado: 75 pts.** Acá ya hay demo: el bot saluda, entiende y muestra horarios reales.

### Ola 4 — Reservar de verdad · 23 pts

| Ticket | Pts | Nota |
| :-- | :-: | :-- |
| T-026 Creación del evento en Calendar | 5 | ← T-022, T-023 |
| T-029 Control de colisión | 5 | ← T-022, T-026 |
| T-030 Consistencia del agendamiento | 5 | ← T-026 |
| T-031 Suite de timezones | 3 | ← T-026 |
| T-033 Fallback ante caídas | 5 | ← T-017, T-020, T-026 |

**Acumulado: 98 pts.** Acá se cumple **CA-02**, la mitad de la promesa.

### Ola 5 — Ausentismo y prueba de ROI · 9 pts

| Ticket | Pts | Nota |
| :-- | :-: | :-- |
| T-036 Migración de registro de asistencia | 1 | sin dependencias — adelantable a la ola 1 |
| T-037 Recordatorio interactivo t-24h | 5 | ← T-002, T-009, T-019, T-026 |
| T-038 Registro de asistencia y tasa | 3 | ← T-009, T-036, T-037 |

**Total del núcleo: 107 pts.** Acá se cumplen **CA-04, CA-05 y CA-06**, y existe el número que se lleva a la reunión de venta.

---

## Recortes dentro de cada ticket del núcleo

Solo los tickets donde el recorte interno cambia el esfuerzo. Los quince restantes entran completos: son enablers, migraciones o trámites sin grasa que sacar.

### T-010 · Ingesta asíncrona con firma HMAC
* **Se construye:** endpoint que responde `200` antes de procesar, validación de `X-Hub-Signature-256` sobre cuerpo crudo con comparación en tiempo constante, encolado en Redis.
* **Se difiere:** el arnés de carga sintética con 50 webhooks concurrentes — *(AC-02.1)*.
* **Por qué puede esperar:** un piloto de una PyME no genera 50 webhooks concurrentes; el p95 se verifica con el instrumento de T-021 sobre tráfico real. **La firma no se toca** (piso de seguridad).

### T-015 · Configuración de agenda
* **Se construye:** persistencia de horario, días laborables, duración, buffer y zona IANA, más las validaciones de coherencia del lado del servidor *(AC-15.6)*, y una pantalla única sin refinamiento.
* **Se difiere:** horarios distintos por día de la semana — un solo rango para todos los días laborables.
* **Por qué puede esperar:** *(AC-15.1)* y *(AC-15.2)* se cumplen con un rango único; el horario partido por día es un caso de negocio (turno mañana/tarde) que aparece después del primer cliente. ⚠️ Si en el MVP la carga la hacemos nosotros por seeder, **el KPI de "setup < 60 min" lo cumplimos nosotros y no el cliente** — hay que decirlo en voz alta antes de reportarlo.

### T-017 · Configuración de mensajes
* **Se construye:** los tres textos editables (bienvenida, fallback, sin disponibilidad) aplicados sin reiniciar el worker *(AC-15.5)*, con caída al valor por defecto de T-014 si quedan vacíos.
* **Se difiere:** el sistema de variables del negocio con validación de variable inexistente.
* **Por qué puede esperar:** el nombre del negocio se interpola desde `tenants`; un motor de variables genérico es flexibilidad especulativa con un solo caller.

### T-018 · Máquina de estados: núcleo
* **Se construye:** persistencia dual `conversations` + caché Redis, lock por `(tenant_id, user_phone)`, rechazo de transiciones no declaradas, y **los estados del camino de reserva**: entrada, selección de horario, confirmación, reservado, pausado y fallback.
* **Se difiere:** los estados de cotización y de derivación a persona del catálogo de once — *(no tienen consumidor: T-041 y T-035 están fuera del núcleo)*.
* **Por qué puede esperar:** construir un estado cuyo único consumidor está diferido es exactamente "por las dudas". ⚠️ **Este es el recorte con más upside del análisis:** los 8 puntos están estimados sobre once estados y el núcleo necesita menos. No se re-estimó acá, pero es el primer candidato a bajar.

### T-019 · Mensajes interactivos de Meta
* **Se construye:** Quick Reply y List Messages, sellado del estado emisor en el `id` del botón *(AC-03.2)*, paginado dentro de los límites de la API.
* **Se difiere:** el truncado legible de textos que superan el máximo.
* **Por qué puede esperar:** los textos son nuestros y de T-017; se escriben cortos. El truncado importa cuando el contenido lo genera el cliente.

### T-021 · Bienvenida y menú inicial
* **Se construye:** transición de entrada, saludo con nombre del negocio y opciones *(AC-16.1)*, avance por botón *(AC-16.2)*, e **instrumentación del tiempo webhook → envío** — es el único lugar donde se mide el KPI de 15 s.
* **Se difiere:** la variante del mensaje fuera del horario de atención *(AC-16.4)*.
* **Por qué puede esperar:** el AC exige que fuera de horario **igual se pueda reservar**, así que el flujo funciona sin la variante; el texto diferenciado es cortesía.

### T-023 · Lista interactiva de horarios
* **Se construye:** renderizado como List Message agrupado por día, con la primera página dentro del límite de Meta y opción de ver más *(AC-05.4)*.
* **Se difiere:** el paginado completo con conservación de contexto entre páginas arbitrarias — alcanza con una segunda página.
* **Por qué puede esperar:** con turnos de 45 min y una ventana de pocos días, la primera página cubre la mayoría de los casos reales.

### T-024 · Respuesta sin disponibilidad
* **Se construye:** **la distinción de los tres vacíos** *(AC-19.4)* —agenda llena, configuración ausente, error de API— y el mensaje de agenda llena. El AC-19.4 no se recorta: comunicar un bug nuestro como "no hay lugar" es mentirle al cliente de la PyME.
* **Se difiere:** la ampliación de la ventana de búsqueda *(AC-19.2)* y el mensaje diferenciado de día no laborable *(AC-19.3)*.
* **Por qué puede esperar:** son dos ramas de conveniencia; el mensaje base ya ofrece hablar con una persona *(AC-19.1)*.

### T-025 · Pausar el bot desde el panel
* **Se construye:** pausar y reactivar por número de teléfono con la llave `pause:` en Redis, reactivación anticipada que retoma desde el estado guardado *(AC-21.2)*, y vencimiento automático *(AC-21.3)*.
* **Se difiere:** los botones **sobre la conversación en el panel** y el indicador de quién pausó y cuándo *(AC-21.4)*.
* **Por qué puede esperar:** ⚠️ el alcance original asume el listado de conversaciones de T-047, que está diferido. En el núcleo la pausa vive en una pantalla mínima con un campo de teléfono y dos botones. Feo, funcional, y sostiene el reemplazo de los 11 puntos de human takeover automático.

### T-029 · Control de colisión de horario
* **Se construye:** re-consulta de `freeBusy` inmediatamente antes de crear el evento, manejo del conflicto de Google como camino esperado, relistado sin volver a pedir datos *(AC-18.1)*, y protección contra doble toque.
* **Se difiere:** la reserva temporal con `SET NX` y TTL, y por lo tanto *(AC-18.3)*. También *(AC-18.4)*, que aplica al re-agendamiento — diferido con T-042.
* **Por qué puede esperar:** ⚠️ la reserva temporal es justo la decisión abierta *"cuándo se toma la reserva temporal"* de `CLAUDE.md`, y su TTL correcto **no se conoce hasta el piloto**. La re-consulta previa cubre *(AC-18.2)* en la ventana que importa; diferirla evita construir sobre una decisión no tomada.

### T-030 · Consistencia del agendamiento
* **Se construye:** clave de idempotencia determinística hacia Google *(AC-22.3)*, confirmación al cliente solo después de persistir *(AC-22.2)*, y el registro del evento huérfano para conciliar *(AC-22.1)*.
* **Se difiere:** el job de conciliación que recorre y reporta desalineados, y el aislamiento del volcado a Sheets *(AC-22.4)* — Sheets está diferido.
* **Por qué puede esperar:** ⚠️ elegir entre borrar el evento y conciliarlo después es la decisión abierta *"compensar o conciliar"*. Se registra el desalineado ahora —eso es barato y no compromete la decisión— y el job se escribe cuando la decisión esté tomada.

### T-033 · Fallback ante caídas
* **Se construye:** manejador que convierte fallos de terceros en resultados tipados, mensaje de cortesía por tenant, y la garantía dura de *(AC-09.4)*: **ningún camino del worker termina en silencio**.
* **Se difiere:** los simuladores de falla del entorno de pruebas y el backoff con jitter fino ante rate limit *(AC-09.3)* — alcanza con el reintento estándar de la cola de T-011.
* **Por qué puede esperar:** los tres modos de falla comparten un solo patrón; diferenciarlos con simuladores propios es infraestructura de test que se justifica con más superficie.

### T-037 · Recordatorio interactivo t-24h
* **Se construye:** scheduler cada 10 min sobre la ventana t-24h ±10, plantilla de tres botones, candado en `notification_logs` contra duplicados *(AC-10.4)*, Confirmar *(AC-10.2)* y Cancelar *(AC-10.3)*, con la hora en zona del tenant *(AC-07.4)*.
* **Se agrega (sustitución):** antes de enviar, **verificar que el evento sigue existiendo en Calendar**. Es una llamada `GET` y cubre el hueco que deja diferir T-027.
* **Se difiere:** el efecto del botón Re-agendar, que queda deshabilitado o ausente de la plantilla hasta T-042.
* **Por qué puede esperar:** ⚠️ la plantilla se aprueba en T-002 y **Meta cobra caro cambiarla**: conviene definir si el botón viaja desde el día 1 aunque su efecto llegue después. Decidir antes de enviar la plantilla a aprobación.

### T-038 · Registro de asistencia y tasa
* **Se construye:** marcado en un clic *(AC-17.1)*, agrupación de pasados sin marcar *(AC-17.2)* y la consulta agregada de tasa por rango *(AC-17.3)* — que es el número que se lleva a la venta.
* **Se difiere:** la correlación con la respuesta al recordatorio *(AC-17.4)*.
* **Por qué puede esperar:** *(AC-17.4)* cruza asistencia con `notification_logs` para analizar *por qué* alguien no vino. El argumento comercial solo necesita la tasa; el análisis de causas llega con volumen. **La columna separada de T-036 se construye igual**, así que el dato no se pierde y el AC se cumple después sin migración.

---

## Lo diferido y lo recortado

### DIFERIDO — 15 tickets · 56 pts

| Ticket | Pts | Qué se pierde | Cuándo retomarlo |
| :-- | :-: | :-- | :-- |
| T-027 Sync inversa *watch* | 8 | La tabla `bookings` no refleja cancelaciones hechas a mano en Calendar | Cuando el piloto muestre que la PyME edita el calendario a mano seguido. **Mitigado** por la verificación previa al recordatorio de T-037 |
| T-028 Renovación de canales | 3 | — | Con T-027 |
| T-042 Re-agendar desde recordatorio | 5 | El cliente cancela y vuelve a empezar en vez de mover el turno | Segunda release. Medir cuántos tocan Cancelar y vuelven a reservar |
| T-040 Motor de cotización | 5 | El cotizador entero | Ver nota abajo |
| T-041 Cotizador conversacional | 3 | Ídem | Ídem |
| T-016 Configuración de precios | 3 | Ídem | Ídem |
| T-006 Discovery de catálogos | 3 | Ídem | Ídem — **pero es 1 día sin código: conviene hacerlo igual mientras corre la ola 1** |
| T-047 Panel: listado | 5 | Ver las conversaciones desde el panel | Cuando haya más de un operador por PyME |
| T-049 Panel: detalle | 5 | Historial de un chat | Con T-047 |
| T-048 Migración `messages` | 2 | Historial persistido | Con T-049. ⚠️ El README argumenta adelantarla porque el historial se acumula con el tiempo: es 2 pts y el argumento es válido |
| T-045 Volcado a Sheets | 3 | El lead en la planilla | Cuando esté escrito su RF (T-007). ⚠️ Ver riesgos |
| T-044 Vincular planilla | 3 | — | Con T-045 |
| T-035 Derivar a una persona | 3 | El circuito pulido de derivación | Tercera release. T-025 + T-033 dejan salida |
| T-039 Recordatorios fallidos | 3 | Detección y reintento de recordatorios no entregados | Con volumen. En piloto se ve en el log de T-020 |
| T-034 Estado de integraciones | 2 | El semáforo en el panel | Cuando exista más panel. El estado ya se persiste en T-013 |

### RECORTADO — 3 tickets · 14 pts

| Ticket | Pts | Por qué se recorta, no se pospone |
| :-- | :-: | :-- |
| T-032 Human takeover automático | 8 | 8 pts, confianza `B`, INVEST 2/6 y **viabilidad desconocida**: si T-004 sale negativo, se rediseña de cero. T-025 entrega el mismo resultado operativo —el bot deja de responder cuando el humano entra— por 3 pts y con certeza. Se replantea con datos del piloto sobre cuánto interviene el equipo a mano |
| T-004 Spike de mensajes salientes | 3 | Existe únicamente para decidir T-032. Sin T-032 no hay pregunta que responder |
| T-043 Aviso t-2h | 3 | Sin RF escrito, confianza `B`, INVEST 2/6, y el propio backlog recomienda **probarlo a mano una semana antes de escribir código**. Si no mueve la asistencia, se cae sin haber costado nada |

### El recorte más discutible: el cotizador

**14 pts entre T-006, T-016, T-040 y T-041.** La UVP dice *"Cotizá y agendá"* y el cotizador aparece primero. Diferirlo es la decisión que más conviene revisar antes de aceptar este plan.

**A favor de diferirlo:**
- Los dos KPIs de impacto del PRD —respuesta < 15 s y asistencia ≥ 92%— **no lo tocan**. El tercero (conversión ≥ 35%) se mide sobre reservas completadas.
- El agendamiento aplica a los cuatro segmentos del lean canvas; la cotización paramétrica solo a algunos (inmobiliarias, servicios, mantenimiento). Salud y estética cotizan por catálogo fijo.
- Depende de un discovery de negocio (T-006) y arrastra un riesgo de multi-tenancy propio: la evaluación segura de fórmulas, que **ningún requisito menciona** y es lo que hace que T-040 valga 5 y no 3.

**En contra:**
- ⚠️ Es la mitad literal de la propuesta de valor con la que se sale a vender. Si el pitch dice "cotizá y agendá", el MVP debería poder hacer las dos.

**Recomendación:** hacer **T-006 igual** durante la ola 1 —es un día, sin código, y su output es conocimiento de negocio que no caduca— y decidir con esa información si el cotizador entra en la release 1.0 o en la 1.1. El discovery cuesta 3 puntos y convierte una apuesta en una decisión informada.

---

## Bloqueos y riesgos del recorte

### Decisiones abiertas que siguen bloqueando el núcleo

Ninguna se resuelve en este documento. Todas viven en T-007.

| Decisión abierta | Tickets del núcleo bloqueados | Efecto |
| :-- | :-- | :-- |
| ⚠️ **Dónde se captura el nombre** (`SLOT_SELECTED` vs. `GATHERING_PARAMS`) | T-018, T-026 | T-026 no puede escribir el evento sin saber en qué estado se pidió el nombre |
| ⚠️ **TTL de inactividad** (30 min / 2 h / otra cosa) | T-018 | *(AC-03.1)* no es verificable hasta fijarlo |
| ⚠️ **Compensar o conciliar** | T-030 | Se difirió el job de conciliación justamente por esto |
| ⚠️ **Cuándo se toma la reserva temporal** | T-029 | Se difirió la reserva temporal por esto |
| ⚠️ **Outlook en el MVP** | T-012 | Si entra, T-012 pasa de 5 a 8 y el núcleo sube a 110 |

**Las dos primeras hacen que T-018 —8 puntos, el ticket más grande del núcleo— no sea comprometible hasta que T-007 esté hecho.** Es la razón por la que T-007 está en la ola 0.

### Riesgos que introduce este recorte

**1. ⚠️ Diferir Sheets contradice CA-02 tal como está escrito.** CA-02 exige que el turno *"aparezca registrado en Google Calendar **y Google Sheets**"*. Con T-045 diferido, el núcleo **no cumple CA-02 literalmente**. Hay dos salidas y hay que elegir una explícitamente en T-007: reescribir CA-02 acotándolo a Calendar, o subir T-044 + T-045 (6 pts) al núcleo. **No se puede dejar así.**

**2. ⚠️ Diferir T-027 deja un hueco acotado.** Si la PyME borra un evento a mano en Calendar, nuestra tabla `bookings` no se entera. El impacto real es un recordatorio enviado a alguien cuyo turno ya no existe — mitigado por la verificación previa que se agregó a T-037. **La disponibilidad no se ve afectada** porque `freeBusy` se consulta en vivo. Si el piloto muestra edición manual frecuente, T-027 vuelve al núcleo y se llevan 8 pts con confianza `B`.

**3. ⚠️ Recortar T-032 apuesta a que la pausa manual alcanza.** El PRD promete *pausa automática* en RF-A4 y CA-03. Con T-025, la pausa existe pero la dispara una persona. **CA-03, como está redactado, no se cumple.** Si el piloto muestra que el equipo interviene seguido y se olvida de pausar, el riesgo se materializa y hay que reabrir T-004 primero.

**4. El cuello de botella no lo arregla este análisis.** El backlog puntúa **2/26 en *Independiente*** y la cadena T-008 → T-010 → T-018 → T-022 → T-026 sigue intacta dentro del núcleo: 26 de los 107 puntos van en serie. **Recortar no paraleliza.** La única palanca sigue siendo la decisión pendiente de rebanadas verticales —agendar punta a punta con disponibilidad falseada y reemplazar el mock después— y este documento no la toma.

**5. La sustitución de T-047 por pantallas mínimas es deuda declarada.** T-025 y T-038 necesitan superficie de panel que hoy vive en tickets diferidos. En el núcleo son dos pantallas feas y funcionales. Cuando T-047 entre, esas pantallas se absorben. **Está contado como deuda, no como ahorro.**

### Si el recorte resulta equivocado

El orden de reincorporación, por relación valor/esfuerzo:

1. **T-044 + T-045** (6 pts) — si CA-02 se sostiene como está escrito.
2. **T-006** (3 pts) — hacerlo igual: es un día sin código.
3. **T-040 + T-041 + T-016** (11 pts) — si el discovery confirma que la cotización cierra ventas.
4. **T-027 + T-028** (11 pts) — si el piloto muestra edición manual frecuente del calendario.
5. **T-042** (5 pts) — si muchos clientes cancelan y vuelven a reservar.
6. **T-047 + T-048 + T-049** (12 pts) — cuando haya más de un operador por PyME.

---

## Trazabilidad

Este documento **no modifica las 26 historias**: las referencia por ID.

**Sí está aplicado sobre los 49 tickets**, de forma puramente aditiva —no se borró una sola línea—: cada ticket lleva la fila **Pareto** en su cabecera, los 18 tickets fuera del núcleo llevan una nota de estado con su motivo y cuándo retomarlos, y los 14 recortados llevan un bloque **Recorte Pareto** al final de su sección *Fuera de alcance*, con el AC que justifica cada corte. Los story points y el orden de prioridad quedaron intactos.

Deriva de:

- [`tickets/README.md`](../tickets/README.md) — orden de prioridad, tipos y dependencias
- [`01-tabla-maestra-de-tickets.md`](01-tabla-maestra-de-tickets.md) — puntos y confianza
- [`stories/`](../stories/) — criterios de aceptación citados por ID
- [`01-producto/01-lean-canvas.md`](../01-producto/01-lean-canvas.md) y [`02-prd.md`](../01-producto/02-prd.md) — la promesa, los KPIs y los CA
- `CLAUDE.md` — decisiones abiertas, huecos bloqueantes y advertencias

Las 26 historias siguen cubiertas: **US-08, US-11, US-12, US-13, US-14, US-20, US-23, US-24, US-25 y US-26 quedan fuera del núcleo** y se retoman según el orden de arriba.
