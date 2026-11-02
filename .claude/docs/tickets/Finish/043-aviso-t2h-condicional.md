# T-043 · Aviso t-2h *(condicional — no arrancar sin RF)*

> ## ✅ Construido el 2026-08-25 — **sin la evidencia que este mismo ticket pedía**
>
> Se construyó por decisión, no porque el piloto haya mostrado que el aviso de 24
> horas no alcanza. **La reserva de abajo sigue en pie:** no hay RF, los criterios
> son propuesta nuestra, y si el piloto muestra que no mueve la asistencia esto se
> borra entero.
>
> **Qué se construyó:** comando `avisos:enviar`, ventana `t-2h ± 10 min`, cadencia
> cada 10 minutos, solo a turnos en `scheduled`. 13 tests con su rojo previo.
>
> ⚠️ **El AC propuesto decía «aviso interactivo» y la plantilla `aviso_turno_2h`
> se aprobó sin botones.** Se resolvió reusando `recordatorio_turno_24h`, que ya
> está aprobada con los tres botones en la cuenta de cada PyME: funciona hoy, sin
> re-aprobación de Meta. El costo aceptado es que `aviso_turno_2h` queda creada y
> sin usar. Detalle en `app/Recordatorios/PlantillaAviso2h.php`.
>
> ⚠️ **De paso destapó un circuito muerto:** `RespuestaAlRecordatorio` filtraba
> `type = reminder_24h`, así que el botón del aviso t-2h no habría resuelto nunca.

> ## 🔒 Cerrado el 2026-08-21
>
> **No tiene requisito y no se sabe si sirve.** No hay RF ni criterio de aceptación
> que lo defina: los criterios de abajo son **propuesta nuestra, no acordados**. Y
> su valor tampoco está establecido — nadie sabe si baja el ausentismo por encima
> del aviso de 24 horas o si solo agrega ruido y costo de mensajería.
>
> **No se destraba con más análisis, se destraba con el piloto.** La recomendación
> del propio backlog es **mandarlo a mano una semana y medir**. Si no mueve la
> asistencia, se cae sin haber costado nada.
>
> **Qué lo reabriría:** que el piloto muestre que el aviso de 24 horas no alcanza,
> con el número que lo demuestre. Recién ahí se escribe el RF y se estima.


| | |
| :-- | :-- |
| **Prioridad** | 43 |
| **Sprint** | 7, **si sobrevive a la validación manual** |
| **Tipo** | Feature condicional |
| **Historia** | [US-11](../../stories/US-11-reagendar-y-aviso-t2h.md) (AC-11.4) |
| **Story points** | 3 · **no estimable hasta escribir el RF** |
| **Depende de** | **T-007** (redacción del RF), T-037 |
| **Bloquea a** | — |
| **Pareto** | **RECORTADO** — fuera del MVP recortado |

> **Fuera del núcleo Pareto — RECORTADO.** Sin RF escrito, confianza `B`, INVEST 2/6. El backlog ya recomienda **probarlo a mano una semana antes de escribir código**: si no mueve la asistencia, se cae sin haber costado nada. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Contexto

El aviso t-2h figura en el alcance del PRD y en el C4 nivel 3 (`Cron t-24h / t-2h`), y `notification_logs.type` ya admite `'reminder_2h'`. **La arquitectura lo anticipa y el requisito no existe:** no hay RF ni criterio de aceptación que lo defina. El AC-11.4 de la historia es una propuesta nuestra, no una definición acordada.

Su valor tampoco está establecido: la propia historia declara que no sabemos si aporta por encima del aviso de 24 horas o si solo agrega ruido y costo de mensajería.

## Alcance propuesto *(sujeto al RF)*

* Ventana t-2h en el scheduler existente.
* Envío solo a turnos no confirmados.
* Plantilla aprobada específica para el segundo aviso.

## Criterios de aceptación *(propuesta, no acordados)*

- [ ] Un turno a 2 horas que **no fue confirmado** recibe el segundo aviso interactivo *(AC-11.4)*.
- [ ] Un turno ya confirmado no recibe el segundo aviso.
- [ ] El registro usa `notification_logs.type='reminder_2h'` y no duplica.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ✅ | ❌ | ⚠️ | ❌ | ✅ | ❌ |

**Es el peor INVEST del plan y se deja explícito a propósito.** No se puede negociar lo que no tiene definición, no se puede estimar lo que no tiene requisito, y no se puede testear contra un criterio que nadie acordó. **Separarlo de T-042 fue justamente la corrección:** dentro de US-11 arrastraba a la baja una funcionalidad de valor claro.

## Fuera de alcance

Todo, hasta que exista el RF.

## Notas y riesgos

⚠️ **Antes de escribir una línea, probarlo a mano.** Mandar el segundo aviso manualmente durante una semana de piloto y medir si mueve la asistencia. Si no la mueve, este ticket se cierra sin desarrollarse y se ahorra la plantilla, el costo de mensajería y el mantenimiento.

Es el único ticket del plan que **conviene no hacer** hasta tener evidencia.
