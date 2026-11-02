# Plan de Diego — lo que depende de vos

**Actualizado:** 2026-08-19

Todo lo de acá lo tenés que hacer vos: son trámites con terceros o decisiones de producto. Ninguno lo puedo resolver yo, y **los tres primeros tienen tiempo de calendario ajeno** — el reloj corre en Meta y en Google, no en nosotros. Cada día que no están enviados se suma al final del proyecto.

---

## Estado actual

**355 tests verdes. El producto agenda turnos de punta a punta y manda el recordatorio de 24 h con botones.**

👉 **[Decisiones que te esperan](decisiones-pendientes.md)** — **las tres que frenaban el MVP están cerradas** (2026-08-20). Queda el resto, ninguna bloqueante.

👉 **[Preguntas que salieron construyendo](preguntas-que-salieron-construyendo.md)** — siete que aparecieron implementando los últimos cuatro tickets. **Ninguna frena el código**: todas quedaron resueltas con una interpretación razonable y escrita. Dos merecen tu revisión porque pueden darte un número falso.

👉 **[Decisiones tomadas](decisiones-tomadas.md)** — ✅ **las doce respondidas el 2026-08-21**, con el fundamento de cada una, qué ticket habilita y **el plan de cómo avanzamos**. Es el archivo que contesta a los dos de arriba.

👉 **Plantillas de Meta** — **las tres aprobadas** y verificadas contra la plataforma real. Cuerpos exactos y orden de parámetros.

Alguien escribe por WhatsApp → recibe la bienvenida con el nombre del negocio → toca «Reservar un turno» → da su nombre → elige entre horarios **realmente libres** de tu Google Calendar → **el turno aparece en el calendario** y le llega la confirmación con la hora de Buenos Aires.

Es CA-02 del PRD cumplido punta a punta, y es lo que el ticket T-026 llama *"el momento donde el producto genera el valor que cobramos"*.

| Ticket | Estado |
| :-- | :-- |
| T-008 · Handshake del webhook | ✅ verificado contra el portal real |
| T-009 · Índices compuestos + tablas | ✅ `EXPLAIN` verificado |
| T-010 · Ingesta asíncrona + firma HMAC | ✅ verificado por el túnel |
| T-011 · Idempotencia y cola | ✅ verificado con worker caído y `failed_jobs` |
| T-012 · OAuth con Google Calendar | ✅ Google acepta el consentimiento real |
| T-013 · Renovación de tokens | ✅ |
| T-014 · `business_settings` | ✅ |
| T-020 · Logging estructurado | ✅ |
| T-036 · Registro de asistencia | ✅ |
| T-046 · Login, roles y permisos | ✅ **adelantado** — cierra H-13 y H-14 |
| T-048 · Tabla `messages` | ✅ adelantado |
| T-005 · Spike de latencia `freeBusy` | ✅ medido contra Google real |
| T-007 · Decisiones y RF faltantes | ✅ **las cuatro cerradas** |
| T-015 · Configuración de agenda | ✅ |
| T-017 · Mensajes del bot | ✅ |
| T-022 · Consulta de disponibilidad | ✅ sin caché, por medición |
| T-024 · Respuesta sin disponibilidad | ✅ los tres vacíos se distinguen |
| T-018a · Núcleo de la máquina de estados | ✅ verificado por el túnel real |
| T-019 · Mensajes interactivos | ✅ con sellado de estado (AC-03.2) |
| T-021 · Bienvenida y menú | ✅ **el bot habla por primera vez** |
| T-023 · Lista de horarios | ✅ paginada, sin colisión entre días |
| T-026 · Reserva en Google Calendar | ✅ **el turno se crea de verdad** |
| T-018b · Pausa humana e inactividad | ✅ corrige una contradicción de los docs |
| T-018c · `ERROR_FALLBACK` | ✅ libera lo reservado y vuelve a `IDLE` |
| T-025 · Pausar el bot desde el panel | ✅ los tres roles pueden pausar |
| T-031 · Suite de zonas horarias | ✅ verificada por mutación |
| T-033 · Fallback ante caídas | ✅ **cerró cuatro caminos mudos** |
| **T-001** · Token permanente de Meta | 🔴 **el token vence y hay que rehacerlo a mano** |
| **T-002** · Plantillas de mensaje | 🟡 **en revisión en Meta** — bloquea T-037. Copy en ese documento *(documento borrado por innecesario el 2026-08-21)* |

**Entrá al panel:** `http://localhost:8000/panel` → `owner@agendallena.test` / `password`
*(lo crea `php artisan db:seed --class=TenantSeeder`)*

---

## 1. 🔥 Token permanente de Meta — cierra T-001

**Por qué es lo primero:** el token actual **vence hoy 19:00 UTC**. Cuando venza, todo lo que mande mensajes devuelve `401`. Los que da la pantalla del número de prueba duran horas; esto lo resuelve para siempre.

### Pasos

1. Entrá a **business.facebook.com** → **Configuración del negocio**
2. Menú lateral → **Usuarios** → **Usuarios del sistema** → **Agregar**
3. Nombre: `agendallena-backend`. Rol: **Administrador**
4. Con el usuario creado, botón **Agregar activos**:
   - **Apps** → `agendallena` → permiso *Control total*
   - **Cuentas de WhatsApp** → la WABA `1360872979217448` → *Control total*
5. Botón **Generar nuevo token**:
   - App: `agendallena`
   - Vencimiento: **Nunca**
   - Permisos: tildá `whatsapp_business_messaging` y `whatsapp_business_management`
6. Copiá el token (**solo se muestra una vez**)

### Qué me pasás

El token. Yo lo cargo en `.env` y en la base — son dos lugares distintos y hay que sincronizar los dos.

> **Cómo verifico que sea el correcto:** `debug_token` tiene que decir `vence: NUNCA`. Si dice una fecha, es otro token temporal.

---

## 2. 🔥 Google Cloud y pantalla de consentimiento — T-003

**Bloquea T-012, T-013 y T-005.** Es el que traba el sprint 1: cuando termine lo que estoy haciendo ahora, sin esto no tengo con qué seguir.

### Pasos

1. **console.cloud.google.com** → crear proyecto `agendallena`
2. **APIs y servicios** → **Biblioteca** → habilitá las dos:
   - `Google Calendar API`
   - `Google Sheets API`
3. **Pantalla de consentimiento de OAuth**:
   - Tipo: **Externo**
   - Nombre de la app, email de soporte, email de contacto
   - **Permisos** → agregá los tres **en el mismo consentimiento**:
     - `.../auth/calendar.events`
     - `.../auth/calendar.readonly`
     - `.../auth/spreadsheets`
   - **Usuarios de prueba** → agregá tu cuenta de Google
4. **Credenciales** → **Crear credenciales** → **ID de cliente de OAuth**:
   - Tipo: **Aplicación web**
   - **URIs de redireccionamiento autorizados** → agregá esta:
     ```
     http://localhost:8000/api/v1/oauth/google/callback
     ```
5. Copiá el **ID de cliente** y el **Secreto de cliente**

### Qué me pasás

```
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
```

### Dos cosas que te ahorran tiempo

**No necesitás que Google verifique la app para el piloto.** En modo *Testing* podés usar los scopes de calendario con hasta 100 usuarios de prueba sin pasar por la revisión, que tarda semanas. La verificación recién hace falta para abrir a público general.

**No registres la URL de cloudflared como redirect.** Cambia cada vez que reiniciás el túnel, y Google exige coincidencia exacta: el día que cambie, el OAuth falla con `redirect_uri_mismatch`. Para desarrollo, `localhost` alcanza — Google lo permite como excepción. Cuando vayamos a producción registramos el dominio fijo.

---

## 3. 🔥 Plantillas de mensaje — T-002

**Bloquea T-037** (recordatorio t-24h). Su propio ticket lo llama *"el cuello de botella real del MVP"*: la aprobación tarda y **hay que prever al menos dos iteraciones**, porque Meta rechaza por criterios de copy que no son evidentes.

### Pasos

1. **business.facebook.com** → **WhatsApp Manager** → **Plantillas de mensajes** → **Crear plantilla**
2. **Plantilla 1 — recordatorio t-24h**
   - Categoría: **Utilidad** (no *Marketing*: se aprueba más fácil y sale más barato)
   - Cuerpo con variables: nombre, servicio, fecha y hora local
   - **Tres botones de respuesta rápida:** `Confirmar`, `Re-agendar`, `Cancelar`
3. **Plantilla 2 — acuse de confirmación de reserva**
   - Categoría: **Utilidad**
   - Mismas variables

### Lo que hay que decidir antes de mandarla

⚠️ **Cambiar una plantilla ya aprobada es caro.** Definí qué botones lleva **antes** de enviarla, aunque el efecto de alguno se implemente recién en T-042. Si después agregás un botón, volvés a la cola de aprobación desde cero.

### Qué me pasás

Nada. Solo avisame cuando estén enviadas y cuando estén aprobadas.

---

## 4. Tres decisiones de producto — cierran T-007

**Bloquea T-018**, que es el ticket más grande del backlog (8 puntos). No hace falta que las pienses mucho: te doy mi recomendación de cada una y con un "dale" alcanza. Son tuyas porque son de producto, no técnicas.

### 4.1 · ¿Outlook entra en el MVP?

**Hoy:** el lean canvas y el `provider ENUM` del modelo de datos lo prometen. El C4 no lo menciona. **El PRD ni lo incluye ni lo excluye** — o sea que nadie tiene con qué desmentir a un vendedor que lo prometa.

**Mi recomendación: fuera, y escrito explícito en el OUT-OF-SCOPE del PRD.** Duplica toda la superficie de calendario —OAuth, consulta de disponibilidad, sincronización inversa— para un segmento que todavía no está validado. El `ENUM` puede conservar el valor sin código detrás.

### 4.2 · ¿Dónde se captura el nombre del cliente?

**Hoy:** `SLOT_SELECTED` según los flujos y la secuencia e2e; `GATHERING_PARAMS` según AC-03.1 y AC-13.1.

**Mi recomendación: `GATHERING_PARAMS`.** Con la otra opción, toda conversación abandonada antes de elegir horario llega a Google Sheets **sin nombre** — y capturar justamente a los que *no* reservan es el punto entero de US-13. Cuesta un turno más al principio de la conversación.

### 4.3 · ¿Cuánto dura una conversación inactiva?

**Hoy:** 30 min según la matriz de transiciones, 2 h según AC-03.1, y otros 30 min para otra cosa en AC-11.3. Tres documentos, tres valores.

**Mi recomendación: 30 minutos.** Dos de los tres ya lo dicen, y una conversación de turno callada media hora terminó. (Ojo: no confundir con la ventana de servicio de 24 h de Meta, que es otra cosa y no se toca.)

---

---

## 4.bis · 🆕 Un número de WhatsApp para probar envíos

**Chico y desbloquea una medición que importa.**

En la pantalla del número de prueba de Meta, agregá **tu propio número** a la lista de destinatarios permitidos (*"Administrar la lista de números de teléfono"*), y pasámelo.

**Para qué:** medir el envío saliente de verdad. Hoy solo tengo una aproximación, porque sin destinatario verificado Meta rechaza con `(#131030)` y lo que mido es el rechazo, no el envío.

**Por qué importa:** con los números actuales, las dos llamadas a terceros —Google `freeBusy` + envío de Meta— se comen el **87 % del presupuesto de 1.500 ms del RNF-04** en el p95, dejando 196 ms para todo lo nuestro. Puede ser un artefacto de la medición aproximada o un problema real de calibración del requisito. **No lo sé, y no quiero diseñar T-018 y T-022 sin saberlo.**

Detalle completo en ese documento *(documento borrado por innecesario el 2026-08-21)*.

---

## 5. 🆕 Decisiones nuevas que aparecieron construyendo

Ninguna estaba en el backlog. Salieron de tocar el código y **todas tienen una respuesta por defecto ya aplicada** — solo necesito que las confirmes o las corrijas.

### 5.1 · ¿Una WABA para todos tus clientes, o una por PyME?

**Aplicado hoy:** una sola, la del número de prueba.

Las plantillas de WhatsApp se aprueban **por WABA**, no por número. Si cada PyME tiene la suya, **cada alta necesita sus propias plantillas aprobadas** con su propia espera de Meta — y eso hace imposible el KPI de *setup en menos de 60 minutos*. Detalle completo en ese documento *(documento borrado por innecesario el 2026-08-21)*.

**Decidilo antes de vender el primer cliente.** Después es rehacer el modelo de alta.

### 5.2 · Los tres roles del panel — definidos, confirmá

Cerré [H-13](../stories-maps/05-huecos-y-trazabilidad.md#h-13), que estaba abierto: `users.role` existía desde el principio y **ninguna historia decía qué puede hacer cada uno**.

| Rol | Puede |
| :-- | :-- |
| **`owner`** | Todo. Único que da de alta y quita usuarios |
| **`admin`** | Configuración e integraciones. **No** gestiona usuarios |
| **`staff`** | Atiende conversaciones, marca asistencia, pausa el bot. **No** configura |

El principio: **atender no es configurar.** La única diferencia entre `owner` y `admin` es quién crea usuarios — es lo que impide que un encargado que se va se lleve el acceso.

⚠️ **`staff` ve las conversaciones completas**, con teléfono y contenido. Es lo que pide la historia de H-13, pero el dueño tiene que saberlo: no hay vista censurada.

Definición completa: [`01-producto/05-roles-y-permisos.md`](../01-producto/05-roles-y-permisos.md).

### 5.3 · Un mismo correo ahora puede estar en dos PyMEs

**Aplicado:** el único de `users.email` pasó de global a `(tenant_id, email)` — cierra [H-14](../stories-maps/05-huecos-y-trazabilidad.md#h-14).

Con el único global, **una misma persona no podía tener cuenta en dos negocios**: rompía al gestor que administra dos PyMEs y al profesional que trabaja en dos locales. Con pilotos no se nota; después es una migración con datos encima.

### 5.4 · ⚠️ `users.id` sigue siendo numérico, no UUID

**Es la única de esta lista que se encarece con el tiempo.**

`04-modelo-de-datos.md` documenta `users.id` como `CHAR(36)` UUID *"para evitar la enumeración de recursos"*. El código tiene el `bigint` autoincremental por defecto de Laravel. **No lo cambié** porque `personal_access_tokens` y `bookings.attendance_marked_by` ya apuntan ahí: es una migración en cadena, no una línea.

El riesgo real es acotado —un `id` secuencial deja contar usuarios probando URLs, pero la autorización por tenant devuelve 403 igual—. **Si querés unificarlo con el documento, es antes de tener usuarios reales.** Cada tabla nueva que referencie usuarios lo encarece.

### 5.5 · ⚠️ No existe un usuario tuyo para dar soporte

Los tres roles son **internos a una PyME**. Ninguno te deja ver o asistir a un cliente desde el panel: hoy, para dar soporte, entrás a la base a mano.

Cuando exista, **no debería ser un valor más del `ENUM`**: un bug de asignación de rol daría acceso a todos los tenants a la vez. Es un mecanismo aparte.

### 5.6 · Horarios: decisión ya tomada, para que la sepas

`business_settings.business_hours` guarda **JSON con lista de rangos por día**, no columnas de apertura y cierre. La razón es de mercado: **la jornada partida es la norma** acá — una peluquería abre 9 a 13 y 16 a 20, y un par de columnas no lo expresa.

El recorte Pareto de T-015 difiere el horario partido *en la pantalla*, pero **la tabla ya lo aguanta**: cuando se retome, no hay que migrar nada.

---

## 6. 🔴 T-018 no son 8 puntos — hay que partirlo

Rehice la estimación que el `README.md` de tickets marcaba como pendiente: *"US-03 está estimada sobre los 4 estados: hay que rehacerla sobre los 11 antes de comprometerla."*

**Ni 4 ni 11: son 9.** El recorte Pareto elimina dos estados que nadie descontó — `CALCULATING_QUOTE` (el cotizador está diferido) y `RESCHEDULED` (T-042 diferido).

Pero lo que dispara el costo no son los estados sino **las transiciones comodín**. La matriz tiene dos entradas que dicen *"desde cualquier estado"*, y eso es una transición por estado de origen, cada una con su semántica propia:

| | Sobre 4 estados | Sobre 9 reales |
| :-- | --: | --: |
| Transiciones | ~6 | **~22** |

La escala del proyecto dice *"8 pts = techo: por encima, partir"*. T-018 está en el techo calculado sobre el 27 % de la superficie real.

**Propongo partirlo en cuatro (13 pts).** Lo importante no es el número: **T-018a solo ya desbloquea T-023 y T-026**, que es lo que rompe la cadena en serie que el README señala como el problema estructural del plan.

### 🔴 Y apareció un hueco que ningún documento registraba

`ERROR_FALLBACK` **está en el catálogo de estados y no está en la matriz de transiciones.** Nadie definió cómo se entra ni cómo se sale.

La pregunta que no puedo contestar solo: **si el cliente estaba eligiendo horario y Google se cae, ¿ese horario queda tomado?** Es el mismo modo de falla de H-04, agendamiento sin compensación.

Lo trajo el grafo de conocimiento, no la lectura: al rastrear por qué US-09 conecta cinco comunidades apareció que referencia un estado que la matriz no define.

Detalle completo, con la propuesta de corte: ese documento *(documento borrado por innecesario el 2026-08-21)*.

---

## Qué hago yo mientras tanto

Sigo con los tickets que no dependen de vos, en este orden:

1. **T-017** — configuración de mensajes del bot (2 pts, el único 6/6 INVEST del backlog, desbloquea tres)
2. **T-015** — configuración de agenda, con el recorte Pareto
3. **T-047 / T-049** — panel de conversaciones y su detalle, que ya tienen datos gracias a T-048
4. **T-038** — pantalla de asistencia, que ya tiene su columna gracias a T-036

**Lo que no puedo tocar sin vos:** T-018 (bloqueado por las tres decisiones de §4), T-022 (necesita Google conectado de verdad y el spike T-005), T-037 (necesita las plantillas aprobadas) y T-016 (necesita el discovery T-006).

---

## Orden sugerido para vos

Si tenés poco tiempo, en este orden de impacto:

1. **Las tres decisiones de §4** (5 min) — respondeme "dale" si estás de acuerdo con las tres. **Destraban T-018, el ticket de 8 puntos.** Es lo más barato y lo que más mueve
2. **Token permanente de Meta** (10 min) — el temporal se vence solo y hay que rehacerlo cada vez
3. **Plantillas** (30 min) — el más lento en aprobarse; cuanto antes entre a la cola de Meta, mejor
4. **§5.1 — una WABA o varias** — no urge hoy, pero **antes del primer cliente pago**
5. **§5.4 — el `id` de usuarios** — la única que se encarece con cada día que pasa
