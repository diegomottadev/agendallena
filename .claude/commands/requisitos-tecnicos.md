---
description: Deriva los requisitos técnicos que exige cada historia de usuario del backlog como una parte más de su descripción, con trazabilidad a los criterios de aceptación y señalamiento de huecos.
argument-hint: "[alcance opcional: US-08, sección 2, o vacío para todo el backlog]"
---

# Rol

Actuá como Arquitecto de Software / Tech Lead con experiencia en SaaS multi-tenant, integraciones con APIs de terceros y sistemas orientados a eventos.

# Objetivo

Dadas las historias de usuario del backlog, determinar **qué requisitos técnicos se necesitarían** para poder construirlas.

Los requisitos son **una parte más de la descripción de cada historia**: el entregable principal es, por historia, un bloque *Requisitos técnicos* listo para pegar en su archivo `US-*.md`, entre `## Criterios de aceptación` y `## Estimación de complejidad`.

El entregable son los requisitos, no el diseño: qué tiene que ser cierto del sistema para que la historia se pueda implementar y verificar.

# Contexto / entrada

**Alcance de este análisis:** $ARGUMENTS

Si el alcance está vacío, cubrí el backlog completo.

Leé antes de responder:

* `.claude/docs/01-producto/03-user-stories-mvp.md` — índice del backlog, orden y estimaciones.
* `.claude/docs/stories/US-*.md` — las historias en alcance, con sus criterios de aceptación BDD.
* `.claude/docs/01-producto/02-prd.md` — requisitos funcionales (RF-*) y no funcionales (RNF-*) que **ya existen**.
* `.claude/docs/02-arquitectura/01-c4-nivel-2-contenedores.md` y `02-c4-nivel-3-componentes.md` — contenedores y componentes ya decididos.
* `.claude/docs/02-arquitectura/03-der-entidad-relacion.md` y `04-modelo-de-datos.md` — entidades, tablas y campos ya definidos.

**Stack ya definido, no proponer alternativas:** Laravel 11+ (Queues + Redis), Vue.js 3, MySQL 8, Meta Cloud API, Google Calendar API, Google Sheets API.

# Instrucciones

1. Leé cada historia en alcance, incluidos sus criterios de aceptación. Los criterios son la fuente más precisa: **un requisito que ningún criterio ni la historia exige, sobra**. No derives de una lista genérica de buenas prácticas.
2. Extraé de cada una lo que el sistema debe poder hacer o garantizar, y escribilo como **viñeta accionable y verificable que empiece por un verbo** ("Garantizar que…", "Persistir…", "Reintentar…", "Aislar por tenant…").
3. **Un requisito, una cosa.** Si necesitás una "y" para unir dos capacidades que se construyen o se verifican por separado, son dos requisitos. Cada uno tiene que poder implementarse y probarse solo.
4. Cubrí, **solo cuando la historia lo requiera**, estas dimensiones: modelo de datos y persistencia · contratos de API e integraciones externas (scopes, tokens, webhooks) · procesamiento asíncrono (colas, reintentos, idempotencia) · estados y transiciones de la máquina de estados · multi-tenancy y aislamiento de datos · seguridad (firma de webhooks, manejo de secretos, permisos) · zonas horarias · observabilidad (logs, métricas, alertas) · rendimiento y límites de rate limiting de las APIs externas · configuración y feature flags.
5. **Regla de concreción.** Podés nombrar cualquier contenedor, componente, tabla, campo, entidad, cola, scope, estado o RF/RNF **que ya exista en el PRD o en los docs de arquitectura**, citando dónde está (ej. `C4-N3`, `modelo-de-datos §2`, `RF-B2`). Nombrar lo ya decidido hace el requisito verificable y no es diseñar. Si para satisfacer la historia hace falta algo que **no** figura en esos docs, no lo inventes: marcalo con `⚠️` como decisión pendiente.
6. Trazá cada requisito al criterio de aceptación que lo exige, citando su ID tal como aparece en la historia (ej. `(AC-05.2)`). Si sale de la historia en general y no de un AC puntual, no cites nada.
7. Marcá con `⚠️` todo requisito que sea un **hueco o decisión abierta** — información que falta, definición de producto pendiente, incertidumbre técnica, o una pieza que caería fuera del stack decidido — y agregá `falta:` con qué habría que definir. Nunca completes el detalle por tu cuenta.
8. **IDs estables.** Antes de numerar, revisá si las historias ya tienen requisitos con ID asignado: respetalos tal cual y continuá la numeración desde el máximo `RT-NN` existente en todo el backlog, aunque el alcance de esta corrida sea una sola historia. Un ID ya emitido no se reasigna ni se renumera.
9. **Requisitos compartidos.** Cuando el mismo requisito subyacente lo exijan varias historias, usá **un solo ID** y anotá en cada bloque `(compartido: US-02, US-09)`. Se implementa una vez, no una por historia.
10. Recién después de los bloques por historia, consolidá todos los requisitos en una tabla transversal, sin duplicar los compartidos.
11. En esa tabla, marcá el estado de cada requisito:
    * **`ya en PRD`** — corresponde a un RF o RNF existente; citá su ID.
    * **`nuevo`** — lo exigen las historias y el PRD no lo cubre.
    * **`bloqueado`** — depende de una decisión abierta; nombrá la decisión. Es el mismo concepto que el `⚠️` de los bloques por historia.
12. Al final, listá las historias cuyos requisitos **no se pudieron derivar** por falta de definición, y qué falta en cada caso.

# Restricciones

* **No inventes funcionalidad.** Si la historia no lo pide, no es un requisito.
* **No diseñes la solución.** El nivel es "qué debe garantizarse", no "cómo se implementa": nada de elegir librerías, escribir DDL, definir firmas ni proponer patrones de implementación. Referenciar lo ya decidido según la regla de concreción no cuenta como diseñar.
* **No conviertas los requisitos en criterios de aceptación ni dupliques su contenido.** Los criterios describen comportamiento observable; los requisitos, lo que hay que construir o disponer para lograrlo.
* **No propongas cambios de stack.** Ya está decidido.
* **No resuelvas las decisiones de producto abiertas.** Marcalas como bloqueantes y seguí.
* **No repitas los RF/RNF existentes como si fueran hallazgos nuevos.** Marcalos como `ya en PRD` y seguí.
* **Un requisito marcado `⚠️` no es trabajo listo para ejecutar.** Tiene que quedar visualmente distinguible del resto en todo el entregable, para que nadie lo tome como implementable sin resolver antes lo que falta.
* No agregues una sección global de arquitectura, diagramas ni plan de implementación al final.
* Escribí todo en español rioplatense, con lenguaje técnico preciso.

# Formato de salida

## Requisitos técnicos por historia

Un bloque por historia en alcance, en el orden del backlog, listo para pegar en su `US-*.md`:

### US-NN — <título de la historia>

**Requisitos técnicos**

* `RT-01` — <requisito accionable> (AC-NN.1)
* `RT-02` — <requisito accionable> (compartido: US-09)
* ⚠️ `RT-03` — <requisito con hueco> — falta: <qué habría que definir>

## Requisitos consolidados

Una tabla por categoría — infraestructura y colas · integraciones externas · modelo de datos y persistencia · seguridad y multi-tenancy · observabilidad · rendimiento · configuración:

| ID | Requisito | Historias que lo exigen | Estado |
| :-- | :-- | :-- | :-- |
| RT-01 | … | US-02, US-09 | nuevo |

## Requisitos bloqueados por decisiones abiertas

Tabla con: requisito · decisión que lo bloquea · historias afectadas · qué cambia según cada opción.

## Historias sin requisitos derivables

Lista con: historia · qué falta definir para poder derivarlos.

## Resumen

Máximo cinco puntos: cuántos requisitos salieron, cuántos ya estaban en el PRD, cuántos son nuevos, cuántos están bloqueados, y cuál es el de mayor riesgo técnico.
