# Estados y Máquina de Estados Conversacional

**Documento:** `docs/01-producto/05-estados-flujo-conversacional.md`  
**Módulo:** Engine Conversacional WhatsApp (Laravel State Machine + Redis)  
**Propósito:** Especificar la arquitectura de la máquina de estados, transiciones deterministas, estructura del payload temporal (`context_data`) y políticas de expiración (timeouts).

---

## 1. Arquitectura de la Máquina de Estados

La gestión de la conversación opera como una **Máquina de Estados Finitos (FSM)** determinista, persistida en la tabla `conversations` de MySQL y acelerada con Redis para consultas en sub-milisegundos.

* **Persistencia Principal:** Tabla `conversations` (`current_state`, `context_data`, `last_interaction_at`).
* **Cache & Locks de Estado:** Redis key `session:tenant:{tenant_id}:phone:{user_phone}` para resolución ultrarrápida durante la ingesta del Webhook.
* **Control de Human Takeover:** Redis key `pause:tenant:{tenant_id}:phone:{user_phone}` con TTL dinámico (60 minutos).

---

## 2. Catálogo Completo de Estados

| Estado | Nombre | Descripción / Responsabilidad | Tipo de Interacción |
| :--- | :--- | :--- | :--- |
| `IDLE` | Reposo / Inicio | Estado por defecto. El usuario no tiene un flujo activo en curso. | En espera de mensaje inicial |
| `GATHERING_PARAMS` | Recolección de Parámetros | El bot está solicitando las variables numéricas o de selección para cotizar. | Pregunta/Respuesta interactiva |
| `CALCULATING_QUOTE` | Cotización Calculada | El presupuesto ha sido calculado y presentado con el botón de agendamiento. | Mensaje con Botón Quick Reply |
| `SELECTING_SLOT` | Selección de Horario | Se desplegó la lista interactiva con huecos libres devueltos por Google Calendar. | Mensaje de Lista Interactiva |
| `SLOT_SELECTED` | Horario Reservado Temporal | El usuario eligió un slot y el sistema solicita el nombre para confirmar. | Espera de texto (Nombre) |
| `BOOKED` | Cita Confirmada | El turno ha sido creado en Google Calendar, DB y Google Sheets. | Mensaje de Confirmación Final |
| `CONFIRMED` | Asistencia Confirmada | El usuario presionó "Confirmar" en el recordatorio a $t-24\text{ hs}$. | Estado pasivo de auditoría |
| `RESCHEDULED` | En Re-agendamiento | El cliente pidió mover su turno. **La cita previa NO se anula acá** (ver §5); el flujo retoma la selección de un nuevo horario. | Transición automática a `SELECTING_SLOT` |
| `CANCELLED` | Cita Cancelada | Cita anulada a pedido del usuario o borrada desde Google Calendar. | Estado final de la sesión |
| `PAUSED_HUMAN` | Intervención Humana | Bot pausado por respuesta manual del operador desde el celular. | Silencio automático del bot |
| `ERROR_FALLBACK` | Excepción Controlada | Ocurrió un fallo en APIs externas; se envió el mensaje de cortesía. | Mensaje de Fallback |

> **⚠️ Un estado queda fuera del MVP recortado:** `CALCULATING_QUOTE` cae con el cotizador (T-016, T-040 y T-041 diferidos). **El MVP tiene diez estados**, no once ni nueve. `RESCHEDULED` volvió el 2026-08-21 al construirse T-042, con sus transiciones en el mismo cambio.

---

## 3. Matriz de Transiciones de Estado

```text
[IDLE] ────(Mensaje Inicial)────> [GATHERING_PARAMS] ────(Parámetros Completos)────> [CALCULATING_QUOTE]
  │                                                                                         │
  │                                                                                  (Clic 'Agendar')
  │                                                                                         ▼
[PAUSED_HUMAN] <────(Mensaje Manual Operador)────── [Cualquier Estado] ───────────────> [SELECTING_SLOT]
  │                                                                                         │
  └────(TTL 60 min expira)────> [IDLE] <────(Expiración 30 min Inactividad)─────────────────┤ (Selecciona Slot)
                                                                                            ▼
[BOOKED] <────(Creación Exitosa)──── [SLOT_SELECTED] <───(Verificación Slot OK)─────────────┘
  │
  ├────(Cron t-24h: Clic 'Confirmar')────> [CONFIRMED]
  ├────(Cron t-24h: Clic 'Re-agendar')───> [RESCHEDULED] ───> [SELECTING_SLOT]
  └────(Cron t-24h: Clic 'Cancelar')─────> [CANCELLED]

[Cualquier Estado] ──(Falla de un tercero)──> [ERROR_FALLBACK] ──(libera lo reservado)──> [IDLE]
```

## 4. La pausa humana **conserva el estado** — corrección (T-018b)

⚠️ **La matriz de arriba dice `[PAUSED_HUMAN] --(TTL 60 min expira)--> [IDLE]`, y eso contradice a los criterios de aceptación.**

- **AC-08.2:** *"el bot retoma la conversación automáticamente **desde el estado en que quedó**"*, y su requisito técnico lo dice sin ambigüedad: *"conservar `conversations.current_state` intacto durante la pausa"*.
- **AC-21.2:** *"al presionar reactivar, el bot retoma de inmediato **desde el estado en que había quedado**"*.

**Ganan los criterios.** Mandar a `IDLE` significaría que un cliente a mitad de una reserva, al que el operador le contestó una duda, **pierde el flujo entero** y empieza de nuevo — exactamente la fricción que el producto viene a eliminar.

### Cómo quedó implementado

La pausa **no es un estado, es una capa**: vive en la llave de Redis `pause:tenant:{id}:phone:{phone}` con TTL de 60 minutos, y se consulta antes de responder. `current_state` no se toca.

| Situación | Qué pasa |
| :-- | :-- |
| Llega un mensaje con la pausa activa | Se **guarda** (AC-08.1) pero el bot no responde, y el TTL se refresca |
| El operador reactiva desde el panel | La llave se borra y el bot sigue desde donde estaba |
| Nadie reactiva | La llave vence sola a los 60 minutos y el estado sigue intacto |
| La conversación queda inactiva 30 min | **No expira si está pausada:** un humano la está atendiendo |

⚠️ **`PAUSED_HUMAN` queda declarado en el enum de estados pero no se usa en el camino normal.** Es deliberado —el catálogo lo nombra y T-025 lo va a querer mostrar en el panel— y está marcado en el código para que no parezca un olvido.

---

## 5. `ERROR_FALLBACK` — comportamiento definido (T-007)

Este estado figuraba en el catálogo y **no aparecía en la matriz**: nadie había definido cómo se entra ni cómo se sale. Quedó así:

| Pregunta | Respuesta |
| :-- | :-- |
| ¿Desde dónde se entra? | **Desde cualquier estado**, ante un fallo de Google o de Meta |
| ¿Qué recibe el cliente? | El texto de *fallback* configurado por el tenant (`business_settings.fallback_message`, RNF-03) |
| ¿Qué pasa con lo reservado a medias? | **Se libera.** El horario vuelve a estar disponible |
| ¿A dónde va la conversación? | A **`IDLE`**. El cliente empieza de nuevo |
| ¿Se reintenta? | **No.** No hay reintento silencioso desde este estado |

**Por qué liberar y no conservar.** Conservar el horario mientras se reintenta daría mejor experiencia si el tercero vuelve rápido, pero exige un mecanismo de **reserva temporal con TTL** que hoy no existe —es la decisión abierta de US-18— y, sobre todo, **un horario bloqueado por un turno que nunca se completó es exactamente el hueco H-04** (agendamiento sin compensación). Se prefirió que el cliente repita la elección antes que dejar la agenda de la PyME con huecos fantasma que nadie puede liberar.

⚠️ **Consecuencia para T-018:** esta transición es un comodín, igual que las de `PAUSED_HUMAN` e inactividad. Son **una transición por estado de origen**, no una sola — y es lo que hace que la superficie real de la máquina sea de ~22 transiciones y no ~6. Ver ese documento *(documento borrado por innecesario el 2026-08-21)*.

---

## 5. `RESCHEDULED` **no anula la cita previa** — corrección (T-042)

⚠️ **La tabla de §2 decía «cita previa anulada» y eso contradice a los criterios de aceptación de T-042.** Es el mismo tipo de error que ya se corrigió en §4 para la pausa humana: el catálogo describe una transición que los AC prohíben.

- **T-042, criterio 4:** *«El turno nuevo se crea **antes** de borrar el viejo: no hay ventana en la que el cliente se quede sin ninguno.»*
- **AC-11.3:** *«Abandonando el re-agendamiento sin elegir horario, pasados 30 minutos **el turno original sigue vigente**.»*

Anular primero produce el peor caso que este flujo puede producir: el cliente pide mover su turno, la agenda no tiene ningún otro horario que le sirva —o se distrae y no contesta—, y **se queda sin ninguno habiendo tenido uno**. Un cliente que perdió su turno por usar el botón que le ofrecimos no vuelve a usarlo.

**El comportamiento implementado es este:**

1. Al tocar «Re-agendar» la conversación pasa a `RESCHEDULED` y **el turno original no se toca**: sigue `scheduled` (o `confirmed`) y su evento sigue en el calendario.
2. `ParametrosCompletos` lleva a `SELECTING_SLOT` — se reusa ese evento y no uno propio porque en un re-agendamiento los datos **ya están juntos**: salen del turno que se está moviendo, y volver a pedirlos es lo que AC-11.1 prohíbe.
3. Recién cuando el turno nuevo está creado en Google **y persistido en `bookings`**, se borra el evento viejo y su fila pasa a `rescheduled`.

`rescheduled` y no `cancelled` a propósito: un turno cancelado es un turno perdido y uno re-agendado es un turno conservado, y el argumento de venta los cuenta distinto (AC-17.4).

⚠️ Si el borrado del evento viejo falla, **el turno nuevo queda igual** y el desalineado se registra para la conciliación de T-030. Deshacer el turno nuevo sería dejar sin turno a alguien que ya recibió su confirmación.
