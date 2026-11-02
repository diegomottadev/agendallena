---
fecha: 2026-08-05
objetivo: Cruzar el backlog existente contra toda la documentación (producto, arquitectura, flujos) y producir el conjunto de story maps del MVP, con huecos, contradicciones y corte por slices.
estado: aprobado
---

## Pedido original

> ejecuta c:\Users\ACER\Documents\Develop\agendallena\.claude\commands\meta-prompt.md sobre
> C:\Users\ACER\Documents\Develop\agendallena\.claude\prompts\generar-user-stories-mvp.md
> ejecuta y revisa todo c:\Users\ACER\Documents\Develop\agendallena\.claude\docs\01-producto,
> c:\Users\ACER\Documents\Develop\agendallena\.claude\docs\02-arquitectura,
> c:\Users\ACER\Documents\Develop\agendallena\.claude\docs\03-flujos y crea las stories maps en
> C:\Users\ACER\Documents\Develop\agendallena\.claude\docs\stories-maps

El prompt de entrada al `/meta-prompt` fue [`generar-user-stories-mvp.md`](../generar-user-stories-mvp.md).

## Prompt

```markdown
# Rol

Actuás como **Senior Product Owner y Strategist de Producto** con experiencia en metodologías
ágiles, Scrum, Lean Startup, Product Discovery y User Story Mapping, y con criterio técnico
suficiente para leer un C4, un DER y un diagrama de secuencias sin traductor.

# Objetivo

Producir el **conjunto de story maps del MVP** de AgendaLlena en
`.claude/docs/stories-maps/`, cruzando el backlog de historias ya escrito contra **toda** la
documentación de producto, arquitectura y flujos, para dejar a la vista:

1. dónde cae cada historia dentro del recorrido real del usuario,
2. qué pasos del recorrido **ningún** ítem del backlog cubre (huecos),
3. qué **contradicciones** existen entre documentos que hoy nadie está resolviendo,
4. qué entra en el MVP, qué se difiere y con qué justificación de riesgo.

# Contexto / entrada

Producto: SaaS multi-tenant **WhatsApp-First Scheduling & Quoting Middleware** para PyMEs
latinoamericanas de 3 a 20 empleados (salud y estética, distribuidoras B2B, inmobiliarias,
servicios de mantenimiento).

* **Propuesta de valor:** cotizar y agendar el 100% de las consultas de WhatsApp en menos de
  15 segundos sin obligar al equipo a cambiar de herramientas (siguen en Google Calendar y
  Google Sheets).
* **Stack:** Laravel 11+ (Queues + Redis + State Machine), Vue 3 (dashboard admin),
  Meta Cloud API, Google Calendar API (OAuth 2.0 FreeBusy + Sync), Google Sheets API.
* **Estrategia Lean:** priorizar la validación de riesgo de habilitación (Meta), de
  factibilidad (latencia, sincronización), de adopción (que el bot no pise a la venta humana)
  y de valor (ausentismo) antes que cualquier funcionalidad de escala.

Fuentes obligatorias — **leelas todas antes de escribir una línea del mapa**:

| Carpeta | Archivos | Qué aporta al mapa |
| :-- | :-- | :-- |
| `.claude/docs/01-producto/` | `01-lean-canvas.md`, `02-prd.md`, `03-user-stories-mvp.md`, `04-user-story-map.md` | KPIs, alcance IN/OUT, RF-*, RNF-*, CA-*, backlog y mapa previo |
| `.claude/docs/02-arquitectura/` | `01-c4-nivel-2-contenedores.md`, `02-c4-nivel-3-componentes.md`, `03-der-entidad-relacion.md`, `04-modelo-de-datos.md` | Componentes, entidades, DDL, índices, enums — insumo para detectar historias sin dónde persistir |
| `.claude/docs/03-flujos/` | `01-casos-de-uso-general.md`, `02-casos-de-uso-cliente-final.md`, `03-casos-de-uso-equipo-pyme.md`, `04-flujo-whatsapp-cliente-final.md`, `05-estados-flujo-conversacional.md`, `06-secuencia-agenda-cotizacion-e2e.md` | Pasos concretos del recorrido, catálogo de estados, caminos de excepción y ramas de error |
| `.claude/docs/stories/` | `US-01` … `US-17` | Historias ya escritas, con AC en BDD, estimación S/M/L, INVEST y notas Lean |

# Instrucciones

## 1. Backbone (eje horizontal)

Construí el backbone a partir de los **flujos y casos de uso reales**, no del índice del PRD.
Separalo en tres perspectivas:

* **Cliente final (WhatsApp)** — desde el primer mensaje hasta la asistencia al turno,
  incluyendo los caminos de excepción del flujo (colisión de horario, sin disponibilidad,
  entrada inválida, caída de API).
* **Equipo PyME (dashboard, calendario y app móvil)** — desde el alta de la cuenta hasta la
  resolución de incidentes.
* **Plataforma (enablers técnicos)** — la cadena ingesta → estado → decisión → efecto en
  terceros → recordatorio → aislamiento → persistencia, derivada del C4 y del modelo de datos.

Debajo de cada actividad, desglosá los **pasos concretos** del usuario. Un paso es algo que la
persona hace o recibe, no un módulo del sistema.

## 2. Poblado del mapa con el backlog existente

* **Referenciá las historias por su ID** (`US-01` … `US-17`) con enlace relativo al archivo en
  `../stories/`. **No regeneres ni renombres historias existentes.**
* Cuando una historia cubra el paso **a medias**, marcala `parcial` y decí exactamente qué
  falta.
* Cuando **ninguna** historia cubra el paso, marcalo **`HUECO`**. Los huecos son el hallazgo
  más valioso del ejercicio: no los rellenes con historias inventadas.
* Para cada hueco, dejá una **historia candidata** en formato mínimo de post-it —
  título, "Como/quiero/para" en una línea, estimación tentativa S/M/L y slice sugerido — sin
  escribir el archivo de historia completo. Escribir la historia es un paso posterior.

## 3. Cruce con arquitectura y datos (obligatorio)

Para cada paso del mapa, verificá contra `02-arquitectura/`:

* ¿Existe el **componente** que lo ejecuta en el C4 nivel 3?
* ¿Existe la **tabla y las columnas** donde persiste lo que produce?
* ¿El **enum** admite los valores que el paso necesita?
* ¿Los **índices** soportan la consulta que el paso dispara dentro del presupuesto del RNF-04?

Toda historia del MVP que no tenga dónde persistir, o cuyo enum no admita el estado que
declara, es un **hueco bloqueante** y debe listarse como tal.

## 4. Detección de contradicciones entre documentos

Cuando dos documentos afirmen cosas incompatibles sobre el mismo hecho, registralo con:
la afirmación de cada documento **citada**, el archivo donde vive, a qué historias afecta, y
la lectura recomendada. No la resuelvas por decreto si es una decisión de producto:
marcala como **decisión abierta con dueño y fecha de cierre sugerida**.

Si una contradicción declarada en el mapa anterior **ya no se sostiene** contra los archivos
actuales, decilo explícitamente en vez de arrastrarla.

## 5. Slices verticales (eje vertical)

Clasificá cada ítem del mapa en:

* **Slice 0 — Habilitación:** trámites externos, spikes y decisiones que bloquean historias y
  no son historias. Corren en paralelo, sin código.
* **Slice 1 — MVP (Release 1.0):** lo indispensable para operar, cotizar, agendar, recordar y
  **medir** el ausentismo.
* **Slice 2 — v2 (Post-MVP):** optimización y escala (cobro de señas, editor visual de flujos,
  multi-sucursal, CRM, integraciones adicionales, métricas en panel).

Un ítem entra al Slice 1 **solo** si sin él no se puede validar un supuesto de riesgo. Que sea
útil no alcanza.

## 6. Secuencia y priorización

Después del mapa, proponé el **orden exacto de ejecución** por sprint, incorporando los huecos
nuevos en la posición que les corresponde, y justificá cada decisión de orden contra el riesgo
que mitiga —no contra el valor que entrega ni contra el orden del flujo conversacional.

Para cada ítem que se mueve respecto de la priorización vigente en
`01-producto/03-user-stories-mvp.md`, explicitá **por qué se movió**.

# Restricciones

* **No inventes datos.** Toda afirmación del mapa debe poder rastrearse a un archivo concreto
  del corpus. Si algo no está escrito en ningún lado, eso *es* el hallazgo: marcalo como hueco,
  no lo completes con criterio propio.
* **No modifiques** los archivos de `01-producto/`, `02-arquitectura/`, `03-flujos/` ni
  `stories/`. La salida es aditiva.
* **No regeneres el backlog.** Las 17 historias existen y son insumo, no entregable.
* Español rioplatense, mismo registro que el resto de la documentación.
* Nombres de archivo según la convención del repo: `NN-kebab-case.md`, minúsculas, ASCII, sin
  acentos ni `ñ`.

# Formato de salida

Escribí en `.claude/docs/stories-maps/` un conjunto de documentos markdown, uno por mapa, más
un `README.md` que los indexe y explique cómo leerlos:

| Archivo | Contenido |
| :-- | :-- |
| `README.md` | Índice, leyenda, y qué agrega este conjunto sobre el mapa previo |
| `01-mapa-cliente-final.md` | Backbone del cliente final, pasos, historias, slices y huecos |
| `02-mapa-equipo-pyme.md` | Backbone de la PyME, ídem |
| `03-mapa-plataforma-y-datos.md` | Backbone de enablers, con el cruce contra C4 y modelo de datos |
| `04-slices-y-secuencia.md` | Corte vertical Slice 0/1/2, orden por sprint y justificación por riesgo |
| `05-huecos-y-trazabilidad.md` | Tabla de huecos con historia candidata, contradicciones abiertas y matriz de trazabilidad historia ↔ RF/CA ↔ caso de uso ↔ estado ↔ tabla |

Dentro de cada mapa, usá una tabla por actividad con las columnas
`Paso | Historia | Cobertura | Slice`, y una nota debajo de cada tabla solo cuando haya algo
que un lector no pueda deducir de la tabla sola.

Cerrá `04-slices-y-secuencia.md` con un resumen ejecutivo que responda dos preguntas:
**por qué lo que quedó afuera puede quedar afuera**, y **cómo este corte valida los supuestos
de mayor riesgo lo antes posible**.
```

## Supuestos

1. **El entregable cambió de nivel por pedido explícito del usuario.** El prompt original genera
   historias; el pedido fue story maps y el backlog ya existe (17 historias en `docs/stories/`).
   El prompt mejorado conserva íntegro el *método* del original —INVEST, criterios BDD, notas
   Lean, agrupación temática, priorización justificada por riesgo— pero lo aplica sobre el corpus
   documental completo y materializa la salida como mapas. Las historias existentes se reusan por
   ID, no se regeneran.
2. **"Las stories maps" en plural** se interpretó como un conjunto de mapas por perspectiva
   (cliente final / PyME / plataforma) más los cortes transversales, no como un único archivo.
3. **`[N]` desapareció** porque sin generación de historias nuevas no hay cantidad que fijar. Los
   huecos determinan cuántas historias candidatas salen.
4. **Se agregó la perspectiva "Plataforma"**, que el prompt hermano `generar-user-story-map.md` no
   tiene, porque es la única forma de que `02-arquitectura/` aporte al mapa en lugar de ser
   decorado.

## Resultado

Ejecutado el 2026-08-05. Archivos creados en `.claude/docs/stories-maps/`:

* `README.md` — índice, leyenda y qué agrega este conjunto sobre `01-producto/04-user-story-map.md`
* `01-mapa-cliente-final.md` — backbone A1–A9 del recorrido por WhatsApp
* `02-mapa-equipo-pyme.md` — backbone B1–B6 del recorrido en panel, calendario y app móvil
* `03-mapa-plataforma-y-datos.md` — backbone C1–C8 de enablers, cruzado contra C4 y modelo de datos
* `04-slices-y-secuencia.md` — Slice 0/1/2, orden por sprint y justificación por riesgo
* `05-huecos-y-trazabilidad.md` — 21 huecos con historia candidata, 6 contradicciones y matriz de trazabilidad

Archivo modificado: `.claude/docs/README.md` (fila de la sección nueva en el índice).

Hallazgos principales: **128 pasos mapeados**, 21 huecos (contra los 6 del mapa previo), de los
cuales **5 son bloqueantes** —cuatro de modelo de datos: US-15, US-14 y US-17 no tienen dónde
persistir lo que declaran, y faltan los índices compuestos que el propio documento afirma tener—
y **2 de las 3 decisiones abiertas del backlog quedaron respondidas** por los documentos de
arquitectura y flujos, lo que vuelve estimables a US-03 y US-14.
