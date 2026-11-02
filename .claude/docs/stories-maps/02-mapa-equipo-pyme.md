# Mapa 2 — Recorrido del equipo de la PyME (panel, calendario y celular)

**Perspectiva:** dueña o dueño de la PyME, staff de ventas y profesional que atiende. Tres actores distintos que el diagrama de casos de uso ya separa y que el backlog trata como uno solo.
**Fuentes del backbone:** [`03-flujos/01-casos-de-uso-general.md`](../03-flujos/01-casos-de-uso-general.md) · [`03-flujos/03-casos-de-uso-equipo-pyme.md`](../03-flujos/03-casos-de-uso-equipo-pyme.md) · [`02-arquitectura/04-modelo-de-datos.md`](../02-arquitectura/04-modelo-de-datos.md)
**Leyenda:** `✅ cubre` · `⚠️ parcial` · `🕳️ HUECO` · `🔴 hueco bloqueante`

```
B1 Dar de     B2 Conectar      B3 Configurar   B4 Atender el   B5 Supervisar   B6 Resolver
   alta      →   integraciones →   el negocio  →   día a día   →   la operación →   incidentes
   (owner)      (owner/admin)     (owner/admin)   (staff + pro)   (owner/staff)    (owner)
```

> **Los tres actores importan.** `users.role` es `ENUM('owner','admin','staff')` y el diagrama de casos de uso reparte las actividades entre *Dueño/Admin*, *Equipo de Ventas/Staff* y *Empleado/Profesional*. Ninguna historia del backlog distingue entre ellos: [US-14](../stories/US-14-panel-logs-y-estado.md) dice "ingresé al panel con mi usuario" sin decir qué ve cada rol. Ver [H-13](05-huecos-y-trazabilidad.md#h-13).

---

## B1 · Dar de alta la cuenta — *owner*

| Paso | Historia | Cobertura | Slice |
| :-- | :-- | :-: | :-- |
| B1.1 Crear el tenant y el usuario administrador | — | 🕳️ | v2 |
| B1.2 Cargar las credenciales de Meta WhatsApp del negocio (UC-2.4) | [US-01](../stories/US-01-handshake-webhook-meta.md) | ⚠️ [H-12](05-huecos-y-trazabilidad.md#h-12) | v2 |
| B1.3 Iniciar sesión en el panel | [US-14](../stories/US-14-panel-logs-y-estado.md) | ⚠️ | MVP |
| B1.4 Dar de alta empleados con distintos permisos | — | 🕳️ [H-13](05-huecos-y-trazabilidad.md#h-13) | v2 |

> **B1.2 se refina respecto del mapa anterior.** Ahí figuraba cubierto por US-01. Pero US-01 construye el **endpoint** que responde el `hub.challenge`; UC-2.4 del diagrama de la PyME es otra cosa: la pantalla donde el tenant carga sus propias credenciales de Meta. AC-01.1 dice *"un tenant tiene su propio `verify_token` configurado"* — configurado **por quién y dónde** no lo dice ninguna historia. En los pilotos lo cargamos nosotros por base de datos, igual que B1.1.
>
> **B1.3 es `parcial` y conviene sacarla.** La autenticación está enterrada dentro de US-14, que la propia historia marca como falla de INVEST ("autenticación y vista de logs son historias separadas"). Bloquea B5 entero y no depende de nada.
>
> **Nota de datos para B1.1:** `users.email` tiene `UNIQUE INDEX idx_users_email (email)` **global**, no compuesto con `tenant_id`. Una misma persona no puede ser usuaria de dos tenants, y dos PyMEs distintas no pueden tener un empleado con el mismo correo. Con pilotos no se nota; con cualquier crecimiento sí. Ver [H-14](05-huecos-y-trazabilidad.md#h-14).

---

## B2 · Conectar las integraciones — *owner / admin*

| Paso | Historia | Cobertura | Slice |
| :-- | :-- | :-: | :-- |
| B2.1 Conectar Google Calendar por OAuth en un clic (UC-2.1) | [US-04](../stories/US-04-conectar-google-calendar-oauth.md) (AC-04.1) | ✅ | MVP |
| B2.2 Que los tokens queden encriptados en reposo | [US-04](../stories/US-04-conectar-google-calendar-oauth.md) (AC-04.2) | ✅ | MVP |
| B2.3 Que el token se renueve solo cuando vence | [US-04](../stories/US-04-conectar-google-calendar-oauth.md) (AC-04.4) | ✅ | MVP |
| B2.4 Vincular la planilla de Google Sheets (UC-2.5) | [US-13](../stories/US-13-leads-a-google-sheets.md) | ⚠️ | MVP |
| B2.5 Ver el estado de la conexión con Meta | [US-04](../stories/US-04-conectar-google-calendar-oauth.md) (AC-04.1) | ⚠️ | MVP |
| B2.6 Conectar Outlook / Microsoft 365 | — | 🕳️ *contradicción abierta* | v2 |

> **B2.4 es `parcial`:** US-13 menciona el alcance de OAuth de Sheets como dependencia y sugiere pedirlo en el mismo consentimiento de US-04, pero **nadie construye la pantalla donde el dueño elige qué planilla**. Sin eso, "volcar leads a tu planilla" significa a una planilla que creamos nosotros.
>
> **B2.6 — la contradicción de Outlook hay que reformularla.** El mapa anterior decía que la prometen el lean canvas y el C4 nivel 2. Contra los archivos actuales eso es solo mitad cierto:
>
> | Documento | Qué dice hoy sobre Outlook |
> | :-- | :-- |
> | [`01-lean-canvas.md`](../01-producto/01-lean-canvas.md) | **La promete:** *"Sync OAuth Google/Outlook"* |
> | [`04-modelo-de-datos.md`](../02-arquitectura/04-modelo-de-datos.md) | **La promete:** `provider ENUM(..., 'outlook_calendar', ...)` |
> | [`02-prd.md`](../01-producto/02-prd.md) | Solo Google Calendar. Tampoco la excluye en el OUT-OF-SCOPE |
> | [`01-c4-nivel-2-contenedores.md`](../02-arquitectura/01-c4-nivel-2-contenedores.md) | **No aparece.** Solo Meta, Google Calendar y Google Sheets |
> | [`02-c4-nivel-3-componentes.md`](../02-arquitectura/02-c4-nivel-3-componentes.md) | **No aparece.** Solo `CalendarAdapter` de Google |
>
> Dos documentos la prometen, dos la ignoran y el PRD ni la nombra —ni para incluirla ni para excluirla, que es lo peor de los tres estados. Mientras siga así, el equipo comercial puede vender algo que el MVP no hace y nadie tiene un documento con el que desmentirlo. Ver [C-01](05-huecos-y-trazabilidad.md#c-01).

---

## B3 · Configurar las reglas del negocio — *owner / admin*

| Paso | Historia | Cobertura | Slice |
| :-- | :-- | :-: | :-- |
| B3.1 Definir horario de atención y días laborables (UC-2.3) | [US-15](../stories/US-15-configurar-reglas-del-negocio.md) (AC-15.1) | 🔴 [H-09](05-huecos-y-trazabilidad.md#h-09) | MVP |
| B3.2 Definir duración de turno y buffer (UC-2.3) | [US-15](../stories/US-15-configurar-reglas-del-negocio.md) (AC-15.2) | 🔴 [H-09](05-huecos-y-trazabilidad.md#h-09) | MVP |
| B3.3 Definir la zona horaria | [US-15](../stories/US-15-configurar-reglas-del-negocio.md) (AC-15.3) | ✅ `tenants.timezone` | MVP |
| B3.4 Cargar las fórmulas de precio (UC-2.2) | [US-15](../stories/US-15-configurar-reglas-del-negocio.md) (AC-15.4) | ✅ `quote_rules` | MVP |
| B3.5 Editar los mensajes de bienvenida y de fallback | [US-15](../stories/US-15-configurar-reglas-del-negocio.md) (AC-15.5) | 🔴 [H-09](05-huecos-y-trazabilidad.md#h-09) | MVP |
| B3.6 Que una configuración incoherente se rechace | [US-15](../stories/US-15-configurar-reglas-del-negocio.md) (AC-15.6) | ✅ | MVP |
| B3.7 Editor visual de flujos conversacionales | — | 🕳️ | v2 |

> **La actividad que era un hueco de backlog ahora es un hueco de datos.** US-15 cerró el hueco de historia que detectó el mapa anterior, pero al cruzarla contra el modelo de datos aparecen **tres de sus cinco dominios sin tabla**:
>
> | Configura | Dónde persiste hoy |
> | :-- | :-- |
> | Zona horaria | ✅ `tenants.timezone` |
> | Fórmulas de precio | ✅ `quote_rules.formula_expression` + `parameters_schema` |
> | Horario de atención y días laborables | ❌ ninguna tabla |
> | Duración de turno y buffer | ❌ ninguna tabla |
> | Textos de bienvenida y fallback | ❌ ninguna tabla |
>
> `integrations.settings JSON` es lo único que podría estirarse, pero es por integración y nadie lo declara para esto. Y no es un detalle de implementación: RF-B2 exige *"respetando horarios de atención"* y *"buffers de 10 minutos"*, [US-05](../stories/US-05-horarios-realmente-libres.md) (AC-05.2/3), [US-16](../stories/US-16-primer-contacto-bienvenida.md) (AC-16.4) y [US-09](../stories/US-09-fallback-ante-caidas.md) los leen. **Cinco historias del MVP consumen una configuración que no tiene dónde vivir.**

---

## B4 · Atender en el día a día — *staff + profesional*

| Paso | Historia | Cobertura | Slice |
| :-- | :-- | :-: | :-- |
| B4.1 Responder a mano desde el celular cuando quiero | [US-08](../stories/US-08-pausa-intervencion-humana.md) | ⚠️ *spike* | MVP |
| B4.2 Que el bot se calle mientras atiendo | [US-08](../stories/US-08-pausa-intervencion-humana.md) (AC-08.1) | ⚠️ *spike* | MVP |
| B4.3 Que el bot retome solo pasados los 60 minutos | [US-08](../stories/US-08-pausa-intervencion-humana.md) (AC-08.2) | ⚠️ *spike* | MVP |
| B4.4 Pausar o reactivar el bot a mano desde el panel (UC-2.10) | — | 🕳️ [H-06](05-huecos-y-trazabilidad.md#h-06) | MVP |
| B4.5 Bloquear un horario a mano en mi calendario (UC-2.8) | [US-05](../stories/US-05-horarios-realmente-libres.md) (AC-05.1) | ✅ | MVP |
| B4.6 Borrar o mover una cita desde la app del celular (UC-2.9) | [US-06](../stories/US-06-reservar-y-liberar-sync-inversa.md) (AC-06.2/3) | ✅ | MVP |
| B4.7 Atender una consulta que el bot me derivó | — | 🕳️ [H-05](05-huecos-y-trazabilidad.md#h-05) | MVP |
| B4.8 Asignar conversaciones a distintos empleados | — | 🕳️ | v2 |

> **B4.4 es el hallazgo más accionable de este mapa.** UC-2.10 —*"Forzar Pausa o Reactivación del Bot"*, con su llave TTL en Redis— está dibujado en los casos de uso de la PyME y **no existe en el backlog**. Vale por dos motivos: le da al dueño el control explícito que hoy depende de que adivinemos su intención, y sobre todo **es el plan B de [US-08](../stories/US-08-pausa-intervencion-humana.md)**. Si el spike confirma que Meta no entrega los mensajes salientes escritos a mano, US-08 se queda sin mecanismo de detección y hay que rediseñarla entera; con B4.4 construido, la degradación es "el dueño aprieta un botón" en vez de "no tenemos takeover". Es una historia S que convierte el riesgo más grave del backlog en un riesgo acotado.
>
> **B4.5 y B4.6 son pasos nuevos del recorrido**, no huecos: el mapa anterior no representaba que la PyME opera desde la app nativa de Google Calendar, que es literalmente la mitad de "sin cambiar tus herramientas". Están cubiertos, pero conviene que estén dibujados: son el único lugar donde el producto se cruza con el actor *Profesional*.

---

## B5 · Supervisar la operación — *owner / staff*

| Paso | Historia | Cobertura | Slice |
| :-- | :-- | :-: | :-- |
| B5.1 Ver la lista de chats con su estado, turno y monto (UC-2.7) | [US-14](../stories/US-14-panel-logs-y-estado.md) (AC-14.1) | ✅ | MVP |
| B5.2 No ver jamás datos de otro tenant | [US-14](../stories/US-14-panel-logs-y-estado.md) (AC-14.2) | ✅ | MVP |
| B5.3 Abrir el detalle con el historial completo de mensajes | [US-14](../stories/US-14-panel-logs-y-estado.md) (AC-14.4) | 🔴 [H-10](05-huecos-y-trazabilidad.md#h-10) | MVP |
| B5.4 Saltar al evento en Google Calendar desde el detalle | [US-14](../stories/US-14-panel-logs-y-estado.md) (AC-14.3) | ✅ | MVP |
| B5.5 Ver que una conversación está en pausa por un humano | [US-14](../stories/US-14-panel-logs-y-estado.md) (AC-14.4) · [US-08](../stories/US-08-pausa-intervencion-humana.md) (AC-08.4) | ✅ | MVP |
| B5.6 Marcar si el cliente asistió o no | [US-17](../stories/US-17-registro-de-asistencia.md) (AC-17.1/2) | 🔴 [H-11](05-huecos-y-trazabilidad.md#h-11) | MVP |
| B5.7 Ver los leads en mi propia planilla | [US-13](../stories/US-13-leads-a-google-sheets.md) | ⚠️ | MVP |
| B5.8 Ver métricas de conversión y ausentismo en el panel (UC-2.6) | — | 🕳️ | v2 |

> **B5.3 no tiene dónde leer lo que promete.** AC-14.4 dice *"veo esa marca junto con el historial completo de mensajes"*, pero en el modelo de datos **no existe tabla de mensajes**. `conversations` guarda `current_state`, `context_data JSON` y `last_interaction_at`: el estado actual, no la conversación. Sin una tabla `messages`, el panel puede listar chats pero no mostrar ninguno.
>
> Esto además refuerza la recomendación que la propia US-14 hace: descopear el MVP a **una lista de solo lectura** y dejar la vista de detalle rica para después. El modelo de datos ya empuja en esa dirección.
>
> **B5.8 va a v2 como panel, pero el dato crudo es MVP** — es B5.6/US-17. Medir a mano sobre una planilla es aceptable con tres pilotos; no tener el dato no lo es.

---

## B6 · Resolver incidentes — *owner*

| Paso | Historia | Cobertura | Slice |
| :-- | :-- | :-: | :-- |
| B6.1 Que mi cliente nunca quede sin respuesta | [US-09](../stories/US-09-fallback-ante-caidas.md) (AC-09.4) | ✅ | MVP |
| B6.2 Enterarme de que una integración se cayó | [US-09](../stories/US-09-fallback-ante-caidas.md) (AC-09.2) | ⚠️ | MVP |
| B6.3 Enterarme de que un recordatorio no salió | — | 🕳️ [H-08](05-huecos-y-trazabilidad.md#h-08) | MVP |
| B6.4 Reconectar la cuenta caída sin llamar a soporte | — | 🕳️ | v2 |
| B6.5 Personalizar el mensaje de cortesía | [US-15](../stories/US-15-configurar-reglas-del-negocio.md) (AC-15.5) | 🔴 [H-09](05-huecos-y-trazabilidad.md#h-09) | MVP |

> **B6.2 es `parcial`:** AC-09.2 dice *"marca en el panel que hay que reconectar la cuenta"* y `integrations.status` ya admite `'expired'`, pero **ninguna historia construye la vista que muestra esa marca**. US-14 lista conversaciones, no integraciones. El dato se escribe y nadie lo lee.
>
> **B6.4 se difiere a v2 con fundamento:** durante los pilotos reconectamos nosotros. Vence cuando el volumen de altas supere lo que soporta el soporte manual — el mismo umbral que B1.1.

---

## Resumen del recorrido

| Actividad | Pasos | Cubiertos | Parciales | Huecos |
| :-- | :-: | :-: | :-: | :-: |
| B1 Dar de alta | 4 | — | 2 | 2 |
| B2 Conectar integraciones | 6 | 3 | 2 | 1 |
| B3 Configurar el negocio | 7 | 3 | — | 4 (3 🔴) |
| B4 Atender el día a día | 8 | 2 | 3 | 3 |
| B5 Supervisar | 8 | 4 | 1 | 3 (2 🔴) |
| B6 Resolver incidentes | 5 | 1 | 1 | 3 (1 🔴) |
| **Total** | **38** | **13** | **9** | **16** |

**El diagnóstico del mapa anterior se confirma y se agrava.** Ahí decía que las historias *"cubren bien el recorrido del cliente final y mal el de la PyME"*: 26 de 44 pasos cubiertos del lado del cliente contra **13 de 38** del lado de la PyME. Y la diferencia ya no está solo en el backlog sino en el modelo de datos — B3 y B5, las dos actividades donde la PyME obtiene autonomía, concentran tres de los cinco huecos bloqueantes del proyecto ([H-09](05-huecos-y-trazabilidad.md#h-09), [H-10](05-huecos-y-trazabilidad.md#h-10), [H-11](05-huecos-y-trazabilidad.md#h-11)).
