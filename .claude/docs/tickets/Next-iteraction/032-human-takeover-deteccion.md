# T-032 · Human takeover: detección y pausa automática

> ## 🔒 Cerrado el 2026-08-21
>
> **El problema que resuelve ya no existe.** El proyecto adoptó el **modelo de dos
> números** (RF-A4): el bot atiende en uno y la atención humana vive en otro. Nadie
> contesta a mano en el número del bot, así que no hay nada que detectar.
>
> Y para callar al bot cuando hace falta está **T-025**, construido y con 14 tests,
> que cubre el mismo riesgo por **3 puntos en vez de 11**.
>
> ⚠️ **El hueco arquitectónico que este ticket nombraba se cerró por otro lado:**
> el C4 nivel 3 modeló un componente que **lee** la pausa y ninguno que la
> **escriba**. Hoy la escribe el panel (T-025), no una detección automática.
>
> **Qué lo reabriría:** que se abandone el modelo de dos números. El costo aceptado
> de ese modelo está escrito en RF-A4 —el cliente cambia de chat y vuelve a
> explicar— y **cuántos se pierden lo mide el piloto**.


| | |
| :-- | :-- |
| **Prioridad** | 32 |
| **Sprint** | 5 |
| **Tipo** | Feature |
| **Historia** | [US-08](../../stories/US-08-pausa-intervencion-humana.md) |
| **Story points** | 8 · **no comprometer hasta cerrar T-004** |
| **Depende de** | **T-004** · T-018, T-025 |
| **Bloquea a** | T-033 |
| **Pareto** | **RECORTADO** — fuera del MVP recortado |

> **Fuera del núcleo Pareto — RECORTADO.** 8 pts, confianza `B`, INVEST 2/6 y viabilidad desconocida hasta T-004. **T-025 entrega el mismo resultado operativo —el bot deja de responder cuando el humano entra— por 3 pts y con certeza.** ⚠️ RF-A4 y CA-03 prometen pausa *automática*: con T-025 la dispara una persona, así que **CA-03 no se cumple como está redactado**. Se replantea con datos del piloto sobre cuánto interviene el equipo a mano. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Contexto

Es lo que decide si el dueño acepta dejar el bot en su número principal o exige uno secundario que le parte la operación. **La objeción número uno en la mesa de venta, antes que el precio.**

Y según el propio C4 nivel 3, está modelado el componente que **lee** la pausa (`HumanDetector`) y ninguno que la **escriba**: el hueco arquitectónico es real.

## Alcance

* Suscripción a los eventos de mensajes salientes de Meta.
* Escritura de la llave `pause:` al detectar el mensaje manual.
* Consulta de la pausa antes de cualquier respuesta automática.
* Conservación del estado durante la pausa.

## Criterios de aceptación

- [ ] Tras responder manualmente desde la app móvil, si el cliente escribe dentro de los 60 minutos el bot no responde y el mensaje queda registrado *(AC-08.1)*.
- [ ] Pasados los 60 minutos, el bot retoma automáticamente **desde el estado en que quedó** *(AC-08.2)*.
- [ ] Estando en intervención manual con el cliente A, el cliente B es atendido con normalidad: la pausa no es global del tenant *(AC-08.3)*.
- [ ] La conversación pausada aparece marcada en el panel con el tiempo restante *(AC-08.4)*.
- [ ] La duración de la pausa se lee de la configuración del tenant, no de una constante.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ⚠️ | ✅ | ❌ | ⚠️ | ✅ |

**Es la peor falla de *Estimable* del backlog** y no se corrige rebanando: no se puede estimar lo que no se sabe si es posible. **Los 8 puntos son provisorios** y valen solo si T-004 confirma que Meta entrega el evento. Si no lo entrega, este ticket se cancela y se rediseña.

La mitigación no fue partirlo sino **construir su cobertura antes**: T-025 ya entrega el takeover manual.

## Fuera de alcance

La pausa manual desde el panel, que es T-025 y ya está entregada a esta altura.

## Notas y riesgos

⚠️ **No planificar este ticket en un sprint hasta tener el resultado de T-004.** Comprometerlo antes es comprometer una estimación construida sobre una incógnita.
