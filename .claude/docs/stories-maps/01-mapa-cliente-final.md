# Mapa 1 — Recorrido del cliente final (WhatsApp)

**Perspectiva:** persona que le escribe al número del negocio para cotizar y sacar un turno.
**Fuentes del backbone:** [`03-flujos/02-casos-de-uso-cliente-final.md`](../03-flujos/02-casos-de-uso-cliente-final.md) · [`03-flujos/04-flujo-whatsapp-cliente-final.md`](../03-flujos/04-flujo-whatsapp-cliente-final.md) · [`03-flujos/05-estados-flujo-conversacional.md`](../03-flujos/05-estados-flujo-conversacional.md) · [`03-flujos/06-secuencia-agenda-cotizacion-e2e.md`](../03-flujos/06-secuencia-agenda-cotizacion-e2e.md)
**Leyenda de cobertura:** `✅ cubre` · `⚠️ parcial` · `🕳️ HUECO` · `🔴 hueco bloqueante` (falta el soporte de datos, no solo la historia)

```
A1 Iniciar    A2 Describir   A3 Recibir     A4 Elegir     A5 Confirmar   A6 Recibir      A7 Responder   A8 Asistir   A9 Cuando algo
   contacto  →   necesidad  →   cotización →   horario   →   la reserva →   recordatorio →   al aviso   →   al turno →   sale del carril
   IDLE         GATHERING_     CALCULATING_   SELECTING_    SLOT_SELECTED   (scheduler)     CONFIRMED /     (post-turno)   PAUSED_HUMAN /
                PARAMS         QUOTE          SLOT          → BOOKED                        RESCHEDULED /                  ERROR_FALLBACK
                                                                                            CANCELLED
```

> El backbone anterior ([`01-producto/04-user-story-map.md`](../01-producto/04-user-story-map.md)) terminaba en A8. Se agrega **A9** porque el diagrama de casos de uso del cliente final tiene un módulo entero —"4. Control Manual y Resguardo"— que el mapa previo no representaba como actividad, sino que repartía entre otras.

---

## A1 · Iniciar contacto

| Paso | Historia | Cobertura | Slice |
| :-- | :-- | :-: | :-- |
| A1.1 Escribir por primera vez al número del negocio | [US-01](../stories/US-01-handshake-webhook-meta.md) · [US-02](../stories/US-02-ingesta-asincrona-rafaga.md) | ✅ | MVP |
| A1.2 Recibir la bienvenida con el nombre del negocio y las opciones | [US-16](../stories/US-16-primer-contacto-bienvenida.md) (AC-16.1) | ✅ | MVP |
| A1.3 Avanzar tocando un botón, sin escribir | [US-16](../stories/US-16-primer-contacto-bienvenida.md) (AC-16.2) | ✅ | MVP |
| A1.4 Escribir fuera del horario de atención y saber cuándo atienden | [US-16](../stories/US-16-primer-contacto-bienvenida.md) (AC-16.4) | ✅ | MVP |
| A1.5 Reiniciar la conversación desde cualquier punto | [US-16](../stories/US-16-primer-contacto-bienvenida.md) (AC-16.3) | ✅ | MVP |

> A1.4 depende de que exista el horario de atención cargado, y hoy **no hay tabla donde vivan esos horarios** (ver [H-09](05-huecos-y-trazabilidad.md#h-09)). US-16 y US-15 son ambas MVP, pero US-15 no tiene dónde persistir.

---

## A2 · Describir la necesidad — `GATHERING_PARAMS`

| Paso | Historia | Cobertura | Slice |
| :-- | :-- | :-: | :-- |
| A2.1 Responder las preguntas de parámetros | [US-03](../stories/US-03-maquina-de-estados-conversacional.md) · [US-12](../stories/US-12-cotizador-parametrico.md) (AC-12.2) | ✅ | MVP |
| A2.2 Retomar la conversación después de abandonarla | [US-03](../stories/US-03-maquina-de-estados-conversacional.md) (AC-03.1) | ⚠️ | MVP |
| A2.3 Que un texto inesperado no rompa el flujo | [US-03](../stories/US-03-maquina-de-estados-conversacional.md) (AC-03.4) | ✅ | MVP |
| A2.4 Que un botón viejo no ejecute la acción vieja | [US-03](../stories/US-03-maquina-de-estados-conversacional.md) (AC-03.2) | ✅ | MVP |
| A2.5 Hacer una pregunta libre que el bot no sabe contestar | [US-03](../stories/US-03-maquina-de-estados-conversacional.md) (AC-03.4) | 🕳️ [H-16](05-huecos-y-trazabilidad.md#h-16) | MVP |
| A2.6 Corregir un dato válido ya aceptado | — | 🕳️ | v2 |

> **A2.2 es `parcial` por una contradicción, no por alcance.** AC-03.1 dice que a las **dos horas** el bot retoma en el mismo estado y conserva los datos. La matriz de transiciones de [`05-estados-flujo-conversacional.md`](../03-flujos/05-estados-flujo-conversacional.md) dice `[IDLE] <── (Expiración 30 min Inactividad)`, o sea que a los **30 minutos** ya volvió a `IDLE` y perdió el contexto. Los dos no pueden ser ciertos. Ver [H-07](05-huecos-y-trazabilidad.md#h-07).
>
> **A2.5:** el flujo resuelve la pregunta libre con `UserClicksBook -- No / Pregunta libre --> PauseHuman`, es decir, delega en el takeover humano. Pero [US-08](../stories/US-08-pausa-intervencion-humana.md) solo se activa **si el operador responde**. Si nadie mira el teléfono, la persona queda con AC-03.4 repitiéndole qué se espera en ese paso. Es el camino más probable de abandono y nadie lo mide.

---

## A3 · Recibir la cotización — `CALCULATING_QUOTE`

| Paso | Historia | Cobertura | Slice |
| :-- | :-- | :-: | :-- |
| A3.1 Ver el precio estimado en el chat | [US-12](../stories/US-12-cotizador-parametrico.md) (AC-12.1) | ✅ | MVP |
| A3.2 Pasar de la cotización a la agenda con un botón | [US-12](../stories/US-12-cotizador-parametrico.md) (AC-12.3) · RF-A5 | ✅ | MVP |
| A3.3 Ser derivado a un asesor cuando el valor está fuera de rango | [US-12](../stories/US-12-cotizador-parametrico.md) (AC-12.4) | ⚠️ [H-05](05-huecos-y-trazabilidad.md#h-05) | MVP |
| A3.4 Pagar una seña para reservar | — | 🕳️ | v2 |

> **A3.3 deriva a un asesor al que nadie le avisa.** AC-12.4 dice "deriva la consulta a un asesor humano" y el flujo dice "Enviar mensaje de disculpa + Contacto con asesor", pero ninguna historia notifica al operador de que hay una consulta esperándolo. El panel de [US-14](../stories/US-14-panel-logs-y-estado.md) es una lista que alguien tiene que abrir por su cuenta. La derivación existe del lado del cliente y no existe del lado de la PyME.
>
> El estado `CALCULATING_QUOTE` es real en los flujos pero **no está en RF-A3**, que declara solo cuatro estados. Ver [C-02](05-huecos-y-trazabilidad.md#c-02) en la lista de contradicciones.

---

## A4 · Elegir horario — `SELECTING_SLOT`

| Paso | Historia | Cobertura | Slice |
| :-- | :-- | :-: | :-- |
| A4.1 Ver únicamente horarios realmente disponibles | [US-05](../stories/US-05-horarios-realmente-libres.md) (AC-05.1/2/3) | ✅ | MVP |
| A4.2 Navegar cuando hay más horarios que el límite de Meta | [US-05](../stories/US-05-horarios-realmente-libres.md) (AC-05.4) | ✅ | MVP |
| A4.3 Ver la hora en la zona horaria correcta | [US-07](../stories/US-07-hora-correcta-timezones.md) *(transversal)* | ✅ | MVP |
| A4.4 Recibir una respuesta útil cuando **no hay ningún** horario libre | — | 🕳️ [H-03](05-huecos-y-trazabilidad.md#h-03) | MVP |
| A4.5 Elegir sucursal o profesional | — | 🕳️ | v2 |

> **A4.4 es un camino del flujo sin historia.** `HasSlots -- No --> NoSlotsMsg[Enviar mensaje de disculpa + Contacto con asesor]` está dibujado en [`04-flujo-whatsapp-cliente-final.md`](../03-flujos/04-flujo-whatsapp-cliente-final.md), pero los cuatro criterios de US-05 asumen que **siempre hay algo que ofrecer**. Con agenda llena —que es el escenario del cliente que más nos conviene— el sistema no tiene definido qué contesta.

---

## A5 · Confirmar la reserva — `SLOT_SELECTED` → `BOOKED`

| Paso | Historia | Cobertura | Slice |
| :-- | :-- | :-: | :-- |
| A5.1 Dar el nombre para cerrar la reserva | — | 🕳️ [H-02](05-huecos-y-trazabilidad.md#h-02) | MVP |
| A5.2 Que el horario elegido siga libre al confirmar | — | 🔴 [H-01](05-huecos-y-trazabilidad.md#h-01) | MVP |
| A5.3 Que se cree el evento con nombre, teléfono y monto | [US-06](../stories/US-06-reservar-y-liberar-sync-inversa.md) (AC-06.1) | ✅ | MVP |
| A5.4 Recibir el comprobante por WhatsApp | [US-06](../stories/US-06-reservar-y-liberar-sync-inversa.md) (AC-06.1) | ✅ | MVP |
| A5.5 Quedar registrado como lead en la planilla | [US-13](../stories/US-13-leads-a-google-sheets.md) (AC-13.1/2) | ⚠️ | MVP |
| A5.6 Que la reserva no quede a medias si falla un paso intermedio | — | 🕳️ [H-04](05-huecos-y-trazabilidad.md#h-04) | MVP |

> **A5.1 — tres documentos dicen tres cosas sobre dónde se pide el nombre.** El estado `SLOT_SELECTED` está descrito como *"El usuario eligió un slot y el sistema solicita el nombre"*, y la secuencia e2e confirma el orden (`Transicion a SLOT_SELECTED (Solicitar Nombre)` en el paso 45, después de elegir el horario). Pero AC-03.1 de US-03 arranca con *"habiendo dado ya mi nombre"* estando en `GATHERING_PARAMS`, y AC-13.1 de US-13 escribe la fila del lead con el nombre **al salir de `GATHERING_PARAMS`** — o sea, antes de que el usuario lo haya dado según los flujos. Si el nombre se pide al final, US-13 escribe una fila con el nombre vacío.
>
> **A5.2 es el riesgo más subestimado del mapa.** El flujo tiene el nodo `CheckCollision{¿Slot sigue disponible?}` con su rama de conflicto, pero ninguna historia lo cubre: US-05 verifica disponibilidad **al listar** y US-06 crea el evento **al confirmar**, y entre esas dos cosas pasan los segundos en los que el cliente lee la lista y elige. Dos clientes mirando la misma lista es el caso normal en un negocio con demanda, y la promesa de US-05 es literalmente "no reservar uno que ya estaba ocupado".
>
> **A5.6 — la transacción distribuida no tiene compensación.** La secuencia e2e marca `critical Agendamiento Atomico` sobre tres pasos: crear el evento en Google, insertar en `bookings`, encolar el job de Sheets. Si el `INSERT` falla después de crear el evento, queda un evento en el calendario del cliente que el sistema no conoce: no genera recordatorio, no se puede cancelar desde el chat, y el horario figura ocupado sin turno asociado. US-09 cubre "la API falla" pero no "la API respondió bien y fallamos nosotros después".

---

## A6 · Recibir el recordatorio

| Paso | Historia | Cobertura | Slice |
| :-- | :-- | :-: | :-- |
| A6.1 Recibir el aviso 24 horas antes, con botones | [US-10](../stories/US-10-recordatorio-t24h.md) (AC-10.1) | ✅ | MVP |
| A6.2 No recibir duplicados ni avisos fuera de ventana | [US-10](../stories/US-10-recordatorio-t24h.md) (AC-10.4) | ✅ | MVP |
| A6.3 Ver la hora del turno en zona local, nunca en UTC | [US-07](../stories/US-07-hora-correcta-timezones.md) (AC-07.4) | ✅ | MVP |
| A6.4 Recibir el segundo aviso 2 horas antes | [US-11](../stories/US-11-reagendar-y-aviso-t2h.md) (AC-11.4) | ⚠️ *sin RF* | MVP condicionado |
| A6.5 Que el aviso llegue igual si el primer intento de envío falla | [US-09](../stories/US-09-fallback-ante-caidas.md) (AC-09.3) | ⚠️ [H-08](05-huecos-y-trazabilidad.md#h-08) | MVP |

> **A6.5:** `notification_logs.status` admite el valor `'failed'`, así que el modelo de datos ya anticipa que un recordatorio puede no salir. AC-09.3 cubre el reintento con backoff ante rate limit de Meta, pero **nadie se entera del fallo definitivo**: no hay alerta, no hay vista, no hay reintento manual. El ausentismo —el número con el que se cierra la venta— depende de que ese mensaje llegue.

---

## A7 · Responder al recordatorio

| Paso | Historia | Cobertura | Slice |
| :-- | :-- | :-: | :-- |
| A7.1 Confirmar la asistencia | [US-10](../stories/US-10-recordatorio-t24h.md) (AC-10.2) → `CONFIRMED` | ✅ | MVP |
| A7.2 Cancelar y que el horario se libere | [US-10](../stories/US-10-recordatorio-t24h.md) (AC-10.3) → `CANCELLED` | ✅ | MVP |
| A7.3 Re-agendar sin repetir datos ni cotización | [US-11](../stories/US-11-reagendar-y-aviso-t2h.md) (AC-11.1/2) → `RESCHEDULED` | ✅ | MVP |
| A7.4 Abandonar el re-agendamiento sin perder el turno original | [US-11](../stories/US-11-reagendar-y-aviso-t2h.md) (AC-11.3) | ✅ | MVP |
| A7.5 No responder nada y que eso también quede registrado | [US-17](../stories/US-17-registro-de-asistencia.md) (AC-17.4) | ✅ | MVP |

> A7.3 vuelve a pasar por A4 y A5 completas, incluida la colisión de A5.2 — el re-agendamiento hereda todos los huecos de la reserva original. Es un argumento a favor de resolver [H-01](05-huecos-y-trazabilidad.md#h-01) antes que US-11.

---

## A8 · Asistir al turno

| Paso | Historia | Cobertura | Slice |
| :-- | :-- | :-: | :-- |
| A8.1 Presentarse en el local | *(fuera del sistema)* | — | — |
| A8.2 Que quede registrado si asistió o no | [US-17](../stories/US-17-registro-de-asistencia.md) | 🔴 [H-11](05-huecos-y-trazabilidad.md#h-11) | MVP |
| A8.3 Correlacionar la asistencia con la respuesta al recordatorio | [US-17](../stories/US-17-registro-de-asistencia.md) (AC-17.4) | ✅ | MVP |

> **A8.2 pasó de hueco de backlog a hueco de datos.** US-17 lo cubre como historia, pero `bookings.status` es `ENUM('scheduled','confirmed','rescheduled','cancelled')`: **no admite `attended` ni `no_show`**. La historia mejor puntuada del backlog (5/6 INVEST, estimada S) no tiene hoy dónde escribir su resultado. Es una línea de migración, pero hasta que se escriba, el KPI que sostiene el modelo de precios sigue sin instrumento.

---

## A9 · Cuando algo se sale del carril

| Paso | Historia | Cobertura | Slice |
| :-- | :-- | :-: | :-- |
| A9.1 Recibir un mensaje de cortesía si un tercero se cae | [US-09](../stories/US-09-fallback-ante-caidas.md) (AC-09.1/4) → `ERROR_FALLBACK` | ✅ | MVP |
| A9.2 Que el bot se calle cuando responde una persona | [US-08](../stories/US-08-pausa-intervencion-humana.md) (AC-08.1) → `PAUSED_HUMAN` | ⚠️ *spike pendiente* | MVP |
| A9.3 Que el bot retome solo a los 60 minutos | [US-08](../stories/US-08-pausa-intervencion-humana.md) (AC-08.2) | ⚠️ *spike pendiente* | MVP |
| A9.4 Que la pausa sea de mi conversación y no del negocio entero | [US-08](../stories/US-08-pausa-intervencion-humana.md) (AC-08.3) | ✅ | MVP |
| A9.5 Que un humano se entere de que me derivaron | — | 🕳️ [H-05](05-huecos-y-trazabilidad.md#h-05) | MVP |

> A9.2 y A9.3 quedan `parcial` por el spike sin cerrar: no sabemos si Meta entrega por webhook los mensajes que el operador escribe a mano. El C4 nivel 3 modela el `HumanDetector` que **lee** la pausa en Redis, pero ningún componente la **escribe** — el hueco arquitectónico que US-08 ya señala sigue abierto. La mitigación natural está en los flujos y no en el backlog: ver [H-06](05-huecos-y-trazabilidad.md#h-06) (UC-2.10, pausar el bot a mano desde el panel).

---

## Resumen del recorrido

| Actividad | Pasos | Cubiertos | Parciales | Huecos |
| :-- | :-: | :-: | :-: | :-: |
| A1 Iniciar contacto | 5 | 5 | — | — |
| A2 Describir la necesidad | 6 | 3 | 1 | 2 |
| A3 Recibir la cotización | 4 | 2 | 1 | 1 |
| A4 Elegir horario | 5 | 3 | — | 2 |
| A5 Confirmar la reserva | 6 | 2 | 1 | 3 |
| A6 Recibir el recordatorio | 5 | 3 | 2 | — |
| A7 Responder al aviso | 5 | 5 | — | — |
| A8 Asistir al turno | 3\* | 1 | — | 1 🔴 |
| A9 Fuera del carril | 5 | 2 | 2 | 1 |
| **Total** | **44** | **26** | **7** | **10** |

`*` A8.1 "presentarse en el local" queda fuera del sistema: no se cuenta en ninguna de las tres columnas.

**Dónde se concentra el riesgo:** A5 (confirmar la reserva) tiene 3 de los 10 huecos y es el momento exacto en el que el producto entrega el valor que cobramos. Los tres —colisión, nombre y atomicidad— salieron de leer los flujos y la secuencia, no el PRD: son pasos que el sistema ejecuta y que el backlog nunca escribió.
