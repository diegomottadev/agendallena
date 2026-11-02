# Decisiones tomadas

**Actualizado:** 2026-08-21

Este archivo **responde los pendientes** de [decisiones-pendientes.md](decisiones-pendientes.md) y de [preguntas-que-salieron-construyendo.md](preguntas-que-salieron-construyendo.md). Una entrada por decisión: qué se preguntó, qué contestó Diego, y qué se desbloquea con eso.

Las decisiones 1, 2, 3 y 5 de `decisiones-pendientes.md` **ya estaban cerradas el 2026-08-20** y no se volvieron a preguntar. Están acá solo como referencia al final.

---

# Resumen: qué se decidió y qué pasó con cada una

| § | Decisión | Resultado |
| :-: | :-- | :-- |
| 1 | Tasa de ausentismo | Se calcula **sobre los turnos marcados**. Ratifica lo implementado: **nada que construir** |
| 2 | Recordatorio frenado por plantillas sin aprobar | **No consume el candado**. ✅ **Cubierto el 2026-08-21** — el comportamiento ya era correcto; faltaba el test. ⚠️ Pero la premisa *«sale al aprobar Meta»* **era falsa**: ver § 13 |
| 3 | PyME con cuenta pero sin plantillas | **Estricto**: sin las tres aprobadas no se manda. ✅ **Construido el 2026-08-21** — era un defecto real, no solo falta de test |
| 4 | Re-reserva del mismo horario cancelado | **Se crea un turno nuevo**. Ya lo hace `live_event_id`: **nada que construir** |
| 5 | Aviso al que perdió el horario | *«Ese horario ya no está disponible. Estos sí:»*. ✅ **Construido el 2026-08-21**, con rojo previo y verificado por mutación |
| 6 | Asistencia de un turno en curso | **Sí, apenas empieza**. ⚠️ **Solo falta el test**: la comparación ya era contra el inicio |
| 7 | Frecuencia de la conciliación | **Cada 15 minutos**, como constante con nombre. ✅ **Construido el 2026-08-21** — `ConciliarAgendamientos::MINUTOS_ENTRE_CORRIDAS`, y el scheduler **deriva** de ella |
| 8 | Destino de los desalineados | **Pantalla en el panel**. Depende de T-034: **queda fuera del núcleo**, sigue el log hasta entonces |
| 9 | Turno borrado a mano en Calendar | **La misma revisión mira las dos direcciones**. ✅ Construido el 2026-08-21. ⚠️ **Corregido el mismo día: ya no cancela, deja el turno en duda.** Ver § 18 |
| 10 | Discovery del cotizador | **Pospuesto hasta después del piloto**. **14 pts congelados** y el riesgo de rubro se asume |
| 11 | Planilla de Google compartida | **Prohibida**: único sobre `spreadsheet_id` dentro de T-044, que sigue diferido |
| 12 | Rebanadas paralelas | **No se reorganiza**. *Independiente* queda en 2/26 a sabiendas |

**En una línea:** cuatro decisiones no cuestan nada, cuatro son ciclos TDD cortos o de una línea, dos difieren trabajo con su alcance ya cerrado, una abre alcance sin estimar (§ 9) y una congela 14 puntos a propósito (§ 10).

---

## ✅ Estado de construcción al 2026-08-21

**Las cinco decisiones que pedían código están construidas**, cada una con su ciclo TDD y con la fase roja escrita por un agente que no vio la implementación. La suite pasó de **428 a 477 tests**.

| § | Qué se construyó | Rojo previo |
| :-: | :-- | :-- |
| 2 | Dos tests del candado | 🟢 caracterización · verificado por mutación |
| 5 | El texto neutro | 🔴 legítimo · verificado por mutación |
| 6 | Tres tests del turno en curso | 🟢 caracterización · **dos** mutaciones |
| 7 | Cadencia de 15 min desde constante | 🔴 legítimo |
| 9 | Dirección inversa de la conciliación | 🔴 legítimo · 11 tests, **+4 después** (ver § 16) |
| **13** | **Defecto nuevo:** el bloqueo de plantillas `PENDING` | 🔴 legítimo · 15 tests |

⚠️ **Tres decisiones nuevas quedaron marcadas para que Diego las ratifique.** Están abajo, en § 14.

---

# 🔴 Las que podían costar plata o un cliente en silencio

## 1 · La tasa de ausentismo, ¿sobre qué denominador se calcula?

**Origen:** `preguntas-que-salieron-construyendo.md` § 1
**Estado antes:** interpretada e implementada como `ausentes ÷ (asistidos + ausentes)`
**Respuesta de Diego:** **solo sobre los turnos marcados.** Confirmó la recomendación.
**Fundamento:** un turno sin marcar **no es una asistencia, es un dato que no tenemos**. Si entrara al denominador, la tasa bajaría cuando el dueño marca menos — el número mejoraría solo por dejar de registrar, que es el peor defecto posible en la métrica que sostiene el precio. En el escenario de prueba la diferencia entre las dos lecturas es **25% contra 16,7%**.
**Destraba:** nada nuevo — **ratifica** lo implementado en T-036 y T-038. **Sigue frenando:** nada.
**Qué hay que construir:** nada. Queda como definición escrita del indicador que se le muestra al dueño y con el que se vende.

## 2 · Si Meta todavía no aprobó las plantillas de una PyME, ¿ese turno se recuerda cuando aprueben?

**Origen:** `preguntas-que-salieron-construyendo.md` § 2
**Estado antes:** abierta — el candado anti-duplicados podía consumirse con un intento frenado
**Respuesta de Diego:** **el intento frenado NO consume el candado.** Confirmó la recomendación.
**Fundamento:** el turno se reintenta en la próxima corrida y el recordatorio sale apenas Meta aprueba. La alternativa deja a un cliente nuevo sin **ningún** recordatorio de su primer día de uso — exactamente cuando está decidiendo si el producto le sirve — y falla en silencio.
**Destraba:** confirma el comportamiento de T-037. **Sigue frenando:** nada. T-039 (3 pts, detección y reintento de recordatorios no entregados) sigue diferido y no lo cambia esta decisión.
**Qué hay que construir:** verificar que el candado de `EnviarRecordatorios` sólo se marque tras un envío efectivo. Si el test que lo prueba no existe, es un **ciclo TDD corto**.

## 3 · Una PyME con cuenta pero sin ninguna plantilla registrada, ¿puede mandar recordatorios?

**Origen:** `preguntas-que-salieron-construyendo.md` § 2 bis
**Estado antes:** rama laxa — pasaba el freno, y **ningún test la ejercitaba**
**Respuesta de Diego:** **hacerlo estricto.** Sin las tres plantillas aprobadas, no se manda.
**Fundamento:** un camino que nadie prueba es un camino que nadie sabe si funciona, y este termina en un mensaje que Meta rechaza con un error que nadie sabe leer. Cierra el hueco de cobertura declarado en `CLAUDE.md`.
**Destraba:** cierra el único hueco de cobertura conocido del núcleo. **Sigue frenando:** nada.
**Qué hay que construir:** ✅ **Ya construido el 2026-08-21**, con su ciclo TDD completo. 🔴 El test con un tenant con `waba_id` y cero plantillas registradas falló mandando a `graph.facebook.com/v21.0/.../messages` con cero códigos registrados. 🟢 El freno pasó a preguntar por `PlantillasDelTenant::todasAprobadas()`.

⚠️ **No hacía falta un tercer freno: el defecto era cuál de los dos métodos preguntaba el que ya existía.** `tienePlantillasAprobadas()` usaba `pendientes()` —las registradas que no están aprobadas—, y una PyME sin alta corrida no tiene ninguna registrada, así que la lista salía vacía y se leía como *"están todas aprobadas"*. `todasAprobadas()`, que sí contestaba bien, **no tenía ningún llamador en producción**: sus únicos invocadores eran los tests. Es la misma forma que el bug de `AltaDeCuenta` del día anterior. Ahora `pendientes()` recorre el juego completo de `NOMBRES` y las dos respuestas coinciden.

## 4 · El cliente canceló y vuelve a pedir el mismo horario, ¿qué hacemos con la fila cancelada?

**Origen:** `preguntas-que-salieron-construyendo.md` § 2 ter
**Estado antes:** el defecto ya estaba corregido; faltaba ratificar **cuál** de las tres salidas era la buena
**Respuesta de Diego:** **crear un turno nuevo.** Confirmó la recomendación.
**Fundamento:** el dato de que un cliente canceló y volvió es justamente el que hay que mirar en el piloto, y perderlo por comodidad es caro. Es además lo que ya hace la corrección del 2026-08-21: la columna generada `live_event_id` queda en `NULL` al cancelar, dos `NULL` no chocan en el único de MySQL, y la fila cancelada **suelta el candado sola** — sin un `if` que alguien pueda borrar.
**Destraba:** ratifica la corrección ya aplicada. **Sigue frenando:** nada.
**Qué hay que construir:** nada. ⚠️ Queda anotado que **revertir la columna generada volvería a traer el bug**: el candado vive en el esquema a propósito.

---

# 🟡 Las que cambian lo que el cliente ve

## 5 · ¿Qué le decimos al cliente que perdió el horario?

**Origen:** `preguntas-que-salieron-construyendo.md` § 3
**Estado antes:** ningún documento fijaba el texto; sólo estaba garantizado que no se confunda con los otros dos avisos
**Respuesta de Diego:** el texto **neutro**: *«Ese horario ya no está disponible. Estos sí:»* seguido de la lista de horarios.
**Fundamento:** el bot no suena coloquial y el mensaje dice lo que pasó sin dar a entender que el sistema falló. Se mantiene la distinción de los tres avisos:

| Situación | Lo que el cliente lee |
| :-- | :-- |
| **Se te adelantaron** | *«Ese horario ya no está disponible. Estos sí:»* + lista |
| Se rompió algo nuestro | «Hubo un problema, te contactamos» |
| No hay agenda disponible | «No tengo lugar para ese día» |

**Destraba:** T-029 queda con su texto definido. **Sigue frenando:** nada.
**Qué hay que construir:** poner el texto donde va (T-029 / configurable en T-017). Si el texto está hardcodeado, es un **cambio de una línea**; si sale de `business_settings`, entra como cuarto texto del bot.

## 6 · Un turno que está ocurriendo ahora mismo, ¿se puede marcar la asistencia?

**Origen:** `preguntas-que-salieron-construyendo.md` § 4
**Estado antes:** deliberadamente sin test, para no congelar una decisión no tomada
**Respuesta de Diego:** **sí, apenas empieza.** Confirmó la recomendación.
**Fundamento:** en una peluquería el dueño marca cuando la persona se sienta, no cuando se va. Y con el ausentismo calculado **sobre los turnos marcados** (§ 1), todo lo que no se marca desaparece de la métrica: cuanto más fácil sea marcar, más confiable es el número.
**Destraba:** cierra la última ambigüedad de T-038. **Sigue frenando:** nada.
**Qué hay que construir:** **solo el test.** ⚠️ **Corrección al párrafo de arriba:** la comparación **ya es contra el inicio**. `AsistenciaController::todaviaNoEmpezo()` hace `$booking->start_time->greaterThan(now())`, o sea que ya rechaza únicamente los turnos futuros y un turno en curso ya se puede marcar. Nunca se comparó contra el fin.

El hueco era **de cobertura, no de comportamiento**: el caso del medio —inicio pasado, fin todavía no— se había dejado sin test **a propósito**, para no congelar en un test una decisión que no estaba tomada. Ahora que está tomada, se escribe. Los dos tests de los extremos (ayer sí, mañana no) quedan como están.

⚠️ Como el comportamiento ya existía, el test pasa a la primera. En este proyecto eso obliga a **verificarlo por mutación**: romper la comparación a propósito y confirmar que el test se pone rojo. Si no se pone, no estaba probando nada.

---

# ⚪ Las de funcionamiento interno

## 7 · ¿Cada cuánto corre la revisión que concilia Calendar con nuestra base?

**Origen:** `preguntas-que-salieron-construyendo.md` § 5
**Estado antes:** lo único escrito era *"periódica"*
**Respuesta de Diego:** **cada 15 minutos.** Confirmó la recomendación.
**Fundamento:** es barato y acota a un cuarto de hora el tiempo en que un horario puede quedar ocupado sin turno real detrás. Es la contracara de la decisión del 2026-08-20 de **conciliar en vez de compensar**.
**Destraba:** T-030 queda con su frecuencia definida. **Sigue frenando:** nada.
**Qué hay que construir:** la frecuencia va como **constante con nombre** en el scheduler, no como número suelto — moverla a 5 o a 60 tiene que ser una línea. ⚠️ Ojo con la cuota de la API de Google: se comparte con la consulta de disponibilidad, que sí es camino crítico del cliente.

## 8 · Cuando la revisión encuentra un desalineado, ¿qué hace con él?

**Origen:** `preguntas-que-salieron-construyendo.md` § 6
**Estado antes:** deja constancia en el registro del sistema, y nada más
**Respuesta de Diego:** **que lo vea el dueño en el panel.**
**Fundamento:** el desalineado le ocupa al dueño un horario que no tiene turno detrás — es una venta que no puede entrar — y el que puede resolverlo es él, no nosotros. Una línea en el log no la mira nadie hasta que algo ya falló.
**Destraba:** define el destino de la conciliación de T-030. **Depende de:** **T-034** (estado de las integraciones), que está fuera del núcleo del MVP. **T-046** (login del panel) ya está hecho —se adelantó—, así que la dependencia real es una sola.
**Qué hay que construir:** una pantalla que liste los desalineados detectados, dentro de T-034. Sale del alcance del núcleo, así que **no entra antes del piloto**.

⚠️ **Hasta que esa pantalla exista, el comportamiento sigue siendo el log** — no porque se haya elegido, sino porque no hay dónde mostrarlo todavía. El log **no se saca** cuando llegue el panel: es la fuente que la pantalla va a leer.

⚠️ **Lo que el piloto tiene que medir, y ahora importa más:** cuántos desalineados aparecen por semana. Si son cero —lo esperable, porque hace falta que el proceso se muera justo en el medio— la pantalla es trabajo caro para un caso que no ocurre, y conviene saberlo antes de construirla. Si son varios, la pantalla está bien priorizada.

## 9 · El dueño borra un turno desde su propio Google Calendar, ¿lo cubrimos?

**Origen:** `preguntas-que-salieron-construyendo.md` § 7 (marcada ⚠️ como abierta, no como olvido)
**Estado antes:** la conciliación mira **una sola dirección** — turnos en Google que nuestra base no conoce
**Respuesta de Diego:** **que la misma revisión de 15 minutos mire las dos direcciones.**
**Fundamento:** reusa el job que ya va a estar corriendo, así que es bastante más barato que T-027. Se paga con latencia: la detección tarda hasta 15 minutos en vez de ser inmediata. Hoy el caso está sólo **parcialmente mitigado** —antes de mandar un recordatorio se verifica que el evento siga existiendo, así que no le recordamos a un cliente un turno que ya no está— pero el turno sigue vivo en la base, **cuenta para la tasa de ausentismo** (§ 1) y ocupa la agenda.
**Destraba:** cubre el hueco de la dirección inversa sin pagar T-027. **Sigue frenando:** nada. **T-027** (8 pts, rango real 8–13) y **T-028** (3 pts) quedan diferidos, ahora con menos urgencia.
**Qué hay que construir:** alcance nuevo sobre **T-030**: la revisión también busca turnos vivos en nuestra base cuyo evento ya no existe en Calendar, y los marca como cancelados. ⚠️ **No está estimado en el backlog** — hay que estimarlo antes de comprometerlo.

⚠️ **Lo que hay que definir al construirlo:** un turno que el dueño borró a mano **¿cuenta como cancelado o como ausente?** Con el ausentismo calculado sobre los turnos marcados, marcarlo mal ensucia el número con el que vendés. Queda como pregunta para el ciclo TDD, no para hoy.

---

# 🟡 Las que frenaban cosas fuera del MVP

## 10 · ¿Hacemos el día de investigación del cotizador (T-006)?

**Origen:** `decisiones-pendientes.md` § 4
**Estado antes:** abierta. La recomendación escrita era hacerlo igual, aunque el cotizador siguiera postergado
**Respuesta de Diego:** **posponerlo hasta después del piloto.** No tomó la recomendación.
**Fundamento:** el piloto puede contestar lo mismo gratis si los clientes que consiga piden cotización. Es información que hoy se pagaría con un día de trabajo y que el mercado puede dar sola.
**Destraba:** nada. **Sigue frenando:** **T-006, T-016, T-040 y T-041 — 14 puntos congelados sin fecha.**
**Qué hay que construir:** nada.

⚠️ **El riesgo aceptado, escrito para que no sorprenda:** el cotizador **encaja con inmobiliarias y mantenimiento, y no encaja con salud, estética ni distribuidoras**, que cobran por lista fija. Si la respuesta termina siendo *"sólo dos rubros de los cuatro"*, se sabe **después** de haber vendido y no antes. Es una apuesta consciente, no un olvido.

⚠️ **Lo que el piloto tiene que medir, y ahora es la fuente de esta respuesta:** cuántos clientes preguntan por precio antes de pedir turno, y de qué rubro son.

## 11 · ¿Dos negocios pueden usar la misma planilla de Google?

**Origen:** `decisiones-pendientes.md` § 6
**Estado antes:** hoy nada lo impide
**Respuesta de Diego:** **prohibirlo.** Confirmó la recomendación.
**Fundamento:** los clientes de una PyME terminarían mezclados con los de otra en la misma planilla. Es un problema de privacidad entre clientes y choca de frente con el aislamiento por tenant (**RNF-01**), que es el invariante que más se audita en este proyecto.
**Destraba:** cierra el alcance de **T-044** (3 pts, diferido). **Sigue frenando:** nada — T-044 sigue diferido por el recorte Pareto, no por esta decisión.
**Qué hay que construir:** cuando se retome T-044, un **único sobre el `spreadsheet_id`** (no una validación en código: el candado va en el esquema, como se aprendió con `live_event_id` en § 4). Ciclo TDD corto dentro del ticket.

---

# ⚪ La de organización

## 12 · ¿Reorganizamos el trabajo en rebanadas paralelas?

**Origen:** `decisiones-pendientes.md` § 7
**Estado antes:** ⚠️ era la **única decisión abierta que podía mover** el 2/26 de INVEST-*Independiente*
**Respuesta de Diego:** **no tocarlo por ahora.** Confirmó la recomendación.
**Fundamento:** los tickets están encadenados, pero el flujo principal ya está terminado: **la cadena más larga ya se recorrió**. Con una sola persona programando, el encadenamiento no cuesta nada. Se replantea si suma una segunda persona.
**Destraba:** nada, y no bloquea nada. **Sigue frenando:** nada.
**Qué hay que construir:** nada. ⚠️ **Queda pendiente el corte de T-018**, que el README ya declara mal estimado en 8 puntos — pero eso es una corrección de estimación, no la reorganización que se descartó acá.

---

# Cómo avanzamos

**Nada de lo que sigue está bloqueado por vos.** Las doce respuestas dejaron el camino libre; lo único que espera de tu lado es el token de Meta, que ya estaba en la lista.

## Ahora — lo que estas decisiones habilitan

| Orden | Qué | Ticket | Tamaño | Bloqueado por |
| :-: | :-- | :-- | :-- | :-- |
| 1 | **Primer commit del repo.** Todo está sin trackear, y los tres agentes TDD tienen su control de límites decorativo hasta que exista un commit base | — | — | nada |
| 2 | Tercer freno estricto: sin las tres plantillas aprobadas no se manda recordatorio (§ 3) | T-037 · T-050 | ciclo TDD corto | nada |
| 3 | El intento frenado **no** consume el candado del recordatorio: test que lo fije (§ 2) | T-037 | ciclo TDD corto | nada |
| 4 | Marcar asistencia de un turno en curso (§ 6) | T-038 | ciclo TDD corto | nada |
| 5 | Texto del aviso de colisión (§ 5) | T-029 · T-017 | 1 línea | nada |
| 6 | Frecuencia de conciliación a 15 min, como constante con nombre (§ 7) | T-030 | 1 línea | nada |
| 7 | **Estimar** la conciliación en las dos direcciones, y recién después construirla (§ 9) | T-030 ampliado | ⚠️ sin estimar | estimarlo primero |

Los cuatro ciclos TDD de las filas 2 a 5 son de los baratos: **un criterio, un test rojo, el mínimo verde**. Ninguno toca el camino crítico del cliente.

## Después — lo que sigue diferido, ahora con su alcance cerrado

| Qué | Ticket | Puntos | Por qué espera |
| :-- | :-- | :-- | :-- |
| Único sobre el `spreadsheet_id` (§ 11) | T-044 | 3 | Sheets sigue fuera del recorte Pareto |
| Pantalla de desalineados (§ 8) | T-034 | — | Fuera del núcleo. **T-046 ya está hecho**, así que la única dependencia es T-034 |
| Sync inversa con *watch* de Google | T-027 · T-028 | 8–13 · 3 | **Menos urgente** desde § 9: la conciliación bidireccional cubre el caso, más lenta pero mucho más barata |
| Discovery de catálogos de precios (§ 10) | T-006 | 3 | Pospuesto hasta después del piloto, por decisión de Diego |
| Motor y conversación del cotizador | T-016 · T-040 · T-041 | 11 | Encadenados a T-006 |

## Lo que espera de vos

| Qué | Ticket | Estado |
| :-- | :-- | :-- |
| **Token permanente de Meta** | T-001 | 🔴 El temporal se vence solo y hay que rehacerlo a mano cada vez |
| Plantillas del **segundo** cliente | T-050 | Las tres aprobadas valen sólo para la cuenta de prueba: una WABA por PyME (decisión del 2026-08-20) |

## Lo que el piloto tiene que medir

Ya no son curiosidades: **dos de las doce respuestas de arriba se apoyan en números que hoy no existen.**

- **Cuántos clientes derivados escriben al segundo número** — los que no lo hagan son ventas perdidas que hoy nadie ve.
- **Cuánto tardás en dar de alta un cliente completo** — si son tres horas, el objetivo de *"alta en menos de una hora"* es fantasía.
- **Si el aviso de t-2h mueve la asistencia** — T-043 está diferido; probalo a mano una semana antes de que alguien escriba código.
- 🆕 **Cuántos desalineados aparecen por semana** (§ 8) — si son cero, la pantalla del panel es trabajo caro para un caso que no ocurre.
- 🆕 **Cuántos clientes preguntan por precio antes de pedir turno, y de qué rubro** (§ 10) — es lo que reemplaza al discovery T-006 que decidiste posponer.

---

# Lo que sigue abierto

**De los dos archivos fuente, nada.** Las 12 preguntas y decisiones abiertas quedaron respondidas el 2026-08-21. Lo que sigue sin cerrar viene de otro lado:

| Qué | Dónde está | Por qué sigue abierto |
| :-- | :-- | :-- |
| ⚠️ Estimar la conciliación bidireccional | § 9 de este archivo | Alcance nuevo sobre T-030, sin puntos asignados |
| ⚠️ Un turno borrado a mano en Calendar, ¿cancelado o ausente? | § 9 de este archivo | Se decide en el ciclo TDD que lo construya |
| ⚠️ Cortar T-018, que no son 8 puntos | `README.md` § 6 | Es corrección de estimación, no la reorganización descartada en § 12 |
| **AC-18.4** · colisión en el re-agendamiento | `preguntas-que-salieron-construyendo.md` | No hay re-agendamiento: T-042 diferido |
| **AC-22.4** · un fallo de Sheets no impide el turno | idem | No hay integración con Sheets: T-044 y T-045 diferidos |
| **AC-17.4** · cuatro respuestas al recordatorio | idem | Se distinguen tres. *"Re-agendó"* no existe todavía |

---

# Referencia: las que ya estaban cerradas antes de esta ronda

No se volvieron a preguntar. Están en `decisiones-pendientes.md` con su fundamento completo.

| # | Decisión | Resolución | Fecha |
| :-: | :-- | :-- | :-- |
| 1 | ¿Cuándo se aparta el horario? | Al elegirlo · TTL 5 min · uno solo por cliente | 2026-08-20 |
| 2 | Turno en Google que no se guardó acá | Dejarlo y conciliar después | 2026-08-20 |
| 3 | ¿Una cuenta de WhatsApp o una por PyME? | Una por PyME → salió T-050 | 2026-08-20 |
| 5 | ¿El bot detecta solo que contestaste? | Sin decisión: la plataforma no lo permite. Cierra T-004 y T-032 | 2026-08-20 |

---

# 🔴 Lo que apareció construyendo

## 13 · Una plantilla `PENDING` no podía llegar nunca a `APPROVED`

**Origen:** salió cubriendo § 2, el 2026-08-21. No estaba en ninguna lista.
**Estado antes:** defecto vivo en producción, invisible para los 442 tests en verde.

Es la **cuarta vez en tres días** que aparece la misma forma en este proyecto: *un camino que nadie recorre en producción*. Las otras tres fueron `AltaDeCuenta` sin llamador, `todasAprobadas()` sin llamador, y dos migraciones que nunca habían corrido contra una base real.

**La cadena, verificada entera:**

1. `PlantillasDelTenant::registrar()` tenía **exactamente dos llamadores**, los dos dentro de `AltaDeCuenta::crear()` — o sea, solo en el momento del alta.
2. **No existe handler del webhook `message_template_status_update`**: nada le avisa al sistema que Meta aprobó.
3. `DarDeAltaCuenta::plantillasPorCrear()` solo re-creaba las `''` o `REJECTED`. **Una `PENDING` se salteaba**, el comando informaba *«ya tiene sus tres plantillas creadas»* y devolvía `SUCCESS`.
4. `EnviarRecordatorios` exige `todasAprobadas()`.

**Resultado:** una PyME recién dada de alta —el estado normal de todo cliente nuevo— **no mandaba un solo recordatorio, nunca**, y no había forma de destrabarla. El operador volvía a correr el comando, le decía que estaba todo bien, y seguía sin salir nada. Es el fallo silencioso que RNF-03 prohíbe, y deja muerta la mitad del producto que se cobra.

⚠️ **Esto vuelve falsa la premisa de § 2.** *«El recordatorio sale apenas Meta aprueba»* no podía ocurrir.

**Qué se construyó:**

- **`app/Meta/EstadoDePlantillasEnMeta.php`** — consulta `GET /{waba-id}/message_templates` con el token de esa integración y registra lo que Meta conteste. **Nunca hace `POST`.** Si Meta no contesta bien, **no registra nada** y lanza: un error de Meta no autoriza a concluir nada sobre las plantillas del cliente.
- **`plantillas:sincronizar`** — tarea agendada, cross-tenant, solo lectura. Saltea a las PyMEs sin `waba_id` y a las que ya tienen las tres aprobadas, así que **en régimen no consulta a nadie**.
- **`cuenta:dar-de-alta`** consulta el estado antes de decidir, y ya **no informa éxito** con el cliente sin aprobar: lista el estado real y devuelve `FAILURE`.

⚠️ **El daño residual que esto NO resuelve, y no tiene ticket:** `EnviarRecordatorios` filtra por `whereBetween('start_time', [ahora+24h ± tolerancia])`. Que el freno no consuma el candado salva al turno que sigue **dentro** de la ventana; **al que ya la cruzó no lo salva nada**. La sincronización acota la pérdida a 15 minutos — no la elimina. Un barrido de rezagados no existe.

⚠️ **El webhook `message_template_status_update` sigue siendo la solución real** y no tiene ticket. Lo construido es una palanca automática, no una notificación.

---

# ⚠️ 14 · Decisiones tomadas construyendo, pendientes de que Diego las ratifique

Ninguna está escrita en los documentos. Se tomaron porque el código no se podía escribir sin ellas, y van marcadas ⚠️ en los docblocks —no coladas como si estuvieran decididas.

| # | Decisión | Por qué se eligió así | Qué pasa si Diego dice que no |
| :-: | :-- | :-- | :-- |
| 1 | **La dirección inversa (§ 9) solo cancela turnos cuyo inicio todavía no pasó** | Los dos daños que § 9 nombra son «ocupa la agenda» y «cuenta para la tasa de ausentismo». Un turno pasado **no ocupa nada**; y como la tasa se calcula solo sobre los marcados (§ 1), cancelar hacia atrás **saca turnos de la métrica en silencio**. El dueño que limpia su calendario viejo borraría la evidencia del número con el que se vende | Dos tests con canario en `ConciliacionDeAgendamientoTest` y una condición en `turnosVivosEnLaVentana()` |
| 2 | **`SincronizarPlantillas::MINUTOS_ENTRE_CORRIDAS = 15`** | Como la tarea saltea a las PyMEs con las tres aprobadas, en régimen no consulta a nadie: `PENDING` solo existe mientras se da de alta un cliente. La cadencia corta sale casi gratis y acota la pérdida de turnos. Mismo valor que la conciliación: un número menos que recordar | Una línea. El test **mide el intervalo real** entre corridas en vez de comparar el string del cron, así que cualquier valor sirve sin tocarlo |
| 3 | **Comando nuevo `plantillas:sincronizar` en vez de reusar `cuenta:dar-de-alta`** | Los códigos de salida son incompatibles: el alta **tiene** que fallar con plantillas sin aprobar, y una tarea agendada que devuelve `FAILURE` en cada corrida es ruido que hace que nadie mire el scheduler. Y el alta **crea** (`POST`) mientras la tarea solo **lee**: una tarea automática que puede crear plantillas en la cuenta de un cliente es un riesgo que nadie pidió — re-pedir una que ya existe la deja `REJECTED`, en silencio y en repetición | Fusionar los dos comandos y romper uno de los dos contratos |

⚠️ **Una regla de borde que salió de construir la # 3:** `cuenta:dar-de-alta` devuelve `SUCCESS` cuando **creó** plantillas y quedaron `PENDING` (es el resultado normal de un alta), y `FAILURE` cuando **no tenía nada que crear** y encontró al cliente sin aprobar (que es el caso que engañaba al operador). No está escrita en ningún lado.

---

# ⚠️ 15 · Código vivo sin cobertura, declarado

Ninguno es un olvido: los tres se detectaron construyendo y se dejan anotados en vez de taparlos.

- **`DarDeAltaCuenta::sincronizar()` cuando Meta falla.** Escribe por pantalla, registra y sigue con lo último conocido. **Ningún test ejerce esa rama** — el criterio la dejó fuera de alcance explícitamente. Es exactamente la forma de la rama laxa de `EnviarRecordatorios` que se corrigió esta misma mañana.
- **Las ramas «PyME sin integración de Meta» y «sin `waba_id`» de `plantillas:sincronizar`.** Se cuentan como salteadas y ningún test las distingue entre sí. **Una PyME a la que nunca le corrieron el alta queda invisible en el scheduler**: nadie la nombra en el log.
- **Paginación de `message_templates`.** Se pide `limit=100` y **no se sigue el cursor `paging.next`**. Con tres plantillas por cuenta no se nota, pero una PyME con muchas plantillas propias podría empujar las nuestras fuera de la primera página y la sincronización no vería ninguna — **sin error**.
- **`REJECTED` que llega por sincronización.** Se registra tal cual, y la corrida siguiente de `cuenta:dar-de-alta` la va a **re-crear**, porque `plantillasPorCrear()` incluye las rechazadas. Puede ser lo correcto o puede ser un bucle de rechazo. Ningún test lo fija.

---

# 🔴 16 · § 9 introdujo un defecto que cancelaba turnos reales

**Origen:** lo encontró el propio agente que implementó § 9, revisando su trabajo. **No lo agarró ningún test.**

## Qué pasaba

`CalendarioDeGoogle::eventosPropios()` pide `maxResults => 250` y **no sigue la paginación**. Si Google devuelve `nextPageToken`, registra `CONCILIACION_LISTADO_TRUNCADO` y **devuelve la lista incompleta igual**.

Para la dirección de los huérfanos eso costaba un reporte perdido. Para la dirección inversa de § 9 **cancela turnos vivos**: el barrido arma el set con lo que vino y da de baja todo turno que no esté ahí, así que **los turnos cuyo evento quedó en la página que nunca se leyó se ven idénticos a los borrados**.

Una PyME con más de 250 eventos nuestros en la ventana de 62 días queda con turnos reales dados de baja —**con la confirmación ya en el celular del cliente**— y la corrida de cada 15 minutos lo repite.

⚠️ **Es una limitación vieja que se volvió destructiva al llegarle un consumidor nuevo.** Estaba anotada y era inofensiva; § 9 la convirtió en pérdida de datos sin que nada avisara.

## Qué se construyó

**Con el listado truncado, el barrido inverso no corre para esa PyME.** Es el mismo principio que ya sostenían el test del `500` y el del turno fuera de la ventana: **no haber mirado no es haber verificado que no está.** La dirección de los huérfanos sigue corriendo con lo que se leyó — reportar de menos es barato, cancelar de más no.

- `CalendarioDeGoogle::ultimoListadoTruncado()` expone el dato, con el idioma que ya usa `Reserva::eventoHuerfano()`: sin cambiar el tipo de retorno, así que **ningún llamador existente se rompe**.
- Código de log **propio**, `CONCILIACION_INVERSA_OMITIDA`. ⚠️ No reusa `CONCILIACION_LISTADO_TRUNCADO` a propósito: aquel cuenta un hecho **sobre la lectura**, este cuenta que a esa PyME **no se le está prestando el barrido inverso**, corrida tras corrida — que es lo accionable. (Y por lo práctico: afirmar sobre el código viejo habría hecho pasar el test sin arreglar nada.)
- Y de paso, el `save()` del barrido quedó dentro de un `try/catch` **por turno**: antes un `UPDATE` fallido abortaba la corrida entera y ni siquiera se imprimía la línea final, así que nadie se enteraba de que la conciliación no había terminado. Código `CONCILIACION_CANCELACION_FALLIDA`.

## ⚠️ La deuda que esto deja, y que no se arregla sola

**El daño está contenido, no resuelto.** Una PyME con el listado truncado ya no pierde turnos, pero **su dirección inversa no se barre nunca**: el dueño que borra un turno desde su calendario sigue teniendo el turno vivo en la base, ocupando agenda y contando para el ausentismo. `CONCILIACION_INVERSA_OMITIDA` es lo que hace visible esa deuda.

**La paginación real de `eventosPropios()` pasó de mejora a requisito**, y no tiene ticket ni estimación.

⚠️ **Un camino que puede terminar mudo:** si el `save()` falla para **todos** los turnos de una PyME —base caída, no un deadlock puntual—, la corrida termina bien y el resumen dice `turnos cancelados: 0`, que es exactamente lo que dice una PyME sana. Las líneas de `CONCILIACION_CANCELACION_FALLIDA` están en el log, así que mudo del todo no es, pero **el resumen no distingue los dos casos**.

---

# ⚠️ 17 · Lo que necesita decisión de Diego antes que código

- **La cancelación por conciliación es muda hacia el cliente.** Tiene la confirmación en el celular y va a llegar a un turno que el sistema dio de baja. ¿Le avisa el bot, o se asume que el dueño ya lo llamó? Es el hueco de negocio más grande que deja § 9, y el silencio va **hacia afuera**: el cliente se entera plantado en la puerta.
- **El apartado con TTL no se libera** al cancelar por conciliación: el horario queda colgado hasta que venza.
- **`ESTADO_REAGENDADO` entra al barrido inverso** (el filtro es `!= cancelled`). Es coherente con «turno vivo», pero ningún test lo ejerce.

---

# 18 · Corrección de § 9 — el turno no se cancela, queda **en duda**

**Origen:** decisión de Diego del 2026-08-21, el mismo día en que § 9 se construyó.
**Estado antes:** la conciliación cancelaba el turno vivo cuyo evento el dueño había borrado de su Google Calendar.

## Por qué se cambió

Cancelar en silencio deja al cliente sin enterarse: **tiene la confirmación en el celular y llega a la puerta**. Y avisarle automáticamente choca con la ventana de 24 horas de Meta, que fuera de ella obliga a una plantilla aprobada — que no existe, cuesta tiempo de aprobación y se paga por envío.

## Qué se decidió

> El turno **no se cancela**: queda **en duda** y aparece en el panel. Una persona del equipo lo resuelve — lo **mantiene** o lo **cancela**. Si hay que hablar con el cliente, lo hace **desde el número de atención humana** (`business_settings.human_phone`).

⚠️ **El número humano es un WhatsApp común, no la Cloud API.** Con eso desaparecen de un plumazo la ventana de 24 horas, la plantilla y el costo por envío: una persona escribe como escribe cualquiera. Es el mismo modelo de dos números de RF-A4, usado para algo nuevo.

**El cliente no recibe nada automático.** No es un olvido: el dueño borró ese evento por un motivo que el sistema no conoce —puede haberlo llamado ya, puede haber sido un error— y automatizar un aviso sobre una decisión ambigua es cómo se le manda *«tu turno se canceló»* a alguien cuyo turno sigue en pie.

## Dónde vive «en duda»: tabla propia, no `bookings.status`

En **`reconciliation_findings`**, no como un valor nuevo del `ENUM`. Mezclar *«qué pasó con la reserva»* con *«detectamos una inconsistencia»* es exactamente el error que T-036 corrigió al sacar la asistencia de `status`.

✅ **Y con eso se cierra media decisión § 8**, que era imposible hasta hoy: mostrar los desalineados en el panel no se podía porque la conciliación **no persistía nada**, solo emitía al log.

## ⚠️ El candado NO lleva condición sobre `resolved_at`, y es contraintuitivo

`unique(tenant_id, type, booking_id)`, a secas. **Un hallazgo por turno y por tipo, para siempre, resuelto o no.**

La tentación es copiar `bookings.live_event_id` —una columna generada que suelte el candado al resolverse— y **sería exactamente lo contrario de lo que hace falta**. Allá la fila cancelada tiene que soltar el candado porque el horario se liberó y otro cliente lo tiene que poder reservar. Acá no: **«mantener» no recrea el evento en Google**, así que después de resolver el desalineado **sigue ahí**, y la corrida de dentro de 15 minutos lo vuelve a ver. Con el candado suelto se insertaría un hallazgo nuevo y **el dueño resolvería lo mismo cada quince minutos hasta dejar de mirar la pantalla**.

La diferencia es de significado, no de mecánica: **el hallazgo no se cierra porque el mundo cambió, se cierra porque una persona decidió** — y esa decisión no caduca a los quince minutos.

## ⚠️ El costo que este modelo introduce, y que el anterior no tenía

Con la cancelación automática, un desalineado de más costaba **líneas de log repetidas**. Ahora cuesta **una fila que una persona tiene que atender a mano**, y como el candado no se suelta, **se escribe una sola vez y se queda**.

En la práctica: una PyME abre la pantalla el día uno con una fila por cada turno cuyo evento no esté, y las resuelve **una por una**. Por eso el barrido inverso sigue acotado por las tres condiciones —dentro de la ventana `−2/+60`, `start_time` futuro, y no cancelado— y por eso importan más que antes.

## Lo que queda sin decidir

- ⚠️ **Los huérfanos no se persisten como hallazgo**, así que **media § 8 sigue abierta**. Y no entran en esta pantalla tal como está: un evento huérfano **no tiene turno que mantener ni cancelar**, necesitaría acciones propias.
- ⚠️ **Qué rol ve y resuelve.** Por coherencia con `asistencia` y `recordatorios-fallidos` debería ser `rol:atender` —quien está en el mostrador sabe si el turno va—, pero nadie lo decidió.
- ⚠️ **El desalineado que se «des-desalinea»:** el dueño recrea el evento con el mismo `id` después de que alguien resolvió, y lo vuelve a borrar. Con el candado como está, ese segundo borrado **no genera hallazgo nuevo**. Es el precio directo de la propiedad de arriba y parece el lado correcto del error, pero nadie lo decidió.

---

# ✅ 19 · Las tres decisiones del team-lead, ratificadas

Diego las ratificó el **2026-08-21**. Ya no queda ninguna decisión tomada por el team-lead esperando confirmación: el código dejó de marcarlas con ⚠️ y pasa a nombrarlas como acuerdo.

| Decisión | Resolución | Fundamento |
| :-- | :-- | :-- |
| **La dirección inversa solo deja en duda turnos futuros** | ✅ Ratificada | **Un turno que ya pasó y fue atendido es un registro cerrado**: la asistencia está marcada y ahí queda. Si el dueño borra después ese evento de su calendario, **lo borró y punto** — no tiene consecuencia de nuestro lado. Los dos daños que § 9 nombra tampoco aplican hacia atrás: un turno pasado no ocupa agenda |
| **`SincronizarPlantillas::MINUTOS_ENTRE_CORRIDAS = 15`** | ✅ Ratificada | En régimen la tarea **no consulta a nadie** —saltea a las PyMEs con las tres aprobadas, y `PENDING` solo existe mientras se da de alta un cliente—, así que la cadencia corta sale casi gratis. Mismo valor que la conciliación |
| **Los estados de Meta no retroceden; `failed` gana** | ✅ Ratificada, **incluido el caso discutible** | `failed` gana **aun sobre `read`**: es la única información accionable para el dueño —número equivocado, cliente que bloqueó— y si el `read` lo tapara, el turno figuraría avisado sin estarlo |

⚠️ **El fundamento de la primera lo mejoró Diego.** El del team-lead era *«un turno pasado no ocupa agenda»*; el de Diego es más fuerte y es el que quedó escrito en el código: **el registro ya está cerrado por la asistencia marcada**, así que el borrado posterior del evento es irrelevante por definición, no solo inofensivo.

---

# ✅ 20 · Los huérfanos: cerrado, no hay nada que construir

Se planteó llevar los eventos huérfanos —los que están en Google sin fila en `bookings`— a la pantalla de turnos en duda, con acciones propias. **Diego lo descartó: «no debe existir huérfanos».**

Y es coherente con lo que el sistema ya hace: **la compensación los ataca en el origen.** Cuando la fila falla después de crear el evento, `Fallback::liberarEvento()` lo borra en el acto, con tres tests cubriéndolo (`libera_el_evento_que_quedo_sin_su_fila`, `un_evento_ya_inexistente_cuenta_como_liberado`, `el_flujo_completo_libera_el_evento_cuando_el_insert_falla`).

**La conciliación solo agarra el caso residual**: aquel en que la compensación misma falló. Ese reporte al log **se conserva** —es la red de H-04 y la decisión del 2026-08-20 de *conciliar en vez de compensar*— pero **no gana pantalla**: no hay a quién avisarle y no hay turno que mantener ni cancelar.
