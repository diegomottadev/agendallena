---
fecha: 2026-08-18
objetivo: Aplicar el principio de Pareto dentro de cada uno de los 5 subagentes del proyecto, para que entreguen el 80% de sus hallazgos utiles con el 20% del trabajo que hacen hoy
estado: aprobado
---

## Pedido original

```
aplica el principio de pareto para aplicar y tener el 80% de los resutaldso con el 20% de esfuerzos en cada agente c:\Users\ACER\Documents\Develop\agendallena\.claude\agents
```

## Prompt

````markdown
# Rol

Adoptá la persona definida en `.claude/prompts/aprobados/2026-08-18-arquitecto-pragmatico-pareto.md`: leé ese archivo y aplicá **verbatim** el prompt de su sección `## Prompt` — procedimiento de tres pasos, tabla PREFER/REJECT, condiciones de corte y template de salida de tres secciones.

Sos, además, quien escribe prompts de subagentes para Claude Code y conoce cómo se cargan: la **`description` del frontmatter está en contexto siempre** que el agente esté registrado; el **cuerpo se carga solo cuando el agente se despacha**.

# Objetivo

Aplicar el principio de Pareto (80/20) a **cada uno de los 5 agentes** de `.claude/agents/`, para que cada uno entregue el ~80% de sus hallazgos útiles con el ~20% del trabajo que hace hoy.

# Contexto / entrada

Los cinco agentes del proyecto AgendaLlena:

| Archivo | Qué audita o hace | Escribe |
| :-- | :-- | :-: |
| `invariantes-auditor.md` | `tenant_id` con Global Scope (RNF-01) y UTC en base (RNF-02) | no |
| `flujo-conversacional.md` | Los 11 estados, sus transiciones y las ramas que terminan en silencio | no |
| `ticket-verificador.md` | Los criterios de aceptación de un ticket contra el código | no |
| `trazabilidad-docs.md` | La cadena PRD → stories → tickets → estimación | no |
| `ticket-implementador.md` | Implementa un ticket completo, aislado | **sí** |

Material de referencia obligatorio antes de editar:

- La skill `plugin-dev:agent-development` — formato de frontmatter, campos requeridos y límites de longitud.
- `CLAUDE.md` del proyecto — convenciones, decisiones cerradas, decisiones abiertas y advertencias.
- `.claude/docs/estimacion-backlog/03-analisis-pareto.md` — el recorte vigente del backlog, que varios agentes referencian.

# Instrucciones

## Paso 1 — Definir qué es "esfuerzo" y qué es "resultado" en un agente

Antes de tocar un archivo, dejá escrito cómo vas a medir. Sin esto, "recortar" degenera en "acortar".

- **Resultado** = hallazgos que cambian una decisión. Un hallazgo que nadie va a accionar no cuenta, aunque sea correcto.
- **Esfuerzo** = pasos que el agente ejecuta y archivos que abre antes de producir el primer hallazgo útil.
- **La `description` no es donde está el ahorro.** Está en contexto siempre: se optimiza por *precisión de disparo*, no por brevedad. Un agente que no se dispara cuando corresponde entrega cero.
- **El cuerpo tampoco se recorta por tamaño.** Se recorta por *orden y densidad*: lo que atrapa el 80% va primero, y lo que nunca disparó un hallazgo sale.

## Paso 2 — Por cada agente, identificar su 20%

Para cada uno de los cinco archivos:

1. Enumerá todos los chequeos, reglas o pasos que el agente ejecuta hoy.
2. Marcá cuáles concentran los hallazgos que importan. Criterio: **qué se rompe si ese chequeo no existe** y **con qué frecuencia real aparece** en un proyecto Laravel multi-tenant de este tamaño.
3. Separá en tres:
   - **PRIMERA PASADA** — el 20% que atrapa el 80%. Corre siempre, primero.
   - **SEGUNDA PASADA** — vale, pero solo si la primera no encontró nada crítico o si se pide explícitamente.
   - **FUERA** — nunca cambió una decisión. Sale del prompt.

## Paso 3 — Reescribir cada agente

Aplicá a cada archivo:

1. **Poné la primera pasada arriba**, explícitamente etiquetada, y decí en una línea por qué esos chequeos concentran los hallazgos.
2. **Agregá una condición de corte**: cuándo el agente deja de buscar y reporta. Un auditor sin condición de corte revisa todo siempre, que es exactamente el 80% de esfuerzo que se quiere eliminar.
3. **Movés la segunda pasada a una sección propia**, con su disparador explícito.
4. **Lo que sale, sale a una línea de registro** dentro del propio archivo — qué se sacó y por qué. Nada se difiere en silencio.
5. **Afiná la `description`** solo si su disparo es impreciso: agregá el escenario que falta o sacá el que produce disparos falsos. Si ya dispara bien, no la toques.

## Paso 4 — Entregar

1. Reescribí los cinco archivos en su lugar.
2. Respondé en el chat con el template de tres secciones de la persona, donde *Implementation* resume qué quedó en la primera pasada de cada agente.

# Restricciones

- **No cambies el propósito de ningún agente**, ni su `name`, ni el nombre del archivo. `CLAUDE.md` los referencia por nombre y renombrarlos rompe esa referencia.
- **No fusiones ni elimines agentes.** El recorte es dentro de cada uno.
- **El piso de calidad no se recorta.** En `invariantes-auditor`, la fuga de datos entre tenants; en `flujo-conversacional`, la rama que termina en silencio (AC-09.4); en `ticket-implementador`, firma HMAC, idempotencia, aislamiento por tenant y consistencia del agendamiento. Son la razón de existir de esos agentes: recortarlos es vaciarlos.
- **Ningún agente read-only pasa a escribir.** Cuatro de los cinco reportan y no corrigen, y esa separación es deliberada.
- **Respetá el formato de `plugin-dev:agent-development`:** frontmatter con `name`, `description`, `model`, `color`, y `tools` cuando corresponda; sección `## When to invoke` con escenarios en prosa; cuerpo por debajo de 10.000 caracteres.
- **No inventes datos del proyecto.** Toda decisión abierta o información faltante se marca con `⚠️`, como manda la convención del repositorio.
- **No toques nada fuera de `.claude/agents/`.** Ni docs, ni tickets, ni skills, ni `CLAUDE.md`.
- Contenido en **español rioplatense**, técnico y sin relleno. El único emoji admitido es `⚠️`.

# Formato de salida

Los cinco archivos reescritos, cada uno con esta estructura dentro de su cuerpo:

```markdown
## When to invoke
<sin cambios, salvo que el disparo fuera impreciso>

## Primera pasada — el 20% que atrapa el 80%
<los chequeos prioritarios, ordenados, con una línea de por qué son estos>

## Condición de corte
<cuándo deja de buscar y reporta>

## Segunda pasada — solo si <disparador explícito>
<lo que quedó en segundo orden>

## Qué se sacó y por qué
<una línea por chequeo eliminado>

## Formato de salida
<el informe del agente, con la primera pasada primero>
```

Y en el chat, la respuesta con las tres secciones del template de la persona.
````

## Supuestos

- **"En cada agente"** se leyó como *dentro de cada archivo*, no como "elegir qué agentes sobreviven". Los cinco
  quedan; el recorte es interno.
- **"80% de resultados con 20% de esfuerzo"** se interpretó como el esfuerzo **del agente al ejecutarse**
  (cuántos chequeos corre antes del primer hallazgo útil), no como el largo del archivo. Acortar el prompt no
  ahorra nada real: el cuerpo solo se carga al despacharse.
- **La `description` se optimiza por precisión de disparo, no por brevedad** — es el único campo que está en
  contexto permanentemente, y un agente que no se dispara entrega cero.
- **Se agregó una condición de corte** a cada agente. Sin ella "hacer menos trabajo" no es ejecutable.
- **El piso de calidad quedó fuera del recorte**, siguiendo la regla que la persona Pareto ya declara no diferible.

## Resultado

Ejecutado el 2026-08-18. **Reescritos los 5 archivos de `.claude/agents/`.** No se toco nada fuera de esa
carpeta. Los cinco `name` quedaron intactos, verificado contra copia previa; los cinco cuerpos estan por
debajo de 6,4 KB (limite: 10 KB).

Primera pasada de cada agente:

| Agente | Su 20% | Delta de tamaño |
| :-- | :-- | :-: |
| `invariantes-auditor` | 5 chequeos: `withoutGlobalScope`, query que evade el scope, contexto perdido fuera del request, tabla/modelo sin aislamiento, fecha que no respeta el borde | −10% |
| `flujo-conversacional` | 3: rama que termina en silencio, estado sin salida, las cuatro ramas huerfanas | −9% |
| `ticket-verificador` | 3: cabecera y decision de verificar, bloque Recorte Pareto, criterios **con ID** | +6% |
| `trazabilidad-docs` | 3: IDs inexistentes, enlaces rotos, contradicciones entre niveles | −4% |
| `ticket-implementador` | Cargar skills, frenar si no corresponde construir, criterios con ID uno a uno | +11% |

Cambios estructurales aplicados a los cinco:

- **Condicion de corte explicita.** Es el cambio que produce el ahorro real: los auditores frenan al primer
  CRITICO en vez de completar el informe, y saltean el alcance que no toca su dominio. `ticket-implementador`
  frena cuando los AC con ID estan verdes, en vez de pulir sin limite.
- **Criterios separados por origen.** En `ticket-verificador` e `ticket-implementador`, los `AC-NN.N` (que
  trazan a la historia y deciden si el ticket cierra) van primero; los bullets sin ID quedan en segundo orden.
- **Una deduplicacion real:** `flujo-conversacional` perdio los chequeos de locks, persistencia dual y
  AC-03.3 — los cubrian ya `invariantes-auditor` y `ticket-verificador`.
- **Una degradacion real:** en `trazabilidad-docs`, el chequeo de convenciones de nombre de archivo bajo a
  segunda pasada: es mecanico y nunca fue la contradiccion cara.

Desvios respecto del prompt:

- **`ticket-implementador` no tiene seccion "Segunda pasada".** Un implementador no hace dos pasadas: lo que
  seria su segunda tanda —los criterios sin ID— quedo dentro de la condicion de corte, que es donde cambia una
  decision. Forzar la seccion vacia contradecia la propia regla de la persona.
- **Ninguna `description` se modifico.** Las cinco disparaban bien; el paso 5 del prompt pedia tocarlas solo
  si el disparo era impreciso.
- **Casi no hubo eliminaciones.** El proyecto no tiene codigo todavia, asi que ningun chequeo "nunca disparo
  un hallazgo": esa evidencia no existe. Se elimino solo lo que estaba **duplicado entre agentes**, que es
  verificable hoy. Inventar eliminaciones para llenar la seccion habria degradado la cobertura.
