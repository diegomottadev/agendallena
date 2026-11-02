# Huecos, contradicciones y trazabilidad

**Qué es este documento:** el resultado accionable del cruce. Los tres mapas muestran dónde cae cada historia; acá está **lo que ninguna historia cubre**, **lo que dos documentos afirman de forma incompatible** y **la matriz que conecta cada historia con su requisito, su caso de uso, su estado y su tabla**.

**Regla que se siguió:** ningún hueco se rellenó con una historia inventada. Cada uno lleva una *historia candidata* en formato de post-it —título, una línea de "como/quiero/para", estimación tentativa y slice— para que escribirla sea un paso posterior y deliberado.

---

## 1 · Los 21 huecos

`🔴` = bloqueante: falta soporte de datos o de arquitectura, no solo la historia.

| ID | Hueco | Dónde | Fuente que lo revela | Slice |
| :-- | :-- | :-- | :-- | :-- |
| [H-01](#h-01) 🔴 | Colisión de horario al confirmar | A5.2 | `04-flujo-whatsapp` (nodo `CheckCollision`) | MVP |
| [H-02](#h-02) | Dónde se captura el nombre del cliente | A5.1 | `05-estados` + `06-secuencia` vs US-03/US-13 | MVP |
| [H-03](#h-03) | Qué se responde con la agenda llena | A4.4 | `04-flujo-whatsapp` (rama `HasSlots -- No`) | MVP |
| [H-04](#h-04) | Agendamiento sin compensación | A5.6 · C4.6 | `06-secuencia` (bloque `critical`) | MVP |
| [H-05](#h-05) | Derivación a un asesor que nadie recibe | A3.3 · A9.5 · B4.7 | `04-flujo-whatsapp` + US-12 AC-12.4 | MVP |
| [H-06](#h-06) | Pausar o reactivar el bot a mano | B4.4 · C2.6 | `03-casos-de-uso-equipo-pyme` (UC-2.10) | MVP |
| [H-07](#h-07) | Cuándo expira la sesión | A2.2 · C2.4 | `05-estados` vs US-03 AC-03.1 | MVP |
| [H-08](#h-08) | Recordatorio que no salió | A6.5 · B6.3 · C5.4 | `notification_logs.status='failed'` | MVP |
| [H-09](#h-09) 🔴 | Configuración del negocio sin tabla | B3.1/2/5 · B6.5 | `04-modelo-de-datos` vs US-15 | MVP |
| [H-10](#h-10) 🔴 | Historial de mensajes sin tabla | B5.3 | `04-modelo-de-datos` vs US-14 AC-14.4 | MVP |
| [H-11](#h-11) 🔴 | Asistencia sin valor en el enum | A8.2 · B5.6 | `bookings.status` vs US-17 | MVP |
| [H-12](#h-12) | Alta de credenciales de Meta por tenant | B1.2 | UC-2.4 vs US-01 | v2 |
| [H-13](#h-13) | Roles y permisos | B1.4 · C6.5 | `users.role` sin historia | v2 |
| [H-14](#h-14) | `users.email` único global | B1.1 · C6.4 | `04-modelo-de-datos` | v2 |
| [H-15](#h-15) 🔴 | Índices compuestos ausentes | C5.5 | `04-modelo-de-datos` (texto vs DDL) | MVP |
| [H-16](#h-16) | Pregunta libre sin salida | A2.5 | `04-flujo-whatsapp` (rama `Pregunta libre`) | MVP |
| [H-17](#h-17) | Renovación de canales *watch* | C1.6 | US-06 "de qué depende", sin AC | MVP |
| [H-18](#h-18) | Evaluación segura de fórmulas del tenant | C3.3 | `quote_rules.formula_expression` | MVP |
| [H-19](#h-19) | Destino del logging estructurado | C7.5 | US-01/US-09/US-14 lo asumen | MVP |
| [H-20](#h-20) | Pantalla para vincular la planilla | B2.4 | UC-2.5 vs US-13 | MVP |
| [H-21](#h-21) | Vista del estado de las integraciones | B6.2 | US-09 AC-09.2 escribe, nadie lee | MVP |

Se mantienen abiertos los tres huecos diferidos del mapa anterior: alta de tenant (B1.1), flujo de reconexión (B6.4) y corregir un dato ya ingresado (A2.6). Los tres siguen siendo deuda operativa consciente, se suplen a mano durante los pilotos y vencen alrededor del cuarto cliente.

---

## 2 · Historias candidatas

<a id="h-01"></a>
### H-01 🔴 · Colisión de horario al confirmar

> **Como** cliente final que eligió un horario de la lista, **quiero** que el sistema verifique que sigue libre antes de confirmármelo, **para** no recibir una confirmación de un turno que otra persona acaba de tomar.

**Est. M** · MVP · consume [US-05](../stories/US-05-horarios-realmente-libres.md), bloquea la promesa de [US-06](../stories/US-06-reservar-y-liberar-sync-inversa.md) y de [US-11](../stories/US-11-reagendar-y-aviso-t2h.md).

Entre que se envía la lista (`SELECTING_SLOT`) y que el cliente elige pasan segundos o minutos. El flujo dibuja el chequeo (`CheckCollision → CollisionMsg → FetchSlots`) y ninguna historia lo escribe. Es bloqueante porque no alcanza con re-consultar `freeBusy`: dos clientes pueden pasar el chequeo a la vez. Requiere una reserva temporal —llave en Redis con TTL, o columna de bloqueo— que hoy no está en el modelo de datos.

<a id="h-02"></a>
### H-02 · Dónde se captura el nombre del cliente

> **Como** equipo, **queremos** una única definición de en qué momento se pide el nombre, **para** que el lead de la planilla y el evento del calendario no salgan con el campo vacío.

**Est. S** (la decisión) · MVP · afecta [US-03](../stories/US-03-maquina-de-estados-conversacional.md), [US-06](../stories/US-06-reservar-y-liberar-sync-inversa.md), [US-13](../stories/US-13-leads-a-google-sheets.md).

Tres documentos, tres momentos: `SLOT_SELECTED` *"solicita el nombre"* y la secuencia e2e lo confirma en el paso 45 (después de elegir horario); AC-03.1 de US-03 dice *"habiendo dado ya mi nombre"* en `GATHERING_PARAMS`; AC-13.1 de US-13 escribe la fila del lead **al salir de `GATHERING_PARAMS`** incluyendo el nombre. Si vale la versión de los flujos, US-13 escribe una fila incompleta en cada lead.

<a id="h-03"></a>
### H-03 · Qué se responde con la agenda llena

> **Como** cliente final que pide turno un día sin lugar, **quiero** que me digan qué opciones tengo, **para** no quedarme sin respuesta justo cuando el negocio está en su mejor momento.

**Est. S** · MVP · extiende [US-05](../stories/US-05-horarios-realmente-libres.md).

Los cuatro criterios de US-05 asumen que hay algo que ofrecer. El flujo tiene la rama (`NoSlotsMsg`) y nadie definió el mensaje, si ofrece la semana siguiente, ni si deriva a un humano — que es [H-05](#h-05).

<a id="h-04"></a>
### H-04 · Agendamiento sin compensación

> **Como** dueña de PyME, **quiero** que nunca quede un evento en mi calendario que el sistema no reconoce, **para** no tener horarios bloqueados por turnos fantasma que no puedo cancelar ni recordar.

**Est. M** · MVP · extiende [US-06](../stories/US-06-reservar-y-liberar-sync-inversa.md) y [US-09](../stories/US-09-fallback-ante-caidas.md).

El bloque `critical Agendamiento Atomico` de la secuencia e2e abarca tres sistemas (Google, MySQL, cola de Sheets) sin mecanismo de reversa. Si el `INSERT INTO bookings` falla después de crear el evento, el horario queda ocupado, sin recordatorio y sin forma de cancelarlo desde el chat. US-09 cubre "el tercero falla", no "el tercero respondió bien y fallamos nosotros".

<a id="h-05"></a>
### H-05 · Derivación a un asesor que nadie recibe

> **Como** vendedora de la PyME, **quiero** enterarme cuando el bot deriva una consulta a una persona, **para** atenderla antes de que el cliente se canse de esperar.

**Est. S** · MVP · cierra el circuito de [US-12](../stories/US-12-cotizador-parametrico.md) (AC-12.4), [H-03](#h-03) y [H-16](#h-16).

Tres caminos distintos terminan en "un asesor te va a atender" —valor fuera de rango, sin disponibilidad, pregunta libre— y ninguno le avisa al asesor. La derivación existe del lado del cliente final y no existe del lado de la PyME.

<a id="h-06"></a>
### H-06 · Pausar o reactivar el bot a mano

> **Como** dueña de PyME, **quiero** poder silenciar el bot en una conversación con un botón, **para** no depender de que el sistema adivine que estoy atendiendo yo.

**Est. S** · MVP · **mitigación de riesgo de [US-08](../stories/US-08-pausa-intervencion-humana.md)**.

UC-2.10 está dibujado en los casos de uso de la PyME, con su llave TTL en Redis, y no existe en el backlog. Su valor real es de cobertura: si el spike confirma que Meta no entrega los mensajes salientes escritos a mano, US-08 se queda sin mecanismo y hay que rediseñarla; con H-06 construido, la degradación es "el dueño aprieta un botón" en lugar de "no hay takeover". Convierte la falla de *Estimable* más grave del backlog en un riesgo acotado por una historia S.

<a id="h-07"></a>
### H-07 · Cuándo expira la sesión

> **Como** equipo, **queremos** un único valor de expiración de conversación, **para** que el bot no prometa recordar durante dos horas algo que borra a los treinta minutos.

**Est. S** (la decisión) · MVP · afecta [US-03](../stories/US-03-maquina-de-estados-conversacional.md) (AC-03.1) y [US-11](../stories/US-11-reagendar-y-aviso-t2h.md) (AC-11.3).

La matriz de transiciones dice `[IDLE] <── (Expiración 30 min Inactividad)`. AC-03.1 dice que a las dos horas el bot retoma conservando los datos. AC-11.3 usa 30 minutos para otra cosa (abandono del re-agendamiento). Tres números en tres lugares para la misma idea.

<a id="h-08"></a>
### H-08 · Recordatorio que no salió

> **Como** dueña de PyME, **quiero** enterarme si un recordatorio no llegó a mi cliente, **para** llamarlo yo antes de que el turno se pierda.

**Est. S** · MVP · instrumenta [US-10](../stories/US-10-recordatorio-t24h.md), consume [US-09](../stories/US-09-fallback-ante-caidas.md) (AC-09.3).

`notification_logs.status` ya admite `'failed'`: el modelo de datos anticipó el caso y nadie escribió qué pasa después. El ausentismo —el número con el que se cierra la venta— depende de que ese mensaje llegue.

<a id="h-09"></a>
### H-09 🔴 · Configuración del negocio sin tabla

> **Como** equipo, **necesitamos** una tabla donde vivan horarios de atención, duración de turno, buffer y textos del bot, **para** que las cinco historias que los leen tengan de dónde leerlos.

**Est. S** (la migración) · MVP · **bloquea** [US-05](../stories/US-05-horarios-realmente-libres.md), [US-09](../stories/US-09-fallback-ante-caidas.md), [US-15](../stories/US-15-configurar-reglas-del-negocio.md), [US-16](../stories/US-16-primer-contacto-bienvenida.md).

De los cinco dominios que configura US-15, solo dos tienen dónde persistir: zona horaria (`tenants.timezone`) y fórmulas (`quote_rules`). Horario de atención, duración, buffer y los textos de bienvenida y fallback **no tienen tabla**. RF-B2 exige respetar horarios y buffers; RNF-03 exige un mensaje de cortesía *"predeterminado"* configurable.

<a id="h-10"></a>
### H-10 🔴 · Historial de mensajes sin tabla

> **Como** dueña de PyME que recibe un reclamo, **quiero** leer la conversación completa en el panel, **para** resolverlo sin escribirle al proveedor.

**Est. M** · MVP · **bloquea** [US-14](../stories/US-14-panel-logs-y-estado.md) (AC-14.4).

`conversations` guarda `current_state`, `context_data JSON` y `last_interaction_at`: el estado, no el diálogo. Sin tabla `messages`, el panel lista chats y no puede abrir ninguno. Refuerza la recomendación que la propia US-14 hace de descopear el MVP a una lista de solo lectura.

<a id="h-11"></a>
### H-11 🔴 · Asistencia sin valor en el enum

> **Como** equipo, **necesitamos** que `bookings.status` admita `attended` y `no_show`, **para** poder registrar el único dato que convierte el MVP en un experimento.

**Est. S** (la migración) · MVP · **bloquea** [US-17](../stories/US-17-registro-de-asistencia.md).

`bookings.status ENUM('scheduled','confirmed','rescheduled','cancelled')`. La historia mejor puntuada del backlog (5/6 INVEST, estimada S) no tiene dónde escribir su resultado. Hay que decidir además si asistencia y estado del turno son el mismo campo o dos: un turno puede estar `confirmed` por el recordatorio y terminar en `no_show`, y con un solo enum esa información se pisa —lo que rompería directamente AC-17.4, el criterio que la propia historia declara el más importante.

<a id="h-12"></a>
### H-12 · Alta de credenciales de Meta por tenant

> **Como** dueña de PyME, **quiero** cargar mis credenciales de WhatsApp Business desde el panel, **para** darme de alta sin depender de que alguien las escriba en una base de datos.

**Est. M** · v2 · complementa [US-01](../stories/US-01-handshake-webhook-meta.md).

AC-01.1 dice *"un tenant tiene su propio `verify_token` configurado"* sin decir por quién ni dónde. UC-2.4 es esa pantalla. Se difiere junto con el alta de tenant: en los pilotos lo cargamos nosotros.

<a id="h-13"></a>
### H-13 · Roles y permisos

> **Como** dueña de PyME, **quiero** que mi staff vea los chats pero no toque la configuración ni las integraciones, **para** delegar la atención sin delegar el control.

**Est. M** · v2 · afecta [US-14](../stories/US-14-panel-logs-y-estado.md), [US-15](../stories/US-15-configurar-reglas-del-negocio.md), [US-17](../stories/US-17-registro-de-asistencia.md).

`users.role ENUM('owner','admin','staff')` existe en el modelo de datos y el diagrama de casos de uso reparte actividades entre tres actores. Ninguna historia dice qué puede hacer cada uno. Con un usuario por tenant en los pilotos no se nota.

<a id="h-14"></a>
### H-14 · `users.email` único global

> **Como** equipo, **queremos** que la unicidad del correo sea por tenant, **para** no impedir que una misma persona trabaje en dos PyMEs.

**Est. S** (la migración) · v2, **decisión hoy** · afecta el alta de tenant (B1.1).

`UNIQUE INDEX idx_users_email (email)` sin `tenant_id`. Es barato de cambiar ahora y caro después de tener datos. No bloquea el MVP pero conviene resolverlo antes del primer alta real.

<a id="h-15"></a>
### H-15 🔴 · Índices compuestos ausentes

> **Como** equipo, **necesitamos** los índices que el modelo de datos dice tener, **para** sostener el presupuesto de latencia del RNF-04 cuando haya volumen.

**Est. S** · MVP · sostiene [US-02](../stories/US-02-ingesta-asincrona-rafaga.md) (AC-02.1) y [US-10](../stories/US-10-recordatorio-t24h.md) (AC-10.1).

El documento afirma estar *"diseñado con índices compuestos específicos para garantizar tiempos de consulta inferiores a 15 ms"* y el DDL no define ninguno: solo PKs, dos únicos y los índices implícitos de las claves foráneas. Faltan al menos `conversations(tenant_id, user_phone)` —camino crítico de cada webhook— y `bookings(tenant_id, start_time, status)` —la consulta que el scheduler dispara cada 10 minutos.

<a id="h-16"></a>
### H-16 · Pregunta libre sin salida

> **Como** cliente final con una duda que el bot no entiende, **quiero** que me pasen con una persona, **para** no quedar repitiendo el mismo mensaje sin avanzar.

**Est. S** · MVP · extiende [US-03](../stories/US-03-maquina-de-estados-conversacional.md) (AC-03.4), depende de [H-05](#h-05).

El flujo resuelve la pregunta libre delegando en el takeover humano (`UserClicksBook -- No / Pregunta libre --> PauseHuman`), pero US-08 solo se activa **si el operador responde**. Si nadie mira el teléfono, AC-03.4 repite indefinidamente qué se espera en ese paso. Es el camino de abandono más probable del flujo y nadie lo mide.

<a id="h-17"></a>
### H-17 · Renovación de canales *watch*

> **Como** dueña de PyME, **quiero** que los turnos que borro a mano se sigan liberando el mes que viene, **para** que la sincronización no deje de funcionar sin que nadie se entere.

**Est. S** · MVP · sostiene [US-06](../stories/US-06-reservar-y-liberar-sync-inversa.md) (AC-06.2/3).

US-06 lo lista en "de qué depende" y no tiene criterio de aceptación ni componente en el C4. Un canal caducado no falla ruidosamente: deja de avisar, y la sincronización inversa —la parte que US-06 identifica como el diferencial frente a la competencia— muere en silencio.

<a id="h-18"></a>
### H-18 · Evaluación segura de fórmulas del tenant

> **Como** equipo, **queremos** que la fórmula que carga un tenant no pueda ejecutar código en el servidor, **para** que un cliente no comprometa los datos de los demás.

**Est. S** · MVP · sostiene [US-12](../stories/US-12-cotizador-parametrico.md) y [US-15](../stories/US-15-configurar-reglas-del-negocio.md) (AC-15.4).

`quote_rules.formula_expression VARCHAR(255)` es una expresión cargada por el usuario que el `QuoteEngine` evalúa. US-12 pide *"validación estricta de entradas"*, pero se refiere a lo que escribe el cliente final en el chat, no a lo que escribe el tenant en el panel. Con multi-tenancy por columna, un `eval()` acá es acceso a los datos de todos.

<a id="h-19"></a>
### H-19 · Destino del logging estructurado

> **Como** equipo, **queremos** un único destino y formato de log con `tenant_id`, **para** que las tres historias que lo asumen no lo implementen de tres maneras.

**Est. S** · MVP · asumido por [US-01](../stories/US-01-handshake-webhook-meta.md) (AC-01.2), [US-09](../stories/US-09-fallback-ante-caidas.md) (AC-09.1), [US-14](../stories/US-14-panel-logs-y-estado.md) (AC-14.2).

No es bloqueante —Laravel escribe a archivo por defecto— pero define si el "conteo de excepciones por integración" que US-09 quiere aprender es consultable o hay que leerlo a mano.

<a id="h-20"></a>
### H-20 · Pantalla para vincular la planilla

> **Como** dueña de PyME, **quiero** elegir en qué planilla mía caen los leads, **para** que aparezcan donde mi equipo ya trabaja y no en una que crearon ustedes.

**Est. S** · MVP · completa [US-13](../stories/US-13-leads-a-google-sheets.md) y UC-2.5.

Sin esta pantalla, "los leads donde el equipo ya trabaja" —el título de US-13 y la mitad de la propuesta de valor— no se cumple literalmente: caen en una planilla nuestra.

<a id="h-21"></a>
### H-21 · Vista del estado de las integraciones

> **Como** dueña de PyME, **quiero** ver en el panel que una integración se cayó, **para** enterarme antes de que me lo diga un cliente.

**Est. S** · MVP · completa [US-09](../stories/US-09-fallback-ante-caidas.md) (AC-09.2).

AC-09.2 *"marca en el panel que hay que reconectar"* y `integrations.status` admite `'expired'`: el dato se escribe y nadie construye dónde leerlo. Junto con [H-08](#h-08), forma el mínimo de observabilidad que hace innecesaria una llamada nuestra.

---

## 3 · Las seis contradicciones abiertas

<a id="c-01"></a>
### C-01 · Outlook: dos documentos lo prometen, dos lo ignoran, el PRD ni lo nombra

| Documento | Qué dice |
| :-- | :-- |
| [`01-lean-canvas.md`](../01-producto/01-lean-canvas.md) | Lo promete: *"Sync OAuth Google/Outlook"* |
| [`04-modelo-de-datos.md`](../02-arquitectura/04-modelo-de-datos.md) | Lo promete: `provider ENUM(..., 'outlook_calendar', ...)` |
| [`02-prd.md`](../01-producto/02-prd.md) | Solo Google Calendar. **No figura en el OUT-OF-SCOPE** |
| [`01-c4-nivel-2-contenedores.md`](../02-arquitectura/01-c4-nivel-2-contenedores.md) | No aparece |
| [`02-c4-nivel-3-componentes.md`](../02-arquitectura/02-c4-nivel-3-componentes.md) | No aparece |

**Afecta:** [US-04](../stories/US-04-conectar-google-calendar-oauth.md) (falla de *Estimable*), [US-05](../stories/US-05-horarios-realmente-libres.md) (falla de *Estimable*), [US-06](../stories/US-06-reservar-y-liberar-sync-inversa.md).
**Lectura recomendada:** v2, siguiendo al PRD, **y escribirlo en el OUT-OF-SCOPE**. Lo peligroso no es que esté afuera: es que el PRD no lo nombre, porque nadie tiene hoy un documento con el que desmentir a un vendedor que lo prometa.
**Corrección al mapa anterior:** [`01-producto/04-user-story-map.md`](../01-producto/04-user-story-map.md) atribuye la promesa al C4 nivel 2. Contra el archivo actual eso no se sostiene —el C4 L2 no menciona Outlook—; quien la sostiene es el `provider` del modelo de datos.

<a id="c-02"></a>
### C-02 · El catálogo de estados: cuatro contra once

RF-A3 declara `IDLE`, `GATHERING_PARAMS`, `SELECTING_SLOT`, `BOOKED`. [`05-estados-flujo-conversacional.md`](../03-flujos/05-estados-flujo-conversacional.md) declara once, agregando `CALCULATING_QUOTE`, `SLOT_SELECTED`, `CONFIRMED`, `RESCHEDULED`, `CANCELLED`, `PAUSED_HUMAN` y `ERROR_FALLBACK`.

**Afecta:** [US-03](../stories/US-03-maquina-de-estados-conversacional.md), que traza a RF-A3, estima **L** sobre cuatro estados y ya anticipa el problema: *"si los cuatro estados alcanzan para los caminos reales, o si la conversación exige estados intermedios que no anticipamos"*. Los estados intermedios ya están escritos en otro documento.
**Lectura recomendada:** el catálogo de once es la definición operativa; RF-A3 está desactualizado. Y confirma la segunda intuición de US-03: **conviene separar el estado de la conversación del estado del turno.** Siete de los once son de conversación; `CONFIRMED`, `RESCHEDULED` y `CANCELLED` son del turno y ya viven en `bookings.status`.

<a id="c-03"></a>
### C-03 · Dónde se captura el nombre

Ver [H-02](#h-02). Tres documentos ubican la captura en dos momentos distintos, y una historia (US-13) escribe un dato que en una de las versiones todavía no existe.

<a id="c-04"></a>
### C-04 · Cuánto dura una conversación inactiva

Ver [H-07](#h-07). Treinta minutos en la matriz de transiciones, dos horas en AC-03.1, treinta minutos para otra cosa en AC-11.3.

<a id="c-05"></a>
### C-05 · Los índices que el modelo dice tener

Ver [H-15](#h-15). El texto del documento afirma índices compuestos para consultas de menos de 15 ms; el DDL del mismo documento no define ninguno.

<a id="c-06"></a>
### C-06 · Dos decisiones "abiertas" que los documentos ya cerraron

[`03-user-stories-mvp.md`](../01-producto/03-user-stories-mvp.md) lista tres decisiones bloqueantes previas al sprint 2. **Dos ya están respondidas** por documentos que el backlog no cruzó:

| Decisión declarada abierta | Qué dicen los documentos | Estado real |
| :-- | :-- | :-- |
| ¿El estado vive en MySQL o en Redis? | `05-estados` §1: MySQL es la persistencia principal, Redis es caché y locks. C4 L2 y `conversations.current_state` son consistentes | **Cerrada: las dos, con roles distintos.** Destraba [US-03](../stories/US-03-maquina-de-estados-conversacional.md) |
| ¿Multi-tenancy por columna o por schema? | RNF-01, C4 L2, C4 L3 y el modelo de datos dicen los cuatro `tenant_id` | **Cerrada: por columna.** Destraba [US-14](../stories/US-14-panel-logs-y-estado.md) |
| ¿Outlook entra al MVP? | Ver [C-01](#c-01) | **Sigue abierta.** Es la única de las tres |

> US-03 atribuye a "C4 nivel 2" la opción Redis y US-14 le atribuye la opción "schema por tenant". El archivo actual del C4 L2 dice `Redis (Queues, State, Cache)` y `MySQL 8 (Multi-tenant with tenant_id)`: **no contradice a nadie**. Las dos decisiones se pueden declarar cerradas por escrito en media hora, y eso convierte dos de las cuatro historias no estimables del backlog en estimables.

---

## 4 · Matriz de trazabilidad

`—` = no existe · `⚠️` = existe pero incompleto

| Historia | RF / RNF | CA | Caso de uso | Estado FSM | Tabla | Hueco asociado |
| :-- | :-- | :-- | :-- | :-- | :-- | :-- |
| [US-01](../stories/US-01-handshake-webhook-meta.md) | RF-A1 | CA-01 | UC-2.4 ⚠️ | — | `tenants` | [H-12](#h-12) · [H-19](#h-19) |
| [US-02](../stories/US-02-ingesta-asincrona-rafaga.md) | RF-A2 · RNF-04 | — | — | — | — | [H-15](#h-15) |
| [US-03](../stories/US-03-maquina-de-estados-conversacional.md) | RF-A3 ⚠️ · RF-A5 | — | UC-1.1 | los 11 | `conversations` | [H-07](#h-07) · [H-16](#h-16) · [C-02](#c-02) |
| [US-04](../stories/US-04-conectar-google-calendar-oauth.md) | RF-B1 · RF-E1 | — | UC-2.1 | — | `integrations` | [C-01](#c-01) |
| [US-05](../stories/US-05-horarios-realmente-libres.md) | RF-B2 · RF-A5 | — | UC-1.3 · UC-2.8 | `SELECTING_SLOT` | ⚠️ falta config | [H-03](#h-03) · [H-09](#h-09) |
| [US-06](../stories/US-06-reservar-y-liberar-sync-inversa.md) | RF-B3 | CA-02 | UC-1.5 · UC-2.9 | `BOOKED` | `bookings` | [H-01](#h-01) · [H-04](#h-04) · [H-17](#h-17) |
| [US-07](../stories/US-07-hora-correcta-timezones.md) | RNF-02 | CA-04 | — | *transversal* | `tenants.timezone` | — |
| [US-08](../stories/US-08-pausa-intervencion-humana.md) | RF-A4 | CA-03 | UC-1.9 · UC-2.10 ⚠️ | `PAUSED_HUMAN` | Redis `pause:` | [H-06](#h-06) |
| [US-09](../stories/US-09-fallback-ante-caidas.md) | RNF-03 | CA-06 | UC-1.10 | `ERROR_FALLBACK` | `integrations.status` | [H-09](#h-09) · [H-19](#h-19) · [H-21](#h-21) |
| [US-10](../stories/US-10-recordatorio-t24h.md) | RF-D1 | CA-05 | UC-1.6 | `CONFIRMED` | `notification_logs` | [H-08](#h-08) · [H-15](#h-15) |
| [US-11](../stories/US-11-reagendar-y-aviso-t2h.md) | RF-D2 · **t-2h sin RF** | — | UC-1.7 | `RESCHEDULED` | `bookings` | [H-01](#h-01) · [H-07](#h-07) |
| [US-12](../stories/US-12-cotizador-parametrico.md) | RF-C1 | — | UC-1.2 · UC-2.2 | `CALCULATING_QUOTE` | `quote_rules` | [H-05](#h-05) · [H-18](#h-18) |
| [US-13](../stories/US-13-leads-a-google-sheets.md) | **sin RF** | CA-02 | UC-2.5 ⚠️ | — | — | [H-02](#h-02) · [H-20](#h-20) |
| [US-14](../stories/US-14-panel-logs-y-estado.md) | RF-E2 · RNF-01 | — | UC-2.7 | — | ⚠️ falta `messages` | [H-10](#h-10) · [H-13](#h-13) · [C-06](#c-06) |
| [US-15](../stories/US-15-configurar-reglas-del-negocio.md) | **sin RF** | — | UC-2.2 · UC-2.3 | — | ⚠️ falta tabla | [H-09](#h-09) · [H-18](#h-18) |
| [US-16](../stories/US-16-primer-contacto-bienvenida.md) | **sin RF** · RF-A5 | — | — | `IDLE` | ⚠️ falta config | [H-09](#h-09) |
| [US-17](../stories/US-17-registro-de-asistencia.md) | **sin RF** | — | UC-2.6 ⚠️ | — | ⚠️ enum incompleto | [H-11](#h-11) |

### Lo que la matriz deja a la vista

1. **Cinco historias del MVP no tienen requisito funcional** (US-11 parcial, US-13, US-15, US-16, US-17), igual que en el diagnóstico anterior. Ninguna se escribió desde entonces.
2. **Cuatro historias no tienen dónde persistir lo que prometen** (US-05, US-14, US-15, US-16, US-17 comparten tres huecos de tabla). Es un hallazgo nuevo: solo aparece cruzando el backlog contra el DDL.
3. **Dos casos de uso de la PyME no tienen historia:** UC-2.10 (pausa manual) y, parcialmente, UC-2.4 y UC-2.5. El recorrido de la PyME sigue siendo el lado flaco.
4. **Ningún caso de uso del cliente final quedó sin historia.** Los once huecos de ese recorrido son *ramas* de casos de uso existentes —colisión, agenda llena, pregunta libre—, no actividades enteras. Es una diferencia cualitativa: del lado del cliente falta robustez; del lado de la PyME falta producto.
