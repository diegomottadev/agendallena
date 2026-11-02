# Decisiones que te esperan

**Actualizado:** 2026-08-20

Cada punto de acá **frena un ticket**. No necesito que lo pienses mucho: en cada uno te doy mi recomendación y con un "dale" alcanza. Lo que necesito es tu respuesta, porque son decisiones de negocio y no técnicas.

Están ordenadas por **cuánto destraban**, no por urgencia.

🟢 **Las siete están cerradas** (las 1, 2, 3 y 5 el 2026-08-20; las 4, 6 y 7 el 2026-08-21). Las respuestas de esta última ronda están en **[decisiones-tomadas.md](decisiones-tomadas.md)**.

---

# ✅ Cerradas el 2026-08-20

## 1 · ¿Cuándo se aparta el horario? — **al elegirlo, y uno solo por cliente**

**Destraba T-029** (5 pts).

- El horario se aparta **cuando el cliente toca uno**, no cuando se le muestra la lista.
- ⚠️ **Un cliente aparta un solo horario a la vez.** Si elige otro, el anterior se libera. Esto no estaba en ninguna de las dos opciones originales y es lo que hace viable la decisión: mata el problema de bloquear media agenda con alguien que solo curiosea, sin perder la protección contra el choque.
- **TTL: 5 minutos.** Diego dio un rango de 5 a 10; se toma el extremo corto porque en WhatsApp el cliente está conversando activamente y decide en segundos, y porque un TTL vencido es recuperable —el bot ofrece elegir de nuevo— mientras que un horario bloqueado de más no se ve. Queda como constante con nombre: moverlo a 10 es una línea.

## 2 · Turno en Google que no se guardó acá — **dejarlo y conciliar después**

**Destraba T-030** (5 pts).

No se borra el evento en el acto: queda y lo limpia una revisión periódica. Que un horario esté ocupado unos minutos de más es molesto; que a alguien le desaparezca un turno del calendario delante de los ojos rompe la confianza.

El caso fácil ya está implementado —cuando el proceso sigue vivo, el evento huérfano se borra solo—; esta decisión cubre cuando el proceso muere en el medio.

---

## 3 · ¿Una cuenta de WhatsApp para todos, o una por PyME? — **una por PyME**

**Decidido el 2026-08-20.** Cada PyME tiene su propia cuenta de WhatsApp (WABA) y su propio número.

**Lo que implica:** las plantillas se aprueban por cuenta, así que **cada cliente necesita las suyas**. Las tres aprobadas el 2026-08-19 valen para la cuenta de prueba y para ninguna otra.

**Lo que NO implica:** que el alta sea manual. Las plantillas se crean por API en el alta. Lo que no controlamos es cuánto tarda Meta en aprobarlas — y eso es lo único que puede romper el objetivo de *"alta en menos de una hora"*.

**Salió un ticket nuevo:** [T-050](../tickets/Finish/050-alta-de-cuenta-de-whatsapp-por-pyme.md), 5 pts. No frena el piloto; frena el **segundo cliente**.

⚠️ El `waba_id` es hoy una variable de entorno global — la misma forma del bug de aislamiento que se arregló el 2026-08-20 con el número emisor. Todavía no se escribió código que lo use, así que se evita en vez de arreglarse.

---

# 🟡 Las que frenan cosas que ya están fuera del MVP

Estas no bloquean nada hoy, pero conviene tenerlas resueltas antes de retomar cada tema.

## 4 · ¿Vale la pena el cotizador?

> ✅ **Respondida el 2026-08-21 — posponer el discovery hasta después del piloto** · fundamento y consecuencias en [decisiones-tomadas.md § 10](decisiones-tomadas.md). El texto de abajo se conserva como estaba.

**Frena:** T-006, T-016, T-040, T-041 (14 puntos en total).

El cotizador calcula un precio antes de ofrecer turno — por metro cuadrado, por ambiente. **Encaja bien con inmobiliarias y mantenimiento. No encaja con salud, estética ni distribuidoras**, que cobran por lista de precios fija.

Antes de construirlo hay **un día de trabajo sin código** (T-006) que responde si tu forma de cotizar cubre los cuatro rubros del plan o solo dos.

**Mi recomendación: hacer ese día de trabajo igual, aunque el cotizador siga postergado.** Es conocimiento que no caduca y convierte una apuesta en una decisión informada. Si la respuesta es "solo dos rubros", conviene saberlo **antes de vender**, no después.

> ¿Querés que prepare el material de ese discovery — qué preguntar y a quién?

## 5 · ~~¿El bot tiene que darse cuenta solo de que vos contestaste?~~ — RESUELTA

> **Ya no hay decisión que tomar: la plataforma no lo permite.** Verificaste que
> un número de la Cloud API **deja de poder usarse como chat humano**. No hay
> respuesta manual que detectar.
>
> **Se resolvió con dos números:** el bot en uno, las personas en otro. Al
> derivar, el bot dice a dónde escribir.
>
> Eso cierra **T-004** (3 pts, sin ejecutarlo) y **T-032** (8 pts). Once puntos
> de backlog que dejaron de tener sentido, descubiertos antes de gastarlos.
>
> ⚠️ **Lo que sí hay que medir en el piloto:** cuántos clientes derivados
> efectivamente escriben al segundo número. Los que no lo hagan son ventas
> perdidas que hoy no se ven. Si el número duele, la alternativa es contestar
> desde el panel vía la API — la mitad ya está construida.

### El texto original, para contexto

**Frena:** T-032 (8 puntos, ya recortado del MVP).

La idea era: si el dueño contesta a mano desde su celular, el bot se calla solo. **Pero nadie verificó si WhatsApp nos avisa de esos mensajes**, y todo el diseño depende de eso.

Ya está resuelto de otra forma: **hay un botón de pausa** en el panel. Funciona, pero hay que apretarlo.

**Mi recomendación: dejarlo así por ahora.** Si en el piloto ves que los dueños se olvidan de pausar y el bot les habla encima al cliente, ahí sí vale la pena investigarlo.

## 6 · ¿Dos negocios pueden usar la misma planilla de Google?

> ✅ **Respondida el 2026-08-21 — prohibirlo** · fundamento y consecuencias en [decisiones-tomadas.md § 11](decisiones-tomadas.md). El texto de abajo se conserva como estaba.

**Frena:** T-044 (3 puntos, diferido).

Hoy nada lo impide, y no debería poder pasar: los clientes de una PyME terminarían mezclados con los de otra en la misma planilla.

**Mi recomendación: prohibirlo.** Es una validación de dos líneas y evita un problema de privacidad entre clientes.

---

# ⚪ Una que es de organización, no de producto

## 7 · ¿Cómo se ordena el trabajo que queda?

> ✅ **Respondida el 2026-08-21 — no tocarlo por ahora** · fundamento y consecuencias en [decisiones-tomadas.md § 12](decisiones-tomadas.md). El texto de abajo se conserva como estaba.

Hay una dificultad estructural en el plan: **los tickets están encadenados**, cada uno depende del anterior. Con una sola persona programando no se nota. Con dos, una espera.

La alternativa sería reorganizar el trabajo en "rebanadas" que se puedan hacer en paralelo. **Es la única decisión que puede cambiar eso**, y hasta ahora no se tomó.

**Mi recomendación: no tocarlo por ahora.** El flujo principal ya está terminado, así que la cadena más larga ya se recorrió. Se puede replantear si sumás una segunda persona.

---

# Resumen: lo que necesito que me contestes

| # | Pregunta | Mi recomendación |
| :-: | :-- | :-- |
| **1** | ¿Cuándo se aparta el horario? ¿Y por cuánto tiempo? | Al elegirlo · 5 minutos |
| **2** | Turno en Google sin guardar acá: ¿borrar o limpiar después? | Limpiar después |
| **3** | ¿Una cuenta de WhatsApp para todos o una por cliente? | **La tenés que decidir vos** |
| 4 | ¿Hacemos el día de investigación del cotizador? | Sí, aunque el cotizador siga postergado |
| ~~5~~ | ~~¿Investigamos la pausa automática?~~ | ✅ **resuelta** — la plataforma no lo permite. Cierra T-004 y T-032 |
| 6 | ¿Se prohíbe compartir planilla entre negocios? | Sí |
| 7 | ¿Reorganizamos el trabajo en paralelo? | No por ahora |

**Con las tres primeras me alcanza para seguir varias semanas.** Las otras podés contestarlas cuando quieras.

---

# Lo que el piloto tiene que medir

No son decisiones: son números que hoy no existen y que van a decidir por vos.

- **Cuántos clientes derivados escriben al segundo número.** Los que no lo hagan son ventas perdidas que hoy nadie ve. Si duele, se contesta desde el panel — la mitad ya está construida.
- **Cuánto tardás en dar de alta un cliente completo.** Si son tres horas, el objetivo de "menos de una hora" es fantasía y hay que construir las pantallas de configuración en serio.
- **Si el aviso de t-2h mueve la asistencia.** T-043 está diferido; probalo a mano una semana antes de que alguien escriba código.
