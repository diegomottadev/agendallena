# T-007 · Cerrar decisiones abiertas y escribir los RF faltantes

| | |
| :-- | :-- |
| **Prioridad** | 7 |
| **Sprint** | 0 — Habilitación |
| **Tipo** | Documentación |
| **Historia** | transversal — [US-03](../../stories/US-03-maquina-de-estados-conversacional.md), [US-11](../../stories/US-11-reagendar-y-aviso-t2h.md), [US-13](../../stories/US-13-leads-a-google-sheets.md), [US-14](../../stories/US-14-panel-logs-y-estado.md), [US-15](../../stories/US-15-configurar-reglas-del-negocio.md), [US-16](../../stories/US-16-primer-contacto-bienvenida.md), [US-17](../../stories/US-17-registro-de-asistencia.md) |
| **Story points** | 3 |
| **Depende de** | — |
| **Bloquea a** | T-018, T-043, T-045, T-047 |
| **Pareto** | **NÚCLEO** · ola 0 |

## Contexto

Cinco funcionalidades del MVP no tienen requisito funcional y cinco decisiones figuran como abiertas. **Dos de esas decisiones ya están respondidas** en documentos que el backlog nunca cruzó: declararlas por escrito vuelve estimables a US-03 y US-14 sin escribir una línea de código.

## Alcance

**Decisiones a declarar cerradas** (la respuesta ya existe, falta el acta):

* Persistencia del estado: MySQL es la fuente de verdad, Redis es caché y locks — `03-flujos/05-estados-flujo-conversacional.md` §1.
* Multi-tenancy: por columna `tenant_id` — RNF-01, C4 L2, C4 L3 y el modelo de datos coinciden.

**Decisiones a tomar:**

* Outlook: dentro o fuera del MVP, y **escribirlo en el OUT-OF-SCOPE del PRD** si queda fuera.
* En qué estado se captura el nombre del cliente: `GATHERING_PARAMS` o `SLOT_SELECTED`.
* Cuánto dura una conversación inactiva antes de volver a `IDLE`.

**RF a escribir en el PRD:** configuración del negocio, mensaje de bienvenida, registro de asistencia, volcado a Sheets y aviso t-2h.

## Criterios de aceptación

- [ ] Las dos decisiones cerradas están escritas en el PRD y las historias afectadas ya no las listan como bloqueantes.
- [ ] Outlook figura explícitamente dentro o fuera del alcance del PRD; hoy no figura de ninguna de las dos formas.
- [ ] La captura del nombre está definida en un solo lugar y las tres historias que la mencionan coinciden.
- [ ] El TTL de inactividad tiene un único valor, y AC-03.1 se corrige si contradice ese valor.
- [ ] Los cinco RF faltantes están redactados con su identificador.

## Fuera de alcance

Implementar nada de lo decidido.

## Notas y riesgos

⚠️ Es el ticket con mejor relación entre esfuerzo y desbloqueo de todo el plan: media jornada de escritura destraba cuatro historias que hoy no son estimables.
