# PRD: Middleware de Agendamiento y Cotización por WhatsApp (MVP Release 1.0)

**Versión:** 1.0 (MVP Comercial)  
**Estado:** Listo para Desarrollo  
**Stack Objetivo:** Laravel 11+ (Backend Engine + Queues) + Vue.js 3 (Admin Dashboard) + Meta Cloud API + Google Calendar API  
**Modelo de Negocio:** Multi-tenant SaaS ($100–$300 Setup / $80–$150 MRR tarifa plana por PyME)

---

## 1. Visión y Alcance del MVP

El MVP tiene un único propósito: **demostrar ROI en la primera llamada comercial eliminando el ausentismo y agendando citas en menos de 15 segundos**, sin obligar a la PyME a cambiar su forma de trabajar (continúa usando Google Calendar y Google Sheets).

### En Alcance (IN-SCOPE para el MVP)
* Ingesta de mensajes de Meta Cloud API con respuestas interactivas (Botones/Listas).
* Validaciones técnicas obligatorias de Meta (Handshake de Webhook).
* Sincronización OAuth 2.0 bidireccional en tiempo real con Google Calendar.
* Cotización paramétrica simple ($Precio = Base + [Variable \times Valor]$).
* Recordatorios automáticos interactivos a $t-24\text{ hs}$ y $t-2\text{ hs}$ (Confirmar / Cancelar / Re-agendar).
* Pausa **manual** del bot desde el panel, y derivación a un segundo número atendido por personas. ⚠️ *Decía "pausa automática por intervención humana"; la Cloud API no permite que el número del bot se use además como chat humano — ver RF-A4.*
* Manejo estricto de zonas horarias locales por PyME (*Timezones*).
* Volcado de leads en tiempo real a Google Sheets.
* Panel minimalista en Vue.js (Conexión OAuth, Logs de chat y Estado de integración).
* Mensajes de *Fallback* ante caídas o excepciones de APIs externas.

### Fuera de Alcance (OUT-OF-SCOPE explícito del MVP)
* ❌ Pasarelas de cobro de señas (Mercado Pago / Stripe).
* ❌ Editor visual drag-and-drop de flujos de conversación.
* ❌ Integración con CRMs pesados o desarrollo de CRM propio.
* ❌ Múltiples sucursales físicas (se cubre 1 sede/cuenta por tenant en el MVP).
* ❌ Uso de librerías no oficiales de WhatsApp (Puppeteer / Baileys).
* ❌ **Integración con Outlook / Microsoft 365 — decisión cerrada (T-007).** El lean canvas y el `provider ENUM` del modelo de datos lo insinuaban, y el PRD no lo incluía **ni lo excluía**: nadie tenía con qué desmentir a un vendedor que lo prometiera. **Queda fuera.** Duplicaría toda la superficie de calendario —OAuth, consulta de disponibilidad, creación de evento y sincronización inversa— para un segmento que el lean canvas todavía no validó. El valor `outlook_calendar` del `ENUM` se conserva para no migrar la tabla si algún día entra, pero **no hay código detrás y `App\Models\Integration` no expone la constante a propósito**.

---

## 2. Objetivos Medibles (KPIs del MVP)

### Impacto en el Cliente (PyME)
* **Tiempo de Respuesta Automatizada:** < 15 segundos por consulta.
* **Reducción de Ausentismo (*No-Show*):** Tasa de asistencia >= 92% en clientes piloto.
* **Tasa de Conversión Chat-a-Reserva:** >= 35% de los chats iniciados completan la reserva.

### Métricas del Producto
* **Tiempo de Puesta en Marcha (Setup):** < 60 minutos para conectar cuenta de Meta, Google Calendar y formulario de cotización.
* **Latency per Request:** Ingesta y encolado de webhooks en < 200 ms.

---

## 3. Requisitos Funcionales del MVP

### Módulo A: Motor de WhatsApp & Máquina de Estados (Laravel)
* **RF-A1 (Handshake de Webhook Meta):** Endpoint `GET /api/v1/webhooks/whatsapp` que responda la validación de `hub.challenge` y `hub.verify_token` requerida por Meta en la configuración inicial.
* **RF-A2 (Ingesta Asíncrona):** Endpoint `POST /api/v1/webhooks/whatsapp` que valide la firma HMAC SHA256, responda `HTTP 200 OK` en < 200 ms y envíe el payload a una cola de procesamiento (`Laravel Queues` + Redis).
* **RF-A3 (State Machine):** Persistencia en DB (`conversations`) del estado del usuario final (`IDLE`, `GATHERING_PARAMS`, `SELECTING_SLOT`, `BOOKED`).
  * **Persistencia del estado — decisión cerrada (T-007):** se usan **las dos**, con jerarquía explícita. La tabla `conversations` es la **única fuente de verdad**: ahí viven `current_state` y `context_data`. Redis es **caché de lectura y lock**, con la clave `session:tenant:{id}:phone:{phone}`, y sostiene el lock por `(tenant_id, user_phone)` que impide que dos mensajes en ráfaga ejecuten la transición dos veces. **Ante cualquier divergencia, manda MySQL.** La respuesta ya estaba en [`03-flujos/05-estados-flujo-conversacional.md`](../03-flujos/05-estados-flujo-conversacional.md) §1; figuraba como decisión abierta y bloqueante en el backlog porque nadie había cruzado los documentos. Desbloquea US-03.
  * **Los estados del MVP son nueve — decisión cerrada (T-007).** Este requisito nombraba cuatro y el catálogo de [`03-flujos/05-estados-flujo-conversacional.md`](../03-flujos/05-estados-flujo-conversacional.md) once. Ninguno de los dos era el número real: el recorte Pareto elimina **`CALCULATING_QUOTE`** (el cotizador está diferido: T-016, T-040, T-041) y **`RESCHEDULED`** (T-042 diferido). Los nueve vigentes son `IDLE`, `GATHERING_PARAMS`, `SELECTING_SLOT`, `SLOT_SELECTED`, `BOOKED`, `CONFIRMED`, `CANCELLED`, `PAUSED_HUMAN` y `ERROR_FALLBACK`.
  * **`ERROR_FALLBACK` — comportamiento definido (T-007).** Estaba en el catálogo de estados y **no en la matriz de transiciones**: nadie había dicho cómo se entra ni cómo se sale. Se entra **desde cualquier estado** ante un fallo de un tercero; se envía el mensaje de cortesía del tenant; **se libera todo lo reservado a medias** y se vuelve a `IDLE`. El cliente empieza de nuevo. Se eligió liberar antes que conservar y reintentar porque conservar exige un mecanismo de reserva temporal con TTL que hoy no existe —es la decisión abierta de US-18— y porque **un horario bloqueado por un turno que nunca se completó es exactamente el hueco H-04**.
  * **Captura del nombre: en `GATHERING_PARAMS` — decisión cerrada (T-007).** Los flujos y la secuencia e2e decían `SLOT_SELECTED`; AC-03.1 y AC-13.1 decían `GATHERING_PARAMS`. **Gana `GATHERING_PARAMS`:** con la otra opción, toda conversación abandonada antes de elegir horario llega a Google Sheets **sin nombre**, y capturar justamente a quien *no* reserva es el propósito entero de US-13. Cuesta un turno más al principio de la conversación.
  * **TTL de inactividad: 30 minutos — decisión cerrada (T-007).** Había tres valores en tres documentos. Gana el de la matriz de transiciones y AC-11.3. ⚠️ **AC-03.1 dice 2 h y hay que corregirlo.** No confundir con la ventana de servicio de 24 h de Meta, que es otra cosa y no se toca.
  * ⚠️ **T-018 hay que partirlo antes de comprometerlo.** Está estimado en 8 puntos —el techo de la escala— sobre 4 estados y ~6 transiciones; la superficie real es de 9 estados y ~22 transiciones, porque la matriz tiene dos entradas *"desde cualquier estado"* que son una transición por estado de origen. Propuesta de corte en cuatro tickets: ese documento *(documento borrado por innecesario el 2026-08-21)*.
* **RF-A4 (Atención humana):** 🔴 **Reescrito el 2026-08-20. La versión anterior describía algo que la plataforma no permite.**

  Decía: *"Detección de mensajes salientes enviados manualmente desde la app móvil o WhatsApp Web del negocio. Si el operador responde a mano, el bot pausa automáticamente."*

  **Un número registrado en la Cloud API deja de poder usarse como chat humano.** O es bot, o es persona: no las dos cosas. No hay app móvil desde la cual el operador responda ese número, así que no hay nada que detectar. Verificado contra la plataforma, no deducido.

  **Lo que rige ahora — modelo de dos números:**

  * El bot atiende en el número de la Cloud API. Las personas atienden en **un segundo número**, normal, que el tenant configura en `business_settings.human_phone`.
  * Al derivar, el bot **le dice al cliente a qué número escribir**. Sin eso la derivación no lleva a ninguna parte.
  * Si el tenant no cargó su segundo número, el mensaje **no promete que alguien escriba**: dice que la consulta quedó registrada, que es lo único cierto.
  * La pausa manual desde el panel (T-025) sigue existiendo, con otra razón: *"lo voy a llamar por teléfono, que el bot no le hable encima"*.

  ⚠️ **El costo está aceptado y conviene tenerlo escrito:** el cliente tiene que cambiar de chat y volver a explicar su caso, y **una parte no lo va a hacer**. Cuántos se pierden es lo primero que el piloto tiene que medir. La alternativa —que el operador responda desde nuestro panel vía la API— queda disponible si el número duele.

  **Qué se cae con esto:** US-08 y T-032 (detección automática) pierden su objeto. **T-004 se cierra sin ejecutarse**: era un spike de 3 puntos con timebox de 2 días para preguntar si Meta entrega esos mensajes por webhook, y la pregunta dejó de aplicar.
* **RF-A5 (Manejo de Respuestas Interactivas):** Soporte para renderizado y recepción de botones de respuesta rápida (*Quick Reply*) y listas dinámicas de horarios (*List Messages*).
* ⚠️ **RF-A6 (Primer Contacto y Bienvenida) — BORRADOR, pendiente de aprobación.** Ante el primer mensaje de un número sin conversación activa, el bot responde con el texto de bienvenida configurado por el tenant (RF-E3) y ofrece el menú inicial de opciones como botones de respuesta rápida. Si el tenant no configuró un texto, se usa el predeterminado del sistema. La conversación pasa de `IDLE` al estado que corresponda según la opción elegida. Cubre US-16 y T-021.

### Módulo B: Integración Google Calendar & Motor de Disponibilidad
* **RF-B1 (OAuth 2.0 Flow):** Permite al usuario del panel conectar su cuenta de Google Workspace o Gmail personal almacenando `access_token` y `refresh_token` encriptados en reposo.
* **RF-B2 (Consulta FreeBusy):** Lectura en tiempo real de espacios ocupados mediante la API `freeBusy`, filtrando por la zona horaria del tenant, respetando horarios de atención y agregando *buffers* de 10 minutos entre turnos.
* **RF-B3 (Creación y Sync Inversa):** Creación automática del evento en el calendario. Escucha de webhooks de Google Calendar (*Watch Notifications*) para liberar turnos si el evento se modifica o elimina manualmente desde el celular.

### Módulo C: Cotizador Paramétrico Simple
* **RF-C1 (Motor de Reglas JSON):** Evaluación de variables numéricas ingresadas por el cliente en el chat (ej. metros cuadrados, cantidad de ambientes) para retornar un presupuesto estimado previo a ofrecer la agenda.

### Módulo D: Engine de Recordatorios Temporizados
* **RF-D1 (Scheduler):** Tarea programada (`Laravel Cron`) ejecutada cada 10 minutos que evalúa turnos próximos a $t-24\text{ hs}$ y dispara la plantilla interactiva de confirmación.
* **RF-D2 (Re-agendamiento Automático):** Si el cliente presiona "Re-agendar" en la plantilla de recordatorio, la máquina de estados retoma el flujo conversacional y despliega los nuevos turnos disponibles.
* ⚠️ **RF-D3 (Aviso t-2 hs Condicional) — BORRADOR, pendiente de aprobación.** Segundo recordatorio a $t-2\text{ hs}$, enviado **solo** a los turnos que no confirmaron el de $t-24\text{ hs}$: quien ya confirmó no recibe un segundo mensaje. Es informativo y no lleva botones. Cubre US-11 y T-043.
  * ⚠️ **Recomendación antes de construirlo:** probarlo a mano durante una semana del piloto. Si no mueve la tasa de asistencia, se cae del MVP sin haber costado código.
* ⚠️ **RF-D4 (Registro de Asistencia) — BORRADOR, pendiente de aprobación.** El negocio marca desde el panel si el cliente asistió o no a cada turno pasado, y el sistema expone la tasa de asistencia del período. **La asistencia se guarda en una columna propia de `bookings`, no como valor de `status`:** con un solo campo, un turno `confirmed` que termina en ausencia perdería el dato de que había confirmado. Cubre US-17, T-036 y T-038.

### Módulo E: Dashboard Admin (Vue 3)
* **RF-E1 (OAuth Settings):** Botón "Conectar Google Calendar" y monitor de estado de conexión de Meta API.
* **RF-E2 (Logs Operativos):** Lista de chats atendidos con estado actual, fecha de turno, monto cotizado y enlace directo al evento en Google Calendar.
* ⚠️ **RF-E3 (Configuración del Negocio) — BORRADOR, pendiente de aprobación.** El tenant configura desde el panel: horario de atención por día, días laborables, duración del turno, *buffer* entre turnos, y los textos de bienvenida y de *fallback*. Hoy **solo persisten `tenants.timezone` y `quote_rules`**: el resto no tiene dónde guardarse, así que RF-B2 está apoyado en valores que ninguna tabla contiene. Requiere la tabla `business_settings` (T-014). Cubre US-15 y T-015 a T-017.
  * ⚠️ **Decidir explícitamente** si en el MVP hay pantallas de carga o se cargan por *seeder*. Si los cargamos nosotros, el KPI de tiempo de *setup* lo estamos cumpliendo nosotros, no el producto.

### Módulo F: Integración Google Sheets

* ⚠️ **RF-F1 (Volcado de Leads a Google Sheets) — BORRADOR, pendiente de aprobación.** Cada conversación que deja datos —haya reservado o no— se agrega como fila en la planilla que el tenant vinculó, en tiempo real. Columnas: fecha y hora del contacto, teléfono, nombre, servicio consultado, monto cotizado y resultado (reservó / abandonó). Cubre US-13, US-26, T-044 y T-045.
  * ⚠️ **Depende de la decisión 4.2** (dónde se captura el nombre). Si el nombre se toma recién en `SLOT_SELECTED`, toda conversación abandonada antes de elegir horario llega a la planilla **sin nombre**, que es justo el lead que esta funcionalidad existe para capturar.
  * ⚠️ **CA-02 exige el turno también en Sheets**, y el recorte Pareto difiere T-045. Mientras siga diferido, **CA-02 no se cumple.**

---

## 4. Requisitos No Funcionales (NFR)

* **RNF-01 (Multi-Tenancy por Columna):** Aislamiento lógico de datos de cada PyME mediante la columna discriminadora `tenant_id` en todas las tablas e índices de la base de datos.
  * **Decisión cerrada (T-007):** es **por columna `tenant_id`, no por schema ni por base separada**, aplicada con *Global Scopes* de Eloquent. Coinciden este RNF, el C4 nivel 2, el C4 nivel 3 y el modelo de datos — **la decisión nunca estuvo realmente abierta**, solo sin declarar en un lugar único. Se deja el acta acá para que ninguna historia vuelva a listarla como bloqueante. Desbloquea US-14.
  * Consecuencias operativas que no son negociables: los Global Scopes **no** alcanzan a `DB::table()`, `DB::raw()`, `whereRaw()`, joins manuales ni subconsultas — ahí el filtro va explícito. Todo índice compuesto **lidera con `tenant_id`**. Los jobs y las tareas programadas **no heredan el tenant**: viaja en el payload. Toda clave de Redis lo lleva adentro.
* **RNF-02 (Manejo de Zonas Horarias - Timezones):** Todas las fechas/horas se almacenan en la base de datos en formato `UTC`. Se realiza la conversión a la zona horaria local configurada en el `tenant` (ej. `America/Argentina/Buenos_Aires`, `America/Bogota`) antes de consultar la API de Google o enviar mensajes por WhatsApp.
* **RNF-03 (Manejo de Errores y Fallback Graceful):** Si la API de Google Calendar o Meta falla, o si vence el token OAuth, el bot debe responder con un mensaje de cortesía predeterminado (*"En este momento nuestro sistema de turnos está actualizándose, un asesor te atenderá a la brevedad"*) y registrar la excepción en los logs, impidiendo que el chat quede colgado.
* **RNF-04 (Performance de Ingesta):** Latencia total interna (Webhook -> Queue -> Google API -> Outbound WhatsApp) <= 1.5 segundos.

---

## 5. Matriz de Criterios de Aceptación (Definition of Done)

| ID | Requisito | Prueba de Validación | Criterio de Pase |
| :--- | :--- | :--- | :--- |
| **CA-01** | Handshake Meta | Registrar la URL en el portal de desarrolladores de Meta. | El servidor responde el `hub.challenge` correcto y la URL se valida en verde. |
| **CA-02** | Agendamiento E2E | Completar el flujo desde WhatsApp con un celular de prueba. | El turno se reserva en WhatsApp y aparece registrado en Google Calendar y Google Sheets. |
| **CA-03** | Intervención Humana | Escribir un mensaje manual desde el WhatsApp Web/Móvil del negocio. | El bot entra en pausa para ese usuario y no responde automáticamente durante 60 minutos. |
| **CA-04** | Timezone Exacto | Agendar turno en un tenant configurado en UTC-3. | El horario guardado en UTC corresponde exactamente a la hora local elegida por el usuario en WhatsApp. |
| **CA-05** | Recordatorio a 24h | Forzar la fecha del servidor a $t-24\text{ hs}$. | Llega la plantilla con botones. Al presionar "Confirmar", el estado del turno pasa a `confirmed`. |
| **CA-06** | Fallback ante Caída | Simular credenciales de Google invalidadas durante la reserva. | El sistema envía el mensaje de cortesía al usuario y registra el error en Log. |