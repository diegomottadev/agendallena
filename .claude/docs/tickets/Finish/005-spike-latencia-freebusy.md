# T-005 · Spike: latencia real de `freeBusy`

| | |
| :-- | :-- |
| **Prioridad** | 5 |
| **Sprint** | 0 — Habilitación |
| **Tipo** | Spike |
| **Historia** | [US-05](../../stories/US-05-horarios-realmente-libres.md) |
| **Story points** | 2 · **timebox 1 día** |
| **Depende de** | T-003 |
| **Bloquea a** | T-022 |
| **Pareto** | **NÚCLEO** · ola 0 |

## Contexto

El RNF-04 fija el presupuesto punta a punta —webhook, cola, Google, WhatsApp saliente— en 1,5 segundos. Nadie midió cuánto de eso consume `freeBusy`. Si no entra, la respuesta no es optimizar código sino cachear disponibilidad, y eso cambia el diseño de T-022 y de T-027 a la vez.

## Alcance

* Medir p50 y p95 de `freeBusy` contra un calendario real, con y sin eventos.
* Medir con la ventana de búsqueda prevista y con la ampliada.
* Contrastar contra el presupuesto de 1,5 s del RNF-04, descontando el resto de la cadena.
* Documentar si hace falta caché y de qué tipo.

## Criterios de aceptación

- [ ] Hay números de p50 y p95 medidos, no estimados.
- [ ] Está documentado cuánto queda del presupuesto de 1,5 s para el resto de la cadena.
- [ ] Hay una recomendación explícita: consulta directa o caché, con su estrategia de invalidación.
- [ ] La estimación de T-022 se confirma o se rehace.

## Fuera de alcance

Implementar el caché. Si la conclusión es que hace falta, sale como ticket propio.

## Notas y riesgos

⚠️ Cachear disponibilidad entra en tensión directa con la sincronización inversa de T-027: un caché que no se invalida al recibir una notificación de Google vuelve a ofrecer horarios ocupados, que es justo lo que US-05 promete evitar.
