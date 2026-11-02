# T-029 · Control de colisión de horario al confirmar

| | |
| :-- | :-- |
| **Prioridad** | 29 |
| **Sprint** | 4 |
| **Tipo** | Feature |
| **Historia** | [US-18](../../stories/US-18-colision-de-horario-al-confirmar.md) |
| **Story points** | 5 |
| **Depende de** | T-022, T-026 |
| **Bloquea a** | T-042 |
| **Pareto** | **NÚCLEO** · ola 4 · con recorte, ver *Fuera de alcance* |

## Contexto

T-022 verifica disponibilidad **al listar** y T-026 crea el evento **al confirmar**; entre esas dos cosas pasan los segundos en que el cliente lee la lista y decide. **Dos personas mirando la misma lista no es un caso de borde: es el caso normal en un negocio con demanda**, que es el cliente que queremos.

Falla en el peor momento: el cliente recibe una confirmación, se organiza el día, y el turno pisa a otro.

## Alcance

* Reserva temporal del slot con `SET NX` en Redis y TTL.
* Re-consulta de `freeBusy` inmediatamente antes de crear el evento.
* Manejo del conflicto de Google como camino esperado.
* Relistado sin perder el contexto de la conversación.

## Criterios de aceptación

- [ ] Si el horario se ocupó entre el listado y la selección, el bot avisa y muestra la lista actualizada **sin volver a pedir los datos** *(AC-18.1)*.
- [ ] Con dos clientes eligiendo el mismo horario con segundos de diferencia, solo uno lo obtiene y el otro recibe el aviso: **nunca se crean dos eventos superpuestos** *(AC-18.2)*.
- [ ] Un horario elegido y no confirmado vuelve a ofrecerse al vencer la reserva temporal *(AC-18.3)*.
- [ ] Un doble toque del mismo cliente sobre el ítem no genera dos eventos.
- [ ] El mismo control aplica al re-agendamiento *(AC-18.4)*.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ✅ | ✅ | ⚠️ | ✅ | ✅ |

**4 de 6.** **E** en ⚠️ porque el TTL correcto depende de cuánto tarda un cliente entre elegir y confirmar, dato que no existe hasta el primer piloto. Empezar con un valor conservador y calibrar.

## Fuera de alcance

La consistencia ante fallo parcial de la transacción, que es T-030: es un problema distinto con la misma apariencia.

**Recorte Pareto.** Se difieren la reserva temporal con `SET NX` y TTL, y con ella *(AC-18.3)* y *(AC-18.4)*. ⚠️ La reserva temporal es justo la decisión abierta *«cuándo se toma la reserva temporal»*, y su TTL correcto no se conoce hasta el piloto. La re-consulta de `freeBusy` inmediatamente antes de crear el evento cubre *(AC-18.1)* y *(AC-18.2)*, que es la ventana que importa. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Notas y riesgos

⚠️ **Decisión abierta:** tomar la reserva temporal al **enviar la lista** deja bloqueados varios horarios por cada cliente que abre el chat y no reserva; tomarla al **elegir el ítem** deja una ventana mínima pero real. La segunda parece preferible.
