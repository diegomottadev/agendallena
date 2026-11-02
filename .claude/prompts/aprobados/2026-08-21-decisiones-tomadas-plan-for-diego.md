---
fecha: 2026-08-21
objetivo: Recorrer con preguntas de opción múltiple las decisiones abiertas de plan-for-diego, registrarlas en decisiones-tomadas.md y derivar de ahí el plan de avance
estado: aprobado
---

## Pedido original

bueno vamos a armar el plan en base a preguntas y vos me das las opciones para responder de acuerdo a lo hay de documentos y tickets y como avanceremos todas las respuesta la cargamos en plan for diego en un archivo que se llama decisiones tomadas para poder contestar lo pendientes de c:\Users\ACER\Documents\Develop\agendallena\.claude\docs\plan-for-diego\decisiones-pendientes.md c:\Users\ACER\Documents\Develop\agendallena\.claude\docs\plan-for-diego\preguntas-que-salieron-construyendo.md

## Prompt

# Rol

Sos el asistente técnico de AgendaLlena. Conocés el repo, la cadena de derivación
PRD → story maps → stories → tickets → estimación, y el estado del código
(428 tests en verde, núcleo del MVP completo, sin commits todavía).

# Objetivo

Armar el plan de avance **a través de preguntas cerradas**: presentarle a Diego,
una por una, las decisiones que siguen abiertas en los dos archivos de abajo,
cada una con **opciones concretas de respuesta** derivadas de lo que ya dicen los
documentos y los tickets — no de opciones inventadas. Cada respuesta que Diego dé
se registra en un archivo nuevo, `decisiones-tomadas.md`, dentro de
`plan-for-diego`. El archivo tiene que quedar en condiciones de **responder los
pendientes** de los dos archivos fuente.

# Contexto / entrada

Fuentes obligatorias, en este orden:

1. `.claude/docs/plan-for-diego/decisiones-pendientes.md`
   — 7 decisiones. Las 1, 2, 3 y 5 ya están cerradas: **no las vuelvas a preguntar**.
   Abiertas: 4 (cotizador), 6 (planilla de Google compartida), 7 (orden del trabajo).
2. `.claude/docs/plan-for-diego/preguntas-que-salieron-construyendo.md`
   — 7 preguntas más las 2 bis / 2 ter, todas con una interpretación ya elegida y
   su justificación. Lo que se pide es confirmar o corregir.
3. `.claude/docs/plan-for-diego/README.md` — el orden sugerido y los bloqueos de Diego.
4. `.claude/docs/tickets/` y `.claude/docs/estimacion-backlog/` — para poner en cada
   opción **qué ticket destraba y cuántos puntos**.
5. `CLAUDE.md` — estado, decisiones ya cerradas, huecos bloqueantes.

# Instrucciones

1. **Leé primero, preguntá después.** Antes de la primera pregunta, leé los cinco
   puntos del contexto y armá la lista de lo que sigue abierto. Si algo que el
   archivo marca como abierto ya está cerrado en otro lado (o al revés), decilo
   antes de preguntar y no lo cuentes dos veces.

2. **Ordená las preguntas por cuánto destraban**, respetando el orden que ya traen
   los archivos fuente: primero las 🔴 de `preguntas-que-salieron-construyendo`
   (número falso en la métrica que sostiene el precio, recordatorio que nunca sale),
   después las 🟡 que cambian lo que el cliente ve, después las ⚪ internas, y al
   final las de `decisiones-pendientes` que solo frenan cosas fuera del MVP.

3. **Preguntá con la herramienta de opciones (`AskUserQuestion`), no en prosa.**
   Por tanda, hasta 4 preguntas. Para cada una:
   - Las opciones salen de las alternativas que **ya están escritas** en el archivo
     fuente. Si el archivo trae una recomendación, va **primera y marcada
     `(Recomendada)`**.
   - En la descripción de cada opción va la consecuencia concreta: qué ticket
     destraba o frena, cuántos puntos, qué cambia para el cliente o para el número
     del piloto. Los números salen de los documentos, no de tu estimación.
   - Si una opción implica trabajo nuevo, decí si es un ciclo TDD corto o un ticket.

4. **Escribí la respuesta apenas la tengas**, no al final. Después de cada tanda,
   agregá esas decisiones a `decisiones-tomadas.md`. Si la sesión se corta, lo
   respondido queda escrito.

5. **Cerrá con el plan de avance.** Una vez respondido todo, agregá al final del
   archivo la sección de cómo seguimos: qué tickets quedan habilitados, en qué
   orden, cuáles siguen bloqueados y por quién (Diego / Meta / Google), y qué
   ciclos TDD dispara cada decisión. El orden se apoya en el que ya propone el
   README, no lo reinventes.

6. **Actualizá las referencias cruzadas**, sin reescribir los archivos fuente:
   en `decisiones-pendientes.md` y `preguntas-que-salieron-construyendo.md`
   marcá cada punto respondido como cerrado con la fecha y un link a
   `decisiones-tomadas.md`. El texto original de cada punto se conserva.

# Restricciones

- **No escribas código de implementación en esta sesión.** El entregable es el
  archivo de decisiones y el plan. Si una decisión pide código, queda anotada
  como ciclo TDD pendiente con su ticket.
- **No inventes opciones ni números.** Si algo no está en los documentos, marcalo
  con `⚠️` y preguntá, no lo rellenes.
- **No decidas por Diego.** Podés recomendar —y tenés que fundamentar— pero la
  respuesta la pone él. Si contesta "dale" a una recomendación, se registra como
  suya, citando la recomendación que aceptó.
- Español rioplatense, técnico y preciso. Nombre de archivo sin acentos ni `ñ`;
  el contenido, con todos los que hagan falta.
- Si un archivo `decisiones-tomadas.md` ya existe, **agregá una sección nueva con
  la fecha; no lo sobrescribas.**

# Formato de salida

Un archivo en `.claude/docs/plan-for-diego/decisiones-tomadas.md` con una entrada
por decisión (origen, estado previo, respuesta de Diego, fundamento, qué destraba,
qué hay que construir), una tabla final "Cómo avanzamos" y una sección
"Lo que sigue abierto".

Y en el chat, después de cada tanda: una línea por decisión registrada
(qué se respondió, qué destrabó) y la tanda siguiente. Nada de resúmenes largos.

## Supuestos

- Nombre y ubicación del archivo: `decisiones-tomadas.md` en
  `.claude/docs/plan-for-diego/` (kebab-case ASCII, sin prefijo `NN-` porque los
  otros tres archivos de esa carpeta tampoco lo llevan).
- "Preguntas con opciones" = `AskUserQuestion`, no una lista en prosa.
- Solo se preguntan los puntos abiertos: las decisiones 1, 2, 3 y 5 ya figuran cerradas.
- Registro incremental en vez de un volcado final.
- El prompt no implementa nada: lo que salga en código queda anotado como ciclo TDD pendiente.

## Resultado

Ejecutado el 2026-08-21. Las 12 decisiones abiertas quedaron respondidas en tres tandas.

**Creado:**
- `.claude/docs/plan-for-diego/decisiones-tomadas.md` — 12 decisiones con fundamento, tabla *Cómo avanzamos*, *Lo que sigue abierto* y las 4 ya cerradas como referencia.

**Modificado:**
- `.claude/docs/plan-for-diego/preguntas-que-salieron-construyendo.md` — banner + marcador de respondida en las 9 secciones. Texto original intacto.
- `.claude/docs/plan-for-diego/decisiones-pendientes.md` — banner + marcador en las secciones 4, 6 y 7.
- `.claude/docs/plan-for-diego/README.md` — puntero al archivo nuevo.
- `CLAUDE.md` — *Decisiones abiertas* ya no tiene ninguna abierta: se cerró la fila de **rebanadas verticales**.

⚠️ **Incidente durante la ejecución:** un script de Python truncó `preguntas-que-salieron-construyendo.md` a 0 bytes (`open(...,'w')` abre y trunca antes de que falle el `encode` del emoji, y el repo no tenía commit base para recuperarlo). Se reconstruyó desde el contenido leído al inicio de la sesión y se verificó byte a byte: **9818 bytes, idéntico al original**. Los scripts posteriores escriben a `.tmp` y recién después hacen `os.replace`.
