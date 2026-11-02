# T-030 · Consistencia del agendamiento: idempotencia y conciliación

| | |
| :-- | :-- |
| **Prioridad** | 30 |
| **Sprint** | 4 |
| **Tipo** | Feature |
| **Historia** | [US-22](../../stories/US-22-reserva-consistente.md) |
| **Story points** | 5 |
| **Depende de** | T-026 |
| **Bloquea a** | — |
| **Pareto** | **NÚCLEO** · ola 4 · con recorte, ver *Fuera de alcance* |

## Contexto

El diagrama de secuencia marca `critical Agendamiento Atomico` sobre tres sistemas —Google Calendar, MySQL y la cola de Sheets— **sin ningún mecanismo que lo haga atómico**. La etiqueta describe una intención, no una garantía.

El modo de falla es silencioso y acumulativo: si el `INSERT` falla después de crear el evento, queda un turno que el sistema no conoce. No genera recordatorio, no se puede cancelar desde el chat, no aparece en el panel, y **bloquea el horario para todos los demás**.

## Alcance

* Clave de idempotencia determinística hacia Google al crear el evento.
* Reordenamiento: confirmación al cliente solo después de persistir.
* Job de conciliación entre eventos propios y filas de `bookings`.
* Aislamiento del volcado a Sheets fuera del camino crítico.

## Criterios de aceptación

- [ ] Si la escritura en base falla después de crear el evento, este no queda huérfano: se elimina o queda registrado para conciliación, y el cliente recibe el fallback *(AC-22.1)*.
- [ ] Ante cualquier fallo del agendamiento, el cliente **no** recibe un mensaje de confirmación de reserva *(AC-22.2)*.
- [ ] Un reintento del job no crea un segundo evento ni una segunda fila *(AC-22.3)*.
- [ ] Un fallo de Google Sheets no impide que el turno quede creado y confirmado *(AC-22.4)*.
- [ ] El job de conciliación detecta y reporta los desalineados entre calendario y base.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ✅ | ⚠️ | ✅ | ✅ | ✅ |

**V** en ⚠️: valor por ausencia de defecto, igual que T-028 y T-031. Nadie lo pide y todos lo sufren si falta. **E** es ✅ porque los criterios enumeran los modos de falla concretos, que es lo que convierte un alcance abierto en uno estimable.

## Fuera de alcance

La colisión entre dos clientes, que es T-029.

**Recorte Pareto.** Se difieren el job de conciliación y el aislamiento del volcado a Sheets *(AC-22.4)* — Sheets está fuera del núcleo. ⚠️ Elegir entre borrar el evento y conciliarlo después es la decisión abierta *«compensar o conciliar»*: se registra el desalineado ahora, que es barato y no compromete la decisión. **La idempotencia hacia Google *(AC-22.3)* y el orden persistir → confirmar *(AC-22.2)* no se recortan.** Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Notas y riesgos

⚠️ **Decisión abierta — compensar o conciliar.** Borrar el evento al fallar es simple y arriesga borrar algo que la PyME ya vio en su calendario; conciliarlo después es más seguro y deja una ventana con el horario ocupado sin turno. La segunda parece preferible con ventana corta.
