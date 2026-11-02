# T-025 · Pausar y reactivar el bot desde el panel

| | |
| :-- | :-- |
| **Prioridad** | 25 |
| **Sprint** | 3 |
| **Tipo** | Feature |
| **Historia** | [US-21](../../stories/US-21-pausar-el-bot-desde-el-panel.md) |
| **Story points** | 3 |
| **Depende de** | T-012, T-018 |
| **Bloquea a** | — · **cubre el riesgo de** T-032 |
| **Pareto** | **NÚCLEO** · ola 3 · con recorte, ver *Fuera de alcance* |

## Contexto

**Es el plan B del riesgo más grave del backlog.** T-032 (human takeover automático) depende entera de un supuesto sin probar. Si T-004 sale negativo, con este ticket construido la degradación es "el dueño aprieta un botón" en vez de "no tenemos takeover".

Por eso va **antes** que T-032 y no después, aunque parezca contraintuitivo construir la cobertura antes que la funcionalidad principal.

## Alcance

* Botones de pausar y reactivar sobre la conversación en el panel.
* Escritura de la llave `pause:` en Redis con origen y usuario.
* Reactivación anticipada que borra la llave.
* Indicador de tiempo restante y de quién pausó.

## Criterios de aceptación

- [ ] Al presionar "pausar el bot", deja de responder a ese cliente y la conversación queda marcada con el tiempo restante a la vista *(AC-21.1)*.
- [ ] Al presionar "reactivar", el bot retoma de inmediato **desde el estado en que había quedado**, sin esperar el vencimiento *(AC-21.2)*.
- [ ] Si nadie reactiva, el bot retoma solo al vencer la ventana configurada *(AC-21.3)*.
- [ ] La conversación muestra quién la pausó y cuándo, distinguible de una pausa detectada automáticamente *(AC-21.4)*.
- [ ] La pausa de una conversación no afecta a las demás del mismo tenant.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ✅ | ✅ | ✅ | ✅ | ✅ |

**5 de 6, y notablemente mejor que T-032 pese a resolver el mismo problema:** este no depende de ninguna incógnita externa. Es el argumento para hacerlo primero.

## Fuera de alcance

La detección automática del mensaje manual, que es T-032.

**Recorte Pareto.** Se difieren los botones sobre la conversación en el panel y el indicador de quién pausó *(AC-21.4)*. ⚠️ El alcance original asume el listado de T-047, que está diferido: en el núcleo la pausa vive en una pantalla mínima con un campo de teléfono y dos botones. Es deuda declarada, no ahorro — se absorbe cuando entre T-047. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Notas y riesgos

⚠️ **Decisión abierta:** UC-2.10 dice "forzar pausa o reactivación del bot" sin aclarar si existe una pausa **global del tenant** además de la pausa por conversación. Una pausa global es útil el día que el equipo atiende todo a mano y peligrosa si alguien la deja activa. Decidir en este ticket.
