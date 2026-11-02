# US-21 · Silenciar el bot con un botón

| | |
| :-- | :-- |
| **Sección** | 3 — Convivir con el equipo humano |
| **Prioridad** | Sprint 3, **antes** de [US-08](US-08-pausa-intervencion-humana.md) ([slices](../stories-maps/04-slices-y-secuencia.md)) |
| **Estimación** | **S** |
| **Trazabilidad** | **sin RF** · UC-2.10 de [`03-casos-de-uso-equipo-pyme.md`](../03-flujos/03-casos-de-uso-equipo-pyme.md) |
| **Origen** | Hueco [H-06](../stories-maps/05-huecos-y-trazabilidad.md#h-06) del story map |
| **Depende de** | [US-03](US-03-maquina-de-estados-conversacional.md) |
| **Bloquea a** | — · **cubre el riesgo de** [US-08](US-08-pausa-intervencion-humana.md) |

## Historia

> **Como** dueña de PyME que va a atender personalmente a un cliente,
> **quiero** silenciar el bot en esa conversación con un botón,
> **para** no depender de que el sistema adivine que estoy atendiendo yo.

## Criterios de aceptación

**AC-21.1 — Pausar desde el panel**
> **Dado que** abro una conversación en el panel,
> **cuando** presiono "pausar el bot",
> **entonces** el bot deja de responder a ese cliente y la conversación queda marcada como pausada, con el tiempo restante a la vista.

**AC-21.2 — Reactivar antes de tiempo**
> **Dado que** una conversación está pausada y ya terminé de atenderla,
> **cuando** presiono "reactivar",
> **entonces** el bot retoma de inmediato desde el estado en que había quedado, sin esperar a que venza la pausa.

**AC-21.3 — La pausa vence sola**
> **Dado que** pausé una conversación y no la reactivé,
> **cuando** vence la ventana configurada,
> **entonces** el bot retoma automáticamente, igual que en una pausa detectada.

**AC-21.4 — Queda registrado quién la pausó**
> **Dado que** una conversación fue pausada desde el panel,
> **cuando** la abro,
> **entonces** veo quién la pausó y cuándo, distinguible de una pausa detectada automáticamente.

## Requisitos técnicos

* Escribir la misma llave `pause:tenant:{tenant_id}:phone:{user_phone}` en Redis que consume el `HumanDetector`, con TTL, agregando en el valor el origen (`manual` o `detected`) y el usuario que la creó (AC-21.1, AC-21.4).
* Endpoint de pausa y de reactivación sobre la conversación, con el aislamiento por `tenant_id` del panel y verificación de que la conversación pertenece al tenant del usuario.
* Borrar la llave al reactivar y devolver la conversación al flujo automático desde `conversations.current_state`, que nunca se modificó (AC-21.2).
* Exponer el TTL restante al panel, compartido con AC-08.4 de [US-08](US-08-pausa-intervencion-humana.md) (AC-21.1).
* Leer la duración de la pausa de la configuración del tenant en vez de fijarla en 60 minutos. ⚠️ Depende de la tabla de [US-15](US-15-configurar-reglas-del-negocio.md).
* Auditar la acción con usuario, conversación y timestamp (AC-21.4). ⚠️ El destino del log estructurado no está definido.
* ⚠️ **Decisión abierta — ¿existe una pausa global del tenant?** UC-2.10 dice *"Forzar Pausa o Reactivación del Bot"* sin aclarar el alcance. Una pausa global es útil el día que el equipo prefiere atender todo a mano, y es peligrosa si alguien la deja activa y las consultas quedan sin responder. Si entra, necesita un aviso visible y probablemente un vencimiento más corto.

## Estimación de complejidad

**S** — Dos endpoints y una llave de Redis que ya está diseñada para esto. La única pieza nueva del lado del panel son dos botones sobre la vista de conversación.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ⚠️ | Necesita el estado de US-03 y una vista mínima de conversación. |
| **Negociable** | ✅ | El alcance global o por conversación, la duración y la ubicación del botón están abiertos. |
| **Valiosa** | ✅ | Doble: le da control explícito al dueño y **es la cobertura del riesgo más grave del backlog**. |
| **Estimable** | ✅ | No depende de ninguna incógnita externa, a diferencia de US-08. |
| **Pequeña** | ✅ | Un día de trabajo. |
| **Testeable** | ✅ | Los cuatro criterios se verifican desde el panel con un teléfono de prueba. |

**Veredicto:** cumple 5 de 6.

## Por qué es urgente (o crítica)

**Es el plan B de la historia con más valor comercial y más incertidumbre técnica del backlog.** [US-08](US-08-pausa-intervencion-humana.md) es la objeción número uno en la mesa de venta y depende entera de un supuesto sin probar: que Meta entregue por webhook los mensajes que el operador escribe a mano. Si el spike sale mal, US-08 se queda sin mecanismo de detección y hay que rediseñarla de cero.

Con esta historia construida, esa falla deja de ser un agujero y pasa a ser una degradación: en vez de "no tenemos takeover", el producto tiene "el dueño aprieta un botón". Es una historia **S** que convierte el riesgo más grave del MVP en un riesgo acotado, y por eso va **antes** que US-08 y no después.

Además cubre un caso que la detección automática nunca va a cubrir: el dueño que **sabe de antemano** que va a atender él, y quiere silenciar el bot antes de escribir el primer mensaje.

## Qué esperamos aprender

* Si los dueños prefieren el control explícito o la detección automática. Si usan el botón más de lo que esperábamos, US-08 puede bajar de prioridad y ahorrarnos su complejidad entera.
* Cuánto dura en la práctica una intervención humana — el dato que calibra el TTL de 60 minutos que RF-A4 fija sin evidencia.

**Señal de éxito:** al menos un uso real por piloto en dos semanas. Si nadie lo toca, la detección automática es efectivamente la única vía y US-08 sube de criticidad.

## De qué depende

* [US-03](US-03-maquina-de-estados-conversacional.md) — conversación con estado.
* Llave de pausa en Redis, compartida con [US-08](US-08-pausa-intervencion-humana.md) y [US-20](US-20-derivar-a-una-persona.md).
* Vista mínima de conversación en el panel.
* **Decisión abierta:** si existe pausa global del tenant además de la pausa por conversación.
