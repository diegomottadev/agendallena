# T-031 · Suite transversal de zonas horarias

| | |
| :-- | :-- |
| **Prioridad** | 31 |
| **Sprint** | 4 |
| **Tipo** | Calidad |
| **Historia** | [US-07](../../stories/US-07-hora-correcta-timezones.md) |
| **Story points** | 3 |
| **Depende de** | T-026 |
| **Bloquea a** | — |
| **Pareto** | **NÚCLEO** · ola 4 |

## Contexto

**US-07 no debería ser una historia y este ticket refleja esa conclusión.** Los timezones son una restricción transversal: como ítem de backlog invita a que alguien la marque "hecha" en un sprint y después se viole en silencio en cada historia nueva.

Es además el error más caro del producto porque es **silencioso**: nadie lo detecta hasta que un paciente llega tres horas tarde.

## Alcance

* Fijar `APP_TIMEZONE=UTC` y verificar que ninguna escritura dependa de la zona del servidor.
* Fijar la versión de `tzdata` en el pipeline.
* Suite de tests con tres husos y un cambio estacional.
* Incorporar los criterios como pruebas dentro de T-022, T-026, T-037 y T-042.

## Criterios de aceptación

- [ ] Con el tenant en `America/Argentina/Buenos_Aires` y el cliente eligiendo las 15:00, la base guarda `18:00 UTC` y el evento muestra 15:00 locales *(AC-07.1)*.
- [ ] Con el tenant en `America/Bogota` y las 15:00, la base guarda `20:00 UTC` *(AC-07.2)*.
- [ ] Con el tenant en `America/Santiago` y un turno posterior al cambio de horario, la hora mostrada y la del calendario coinciden **sin desplazamiento de una hora** *(AC-07.3)*.
- [ ] El texto de todo recordatorio muestra la hora local del tenant, nunca UTC *(AC-07.4)*.
- [ ] La suite corre en integración continua y falla el build si alguno de los cuatro se rompe.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ❌ | ❌ | ⚠️ | ✅ | ⚠️ | ✅ |

**Es el ticket con peor INVEST del plan, y a propósito.** US-07 puntúa 2/6 y la conclusión de su propia evaluación es que **no debería ser una historia**. Se mantiene como ticket de calidad —no de funcionalidad— cuyo entregable es una suite de tests que hace imposible darlo por "terminado". Que sea imposible de cerrar es exactamente el punto.

## Fuera de alcance

Soportar más de una zona horaria por tenant: eso anticiparía el OUT-OF-SCOPE de multi-sucursal.

## Notas y riesgos

⚠️ Un `tzdata` desactualizado produce justo el desplazamiento de una hora que AC-07.3 prohíbe, y lo hace solo unas semanas al año. Fijar la versión en el pipeline es la mitad del valor de este ticket.
