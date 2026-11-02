---
fecha: 2026-08-18
objetivo: Aplicar el principio de Pareto a los 49 tickets del MVP usando las 26 historias como medida de valor, para obtener un nucleo que entregue el 80% de la promesa con una fraccion de los 177 puntos
estado: aprobado
---

## Pedido original

```
c:\Users\ACER\Documents\Develop\agendallena\.claude\prompts\aprobados\2026-08-18-arquitecto-pragmatico-pareto.md teniendo en cuenta las historias de usuario c:\Users\ACER\Documents\Develop\agendallena\.claude\docs\stories no modificar pero solamente analizarlas para aplicar el principio de pareto en todos los tickets c:\Users\ACER\Documents\Develop\agendallena\.claude\docs\tickets para tener mejores resultados
```

## Prompt

````markdown
# Rol

Adoptá la persona definida en `.claude/prompts/aprobados/2026-08-18-arquitecto-pragmatico-pareto.md`. Leé ese archivo primero y aplicá **verbatim** el prompt que está bajo su sección `## Prompt`: procedimiento de tres pasos, tabla PREFER/REJECT, condiciones de corte y template de salida de tres secciones.

Sos, además, quien conoce este producto: AgendaLlena, middleware de agendamiento y cotización por WhatsApp para PyMEs latinoamericanas de 3 a 20 empleados. Todavía no hay código: el repositorio es documentación de producto.

# Objetivo

Aplicar el principio de Pareto (80/20) a **los 49 tickets** de `.claude/docs/tickets/`, usando las 26 historias de usuario de `.claude/docs/stories/` como fuente de verdad del valor que cada ticket entrega, para obtener un MVP que entregue el ~80% del valor prometido con el ~20% del esfuerzo comprometido.

# Contexto / entrada

| Ruta | Qué es | Cómo usarla |
| :-- | :-- | :-- |
| `.claude/prompts/aprobados/2026-08-18-arquitecto-pragmatico-pareto.md` | La persona a adoptar | Leer y aplicar su sección `## Prompt` |
| `.claude/docs/stories/` (US-01 … US-26) | 26 historias con criterios de aceptación y nota Lean | **Solo lectura. No modificar ningún archivo.** Son el insumo para medir valor |
| `.claude/docs/tickets/` (001 … 049 + `README.md`) | 49 tickets, 177 pts, 9 bloques | Objeto del análisis. El `README.md` trae el orden de prioridad, sprints y bloques |
| `.claude/docs/estimacion-backlog/01-tabla-maestra-de-tickets.md` | Puntos y confianza por ticket | Fuente de los story points. **No re-estimar** |
| `.claude/docs/01-producto/` | Lean canvas y PRD (RF-*, RNF-*, CA-*) | Ancla de qué es "valor" en este producto |
| `CLAUDE.md` | Decisiones cerradas, decisiones abiertas, huecos bloqueantes y advertencias | Leer antes de empezar: ya identifica riesgos que el análisis no debe volver a descubrir ni contradecir |

Anatomía de un ticket (respetala al leer y al proponer cambios): cabecera con Prioridad, Sprint, Tipo, Historia, Story points, Depende de, Bloquea a — más `## Contexto`, `## Alcance`, `## Criterios de aceptación`, `## INVEST`, `## Fuera de alcance`, `## Notas y riesgos`.

# Instrucciones

## Paso 1 — Definir la medida de valor antes de puntuar nada

1. Derivá del lean canvas y del PRD **una sola frase** que exprese la promesa que el producto cobra. Todo el análisis se mide contra esa frase.
2. Fijá las dos escalas y dejalas escritas:
   - **Impacto** = qué parte de esa promesa se cae si el ticket no está. Se deriva de la historia de origen y de sus criterios de aceptación, no de la intuición.
   - **Esfuerzo** = los story points ya estimados en la tabla maestra. No los recalcules.
3. **Regla de los enablers:** un ticket de tipo `Enabler`, `Migración`, `Spike` o `Trámite` no tiene valor de usuario propio; hereda el impacto máximo de los tickets que desbloquea (campo `Bloquea a`). Sin esta regla, Pareto recorta justo los cimientos y el resultado es basura.
4. **Regla del camino crítico:** un ticket barato que desbloquea muchos puntos vale más que su propia estimación. `CLAUDE.md` ya documenta el caso testigo — 16 pts del bloque 0 eliminan hasta 50 pts de incertidumbre — y también la cadena que no se paraleliza: T-008 → T-010 → T-018 → T-022 → T-026.

## Paso 2 — Pareto macro: qué tickets entran en el núcleo

5. Clasificá **los 49 tickets, sin excepción**, en tres categorías, con una línea de justificación cada uno:
   - **NÚCLEO** — entra en el 20% que entrega el 80%. Sin él, la promesa no se cumple.
   - **DIFERIDO** — valor real, pero el producto es demostrable y vendible sin él en esta iteración.
   - **RECORTADO** — el costo no se paga con el valor que entrega hoy.
6. Sumá los puntos del NÚCLEO y contrastalos contra los **177 pts** totales. Si el núcleo no baja significativamente ese número, el recorte no sirvió: volvé al paso 5 y sé más duro.
7. Verificá que el NÚCLEO sea **un camino end-to-end ejecutable**: recorré el campo `Depende de` de cada ticket del núcleo y confirmá que ninguna dependencia quedó en DIFERIDO o RECORTADO. Si quedó, o entra al núcleo o el ticket que la necesita sale.

## Paso 3 — Pareto micro: recortar dentro de cada ticket del núcleo

8. Para cada ticket del NÚCLEO, separá su alcance en dos:
   - **Lo mínimo** que satisface los criterios de aceptación que sostienen la promesa.
   - **Lo diferible**, que hoy está en `## Alcance` o en los criterios y puede esperar sin romper el camino end-to-end.
9. Nombrá qué criterios de aceptación específicos justifican cada recorte, por su ID (`AC-05.2`, `UC-2.8`, etc.). Un recorte sin AC de referencia no se propone.
10. Aplicá el piso de calidad de la persona: **seguridad, corrección del camino principal y pérdida de datos no son diferibles.** Firma HMAC, idempotencia, aislamiento por `tenant_id` y consistencia del agendamiento no se recortan por ser "el 80/20".

## Paso 4 — Entregar

11. Escribí el análisis en un archivo nuevo: `.claude/docs/estimacion-backlog/03-analisis-pareto.md`.
12. En el chat, respondé con el template de tres secciones de la persona (Value-to-Effort Justification / Implementation / Intentionally Deferred Scope), donde *Implementation* es el resumen del análisis y la ruta del archivo creado.
13. **No apliques los recortes a los archivos de tickets.** Cerrá proponiendo esa segunda pasada y esperá aprobación explícita antes de tocar ningún ticket.

# Restricciones

- **Los 26 archivos de `.claude/docs/stories/` son de solo lectura.** Se analizan, no se editan, ni se renumeran, ni se regeneran.
- **En esta pasada tampoco se editan los 49 tickets.** El entregable es el documento de análisis.
- **No inventes información faltante.** Toda decisión abierta o dato ausente se marca con `⚠️`, como manda la convención del repositorio.
- **No re-estimes puntos ni re-priorices por corazonada.** Los puntos vienen de la tabla maestra; el orden vigente, de `docs/tickets/README.md`.
- **Respetá la cadena de derivación:** este análisis referencia PRD, historias y tickets por ID y no los regenera.
- **No resuelvas las decisiones abiertas** listadas en `CLAUDE.md` (Outlook en el MVP, dónde se captura el nombre, TTL de inactividad, compensar o conciliar, cuándo se toma la reserva temporal). Indicá qué tickets del núcleo quedan bloqueados por cada una.
- Contenido en **español rioplatense**, técnico y sin relleno. Nombre de archivo en `NN-kebab-case.md`, ASCII, sin acentos ni `ñ`.
- Nada de prosa motivacional ni emojis decorativos. El único emoji admitido es `⚠️` en su rol convencional.

# Formato de salida

El archivo `.claude/docs/estimacion-backlog/03-analisis-pareto.md` con esta estructura:

```markdown
# Análisis Pareto del backlog — MVP Release 1.0

## La promesa que se cobra
<una frase, derivada del lean canvas y el PRD>

## Cómo se midió
<las escalas de impacto y esfuerzo, la regla de enablers y la del camino crítico>

## Veredicto
<pts del núcleo vs. 177 · cuántos tickets quedan · qué queda afuera en una línea>

## Clasificación de los 49 tickets

| # | Ticket | Historia | Pts | Categoría | Por qué |
| :-: | :-- | :-- | :-: | :-: | :-- |
<las 49 filas, en el orden de prioridad vigente>

## El núcleo, en orden de ejecución
<secuencia respetando `Depende de`, con los puntos acumulados por bloque>

## Recortes dentro de cada ticket del núcleo

### T-0NN · <título>
* **Se construye:** <lo mínimo>
* **Se difiere:** <qué sale del alcance> — *(AC de referencia)*
* **Por qué puede esperar:** <una línea>

## Lo diferido y lo recortado
<tabla: ticket · categoría · qué se pierde · cuándo conviene retomarlo>

## Bloqueos y riesgos del recorte
<decisiones abiertas ⚠️ que afectan al núcleo, y qué pasa si el recorte resulta equivocado>
```

Y en el chat, la respuesta con las tres secciones del template de la persona.
````

## Supuestos

- **Dos niveles de Pareto.** "Aplicar el principio de Pareto en todos los tickets" se leyó como las dos cosas
  que un backlog requiere: **macro** (qué subconjunto de los 49 entrega el 80% del valor) y **micro** (qué se
  recorta dentro de cada ticket que queda). Aplicar solo el micro dejaría los 177 puntos intactos y no habría
  "mejores resultados".
- **No se modifican los tickets en esta pasada.** El "no modificar" del pedido calificaba a las historias, lo
  que implica que los tickets sí serían editables; aun así, reescribir 49 archivos es irreversible y quedó como
  segunda fase sujeta a aprobación explícita. El entregable de esta pasada es el documento de análisis.
- **Ubicación del entregable:** `.claude/docs/estimacion-backlog/03-analisis-pareto.md`, siguiendo la
  numeración existente de esa carpeta (`01-tabla-maestra`, `02-estimacion-por-historia`) — es análisis de
  backlog, no un ticket ni una historia.
- **Los puntos no se recalculan.** La convención del repositorio dice que cada nivel referencia al anterior y
  no lo regenera; re-estimar convertiría esto en una re-estimación encubierta, que `CLAUDE.md` ya declara
  inútil para el problema de fondo.
- **Regla de enablers y de camino crítico agregadas explícitamente.** No están en el pedido, pero sin ellas un
  80/20 ingenuo recorta migraciones, spikes y trámites — que es exactamente lo que `CLAUDE.md` advierte que
  hunde el plan. Se consideran clarificación del criterio "Value-to-Effort", no alcance nuevo.

## Resultado

Ejecutado el 2026-08-18.

**Creado** `.claude/docs/estimacion-backlog/03-analisis-pareto.md`. **Modificado**
`.claude/docs/estimacion-backlog/README.md` (una fila de indice). **No se toco** ningun archivo de
`stories/` ni de `tickets/`.

Veredicto del analisis: **31 tickets · 107 pts de nucleo contra 177** — 15 tickets diferidos (56 pts) y
3 recortados (14 pts). El nucleo conserva el circuito `WhatsApp → estado → disponibilidad real → evento
en Calendar → recordatorio t-24h → tasa de asistencia`, mas los cuatro items del piso de calidad
(firma HMAC, idempotencia de ingesta, aislamiento por `tenant_id`, consistencia del agendamiento).

Hallazgos que produjo el recorte:

- **T-027 sync inversa (8 pts, unica confianza `B` del sprint 4) sale del nucleo.** La disponibilidad ya
  se consulta en vivo con `freeBusy`, asi que los cambios manuales del calendario se ven igual; el
  *watch* solo mantiene alineada la tabla `bookings`. El hueco se mitiga con un `GET` de verificacion
  antes de enviar el recordatorio en T-037.
- **T-025 (3 pts) sustituye a T-004 + T-032 (11 pts).** Pausar el bot a mano entrega el mismo resultado
  operativo que la deteccion automatica, con certeza en vez de un spike sin resolver.
- **T-046 se adelanta de la posicion 46 a la ola 1** por RNF-01, como ya recomendaba el README de tickets.
- **T-017 (2 pts) se mantuvo en el nucleo** aunque Pareto lo sacaria: cerraba la dependencia de T-021,
  T-024 y T-033 mas barato que sustituirlo tres veces.
- **Diferir el volcado a Sheets contradice CA-02 tal como esta escrito.** Marcado ⚠️ con las dos salidas
  posibles; hay que elegir una en T-007.
- **El cuello de botella no se arregla recortando:** 26 de los 107 puntos del nucleo siguen en la cadena
  serie T-008 → T-010 → T-018 → T-022 → T-026.

Desvios respecto del prompt:

- **Se modifico `estimacion-backlog/README.md`**, que el prompt no menciona. Es una fila de indice para que
  el documento nuevo sea navegable desde la carpeta; no altera contenido existente.
- **Los 107 pts se sumaron con las estimaciones originales**, sin descontar los recortes internos del paso 3.
  Es deliberado y esta dicho en el documento: la cifra comprometible es la conservadora, y T-018, T-015,
  T-024, T-029, T-030 y T-033 quedan como candidatos a bajar.
### Segunda pasada — aplicacion sobre los tickets

Aprobada y ejecutada el mismo dia. **Modificados los 49 tickets de `.claude/docs/tickets/` y su
`README.md`.** Los cambios son **puramente aditivos**: se verifico con `diff` contra una copia previa que
no se borro una sola linea en ninguno de los 49 archivos. Los story points, el orden de prioridad y la
auditoria INVEST quedaron intactos.

Que se agrego:

- **Fila `| **Pareto** | ... |` en la cabecera de los 49 tickets**, con la categoria y la ola de ejecucion.
- **Nota de estado** en los 18 tickets fuera del nucleo (15 DIFERIDO + 3 RECORTADO), con el motivo y cuando
  retomarlos, como blockquote debajo de la cabecera.
- **Bloque `**Recorte Pareto.**`** al final de la seccion `## Fuera de alcance` de los 14 tickets del nucleo
  que entran recortados, citando el AC que justifica cada corte. Es la seccion que la convencion del
  repositorio ya destina a "que **no** entra, para que el corte sea explicito".
- **Seccion "Recorte Pareto vigente"** en `tickets/README.md`, con la tabla de categorias, la lista de los 18
  tickets fuera del nucleo y las dos advertencias ⚠️ sobre CA-02 y CA-03.

Metodo: script Python (`aplicar_pareto.py`, en el scratchpad de la sesion) con la clasificacion embebida,
idempotente —saltea archivos que ya tengan la fila `**Pareto**`— y con asserts sobre la estructura de cada
ticket antes de escribir.
