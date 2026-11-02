# Preguntas que salieron construyendo

**Escrito la noche del 2026-08-20**, mientras se implementaban los últimos cuatro tickets del núcleo (T-029, T-030, T-038, T-050).

Ninguna de estas frena el código: todas se resolvieron con una interpretación razonable y **está escrito cuál se eligió y por qué**. Lo que necesito es que confirmes o corrijas, porque son decisiones de producto y no técnicas.

Están ordenadas por **cuánto duele equivocarse**, no por urgencia.

🟢 **Las nueve originales están respondidas** (2026-08-21). Las respuestas, con su fundamento y lo que habilita cada una, viven en **[decisiones-tomadas.md](decisiones-tomadas.md)**. Este archivo queda como el planteo original de cada pregunta.

🔴 **Pero hay una décima abierta desde el 2026-08-25**, la número 8, que salió mandando el primer recordatorio de prueba a un celular real. No frena nada y no es de producto puro: es qué ve el operador en el panel.

---

# 🔴 Las dos que pueden hacerte tomar una decisión con un número falso

## 1 · La tasa de ausentismo, ¿sobre qué se calcula?

> ✅ **Respondida el 2026-08-21 — sobre los turnos marcados** · fundamento y consecuencias en [decisiones-tomadas.md § 1](decisiones-tomadas.md). El texto de abajo se conserva como estaba.

**Es el número con el que vendés.** El lean canvas promete bajar el ausentismo de 20-35% a menos del 8%, y ese es el argumento entero del precio.

El criterio pide *"cantidad de turnos, asistidos, ausentes y la tasa resultante"* y **no dice cuál es el denominador**. Hay dos lecturas y dan números distintos:

- **Sobre los turnos marcados** — `ausentes ÷ (asistidos + ausentes)`. Es lo que quedó implementado.
- **Sobre todos los turnos del rango**, incluidos los que nadie marcó.

**Por qué elegí la primera:** un turno sin marcar **no es una asistencia, es un dato que no tenemos**. Si entra al denominador, la tasa **baja cuando el dueño marca menos** — o sea que el número mejoraría solo por dejar de registrar. Sería el peor defecto posible en la métrica que sostiene el precio.

En el escenario de prueba las dos lecturas dan **25% contra 16,7%**. No es un matiz.

> **Lo que necesito:** un "dale" a la primera, o decime si preferís la otra.

## 2 · Si Meta todavía no aprobó las plantillas de un cliente, ¿ese turno se recuerda después?

> ✅ **Respondida el 2026-08-21 — el intento frenado no consume el candado** · fundamento y consecuencias en [decisiones-tomadas.md § 2](decisiones-tomadas.md). El texto de abajo se conserva como estaba.

Este puede dejar clientes sin recordatorio **para siempre y en silencio**, que es la peor forma de fallar.

El sistema tiene un candado para no mandar dos veces el mismo recordatorio: una vez que un turno se recordó, queda marcado y no se vuelve a intentar. **La pregunta es qué pasa cuando el recordatorio se frena porque las plantillas de esa PyME todavía están en revisión de Meta.**

- **Si el intento frenado consume el candado:** ese turno **nunca** recibe su recordatorio, aunque Meta apruebe cinco minutos después. Y nadie se entera.
- **Si no lo consume:** el turno se vuelve a intentar en la próxima corrida, y sale apenas Meta apruebe.

**Mi recomendación fuerte: que no lo consuma.** Un cliente nuevo cuyas plantillas se aprueban a la mañana perdería todos los recordatorios de ese día, justo en su primer día de uso — que es exactamente cuando está decidiendo si el producto le sirve.

> **Lo que necesito:** confirmación. Si estás de acuerdo, lo dejo así y no hace falta que contestes.

---

## 2 bis · Un cliente al que le falta *todo*, ¿debería poder mandar recordatorios?

> ✅ **Respondida el 2026-08-21 — hacerlo estricto** · fundamento y consecuencias en [decisiones-tomadas.md § 3](decisiones-tomadas.md). El texto de abajo se conserva como estaba.

Salió al terminar, y es chico pero deja **código vivo que nadie prueba**.

El sistema frena el recordatorio en dos casos: si la PyME no tiene cuenta de WhatsApp cargada, y si tiene cuenta pero sus plantillas no están aprobadas. Hay un tercer caso que hoy **pasa sin frenar**: una PyME **con cuenta pero sin ninguna plantilla registrada**.

Eso solo ocurre si el alta quedó a medio hacer. Y como ningún cliente de las pruebas está en ese estado, **esa rama del código no la ejercita ningún test**.

- **Dejarlo así** — es un caso que no debería existir.
- **Hacerlo estricto** — sin las tres plantillas aprobadas, no se manda. Un ciclo de trabajo corto y barato.

**Mi recomendación: hacerlo estricto.** Un camino que nadie prueba es un camino que nadie sabe si funciona, y este termina en un mensaje que Meta rechaza con un error que nadie sabe leer.

---

## 2 ter · El cliente canceló y vuelve a pedir el mismo horario, ¿qué hacemos?

> ✅ **Respondida el 2026-08-21 — crear un turno nuevo** · fundamento y consecuencias en [decisiones-tomadas.md § 4](decisiones-tomadas.md). El texto de abajo se conserva como estaba.

**Lo encontró una auditoría del código de esta misma noche**, y es un silencio hacia el cliente — el modo de falla que más nos costó en este proyecto.

Cuando alguien cancela un turno desde el recordatorio, borramos el evento del calendario pero **la fila queda guardada, marcada como cancelada**. Si esa misma persona vuelve a pedir **el mismo horario**, el sistema se topa con esa fila vieja y la toma por buena: le manda *"tu turno quedó reservado"* **sin que exista ni el evento en el calendario ni un turno vivo en el panel**.

El cliente se organiza el día para ir a un turno que no existe. Y no es un caso raro: cancelar y volver a sacar el mismo horario es de las cosas más normales que hace una persona.

**Lo que ya está decidido y no se discute:** un turno cancelado **no** se le puede presentar al cliente como reservado. Eso se está corrigiendo.

**Lo que necesito que decidas** es qué hacer al toparse con esa fila:

- **Reutilizarla** — se "revive" el turno cancelado. Simple, y el historial queda más pobre: se pierde que hubo una cancelación en el medio.
- **Crear un turno nuevo** — más limpio de leer después, y conserva el rastro de la cancelación.
- **Avisarle al cliente que no se pudo** y que elija otro horario. La más segura y la peor experiencia.

**Mi recomendación: crear uno nuevo.** El dato de que canceló y volvió es justamente el que vas a querer mirar en el piloto, y perderlo por comodidad es caro.

---

# 🟡 Las que cambian lo que el cliente ve

## 3 · ¿Qué le decimos al cliente que perdió el horario?

> ✅ **Respondida el 2026-08-21 — «Ese horario ya no está disponible. Estos sí:»** · fundamento y consecuencias en [decisiones-tomadas.md § 5](decisiones-tomadas.md). El texto de abajo se conserva como estaba.

Cuando dos personas eligen el mismo horario con segundos de diferencia, una lo consigue y **a la otra hay que decirle que no**. Ningún documento fija ese texto.

Lo que sí quedó garantizado es que **ese aviso no se confunda con otros dos** que hoy existen y dicen cosas distintas:

| Situación | Lo que el cliente entiende |
| :-- | :-- |
| **Se te adelantaron** | "Ese horario ya no está, pero hay otros" |
| Se rompió algo nuestro | "Hubo un problema, te contactamos" |
| No hay agenda disponible | "No tengo lugar para ese día" |

Las tres son distintas y **un cliente que recibe la equivocada saca la conclusión equivocada** — el que se topó con otro cliente más rápido no tiene que pensar que el sistema falló.

> **Lo que necesito:** el texto, si querés uno particular. Si no, escribo uno y lo cambiás cuando lo veas.

## 4 · Un turno que está ocurriendo ahora mismo, ¿se puede marcar?

> ✅ **Respondida el 2026-08-21 — sí, apenas empieza** · fundamento y consecuencias en [decisiones-tomadas.md § 6](decisiones-tomadas.md). El texto de abajo se conserva como estaba.

Un turno de las 15:00 que dura media hora, a las 15:10: **¿ya se puede marcar si el cliente vino?**

- **Sí** — el cliente ya llegó o ya no llegó; el dueño lo sabe.
- **No, hasta que termine** — más prolijo, pero el dueño tiene que acordarse después.

Los tests solo afirman los dos extremos que nadie discute: **un turno de ayer se puede marcar, uno de mañana no.** El turno en curso quedó deliberadamente sin test para no congelar una decisión que no tomaste.

> **Mi recomendación: que sí se pueda.** En una peluquería el dueño marca cuando la persona se sienta, no cuando se va.

---

# ⚪ Las de funcionamiento interno

## 5 · ¿Cada cuánto se revisa que el calendario y nuestra base coincidan?

> ✅ **Respondida el 2026-08-21 — cada 15 minutos** · fundamento y consecuencias en [decisiones-tomadas.md § 7](decisiones-tomadas.md). El texto de abajo se conserva como estaba.

Decidiste conciliar en vez de borrar: cuando un turno queda creado en Google pero no en nuestra base, **una revisión periódica lo detecta**. Lo único escrito es *"periódica"*.

Mientras más seguido corre, menos tiempo queda un horario ocupado sin turno real. **Sugiero cada 15 minutos** — es barato y acota el daño a un cuarto de hora.

## 6 · Cuando la revisión encuentra un desalineado, ¿qué hace con él?

> ✅ **Respondida el 2026-08-21 — lo ve el dueño en el panel** · fundamento y consecuencias en [decisiones-tomadas.md § 8](decisiones-tomadas.md). El texto de abajo se conserva como estaba.

Hoy **deja constancia en el registro del sistema**, que es lo que ya sabe leer el resto del proyecto. Las alternativas serían avisarte a vos, o escribirlo en una pantalla del panel.

**No borra nada**, y eso es a propósito: tu decisión fue conciliar, no compensar. Borrar quedaría para una revisión que nadie definió.

## 7 · ⚠️ La revisión mira en una sola dirección

> ✅ **Respondida el 2026-08-21 — la revisión de 15 min mira las dos direcciones** · fundamento y consecuencias en [decisiones-tomadas.md § 9](decisiones-tomadas.md). El texto de abajo se conserva como estaba.

Detecta **turnos en Google que nuestra base no conoce**. La situación inversa —**un turno nuestro que el dueño borró desde su propio Google Calendar**— es igual de desalineada, y **hoy no la mira nadie**.

Está parcialmente cubierta: antes de mandar un recordatorio se verifica que el evento siga existiendo, así que al menos no le recordamos a un cliente un turno que ya no está. Pero el turno sigue vivo en nuestra base, cuenta para las métricas y ocupa la agenda.

> **Es una pregunta abierta, no un olvido.** Decidir si la conciliación también la cubre es tuyo.

## 8 · ⚠️ El recordatorio y la respuesta del cliente quedan en conversaciones distintas

> 🔴 **Abierta. Salió el 2026-08-25**, mandando el primer recordatorio de prueba a un celular real. No la respondió nadie todavía.

Un mismo teléfono acumula **una conversación por sesión**: cada vez que pasan 30 minutos sin actividad, la anterior se cierra y el mensaje siguiente abre una nueva. En la prueba, ese número tenía seis.

El problema es que el recordatorio y la respuesta caen en dos distintas:

- **El recordatorio sale** contra la conversación donde se agendó el turno — que a las 24 horas está cerrada hace rato.
- **La respuesta del cliente entra** en la conversación viva, que es otra.

**Nada se rompe y el turno se confirma bien**: la respuesta se resuelve por el identificador del mensaje original, no por la conversación, y eso fue una decisión deliberada justamente para que aguante estos casos.

**Lo que se rompe es lo que ve el operador en el panel.** Abre una conversación y ve un recordatorio que nadie contestó; abre la otra y ve un *Confirmar* y una respuesta del bot que salieron de la nada. **La conversación completa no existe en ninguna pantalla.**

Tres salidas posibles, de menor a mayor:

1. **Dejarlo así** y que el panel muestre las conversaciones de un mismo teléfono juntas. Es cambio de pantalla, no de datos.
2. **Que el recordatorio salga contra la conversación viva** en vez de la del turno. Barato, pero mueve el problema: entonces el turno queda desconectado de donde se agendó.
3. **Que un teléfono tenga una sola conversación** y las sesiones sean un corte adentro. Es el modelo correcto y el más caro; toca todo lo que hoy consulta por conversación.

> **Sugiero la 1** para el piloto: no toca el flujo, y hasta que un operador use el panel de verdad no sabemos si molesta lo suficiente como para pagar la 3.

---

# Dos criterios que quedaron declarados sin cubrir

**Y es a propósito.** Los dos dependen de funcionalidad que el recorte dejó afuera, y escribir un test sobre un flujo que no existe es peor que dejar el criterio marcado.

| Criterio | Por qué no se puede probar |
| :-- | :-- |
| **AC-18.4** · El control de colisión aplica al re-agendamiento | **No hay re-agendamiento.** Es T-042, diferido |
| **AC-22.4** · Un fallo de Google Sheets no impide el turno | **No hay integración con Sheets.** Son T-044 y T-045, diferidos |

Y uno que se cumple **a medias por la misma razón**:

- **AC-17.4** pide distinguir cuatro respuestas al recordatorio: confirmó, canceló, re-agendó, no respondió. **Se distinguen tres.** "Re-agendó" no existe todavía.

---

# Lo que no cambió y conviene que sepas

- **Dos tests nacieron en verde y está bien.** Uno verifica que la reserva temporal de una PyME no bloquee el horario de otra; el otro, que un doble toque del cliente no cree dos turnos. Los dos ya estaban cubiertos por mecanismos que existían. **Quedan escritos igual**, como guardia: si alguien rompe eso mañana, se enteran los tests y no un cliente.
- **La exclusión la da la infraestructura, no el código.** El candado contra recordatorios duplicados es una restricción de la base de datos; la reserva del horario es una operación atómica de Redis. Comprobar primero y escribir después deja abierta la misma ventana que estos tickets vienen a cerrar.
