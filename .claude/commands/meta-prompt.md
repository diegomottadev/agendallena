---
description: Reescribe un prompt crudo aplicando ingeniería de prompts (rol, objetivo, contexto, formato de salida) sin cambiar lo que pide el original.
argument-hint: <prompt original a mejorar>
---

# Instructions

You are an expert in prompt engineering.

Given the following prompt, prepare it using best-practice structure (role, objective, etc.) and formatting to achieve a precise and comprehensive result. Stick strictly to the requested objective by carefully analyzing what is asked in the original prompt.

# Original Prompt:

$ARGUMENTS

---

## Reglas de ejecución

- Si `$ARGUMENTS` viene vacío: pedí el prompt original y frená (no inventes uno).
- **No amplíes ni recortes el objetivo.** Estructurás y clarificás lo que el prompt ya pide; no agregás
  requisitos nuevos ni sacás los existentes.
- Mantené el **idioma del prompt original** en el resultado.
- Si el original tiene ambigüedades que cambian el resultado, resolvelas con el supuesto más razonable y
  listalas al final bajo **Supuestos**; no interrumpas para preguntar.
- **No ejecutes el prompt** — tu entrega es el prompt mejorado, no su resultado.

## Salida

Devolvé el prompt mejorado dentro de un bloque de código markdown (listo para copiar), y nada más antes de él.
Después del bloque, solo si aplica, los **Supuestos**. Cerrá ofreciendo ejecutarlo o ajustarlo.

## Persistir el prompt aprobado

**No guardes nada al generar.** Solo se archivan los prompts que el usuario aprobó, porque el valor está en
tener los que sirvieron, no todos los intentos.

Cuando el usuario apruebe —"ejecutá el prompt", "dale", "está bien", o pida directamente guardarlo—, escribí
el prompt **en su versión final** (con los ajustes que haya pedido, no el borrador) en:

```
.claude/prompts/aprobados/YYYY-MM-DD-<slug>.md
```

`<slug>` en kebab-case, corto y descriptivo del objetivo, no del pedido literal: `comando-commit`,
`skill-explore-codebase`, `merge-prod-a-dev`. Si el archivo ya existe, agregá `-2` en vez de pisarlo — un
prompt aprobado no se sobrescribe.

Formato del archivo:

```markdown
---
fecha: YYYY-MM-DD
objetivo: <una línea — qué logra este prompt>
estado: aprobado
---

## Pedido original

<el `$ARGUMENTS` crudo, textual>

## Prompt

<el prompt mejorado, verbatim>

## Supuestos

<los supuestos, si los hubo>

## Resultado

<qué se produjo al ejecutarlo: archivos creados o modificados. Completar al terminar la ejecución;
si no se ejecutó, poner "no ejecutado">
```

Guardar el pedido original al lado del prompt final es lo que hace útil el archivo después: permite ver qué
transformación se aplicó, no solo el resultado. Avisá en una línea dónde quedó guardado.

Estructura sugerida del prompt mejorado (adaptala a lo que el original necesite; no fuerces secciones vacías):

```
# Rol
# Objetivo
# Contexto / entrada
# Instrucciones (pasos o reglas)
# Restricciones
# Formato de salida
# Ejemplos  (solo si aportan)
```
