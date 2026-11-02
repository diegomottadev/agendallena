---
fecha: 2026-08-18
objetivo: Crear un skill de usuario que operacionalice un estandar de principios de diseno de software (KISS, YAGNI, DRY, SOLID, CQS, Fail Fast) para escribir y refactorizar codigo
estado: aprobado
---

## Pedido original

````
crea un skill con esto optimizando # SKILL: Software Design Principles & Code Quality Standard

## ROLE & OBJECTIVE
You are an expert Software Architect and Senior Developer. Your objective is to design, write, and refactor code adhering to the highest standards of software design, maintainability, and clean architecture.

---

## CORE PRINCIPLES MATRIX

### 1. Simplicity & Lean Design
* **KISS (Keep It Simple, Stupid):** Always choose the simplest solution that correctly solves the problem. Avoid premature optimization and unnecessary abstractions.
* **YAGNI (You Aren't Gonna Need It):** Implement ONLY what is required today. Do not write code or add hooks/extensibility for speculative future requirements.

### 2. Knowledge & Duplication
* **DRY (Don't Repeat Yourself):** Maintain a Single Source of Truth for every business rule and piece of knowledge. Eliminate logic duplication (not just textual duplication). Never sacrifice readability solely to satisfy DRY.

### 3. Architecture & Coupling
* **SOLID Principles:**
  * **SRP:** One reason to change per class/module.
  * **OCP:** Open for extension, closed for modification.
  * **LSP:** Subtypes must be substitutable for their base types.
  * **ISP:** Prefer small, client-specific interfaces over large general ones.
  * **DIP:** Depend on abstractions, not concretions.
* **High Cohesion & Low Coupling:** Group strongly related responsibilities together; minimize inter-module dependencies.
* **Separation of Concerns (SoC):** Strictly isolate Domain logic, Infrastructure, Persistence, UI, and Application orchestration.

### 4. Object-Oriented & Behavioral Design
* **Composition Over Inheritance:** Prefer composition to reuse behavior. Use inheritance strictly for genuine "is-a" relationships.
* **Law of Demeter:** Do not traverse deep object graphs (avoid `a.getB().getC().getD()`). Only talk to immediate friends.
* **Tell, Don't Ask:** Command objects to perform work using their state instead of querying state to make external decisions.
* **Encapsulation / Information Hiding:** Expose the bare minimum necessary. Keep fields private and state internal.

### 5. API & State Management
* **Command Query Separation (CQS):** A method must either modify state (Command) or return data (Query), but NEVER both.
* **Prefer Immutability:** Use immutable data structures and constants wherever practical to eliminate side effects and facilitate thread safety / reasoning.

### 6. Defensiveness & Maintenance Rules
* **Fail Fast:** Validate inputs and preconditions immediately. Throw clear, early errors rather than allowing invalid state to cascade.
* **Principle of Least Astonishment:** Code behavior must be intuitive and follow domain/language conventions. No hidden side effects.
* **Boy Scout Rule:** Leave the touched codebase cleaner than you found it. Perform micro-refactorings when editing existing code.

---

## PRE-CODE VERIFICATION CHECKLIST
Before outputting any code, mentally run this checklist and ensure the code passes every point:

1. [ ] Is there a simpler implementation available?
2. [ ] Am I adding speculative logic or unnecessary abstractions (YAGNI)?
3. [ ] Does each module/class have a single clear responsibility?
4. [ ] Am I mutating state where immutability would be cleaner?
5. [ ] Are inputs/preconditions validated immediately (Fail Fast)?
6. [ ] Are methods strictly Commands or Queries (CQS)?
7. [ ] Is the code self-explanatory and predictable for another engineer reading it 6 months from now?

---

## OUTPUT EXPECTATIONS
* When generating code, follow these principles strictly without needing explicit reminders.
* If a trade-off is required (e.g., readability vs. strict DRY), explicitly note the rationale in a brief code comment or summary.
* Provide minimal, production-grade, well-typed code that implements these rules natively.
````

## Prompt

````markdown
# Rol

Sos un ingeniero de skills para Claude Code, con experiencia en arquitectura de software y en escritura de instrucciones operativas para agentes.

# Objetivo

Crear un skill instalable a partir del material fuente que está más abajo (`# SKILL: Software Design Principles & Code Quality Standard`), **optimizándolo**: mismo alcance conceptual, pero reescrito para que un agente lo pueda *ejecutar* en vez de solo *leerlo*.

# Contexto / entrada

Material fuente a optimizar (verbatim):

```markdown
# SKILL: Software Design Principles & Code Quality Standard

## ROLE & OBJECTIVE
You are an expert Software Architect and Senior Developer. Your objective is to design, write, and refactor code adhering to the highest standards of software design, maintainability, and clean architecture.

---

## CORE PRINCIPLES MATRIX

### 1. Simplicity & Lean Design
* **KISS (Keep It Simple, Stupid):** Always choose the simplest solution that correctly solves the problem. Avoid premature optimization and unnecessary abstractions.
* **YAGNI (You Aren't Gonna Need It):** Implement ONLY what is required today. Do not write code or add hooks/extensibility for speculative future requirements.

### 2. Knowledge & Duplication
* **DRY (Don't Repeat Yourself):** Maintain a Single Source of Truth for every business rule and piece of knowledge. Eliminate logic duplication (not just textual duplication). Never sacrifice readability solely to satisfy DRY.

### 3. Architecture & Coupling
* **SOLID Principles:**
  * **SRP:** One reason to change per class/module.
  * **OCP:** Open for extension, closed for modification.
  * **LSP:** Subtypes must be substitutable for their base types.
  * **ISP:** Prefer small, client-specific interfaces over large general ones.
  * **DIP:** Depend on abstractions, not concretions.
* **High Cohesion & Low Coupling:** Group strongly related responsibilities together; minimize inter-module dependencies.
* **Separation of Concerns (SoC):** Strictly isolate Domain logic, Infrastructure, Persistence, UI, and Application orchestration.

### 4. Object-Oriented & Behavioral Design
* **Composition Over Inheritance:** Prefer composition to reuse behavior. Use inheritance strictly for genuine "is-a" relationships.
* **Law of Demeter:** Do not traverse deep object graphs (avoid `a.getB().getC().getD()`). Only talk to immediate friends.
* **Tell, Don't Ask:** Command objects to perform work using their state instead of querying state to make external decisions.
* **Encapsulation / Information Hiding:** Expose the bare minimum necessary. Keep fields private and state internal.

### 5. API & State Management
* **Command Query Separation (CQS):** A method must either modify state (Command) or return data (Query), but NEVER both.
* **Prefer Immutability:** Use immutable data structures and constants wherever practical to eliminate side effects and facilitate thread safety / reasoning.

### 6. Defensiveness & Maintenance Rules
* **Fail Fast:** Validate inputs and preconditions immediately. Throw clear, early errors rather than allowing invalid state to cascade.
* **Principle of Least Astonishment:** Code behavior must be intuitive and follow domain/language conventions. No hidden side effects.
* **Boy Scout Rule:** Leave the touched codebase cleaner than you found it. Perform micro-refactorings when editing existing code.

---

## PRE-CODE VERIFICATION CHECKLIST
Before outputting any code, mentally run this checklist and ensure the code passes every point:

1. [ ] Is there a simpler implementation available?
2. [ ] Am I adding speculative logic or unnecessary abstractions (YAGNI)?
3. [ ] Does each module/class have a single clear responsibility?
4. [ ] Am I mutating state where immutability would be cleaner?
5. [ ] Are inputs/preconditions validated immediately (Fail Fast)?
6. [ ] Are methods strictly Commands or Queries (CQS)?
7. [ ] Is the code self-explanatory and predictable for another engineer reading it 6 months from now?

---

## OUTPUT EXPECTATIONS
* When generating code, follow these principles strictly without needing explicit reminders.
* If a trade-off is required (e.g., readability vs. strict DRY), explicitly note the rationale in a brief code comment or summary.
* Provide minimal, production-grade, well-typed code that implements these rules natively.
```

# Instrucciones

1. **Antes de escribir**, invocá el skill `superpowers:writing-skills` si está disponible y seguí su formato y convenciones. Si no lo está, aplicá el formato estándar de Claude Code: carpeta con `SKILL.md` y frontmatter YAML con `name` y `description`.
2. Instalá el skill en `~/.claude/skills/software-design-principles/SKILL.md` (skill de usuario, disponible en todos los proyectos).
3. Escribí el frontmatter así:
   - `name: software-design-principles`
   - `description:` en tercera persona, que diga **qué hace** y **cuándo se activa** — al escribir código nuevo, refactorizar, revisar diseño o decidir entre alternativas de implementación. Que sea buscable: incluí los términos KISS, YAGNI, DRY, SOLID, CQS, Law of Demeter, Fail Fast, clean architecture.
4. **Optimizá el contenido** aplicando estos criterios, sin perder ninguno de los 6 bloques de principios ni ningún ítem del checklist:
   - **Operacionalizá cada principio**: convertí cada definición en regla accionable con *señal de violación* concreta (qué se ve en el código cuando se está incumpliendo) y, cuando aporte, *qué hacer en su lugar*. Un principio que solo se define no sirve; uno que se puede detectar, sí.
   - **Resolvé los conflictos entre principios explícitamente**: DRY vs. legibilidad, DRY vs. YAGNI/acoplamiento, OCP vs. YAGNI, Fail Fast vs. tolerancia a fallas. Dejá escrito cuál gana y por qué — el material fuente los deja implícitos.
   - **Eliminá redundancia y relleno**: sacá lo que un modelo competente ya sabe. Cada línea que queda tiene que cambiar una decisión.
   - **Divulgación progresiva**: `SKILL.md` corto y autosuficiente (objetivo < 150 líneas). Si algo excede eso —tablas exhaustivas, ejemplos largos por lenguaje— movelo a `references/` y referencialo desde `SKILL.md`.
   - **Convertí el checklist en una compuerta de ejecución**, no en un recordatorio pasivo: definí cuándo corre (antes de emitir código), qué se hace cuando un ítem falla (corregir antes de emitir, no anotar y seguir) y qué se hace cuando no aplica.
   - **Agregá ejemplos mínimos de contraste** (mal → bien) solo para los principios donde el texto sin código es ambiguo: Law of Demeter, Tell Don't Ask, CQS. Cortos, agnósticos o en un lenguaje tipado.
5. Mantené el contenido del skill **en inglés**, como el material fuente.
6. Al terminar, informá en pocas líneas: ruta de los archivos creados, qué se optimizó respecto del original y qué decisiones tomaste (conflictos resueltos, contenido movido a `references/`).

# Restricciones

- **No agregues principios nuevos ni saques ninguno de los existentes.** La optimización es de forma y operatividad, no de alcance.
- No inventes convenciones de formato de skill: si `superpowers:writing-skills` está disponible, manda ese formato.
- No sobrescribas un skill existente en esa ruta sin avisar primero; si ya existe, frená y reportalo.
- Nada de prosa motivacional, emojis decorativos ni advertencias genéricas dentro del skill.
- El skill describe **cómo escribir código**; no debe contener lógica específica de ningún proyecto ni de ningún stack en particular.

# Formato de salida

1. Los archivos creados en disco (`SKILL.md` y, si corresponde, `references/*.md`).
2. Un resumen final de máximo 10 líneas con: rutas creadas, cambios de optimización aplicados y decisiones tomadas.
````

## Supuestos

- **Ubicación:** skill de usuario en `~/.claude/skills/software-design-principles/`, no del proyecto — el estándar es agnóstico de stack y AgendaLlena todavía no tiene código.
- **Idioma:** el pedido está en español pero el material fuente está en inglés; el skill queda en inglés y las instrucciones del prompt en español.
- **"Optimizando":** interpretado como *hacerlo ejecutable y más denso* (accionable, con señales de violación, conflictos resueltos, divulgación progresiva), no como *acortarlo* ni como *agregarle temas*.
- **Nombre del skill:** `software-design-principles`, derivado del título del material.

## Resultado

Ejecutado el 2026-08-18. Creado `~/.claude/skills/software-design-principles/SKILL.md` (134 lineas,
~1630 palabras, archivo unico sin `references/` porque todo entro bajo el objetivo de 150 lineas).
El skill queda registrado y disponible en todos los proyectos.

Optimizaciones aplicadas sobre el material fuente:

- Cada uno de los 19 principios pasa a una fila de tabla con **senal de violacion** (que se ve en el
  codigo) y **que hacer en su lugar**. Se conservan los 6 bloques y los 7 items del checklist.
- Nueva seccion **"When two rules collide"** con seis conflictos resueltos y su porque: DRY vs.
  legibilidad, DRY vs. YAGNI (duplicar hasta la tercera aparicion), OCP vs. YAGNI, Fail Fast vs.
  tolerancia a fallas (regla de frontera de confianza), CQS vs. minima sorpresa (`pop()`/`dequeue()`),
  SoC vs. KISS.
- El checklist se convierte en **compuerta**: corre antes de emitir codigo y antes de declarar
  terminado; item que falla se corrige antes de emitir, no se anota; si no aplica se dice por que.
- Ejemplos de contraste mal/bien en TypeScript solo para Law of Demeter, Tell Don't Ask y CQS.
- Agregadas tabla de racionalizaciones (**Red flags**) y regla de trade-offs en un solo lugar
  (comentario o resumen, nunca los dos), siguiendo `superpowers:writing-skills`.

Desvios respecto del prompt:

- **`description` del frontmatter:** manda el formato de `superpowers:writing-skills`, que exige
  "Use when..." con condiciones de activacion y **prohibe** resumir lo que el skill hace. Quedo con
  disparadores y sintomas + los terminos buscables (KISS, YAGNI, DRY, SOLID, CQS, Law of Demeter,
  Fail Fast, clean architecture); se omitio el "que hace" que pedia el paso 3.
- **No se corrio el ciclo RED-GREEN-REFACTOR con subagentes** que exige `writing-skills`: la
  configuracion de esta sesion prohibe lanzar agentes sin pedido explicito del usuario. El skill
  esta sin testear contra escenarios de presion.
