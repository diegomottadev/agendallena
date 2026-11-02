# User Stories — MVP Release 1.0

**Generado con:** [`prompts/generar-user-stories-mvp.md`](../../prompts/generar-user-stories-mvp.md) (N=14, formato Agile & INVEST)
**Fuente:** [`02-prd.md`](02-prd.md) · [`01-lean-canvas.md`](01-lean-canvas.md)
**Formato por historia:** Como/Quiero/Para · ≥3 criterios BDD · estimación S/M/L · evaluación INVEST · 3 notas Lean.

> Cada historia vive en su propio archivo dentro de [`stories/`](stories/). Este documento es el índice: mantiene el orden, la secuencia de aprendizaje y la priorización justificada.

## Backlog

| Orden | Historia | Est. | INVEST | Trazabilidad | Riesgo que mitiga |
| :-: | :-- | :-: | :-: | :-- | :-- |
| 1 | [US-01](stories/US-01-handshake-webhook-meta.md) · Handshake de webhook de Meta | S | 4/6 | RF-A1 · CA-01 | Habilitación externa |
| 2 | [US-02](stories/US-02-ingesta-asincrona-rafaga.md) · Ingesta asíncrona bajo ráfaga | M | 3/6 | RF-A2 · RNF-04 | Plataforma nos corta |
| 3 | [US-03](stories/US-03-maquina-de-estados-conversacional.md) · Máquina de estados conversacional | L | 3/6 | RF-A3 · RF-A5 | Coherencia del diálogo |
| 4 | [US-04](stories/US-04-conectar-google-calendar-oauth.md) · Conectar Google Calendar (OAuth) | M | 4/6 | RF-B1 · RF-E1 | Fricción de setup |
| **5** | [**US-15**](stories/US-15-configurar-reglas-del-negocio.md) · **Configurar las reglas del negocio** | L | 3/6 | *(sin RF)* | Insumo de 4 historias |
| 6 | [US-05](stories/US-05-horarios-realmente-libres.md) · Ofrecer solo horarios libres | L | 2/6 | RF-B2 · RF-A5 | Doble reserva |
| 7 | [US-06](stories/US-06-reservar-y-liberar-sync-inversa.md) · Reservar y liberar (sync inversa) | L | 3/6 | RF-B3 · CA-02 | Pérdida de confianza |
| 8 | [US-07](stories/US-07-hora-correcta-timezones.md) · La hora correcta, siempre | M | 2/6 | RNF-02 · CA-04 | Killer silencioso |
| **9** | [**US-16**](stories/US-16-primer-contacto-bienvenida.md) · **La primera respuesta del bot** | S | **5/6** | *(sin RF)* | Entrada del recorrido |
| 10 | [US-08](stories/US-08-pausa-intervencion-humana.md) · Pausa por intervención humana | L | 2/6 | RF-A4 · CA-03 | Objeción de venta #1 |
| 11 | [US-09](stories/US-09-fallback-ante-caidas.md) · Fallback ante caída de APIs | M | 4/6 | RNF-03 · CA-06 | Chat colgado |
| 12 | [US-10](stories/US-10-recordatorio-t24h.md) · Recordatorio interactivo t-24h | M | 3/6 | RF-D1 · CA-05 | **El ROI que vendemos** |
| **13** | [**US-17**](stories/US-17-registro-de-asistencia.md) · **Registrar si el cliente asistió** | S | **5/6** | *(sin RF)* | **Mide el ROI** |
| 14 | [US-11](stories/US-11-reagendar-y-aviso-t2h.md) · Re-agendar y segundo aviso t-2h | L | **0/6** | RF-D2 · *(t-2h sin RF)* | Recuperar el turno |
| 15 | [US-12](stories/US-12-cotizador-parametrico.md) · Cotizador paramétrico | L | 3/6 | RF-C1 | Encaje producto-segmento |
| 16 | [US-13](stories/US-13-leads-a-google-sheets.md) · Volcado de leads a Sheets | S | 2/6 | *(sin RF)* · CA-02 | "Sin cambiar herramientas" |
| 17 | [US-14](stories/US-14-panel-logs-y-estado.md) · Panel de logs y estado | L | 3/6 | RF-E2 · RNF-01 | Time-to-value |

**Distribución:** 4 S · 5 M · 8 L. Que casi la mitad sea L sigue siendo un diagnóstico: falta rebanar.

> **US-15, US-16 y US-17 salieron del [story map](04-user-story-map.md)**, no del PRD. Cubren los tres huecos de MVP que el cruce por recorrido de usuario dejó a la vista. US-15 se insertó **antes de US-05** porque cuatro historias consumen esa configuración; US-17 va **pegada a US-10** porque es su instrumento de medición.
>
> ⚠️ **Cinco historias del MVP no tienen requisito funcional:** US-11 (t-2h), US-13, US-15, US-16 y US-17. Conviene una pasada de escritura sobre el PRD antes de estimar el sprint 2.

## Salud INVEST del backlog

| Criterio | Limpias | Lectura |
| :-- | :-: | :-- |
| **I**ndependiente | 2/14 | El más débil. Consecuencia directa de priorizar por riesgo en cadena. |
| **N**egociable | 8/14 | Sano. Las fallas son historias que prescriben stack (US-02, US-07). |
| **V**aliosa | 9/14 | Sano. Las excepciones son enablers (US-01) o valor dudoso declarado (US-13). |
| **E**stimable | 5/14 | Cuatro historias bloqueadas por decisiones abiertas o requisitos faltantes. |
| **S**mall | 3/14 | Segundo más débil. Seis historias son en realidad dos. |
| **T**esteable | 11/14 | El más fuerte, ahora que cada historia tiene criterios BDD. |

### Los cinco hallazgos transversales

1. **La independencia se sacrificó a propósito.** El orden por riesgo produce la cadena US-01 → US-02 → US-03 → US-04 → US-05 → US-06: seis historias sin paralelizar. Con dos desarrolladores, uno mira. La alternativa es reorganizar en rebanadas verticales (agendar punta a punta con disponibilidad falseada, y después reemplazar el mock). **Decisión pendiente.**

2. **Seis historias son dos.** US-03, US-05, US-06, US-11, US-12 y US-14. Partirlas lleva el backlog de 14 a ~22 ítems que sí entran en un sprint. Cada archivo indica dónde cortar.

3. **Cuatro historias no son estimables hoy:** US-03 (persistencia sin decidir), US-08 (mecanismo de detección sin probar), US-11 (t-2h sin RF), US-13 (Sheets sin RF), y US-14 (multi-tenancy sin decidir).

4. **US-07 no debería ser una historia.** Los timezones son una restricción transversal. Sus cuatro criterios BDD funcionan mejor repetidos dentro de US-05, US-06, US-10 y US-11.

5. **US-01, US-02 y US-03 son enablers,** no historias de usuario. Etiquetarlas como tales en vez de fabricarles un beneficio de usuario final.

---

## Secuencia de aprendizaje

### Sección 1 — Habilitar el canal antes de prometer nada
**US-01, US-02**

Meta es un tercero que puede tardar semanas en aprobar una cuenta y que deshabilita webhooks lentos sin avisar. Nada de lo que sigue es testeable hasta que el canal esté vivo y estable, y es lo más barato de probar. Empezamos acá porque es riesgo de habilitación pura: no depende de nuestro talento, depende de un permiso externo.

### Sección 2 — Probar la promesa central: agendar en 15 segundos
**US-03, US-04, US-05, US-06, US-07**

Acá vive la propuesta de valor entera. El orden interno no es arbitrario: primero el bot tiene que sostener una conversación coherente (US-03), después necesita ver el calendario (US-04), después leerlo bien (US-05), después escribirlo bien (US-06) y recién ahí importa que la hora sea la correcta (US-07). Cada una hace testeable a la siguiente.

### Sección 3 — Convivir con el equipo humano que atiende
**US-08, US-09**

Las dos secciones anteriores prueban que el sistema funciona. Esta prueba que la PyME lo tolera adentro de su operación real, que es una pregunta distinta y más difícil. El dueño no evalúa nuestra arquitectura: evalúa si el bot lo hace quedar mal delante de su cliente.

### Sección 4 — Demostrar el ROI que salimos a vender
**US-10, US-11**

Todo lo anterior hace que el producto funcione. Esta sección es la que hace que se pague. El lean canvas promete bajar el ausentismo de 20-35% a menos del 8%, y esa es la cifra con la que cerramos ventas. Va después de la agenda porque necesita turnos reales para medirse, y su ciclo de aprendizaje es el más lento del MVP.

### Sección 5 — Extender el valor hacia adelante y hacia atrás
**US-12, US-13**

El cotizador antes de agendar, el volcado de leads después. Van al final a propósito: el producto ya es vendible sin ellas, así que su lugar natural es donde podemos aprender sin bloquear el piloto.

### Sección 6 — Que el cliente pueda arrancar y sostenerse solo
**US-14**

Última porque es la única sección que podemos suplir a mano durante los pilotos. Deja de ser opcional cuando queremos vender el cuarto cliente sin que el margen se lo coma el soporte.

---

## Priorización final sugerida

**Orden exacto de ejecución:**

```
Semana 0 (paralelo, sin código)   Trámites externos + 2 spikes + 2 RF faltantes
Sprint 1   US-01 → US-02 → US-04
Sprint 2   US-03 → US-05
Sprint 3   US-06 → US-07(*)
Sprint 4   US-08 → US-10
Sprint 5   US-09 → US-11a → US-12
Sprint 6   US-13 → US-14
```

`(*)` US-07 no se ejecuta como historia: sus criterios se incorporan a US-05, US-06 y US-10.

### Semana 0 — lo que arranca antes que el código

Cinco ítems que no son historias pero bloquean historias, y que hoy están escondidos dentro de estimaciones:

| Ítem | Bloquea | Por qué va primero |
| :-- | :-- | :-- |
| Verificación de Meta Business + número | US-01 | Demora externa impredecible, de días a semanas |
| **Aprobación de plantillas de mensaje** | US-10 | El cuello de botella real del MVP |
| Pantalla de consentimiento de Google | US-04 | Revisión de Google con demora propia |
| **Spike:** ¿Meta entrega mensajes salientes manuales? | US-08 | Sin esto, US-08 no es estimable |
| **Descubrimiento:** listas de precios reales de 4 rubros | US-12 | Un día de trabajo responde la pregunta más cara |

### Justificación del orden

**Por qué US-01 y US-02 primero:** son el único riesgo que no controlamos con talento sino con permisos. Si Meta rechaza la cuenta, todo el plan cambia — y es mejor saberlo en la semana 1 que en la 6. Además son baratas: si el riesgo se disipa, perdimos poco.

**Por qué US-04 sube al sprint 1 pese a estar cuarta:** es la única historia sin dependencias internas además de US-01. Es la única oportunidad real de paralelizar en todo el backlog, y desperdiciarla dejaría a medio equipo esperando.

**Por qué la agenda antes que el cotizador:** el producto es vendible agendando sin cotizar; no es vendible cotizando sin agendar. La secuencia sigue a la propuesta de valor, no al orden del flujo conversacional.

**Por qué US-08 antes que US-10 pese a que US-10 vale más:** US-08 es la condición para poner el bot en un número real con clientes reales, y US-10 necesita exactamente eso para medirse. El orden no lo dicta el valor sino la habilitación.

**Por qué US-10 tan tarde siendo la de mayor valor comercial:** necesita turnos reales acumulados y tiene el ciclo de aprendizaje más lento del MVP — cada dato tarda 24 horas. Por eso su dependencia externa (las plantillas) se inicia en la semana 0: para que cuando llegue el sprint 4, el trámite ya esté cerrado.

**Por qué US-13 y US-14 al final:** durante los pilotos podemos suplirlas a mano. Tres clientes se atienden con soporte humano; el cuarto ya no.

### Las tres decisiones que hay que cerrar antes del sprint 2

Heredadas de la auditoría de coherencia. Ninguna es técnica en su fondo: las tres son de producto.

**Dos de las tres nunca estuvieron realmente abiertas** — la respuesta ya existía en documentos que este backlog no había cruzado. Quedaron cerradas por escrito en el PRD (T-007) y **ya no bloquean nada**:

1. ~~**¿El estado de la conversación vive en MySQL o en Redis?**~~ → **Las dos, con jerarquía:** `conversations` es la única fuente de verdad, Redis es caché de lectura y lock. Ante divergencia manda MySQL. Escrito en **RF-A3**; ya estaba en `03-flujos/05-estados-flujo-conversacional.md` §1. **US-03 desbloqueada.**
2. ~~**¿Multi-tenancy por columna `tenant_id` o por schema?**~~ → **Por columna, con Global Scopes.** Escrito en **RNF-01**; ya coincidían el C4 L2, el C4 L3 y el modelo de datos. **US-14 desbloqueada.**
3. ~~**¿Outlook entra al MVP?**~~ → **Fuera del MVP.** Escrito en el OUT-OF-SCOPE explícito del PRD (T-007). Duplicaría toda la superficie de calendario para un segmento sin validar. El valor `outlook_calendar` del `ENUM` se conserva para no migrar la tabla, pero sin código detrás. **US-04, US-05 y US-06 desbloqueadas.**

> **Corrección:** la versión anterior atribuía al C4 nivel 2 la promesa de Outlook. El archivo actual no la contiene; quien la sostiene es el `provider ENUM` del modelo de datos.

**Las dos decisiones que este listado no registraba también quedaron cerradas** (T-007):

4. **¿Dónde se captura el nombre?** → **`GATHERING_PARAMS`.** Con `SLOT_SELECTED`, toda conversación abandonada antes de elegir horario llegaría a Sheets sin nombre, que es justo el lead que US-13 existe para capturar. Afectaba US-03, US-06 y US-13.
5. **¿Cuánto dura una conversación inactiva?** → **30 minutos.** Coincide con la matriz de transiciones y AC-11.3. ⚠️ **AC-03.1 dice 2 h y hay que corregirlo.** Afectaba US-03 y US-11.

Y apareció una sexta que ningún documento registraba, también cerrada:

6. **¿Qué pasa con el horario si Google se cae mientras el cliente lo está eligiendo?** → **Se libera y la conversación vuelve a `IDLE`.** `ERROR_FALLBACK` estaba en el catálogo de estados y no en la matriz de transiciones: nadie había definido cómo se entra ni cómo se sale. Conservar el horario y reintentar exigiría una reserva temporal con TTL que no existe, y un horario bloqueado por un turno que nunca se completó **es exactamente H-04**.
