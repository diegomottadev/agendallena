# US-18 · Que no me den un horario que otro acaba de tomar

| | |
| :-- | :-- |
| **Sección** | 2 — Probar la promesa central |
| **Prioridad** | Sprint 4, pegada a [US-06](US-06-reservar-y-liberar-sync-inversa.md) ([slices](../stories-maps/04-slices-y-secuencia.md)) |
| **Estimación** | **M** |
| **Trazabilidad** | **sin RF** · nodo `CheckCollision` de [`04-flujo-whatsapp-cliente-final.md`](../03-flujos/04-flujo-whatsapp-cliente-final.md) |
| **Origen** | Hueco [H-01](../stories-maps/05-huecos-y-trazabilidad.md#h-01) del story map |
| **Depende de** | [US-05](US-05-horarios-realmente-libres.md) · [US-06](US-06-reservar-y-liberar-sync-inversa.md) |
| **Bloquea a** | [US-11](US-11-reagendar-y-aviso-t2h.md) *(hereda el mismo camino)* |

## Historia

> **Como** cliente final que eligió un horario de la lista,
> **quiero** que el sistema verifique que sigue libre antes de confirmármelo,
> **para** no recibir una confirmación de un turno que otra persona acaba de tomar.

## Criterios de aceptación

**AC-18.1 — El horario se ocupó mientras yo leía**
> **Dado que** recibí la lista de horarios y el de las 10:00 se ocupó antes de que yo eligiera,
> **cuando** lo selecciono,
> **entonces** el bot me avisa que ya no está disponible y me muestra la lista actualizada, sin pedirme de nuevo mis datos.

**AC-18.2 — Dos clientes eligen el mismo horario a la vez**
> **Dado que** dos personas tienen abierta la misma lista y eligen el mismo horario con segundos de diferencia,
> **cuando** ambas confirman,
> **entonces** solo una obtiene el turno y la otra recibe el aviso de colisión con horarios nuevos: nunca se crean dos eventos superpuestos.

**AC-18.3 — La reserva temporal se libera sola**
> **Dado que** elegí un horario y no completé la confirmación,
> **cuando** pasa la ventana de reserva temporal,
> **entonces** ese horario vuelve a ofrecerse a otros clientes sin intervención manual.

**AC-18.4 — También al re-agendar**
> **Dado que** estoy re-agendando desde el recordatorio,
> **cuando** elijo un horario que se ocupó,
> **entonces** recibo el mismo aviso de colisión y mi turno original sigue vigente.

## Requisitos técnicos

* Tomar una reserva temporal del slot con `SET NX` sobre la llave Redis `slot:tenant:{tenant_id}:{start_utc}` y TTL corto, en el momento en que el cliente selecciona el ítem de la lista (AC-18.2, AC-18.3).
* Re-consultar `freeBusy` para esa franja **inmediatamente antes** de crear el evento: la reserva en Redis protege de nuestros propios clientes concurrentes, no de un evento que la PyME acaba de crear a mano en su calendario (AC-18.1).
* Manejar el conflicto devuelto por Google al crear el evento como camino esperado, no como excepción.
* Liberar la llave al confirmar la reserva, al cancelar y al expirar el TTL (AC-18.3).
* Volver a `SELECTING_SLOT` conservando `context_data` al detectar la colisión, para no repreguntar parámetros ni recalcular la cotización (AC-18.1).
* Garantizar idempotencia por `message_id`: un doble toque del mismo cliente sobre el ítem no puede producir dos eventos.
* Reusar el mismo camino desde el reingreso por recordatorio de [US-11](US-11-reagendar-y-aviso-t2h.md) (AC-18.4).
* ⚠️ **Decisión abierta — cuándo se toma la reserva temporal.** Al **enviar la lista** deja bloqueados diez horarios por cada cliente que abre el chat y no reserva; al **elegir el ítem** la ventana es mínima pero real. La segunda parece razonable y no está medida cuánto dura en la práctica el tramo entre elegir y confirmar.
* ⚠️ **Decisión abierta — duración del TTL.** Depende de cuánto tarda un cliente entre elegir el horario y mandar su nombre, dato que no tenemos hasta el primer piloto.

## Estimación de complejidad

**M** — La reserva temporal y el relistado son acotados. Lo que agrega tamaño es que hay dos fuentes de colisión distintas —otro cliente nuestro y la PyME editando su calendario— y cada una se detecta en un lugar distinto del flujo.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ⚠️ | Necesita disponibilidad (US-05) y reserva real (US-06) para tener qué proteger. |
| **Negociable** | ✅ | El mecanismo de reserva temporal, su duración y el momento en que se toma están todos abiertos. |
| **Valiosa** | ✅ | Es la condición para que la promesa de [US-05](US-05-horarios-realmente-libres.md) —"no reservar uno que ya estaba ocupado"— sea verdadera y no solo probable. |
| **Estimable** | ⚠️ | El diseño está claro; el TTL correcto depende de un dato de comportamiento que todavía no tenemos. |
| **Pequeña** | ✅ | Entra en un sprint junto con US-06. |
| **Testeable** | ✅ | AC-18.2 se prueba con dos teléfonos y un script; es un test de concurrencia concreto. |

**Veredicto:** cumple 4 de 6. Las dos observaciones se resuelven con el primer piloto.

## Por qué es urgente (o crítica)

**Es el riesgo más subestimado del recorrido.** US-05 verifica disponibilidad *al listar* y US-06 crea el evento *al confirmar*; entre esas dos cosas pasan los segundos en los que el cliente lee la lista y decide. Dos personas mirando la misma lista no es un caso de borde: es el caso normal en un negocio con demanda, que es exactamente el cliente que queremos.

Y falla en el peor momento posible: el cliente recibe una confirmación, se organiza el día, y el turno no existe o pisa a otro. Eso obliga al equipo a hacer justo el trabajo manual que vinimos a eliminar, y destruye la confianza en el único momento en que el producto entrega valor.

## Qué esperamos aprender

* Cuánto tarda realmente un cliente entre elegir el horario y confirmar — el dato que define el TTL y que hoy es pura suposición.
* Con qué frecuencia ocurre la colisión de verdad. Si es marginal, el mecanismo puede simplificarse; si es frecuente, hay que replantear el tamaño de la lista o su vigencia.

**Señal de éxito:** cero eventos superpuestos en el calendario de los pilotos, y ninguna confirmación enviada por un turno que no se creó.

## De qué depende

* [US-05](US-05-horarios-realmente-libres.md) — disponibilidad consultable.
* [US-06](US-06-reservar-y-liberar-sync-inversa.md) — creación real del evento.
* Redis con llaves por `tenant_id` y expiración.
* **Decisiones abiertas:** momento de la reserva temporal y duración del TTL.
