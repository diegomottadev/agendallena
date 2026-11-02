# T-049 · Panel: detalle de conversación con historial

| | |
| :-- | :-- |
| **Prioridad** | 49 |
| **Sprint** | 8 |
| **Tipo** | Feature |
| **Historia** | [US-14](../../stories/US-14-panel-logs-y-estado.md) (AC-14.4) |
| **Story points** | 5 |
| **Depende de** | T-047, T-048 |
| **Bloquea a** | — |
| **Pareto** | **DIFERIDO** — fuera del MVP recortado |

> **Fuera del núcleo Pareto — DIFERIDO.** Cae con T-047. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Contexto

Es el ticket que responde al caso de uso original de US-14: *"recibo un reclamo de un cliente y quiero resolverlo yo en dos minutos en lugar de escribirle al proveedor y esperar"*.

Va último del plan porque es lo único del panel que **no se puede construir** con el modelo de datos actual, y porque durante los pilotos el reclamo se resuelve mirando el WhatsApp del negocio.

## Alcance

* Vista de detalle de una conversación.
* Historial completo de mensajes en orden cronológico.
* Estado actual, turno asociado y monto cotizado.
* Marca de intervención humana con su origen.

## Criterios de aceptación

- [ ] Abriendo una conversación se ve el historial completo de mensajes, entrantes y salientes, en orden *(AC-14.4)*.
- [ ] Se ve el estado actual, el turno asociado con enlace al calendario y el monto cotizado *(AC-14.3)*.
- [ ] Una conversación pausada muestra la marca junto al historial, distinguiendo pausa manual de detectada *(AC-14.4, AC-21.4)*.
- [ ] Abrir una conversación de otro tenant por URL directa devuelve `403` *(AC-14.2)*.
- [ ] El historial pagina hacia atrás sin cargar la conversación entera de una vez.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ❌ | ✅ | ✅ | ✅ | ✅ | ✅ |

**Corrección aplicada:** era el AC que hacía a US-14 no *Estimable*, porque prometía leer de una tabla inexistente. Con T-048 creando la tabla, este ticket pasa a ser estimable y chico. **I** en ❌ es irreducible: es la vista más dependiente de todo el plan.

## Fuera de alcance

Responder al cliente desde el panel: en el MVP el equipo responde desde WhatsApp, que es donde ya trabaja. Métricas de conversión y ausentismo: v2.

## Notas y riesgos

El valor de este ticket crece con el tiempo transcurrido desde T-048: sin mensajes acumulados, el detalle está vacío. Es otro argumento para adelantar la migración.
