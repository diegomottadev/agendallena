---
fecha: 2026-08-07
objetivo: Derivar los tickets de trabajo del MVP desde el backlog de 26 historias, rebanandolas para corregir sus fallas INVEST, con auditoria INVEST del backlog y plan de sprints.
estado: reconstruido
origen: ingenieria inversa del artefacto (.claude/docs/tickets/), no es el prompt original
---

## Advertencia de trazabilidad

⚠️ **Este no es el prompt que se ejecutó.** Los 49 tickets se generaron el 2026-08-05 y el prompt
nunca se aprobó, así que no se guardó — `/meta-prompt` no persiste nada hasta la aprobación. Lo que
sigue se reconstruyó el 2026-08-07 leyendo el artefacto: `docs/tickets/README.md` §"Cómo leer un
ticket", la auditoría INVEST, y los 49 archivos de ticket.

**Qué es fiable y qué no:** el contrato de salida (anatomía del ticket, tipos, escala de puntos,
agrupación, secciones del README) está verificado contra los archivos y es reproducible. El
*fraseo* de las instrucciones es inferencia — el prompt original pudo decir lo mismo con otras
palabras, o pudo incluir instrucciones cuyo efecto no quedó visible en la salida.

**Usarlo para regenerar tickets produciría un artefacto equivalente, no idéntico.** Si se usa, vale
más como plantilla para el próximo nivel de derivación que como reconstrucción histórica.

## Pedido original

No conservado. Del artefacto se deduce un pedido con esta forma:

> revisá `.claude/docs/stories/` y generá los tickets de trabajo en `.claude/docs/tickets/`,
> auditando INVEST y partiendo las historias que no cumplen

## Prompt

```markdown
# Rol

Actuás como **Senior Product Owner y Tech Lead** con experiencia en Scrum, refinamiento de
backlog y descomposición de historias, y con criterio técnico suficiente para leer un C4, un
DER y un DDL sin traductor.

# Objetivo

Convertir el backlog de **26 historias** de `.claude/docs/stories/` en **tickets de trabajo
ejecutables** en `.claude/docs/tickets/`, uno por archivo, ordenados por prioridad y agrupados
en bloques de trabajo.

El ticket no es un resumen de la historia: es el resultado de **rebanarla para corregir sus
fallas INVEST**. Una historia que puntúa mal en *Small* produce varios tickets; una que puntúa
mal en *Estimable* produce primero un spike, un trámite o una decisión, y recién después el
ticket de implementación.

# Contexto / entrada

Producto: SaaS multi-tenant **WhatsApp-First Scheduling & Quoting Middleware** para PyMEs
latinoamericanas de 3 a 20 empleados.

* **Stack:** Laravel 11+ (Queues + Redis + State Machine), Vue 3 (dashboard admin),
  Meta Cloud API, Google Calendar API (OAuth 2.0 FreeBusy + Sync), Google Sheets API.
* **Estrategia Lean:** ordenar por **riesgo mitigado**, no por valor entregado ni por el orden
  del flujo conversacional.

Fuentes obligatorias — **leelas todas antes de escribir un ticket**:

| Carpeta | Qué aporta al ticket |
| :-- | :-- |
| `.claude/docs/stories/` | Las 26 historias con sus AC en BDD, requisitos técnicos, estimación S/M/L, INVEST y notas Lean. **Es el insumo principal** |
| `.claude/docs/stories-maps/` | Slices, secuencia por riesgo, los 21 huecos y la matriz de trazabilidad |
| `.claude/docs/01-producto/` | RF-*, RNF-*, CA-*, alcance IN/OUT del MVP |
| `.claude/docs/02-arquitectura/` | C4 L2/L3, DER y DDL — para verificar que lo que el ticket produce tenga dónde persistir |
| `.claude/docs/03-flujos/` | Casos de uso, catálogo de estados, ramas de excepción |

# Instrucciones

## 1. Auditoría INVEST previa (antes de rebanar)

Puntuá **cada una de las 26 historias** en los seis criterios con `✅` cumple, `⚠️` parcial,
`❌` falla, y un total sobre 6. Esta auditoría es la que **determina el rebanado**: no la
escribas después para justificar los cortes que ya hiciste.

Reglas de lectura:

* Una falla de ***Estimable* casi nunca es complejidad de código.** Distinguí si lo que falta
  es tiempo de calendario de un tercero (→ ticket de **Trámite**), conocimiento de negocio
  (→ **Discovery**), una decisión que ya está escrita y nadie declaró (→ ticket de decisión), o
  una pregunta técnica genuinamente abierta (→ **Spike**). Solo el último caso es inestimable
  de verdad.
* Una falla de ***Negociable* o *Valiosa* en una historia de habilitación técnica no es un
  defecto corregible.** No le fabriques un beneficio de usuario final: etiquetala `Enabler`.
* Una restricción transversal **no es una historia.** Como ítem de backlog invita a marcarla
  "hecha" y violarla en silencio en cada historia nueva. Convertila en un ticket de `Calidad`
  cuyo entregable sea una suite de tests.
* ***Independiente* no se arregla rebanando.** Si el problema es la secuencia y no el tamaño,
  decilo y no finjas haberlo resuelto.

## 2. Rebanado

* **La historia indica dónde cortar.** El corte sale de sus propios AC y de su sección de
  requisitos técnicos, no de un criterio externo de tamaño.
* Sacá a ticket propio todo lo que **bloquea sin ser código**: trámites con terceros, spikes,
  discovery y decisiones abiertas. Van a un **bloque 0** que arranca el día 1 en paralelo.
* Cada **hueco bloqueante de modelo de datos** es un ticket de `Migración` propio, previo al
  ticket que lo consume.
* **Ninguna historia queda sin cubrir.** Al final, toda historia tiene al menos un ticket.

## 3. Anatomía del ticket (obligatoria y uniforme)

Encabezado `# T-NNN · <título>` seguido de una tabla de dos columnas sin encabezados con:
**Prioridad**, **Sprint**, **Tipo**, **Historia** (link relativo a `../stories/`),
**Story points**, **Depende de**, **Bloquea a**.

Después, en este orden:

| Sección | Regla |
| :-- | :-- |
| `## Contexto` | **Por qué existe**, en dos o tres oraciones. Hereda la nota Lean de la historia. No repite el alcance |
| `## Alcance` | Bullets de qué hay que construir. Concreto: endpoints, columnas, jobs |
| `## Criterios de aceptación` | Checkboxes verificables. **Los heredados citan su origen entre paréntesis en cursiva** — `*(AC-01.2)*`. Los que no citan nada son agregados nuestros y se nota |
| `## INVEST` | Tabla de una fila `I N V E S T` con ✅/⚠️/❌, y debajo un párrafo que diga **qué falla de la historia corrige este rebanado**. Omitir la sección entera en los tickets del bloque 0, que son `n/a` |
| `## Fuera de alcance` | Qué **no** entra y a qué ticket se fue. El corte tiene que ser explícito o no es un corte |
| `## Notas y riesgos` | Decisiones abiertas con ⚠️. Cuando el hueco se resuelve dentro del ticket, decir el candidato |

**Tipos:** `Feature` entrega valor de usuario · `Enabler` habilita features · `Migración`
cambia el modelo de datos · `Spike` responde una pregunta · `Trámite` depende de un tercero ·
`Calidad` no agrega funcionalidad.

## 4. Estimación

Fibonacci con **techo en 8** — por encima, partir:
`1` medio día · `2` un día · `3` uno o dos días · `5` tres o cuatro días · `8` una semana.

Los `Spike` llevan **timebox explícito** en la celda de puntos.

## 5. Agrupación y plan

Agrupá los tickets en un **bloque 0 de habilitación** más sprints numerados, cada uno con un
título que diga qué logra el sprint —no "Sprint 3" a secas— y su total de puntos.

Después del plan, señalá con ⚠️ los **desbalances y las posiciones falsas**: sprints
sobrecargados frente al promedio, y tickets cuya posición responde a la trazabilidad con su
historia y no a la prioridad real. Proponé el movimiento concreto y verificá que no rompa
dependencias.

## 6. Hallazgos transversales

Cerrá con los patrones que **solo se ven mirando el backlog entero**, cada uno con la evidencia
numérica que lo sostiene: en qué criterio INVEST mejoró el rebanado y en cuál no, qué clase de
historia puntúa mejor y por qué, y qué historias no deberían haber sido historias.

# Restricciones

* **No modifiques** `stories/`, `stories-maps/`, `01-producto/`, `02-arquitectura/` ni
  `03-flujos/`. La salida es aditiva.
* **No regeneres las historias.** Son insumo, no entregable. Se referencian por ID con link
  relativo.
* **No inventes el detalle que falta.** Marcá con ⚠️ toda decisión abierta o dato ausente.
  Un ticket que declara su hueco vale más que uno que lo tapa con una suposición.
* **No fabriques valor de usuario** para tickets que no lo tienen. Etiquetalos y seguí.
* Español rioplatense, mismo registro que el resto de la documentación.
* Nombres de archivo: `NNN-kebab-case.md`, minúsculas, ASCII, sin acentos ni `ñ`.

# Formato de salida

En `.claude/docs/tickets/`:

| Archivo | Contenido |
| :-- | :-- |
| `NNN-<slug>.md` | Un archivo por ticket, numerado por prioridad, con la anatomía de §3 |
| `README.md` | Índice y auditoría |

El `README.md` lleva, en este orden: total de tickets, puntos y escala · tabla "cómo leer un
ticket" · glosario de tipos · **orden de prioridad** con una tabla por bloque
(`# | Ticket | Historia | Pts | INVEST`) · ajustes de planificación recomendados · **auditoría
INVEST de las 26 historias** · tabla de salud por criterio comparando historias limpias contra
tickets limpios · hallazgos transversales · mejoras sugeridas por historia —solo las que el
rebanado **no** resolvió— · **trazabilidad historia → tickets** cerrando con la verificación de
que las 26 están cubiertas.
```

## Supuestos de la reconstrucción

1. **La auditoría INVEST precede al rebanado.** El README la presenta como "puntuación antes del
   rebanado" y la tabla de salud compara historias contra tickets, así que la auditoría es entrada
   del proceso y no una justificación posterior. Es el supuesto más fuerte de esta reconstrucción
   y el que más cambia el resultado si es falso.
2. **La anatomía de siete secciones estaba en el prompt, no emergió sola.** Los 49 archivos la
   respetan sin desviaciones, incluida la omisión de `## INVEST` en los siete del bloque 0.
3. **La convención `*(AC-XX.Y)*`** para trazar criterios heredados es explícita: aparece en todos
   los tickets derivados y nunca en los AC agregados.
4. **`n/a` en INVEST para el bloque 0** explica la diferencia entre los 49 tickets y el
   denominador `/42` de la tabla de salud por criterio. No es un error del documento.
5. **El total de tickets no estaba fijado en el prompt.** A diferencia del `[N]` de
   `generar-user-stories-mvp.md`, acá el número sale del rebanado: 49 es resultado, no parámetro.
6. **La sección de hallazgos transversales fue pedida explícitamente.** Seis hallazgos con
   evidencia numérica no salen de una instrucción genérica de "resumí".

## Resultado

Ejecutado el 2026-08-05 (con el prompt original, no con este). Artefacto en
`.claude/docs/tickets/`:

* 49 tickets `001-…` a `049-…`, **177 story points**, 9 bloques (bloque 0 + sprints 1 a 8)
* `README.md` con la auditoría INVEST de las 26 historias, salud por criterio, seis hallazgos
  transversales, mejoras por historia y trazabilidad completa

Hallazgo principal: ***Small* pasó de 13/26 a 38/42** —la mejora más grande del ejercicio— pero
***Independiente* sigue en 2/26 y el rebanado no lo mueve**, porque el problema es la secuencia y
no el tamaño. Siete historias eran dos o tres y produjeron 18 tickets entre ellas.
