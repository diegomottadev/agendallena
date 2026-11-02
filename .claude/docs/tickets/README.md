# Tickets de trabajo — MVP Release 1.0

49 tickets ordenados por prioridad, derivados del [backlog de 26 historias](../stories/) y **rebanados para corregir sus fallas INVEST**. Cada ticket declara su puntuación INVEST y qué falla de la historia original resuelve.

**177 story points** · 9 bloques de trabajo · escala Fibonacci: `1` medio día · `2` un día · `3` uno o dos días · `5` tres o cuatro días · `8` una semana (techo: por encima, partir).

---

## Cómo leer un ticket

| Sección | Para qué |
| :-- | :-- |
| **Cabecera** | Prioridad, sprint, tipo, historia de origen, puntos, dependencias y **clasificación Pareto** |
| **Contexto** | Por qué existe. Hereda la nota Lean de la historia |
| **Alcance** | Qué hay que construir |
| **Criterios de aceptación** | Verificables, con referencia al AC de la historia entre paréntesis |
| **INVEST** | Puntuación del ticket y **qué falla de la historia corrige el rebanado** |
| **Fuera de alcance** | Qué **no** entra, para que el corte sea explícito |
| **Notas y riesgos** | Decisiones abiertas marcadas con ⚠️ |

**Tipos:** `Feature` entrega valor de usuario · `Enabler` habilita features · `Migración` cambia el modelo de datos · `Spike` responde una pregunta · `Trámite` depende de un tercero · `Calidad` no agrega funcionalidad.

---

## Recorte Pareto vigente

Cada ticket declara en su cabecera la fila **Pareto**, derivada de [`estimacion-backlog/03-analisis-pareto.md`](../estimacion-backlog/03-analisis-pareto.md). **Los 177 puntos y el orden de prioridad de abajo no cambiaron:** el recorte es una capa encima, no un reemplazo.

| Categoría | Tickets | Pts | Qué significa |
| :-- | :-: | :-: | :-- |
| **NÚCLEO** | 31 | **107** | Sin él la promesa no se cumple. Se ejecuta en 6 olas por corte de dependencia |
| DIFERIDO | 15 | 56 | Valor real, pero el producto es demostrable y vendible sin él |
| RECORTADO | 3 | 14 | El costo no se paga con el valor de hoy. Se replantea, no se pospone |

**Fuera del núcleo:** T-004, T-006, T-016, T-027, T-028, T-032, T-034, T-035, T-039, T-040, T-041, T-042, T-043, T-044, T-045, T-047, T-048, T-049.

**Catorce tickets del núcleo entran recortados:** T-010, T-015, T-017, T-018, T-019, T-021, T-023, T-024, T-025, T-029, T-030, T-033, T-037 y T-038. Lo que sale de cada uno está escrito en su sección *Fuera de alcance*, con el AC que lo justifica.

⚠️ **Dos recortes rompen criterios de aceptación del PRD y hay que resolverlos en T-007:** diferir T-045 contradice **CA-02** (que exige el turno también en Sheets), y recortar T-032 deja **CA-03** sin cumplir en su forma automática.

⚠️ **El recorte no toca el problema estructural.** 26 de los 107 puntos del núcleo siguen en la cadena serie T-008 → T-010 → T-018 → T-022 → T-026. Eso solo lo mueve la decisión pendiente de rebanadas verticales.

---

## Orden de prioridad

### Bloque 0 — Habilitación · 16 pts · *sin código, arranca el día 1*

| # | Ticket | Historia | Pts | INVEST |
| :-: | :-- | :-- | :-: | :-: |
| 1 | [Verificación de Meta Business y alta del número](Finish/001-verificacion-meta-business.md) | US-01 | 1 | *n/a* |
| 2 | [Aprobación de plantillas de mensaje](Finish/002-aprobacion-de-plantillas.md) | US-10 | 2 | *n/a* |
| 3 | [Consentimiento de Google y credenciales OAuth](Finish/003-consentimiento-google-oauth.md) | US-04 | 2 | *n/a* |
| 4 | [**Spike:** ¿Meta entrega los mensajes salientes?](Next-iteraction/004-spike-mensajes-salientes-meta.md) | US-08 | 3 | *n/a* |
| 5 | [**Spike:** latencia real de `freeBusy`](Finish/005-spike-latencia-freebusy.md) | US-05 | 2 | *n/a* |
| 6 | [**Discovery:** catálogos de precios de 4 rubros](Next-iteraction/006-discovery-catalogos-de-precios.md) | US-12 | 3 | *n/a* |
| 7 | [Cerrar decisiones abiertas y escribir los RF faltantes](Finish/007-cerrar-decisiones-y-rf-faltantes.md) | 7 historias | 3 | *n/a* |

### Sprint 1 — Habilitar el canal · 21 pts

| # | Ticket | Historia | Pts | INVEST |
| :-: | :-- | :-- | :-: | :-: |
| 8 | [Handshake de webhook de Meta](Finish/008-handshake-webhook-meta.md) | US-01 | 3 | 4/6 |
| 9 | [**Migración:** índices compuestos](Finish/009-migracion-indices-compuestos.md) | US-02, US-10 | 2 | 4/6 |
| 10 | [Ingesta asíncrona con firma HMAC](Finish/010-ingesta-asincrona-y-firma-hmac.md) | US-02 | 5 | 4/6 |
| 11 | [Idempotencia y resiliencia de la cola](Finish/011-idempotencia-y-resiliencia-de-cola.md) | US-02 | 3 | 5/6 |
| 12 | [OAuth 2.0 con Google Calendar](Finish/012-oauth-google-calendar.md) | US-04 | 5 | 5/6 |
| 13 | [Renovación automática de tokens](Finish/013-renovacion-automatica-de-tokens.md) | US-04 | 3 | 5/6 |

### Sprint 2 — Fundación de datos y conversación · 27 pts ⚠️

| # | Ticket | Historia | Pts | INVEST |
| :-: | :-- | :-- | :-: | :-: |
| 14 | [**Migración:** tabla `business_settings`](Finish/014-migracion-business-settings.md) | US-15 | 2 | 5/6 |
| 15 | [Configuración de agenda](Finish/015-configuracion-de-agenda.md) | US-15 | 5 | 5/6 |
| 16 | [Configuración de precios](Next-iteraction/016-configuracion-de-precios.md) | US-15 | 3 | 5/6 |
| 17 | [Configuración de mensajes](Finish/017-configuracion-de-mensajes.md) | US-15 | 2 | **6/6** |
| 18 | [Máquina de estados: núcleo](Finish/018-maquina-de-estados-nucleo.md) | US-03 | 8 | 3/6 |
| 19 | [Mensajes interactivos de Meta](Finish/019-mensajes-interactivos-de-meta.md) | US-03 | 5 | 4/6 |
| 20 | [Logging estructurado](Finish/020-logging-estructurado.md) | US-01, US-09, US-14 | 2 | 5/6 |

### Sprint 3 — Primer contacto y disponibilidad · 17 pts

| # | Ticket | Historia | Pts | INVEST |
| :-: | :-- | :-- | :-: | :-: |
| 21 | [Bienvenida y menú inicial](Finish/021-bienvenida-y-menu-inicial.md) | US-16 | 3 | 5/6 |
| 22 | [Consulta de disponibilidad](Finish/022-consulta-de-disponibilidad.md) | US-05 | 5 | 4/6 |
| 23 | [Lista interactiva de horarios](Finish/023-lista-interactiva-de-horarios.md) | US-05 | 3 | 5/6 |
| 24 | [Respuesta sin disponibilidad](Finish/024-respuesta-sin-disponibilidad.md) | US-19 | 3 | 5/6 |
| 25 | [Pausar el bot desde el panel](Finish/025-pausar-el-bot-desde-el-panel.md) | US-21 | 3 | 5/6 |

### Sprint 4 — Reservar de verdad · 29 pts ⚠️

| # | Ticket | Historia | Pts | INVEST |
| :-: | :-- | :-- | :-: | :-: |
| 26 | [Creación del evento en Calendar](Finish/026-creacion-del-evento-en-calendar.md) | US-06 | 5 | 5/6 |
| 27 | [Sincronización inversa vía *watch*](Next-iteraction/027-sincronizacion-inversa-watch.md) | US-06 | 8 | 3/6 |
| 28 | [Renovación de canales *watch*](Next-iteraction/028-renovacion-de-canales-watch.md) | US-23 | 3 | 4/6 |
| 29 | [Control de colisión de horario](Finish/029-control-de-colision-de-horario.md) | US-18 | 5 | 4/6 |
| 30 | [Consistencia del agendamiento](Finish/030-consistencia-del-agendamiento.md) | US-22 | 5 | 4/6 |
| 31 | [Suite transversal de timezones](Finish/031-suite-transversal-de-timezones.md) | US-07 | 3 | **2/6** |

### Sprint 5 — Convivir con el equipo humano · 18 pts

| # | Ticket | Historia | Pts | INVEST |
| :-: | :-- | :-- | :-: | :-: |
| 32 | [Human takeover: detección](Next-iteraction/032-human-takeover-deteccion.md) | US-08 | 8 | **2/6** |
| 33 | [Fallback ante caídas](Finish/033-fallback-ante-caidas.md) | US-09 | 5 | 4/6 |
| 34 | [Estado de las integraciones](Finish/034-estado-de-las-integraciones.md) | US-25 | 2 | 5/6 |
| 35 | [Derivación a una persona](Finish/035-derivar-a-una-persona.md) | US-20 | 3 | 5/6 |

### Sprint 6 — Demostrar el ROI · 12 pts

| # | Ticket | Historia | Pts | INVEST |
| :-: | :-- | :-- | :-: | :-: |
| 36 | [**Migración:** registro de asistencia](Finish/036-migracion-registro-de-asistencia.md) | US-17 | 1 | 4/6 |
| 37 | [Recordatorio interactivo t-24h](Finish/037-recordatorio-t24h.md) | US-10 | 5 | 3/6 |
| 38 | [Registro de asistencia y tasa](Finish/038-registro-de-asistencia.md) | US-17 | 3 | 5/6 |
| 39 | [Recordatorios fallidos](Finish/039-recordatorios-fallidos.md) | US-24 | 3 | 5/6 |

### Sprint 7 — Cotizar · 16 pts

| # | Ticket | Historia | Pts | INVEST |
| :-: | :-- | :-- | :-: | :-: |
| 40 | [Motor de cotización seguro](Next-iteraction/040-motor-de-cotizacion-seguro.md) | US-12 | 5 | 4/6 |
| 41 | [Cotizador en la conversación](Next-iteraction/041-cotizador-en-la-conversacion.md) | US-12 | 3 | 5/6 |
| 42 | [Re-agendar desde el recordatorio](Finish/042-reagendar-desde-el-recordatorio.md) | US-11 | 5 | 5/6 |
| 43 | [Aviso t-2h *(condicional)*](Next-iteraction/043-aviso-t2h-condicional.md) | US-11 | 3 | **2/6** |

### Sprint 8 — Autonomía del cliente · 21 pts

| # | Ticket | Historia | Pts | INVEST |
| :-: | :-- | :-- | :-: | :-: |
| 44 | [Vincular la planilla de Sheets](Finish/044-vincular-planilla-de-sheets.md) | US-26 | 3 | 5/6 |
| 45 | [Volcado de leads a la planilla](Finish/045-volcado-de-leads-a-sheets.md) | US-13 | 3 | **2/6** |
| 46 | [Autenticación del panel](Finish/046-autenticacion-del-panel.md) | US-14 | 3 | 5/6 |
| 47 | [Panel: listado de conversaciones](Finish/047-panel-lista-de-conversaciones.md) | US-14 | 5 | 5/6 |
| 48 | [**Migración:** tabla `messages`](Finish/048-migracion-tabla-messages.md) | US-14 | 2 | 4/6 |
| 49 | [Panel: detalle de conversación](Finish/049-panel-detalle-de-conversacion.md) | US-14 | 5 | 5/6 |

### Dos ajustes de planificación recomendados

⚠️ **Los sprints 2 (27 pts) y 4 (29 pts) están sobrecargados** frente al promedio de ~20. El sprint 6 (12 pts) tiene lugar. Mover T-031 del 4 al 6 y T-020 del 2 al 3 equilibra sin romper dependencias.

⚠️ **T-046 (autenticación del panel) está en la posición 46 por trazabilidad con US-14, no por prioridad real.** Cinco tickets anteriores —T-015, T-025, T-034, T-035, T-038— necesitan un panel con login. En la práctica va al sprint 2, junto con el shell de T-012. Lo mismo con T-048: cuanto antes se empiecen a guardar los mensajes, más historial habrá cuando el detalle exista.

---

## Auditoría INVEST del backlog

Puntuación de las 26 historias antes del rebanado. `✅` cumple · `⚠️` parcial · `❌` falla.

| Historia | I | N | V | E | S | T | Total |
| :-- | :-: | :-: | :-: | :-: | :-: | :-: | :-: |
| [US-01](../stories/US-01-handshake-webhook-meta.md) Handshake de Meta | ✅ | ⚠️ | ⚠️ | ✅ | ✅ | ✅ | 4/6 |
| [US-02](../stories/US-02-ingesta-asincrona-rafaga.md) Ingesta asíncrona | ⚠️ | ❌ | ✅ | ✅ | ⚠️ | ✅ | 3/6 |
| [US-03](../stories/US-03-maquina-de-estados-conversacional.md) Máquina de estados | ❌ | ✅ | ✅ | ⚠️ | ❌ | ✅ | 3/6 |
| [US-04](../stories/US-04-conectar-google-calendar-oauth.md) OAuth Calendar | ✅ | ✅ | ✅ | ⚠️ | ⚠️ | ✅ | 4/6 |
| [US-05](../stories/US-05-horarios-realmente-libres.md) Horarios libres | ⚠️ | ⚠️ | ✅ | ⚠️ | ❌ | ✅ | **2/6** |
| [US-06](../stories/US-06-reservar-y-liberar-sync-inversa.md) Reservar y liberar | ⚠️ | ✅ | ✅ | ⚠️ | ❌ | ✅ | 3/6 |
| [US-07](../stories/US-07-hora-correcta-timezones.md) Timezones | ❌ | ❌ | ⚠️ | ✅ | ⚠️ | ✅ | **2/6** |
| [US-08](../stories/US-08-pausa-intervencion-humana.md) Human takeover | ⚠️ | ⚠️ | ✅ | ❌ | ⚠️ | ✅ | **2/6** |
| [US-09](../stories/US-09-fallback-ante-caidas.md) Fallback | ⚠️ | ✅ | ✅ | ✅ | ⚠️ | ✅ | 4/6 |
| [US-10](../stories/US-10-recordatorio-t24h.md) Recordatorio t-24h | ⚠️ | ✅ | ✅ | ⚠️ | ⚠️ | ✅ | 3/6 |
| [US-11](../stories/US-11-reagendar-y-aviso-t2h.md) Re-agendar + t-2h | ⚠️ | ⚠️ | ⚠️ | ❌ | ❌ | ⚠️ | **0/6** |
| [US-12](../stories/US-12-cotizador-parametrico.md) Cotizador | ⚠️ | ✅ | ✅ | ⚠️ | ❌ | ✅ | 3/6 |
| [US-13](../stories/US-13-leads-a-google-sheets.md) Leads a Sheets | ⚠️ | ✅ | ⚠️ | ❌ | ✅ | ⚠️ | **2/6** |
| [US-14](../stories/US-14-panel-logs-y-estado.md) Panel de logs | ❌ | ✅ | ✅ | ⚠️ | ❌ | ✅ | 3/6 |
| [US-15](../stories/US-15-configurar-reglas-del-negocio.md) Config del negocio | ⚠️ | ✅ | ✅ | ⚠️ | ❌ | ✅ | 3/6 |
| [US-16](../stories/US-16-primer-contacto-bienvenida.md) Bienvenida | ⚠️ | ✅ | ✅ | ✅ | ✅ | ✅ | **5/6** |
| [US-17](../stories/US-17-registro-de-asistencia.md) Asistencia | ⚠️ | ✅ | ✅ | ✅ | ✅ | ✅ | **5/6** |
| [US-18](../stories/US-18-colision-de-horario-al-confirmar.md) Colisión | ⚠️ | ✅ | ✅ | ⚠️ | ✅ | ✅ | 4/6 |
| [US-19](../stories/US-19-agenda-sin-disponibilidad.md) Sin disponibilidad | ⚠️ | ✅ | ✅ | ✅ | ✅ | ✅ | **5/6** |
| [US-20](../stories/US-20-derivar-a-una-persona.md) Derivación | ⚠️ | ✅ | ✅ | ✅ | ✅ | ✅ | **5/6** |
| [US-21](../stories/US-21-pausar-el-bot-desde-el-panel.md) Pausa manual | ⚠️ | ✅ | ✅ | ✅ | ✅ | ✅ | **5/6** |
| [US-22](../stories/US-22-reserva-consistente.md) Reserva consistente | ⚠️ | ✅ | ⚠️ | ✅ | ✅ | ✅ | 4/6 |
| [US-23](../stories/US-23-renovacion-de-canales-watch.md) Canales *watch* | ⚠️ | ✅ | ⚠️ | ✅ | ✅ | ✅ | 4/6 |
| [US-24](../stories/US-24-recordatorio-que-no-salio.md) Recordatorio fallido | ⚠️ | ✅ | ✅ | ✅ | ✅ | ✅ | **5/6** |
| [US-25](../stories/US-25-estado-de-las-integraciones.md) Estado integraciones | ⚠️ | ✅ | ✅ | ✅ | ✅ | ✅ | **5/6** |
| [US-26](../stories/US-26-vincular-mi-planilla.md) Vincular planilla | ⚠️ | ✅ | ✅ | ✅ | ✅ | ✅ | **5/6** |

### Salud por criterio

| Criterio | Historias limpias | Tickets limpios | Lectura |
| :-- | :-: | :-: | :-- |
| **I**ndependiente | **2/26** | 10/42 | El más débil, antes y después. Es consecuencia directa de priorizar por riesgo en cadena, y el rebanado no lo arregla porque el problema es el orden, no el tamaño |
| **N**egociable | 20/26 | 36/42 | Sano. Las fallas son historias que prescriben stack (US-02, US-07) o que no tienen definición que negociar (US-11) |
| **V**aliosa | 20/26 | 33/42 | Sano. Las excepciones son enablers y las historias de "valor por ausencia de defecto" (US-07, US-22, US-23) |
| **E**stimable | 14/26 | 34/42 | Mejoró mucho: sacar trámites y spikes a tickets propios destrabó US-10 y US-08 parcialmente |
| **S**mall | **13/26** | **38/42** | **La mejora más grande del ejercicio.** Siete historias eran dos o tres |
| **T**esteable | 24/26 | 40/42 | El más fuerte. Las dos fallas son historias sin requisito acordado |

### Los seis hallazgos transversales

**1. La independencia se sacrificó a propósito, y sigue siendo el problema estructural.** El orden por riesgo produce la cadena T-008 → T-010 → T-018 → T-022 → T-026: cinco tickets sin paralelizar. Con dos desarrolladores, uno mira. **Rebanar no lo resuelve** — es un problema de secuencia, no de tamaño. La alternativa es reorganizar en rebanadas verticales: agendar punta a punta con disponibilidad falseada, y después reemplazar el mock. Sigue siendo una **decisión pendiente**.

**2. Siete historias eran dos o tres, y el rebanado lo confirmó.** US-03, US-05, US-06, US-11, US-12, US-14 y US-15 produjeron 18 tickets entre las siete. Cada historia indicaba dónde cortar y el corte se respetó tal cual.

**3. La falla de *Estimable* casi siempre era otra cosa disfrazada.** US-10 no era inestimable en esfuerzo sino en **tiempo de calendario** (aprobación de plantillas → T-002). US-12 no lo era en código sino en **conocimiento de negocio** (→ T-006). US-03 y US-14 no lo eran por complejidad sino por **decisiones que ya estaban escritas y nadie había declarado** (→ T-007). La única falla genuina de estimabilidad es **US-08**, donde no se sabe si lo pedido es posible.

**4. Cinco historias siguen sin requisito funcional:** US-11 (t-2h), US-13, US-15, US-16 y US-17. T-007 las cubre en media jornada de escritura, y hasta entonces sus criterios de aceptación son propuestas nuestras y no definiciones acordadas. Es la razón de que T-045 puntúe 2/6 pese a ser un ticket chico y claro.

**5. Las historias nacidas de huecos puntúan mejor que las nacidas del PRD.** Las nueve nuevas (US-18 a US-26) promedian **4,7/6**; las diecisiete originales promedian **3,0/6**. No es casualidad: una historia derivada de un paso concreto del recorrido tiene alcance acotado por construcción, mientras que una derivada de un módulo del PRD hereda el tamaño del módulo.

**6. Dos historias no deberían ser historias.** [US-07](../stories/US-07-hora-correcta-timezones.md) (timezones) es una restricción transversal: como ítem de backlog invita a marcarla "hecha" y después violarla en silencio en cada historia nueva. Se convirtió en T-031, un ticket de **calidad** cuyo entregable es una suite de tests. [US-11](../stories/US-11-reagendar-y-aviso-t2h.md) agrupaba por narrativa dos cosas sin cohesión funcional; separada en T-042 y T-043, la mitad con valor pasa de 0/6 a 5/6.

---

## Mejoras sugeridas por historia

Solo las historias donde la mejora **no** quedó ya resuelta por el rebanado.

| Historia | Mejora sugerida |
| :-- | :-- |
| **US-01, US-02** | **Etiquetarlas `Enabler`** en vez de fabricarles un beneficio de usuario final. Sus fallas de *Negociable* y *Valiosa* son inherentes a la habilitación técnica, no defectos corregibles |
| **US-03** | **Rehacer la estimación sobre once estados, no cuatro.** El **L** actual está calculado sobre el 36% de la superficie real; RF-A3 quedó desactualizado frente al catálogo de los flujos |
| **US-05** | Ya partida en T-022/T-023, con la configuración fuera. Queda pendiente **medir `freeBusy`** antes de comprometerla: si no entra en el RNF-04, el diseño cambia |
| **US-07** | **Dejar de tratarla como historia.** Sus cuatro criterios funcionan mejor repetidos dentro de US-05, US-06, US-10 y US-11, más la suite de T-031 |
| **US-08** | **No planificarla hasta cerrar el spike.** Y construir antes su cobertura ([US-21](../stories/US-21-pausar-el-bot-desde-el-panel.md)), que es lo que convierte el riesgo abierto en riesgo acotado |
| **US-10** | Ya hecho: sacar la aprobación de plantillas a un ítem de gestión rastreado por separado (T-002), iniciado en el bloque 0 |
| **US-11** | Ya partida. La recomendación fuerte es **probar el t-2h a mano una semana antes de escribir código**: si no mueve la asistencia, se cae del MVP sin costo |
| **US-12** | Ya invertida la secuencia: el discovery (T-006) va antes que el motor. Agregar la **evaluación segura de fórmulas**, que ningún requisito menciona y es un riesgo de multi-tenancy |
| **US-13** | **Escribir el RF antes de estimarla.** Media hora de trabajo que convierte una historia no estimable en una S de uno o dos días |
| **US-14** | Ya partida en cuatro. **Adelantar T-046 y T-048**: la autenticación bloquea cinco tickets anteriores y la tabla de mensajes gana valor con el tiempo |
| **US-15** | Ya partida en tres. Decidir explícitamente si en el MVP hay pantallas o carga por seeder — **y admitir que, si cargamos nosotros, el KPI de setup lo cumplimos nosotros** |
| **US-17** | **La asistencia va en columna propia, no como valor de `status`.** Con un solo campo, un turno confirmado que termina en ausencia pierde el dato de que había confirmado, y eso rompe AC-17.4 |
| **Todo el backlog** | **Cerrar la decisión de rebanadas verticales.** Es lo único que puede mover *Independiente* de 2/26, y ninguna otra mejora lo hace |

---

## Trazabilidad historia → tickets

| Historia | Tickets | Historia | Tickets |
| :-- | :-- | :-- | :-- |
| US-01 | T-001, T-008 | US-14 | T-046, T-047, T-048, T-049 |
| US-02 | T-009, T-010, T-011 | US-15 | T-014, T-015, T-016, T-017 |
| US-03 | T-018, T-019 | US-16 | T-021 |
| US-04 | T-003, T-012, T-013 | US-17 | T-036, T-038 |
| US-05 | T-005, T-022, T-023 | US-18 | T-029 |
| US-06 | T-026, T-027 | US-19 | T-024 |
| US-07 | T-031 | US-20 | T-035 |
| US-08 | T-004, T-032 | US-21 | T-025 |
| US-09 | T-020, T-033 | US-22 | T-030 |
| US-10 | T-002, T-037 | US-23 | T-028 |
| US-11 | T-042, T-043 | US-24 | T-039 |
| US-12 | T-006, T-040, T-041 | US-25 | T-034 |
| US-13 | T-045 | US-26 | T-044 |

**Las 26 historias están cubiertas.** T-007 es transversal a siete de ellas.
