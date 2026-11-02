# Estimación del backlog — MVP Release 1.0

**177 story points** repartidos en **49 tickets** que completan las **26 historias** del backlog.

**Generado el:** 2026-08-06
**Fuentes:** [`stories/`](../stories/) (backlog y prioridades) · [`tickets/`](../tickets/) (tickets con criterios de aceptación) · [`stories-maps/`](../stories-maps/README.md) (huecos y secuencia)

| Doc | Contenido |
| :-- | :-- |
| [01-tabla-maestra-de-tickets.md](01-tabla-maestra-de-tickets.md) | **Los 49 tickets en orden de prioridad**, con puntos, confianza, dependencias y justificación de cada estimación |
| [02-estimacion-por-historia.md](02-estimacion-por-historia.md) | Los puntos agregados por historia, con sus factores de priorización y la calibración contra la estimación S/M/L original |
| [03-analisis-pareto.md](03-analisis-pareto.md) | **Recorte 80/20 del backlog:** los 49 tickets clasificados en núcleo, diferido y recortado — **107 pts contra 177** — con el recorte interno de cada ticket del núcleo y los riesgos que introduce |

---

## Método

**Escala Fibonacci con techo en 8.** Ningún ticket puede superar los 8 puntos: si al planificar uno crece por encima, se parte. Es la regla que evita que vuelva a pasar lo del backlog original, donde 8 de 17 historias eran **L** y ninguna entraba en un sprint.

| Pts | Equivale a | Criterio |
| :-: | :-- | :-- |
| **1** | Medio día | Una migración de una columna, un cambio de configuración |
| **2** | Un día | Una preocupación única, sin integraciones externas |
| **3** | Uno o dos días | Una preocupación con una integración, o una vista completa |
| **5** | Tres o cuatro días | Dos preocupaciones acopladas, o una con incertidumbre acotada |
| **8** | Una semana | **Techo.** Solo para lo genuinamente irreducible |

**Qué se puntúa y qué no.** Los trámites externos se puntúan por el **esfuerzo propio**, no por el tiempo de calendario ajeno: T-001 vale 1 punto aunque Meta tarde tres semanas. Esa demora se gestiona con la secuencia (arrancan en el bloque 0), no inflando la estimación — que es exactamente el error que US-10 tenía escondido adentro.

**Los spikes se puntúan por su timebox**, no por su resultado. T-004 vale 3 puntos porque son dos días acotados; si la respuesta obliga a rediseñar US-08, eso es una re-estimación, no una desviación.

---

## Confianza de la estimación

De los 49 tickets, **41 tienen confianza alta** (alcance cerrado). Los ocho restantes:

| Ticket | Pts | Por qué la confianza es menor | Rango realista |
| :-- | :-: | :-- | :-- |
| [T-027](../tickets/Next-iteraction/027-sincronizacion-inversa-watch.md) Sync inversa | 8 | Si las notificaciones de Google resultan poco fiables, hace falta un respaldo por *polling* que hoy no está estimado | **8 – 13** |
| [T-032](../tickets/Next-iteraction/032-human-takeover-deteccion.md) Human takeover | 8 | **Provisorio.** Vale solo si T-004 confirma que Meta entrega el evento; si no, el ticket se cancela y se rediseña | **0 – 13** |
| [T-043](../tickets/Next-iteraction/043-aviso-t2h-condicional.md) Aviso t-2h | 3 | No tiene requisito funcional. Los 3 puntos son un marcador de espacio | **0 – 5** |
| [T-045](../tickets/Finish/045-volcado-de-leads-a-sheets.md) Volcado de leads | 3 | Sin RF, "volcar leads" puede significar cualquier cosa | **3 – 8** |
| [T-012](../tickets/Finish/012-oauth-google-calendar.md) OAuth Calendar | 5 | Sube si la decisión de Outlook lo convierte en abstracción de dos proveedores | **5 – 8** |
| [T-022](../tickets/Finish/022-consulta-de-disponibilidad.md) Disponibilidad | 5 | Sube si T-005 concluye que hace falta cachear disponibilidad | **5 – 8** |
| [T-029](../tickets/Finish/029-control-de-colision-de-horario.md) Colisión | 5 | El TTL correcto depende de un dato de comportamiento que no existe hasta el piloto | **5 – 8** |
| [T-040](../tickets/Next-iteraction/040-motor-de-cotizacion-seguro.md) Motor de cotización | 5 | La forma del esquema depende del resultado de T-006 | **5 – 8** |

**Rango total honesto: 165 – 215 puntos.** El extremo bajo asume que el aviso t-2h se descarta tras la validación manual y que el takeover automático resulta innecesario; el alto asume *polling* de respaldo, Outlook adentro y caché de disponibilidad.

**Los cinco tickets del bloque 0 son los que colapsan ese rango.** Cuatro de los ocho tickets de confianza baja dependen de un spike, un discovery o una decisión que se resuelve sin escribir código. Por eso el bloque 0 arranca el día 1: no por prolijidad, sino porque **16 puntos de trabajo sin código eliminan hasta 50 puntos de incertidumbre.**

---

## Plan de capacidad

Con un equipo de dos desarrolladores y sprints de dos semanas:

| Velocidad | Sprints | Calendario | Escenario |
| :-: | :-: | :-: | :-- |
| 22 pts | 8 | ~16 semanas | Optimista: todo paralelizado, sin sorpresas en T-027 ni T-032 |
| **18 pts** | **10** | **~20 semanas** | **Realista.** Es la cifra para planificar |
| 14 pts | 13 | ~26 semanas | Conservador: T-027 necesita *polling* y T-032 se rediseña |

⚠️ **La velocidad efectiva va a estar por debajo de la nominal durante los sprints 1 a 4.** El backlog puntúa **2 de 26 en *Independiente*** y el orden por riesgo produce la cadena T-008 → T-010 → T-018 → T-022 → T-026: cinco tickets que no se pueden paralelizar. Con dos desarrolladores, durante buena parte de esa cadena uno queda esperando.

**Es la decisión pendiente más importante del plan**, y ninguna re-estimación la resuelve: o se acepta el costo de la secuencia, o se reorganiza en rebanadas verticales —agendar punta a punta con disponibilidad falseada y reemplazar el mock después—. La segunda opción mejora la paralelización y empeora el aprendizaje temprano sobre Google Calendar, que es justo el riesgo que el orden actual ataca primero.

### Dos ajustes de carga recomendados

| Ajuste | Motivo |
| :-- | :-- |
| Mover **T-031** (timezones, 3 pts) del bloque 4 al 6 | El bloque 4 tiene 29 pts y el 6 tiene 12. No rompe dependencias |
| Mover **T-020** (logging, 2 pts) del bloque 2 al 3 | El bloque 2 tiene 27 pts. T-020 no bloquea nada antes de T-033 |
| Adelantar **T-046** (autenticación, 3 pts) al bloque 2 | Está en la posición 46 por trazabilidad con US-14, pero **cinco tickets anteriores necesitan un panel con login** |
| Adelantar **T-048** (tabla `messages`, 2 pts) junto con T-018 | Cuanto antes se guarden los mensajes, más historial habrá cuando el detalle exista en el bloque 8 |

Con los cuatro ajustes, la distribución queda entre 17 y 24 puntos por bloque, sin ningún salto por encima del 30%.

---

## Advertencia sobre estos números

**Los story points estiman esfuerzo relativo, no tiempo.** Las equivalencias en días de la tabla de arriba son una referencia inicial para un equipo que todavía no tiene velocidad medida; **hay que reemplazarlas por la velocidad real después de dos sprints** y volver a proyectar el calendario. Hasta entonces, cualquier fecha derivada de estos 177 puntos es una hipótesis, no un compromiso.

**Cinco funcionalidades del MVP siguen sin requisito funcional** —el aviso t-2h, el volcado a Sheets, la configuración del negocio, la bienvenida y el registro de asistencia—. Sus tickets están estimados contra criterios de aceptación que **son una propuesta nuestra y no una definición acordada**. [T-007](../tickets/Finish/007-cerrar-decisiones-y-rf-faltantes.md) los escribe en media jornada; hasta que eso pase, esas estimaciones descansan sobre supuestos propios.
