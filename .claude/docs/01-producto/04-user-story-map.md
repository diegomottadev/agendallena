# User Story Map — MVP Release 1.0 vs. v2

**Generado con:** [`prompts/generar-user-story-map.md`](../../prompts/generar-user-story-map.md)
**Backlog referenciado:** [`03-user-stories-mvp.md`](03-user-stories-mvp.md) — US-01 a US-14, no se generaron historias nuevas.
**Leyenda:** `US-NN` historia existente · `parcial` la cubre a medias · **`HUECO`** ningún ítem del backlog lo cubre.

> **Resultado del cruce:** las 14 historias cubren bien el recorrido del cliente final y mal el de la PyME. Aparecieron **6 huecos**, uno de los cuales es una actividad entera del backbone.

---

## Backbone — Perspectiva Cliente Final (WhatsApp)

```
A1 Iniciar     A2 Describir   A3 Recibir     A4 Elegir      A5 Confirmar   A6 Recibir      A7 Responder   A8 Asistir
   contacto  →    necesidad  →   cotización →   horario    →   reserva    →   recordatorio →   al aviso   →   al turno
```

### A1 · Iniciar contacto

| Paso | Historia | Slice |
| :-- | :-- | :-- |
| A1.1 Escribir por primera vez al número del negocio | US-01, US-02 | MVP |
| A1.2 Recibir la primera respuesta del bot | [US-16](stories/US-16-primer-contacto-bienvenida.md) ✅ *hueco cerrado* | MVP |

> Era un hueco: ninguna de las 14 originales definía qué contesta el bot ante un primer contacto. US-03 declara el estado `IDLE` pero no especifica el saludo ni el menú. **Cubierto por US-16.**

### A2 · Describir la necesidad

| Paso | Historia | Slice |
| :-- | :-- | :-- |
| A2.1 Responder las preguntas de parámetros | US-03, US-12 (AC-12.2) | MVP |
| A2.2 Retomar la conversación tras abandonarla | US-03 (AC-03.1) | MVP |
| A2.3 Corregir un dato ya ingresado | **HUECO — "me equivoqué, son 90 no 80"** | v2 |

> US-03 (AC-03.4) maneja una entrada *inválida*, pero no la corrección de un dato **válido ya aceptado**. En el MVP se resuelve reiniciando la conversación; es molesto, no bloqueante.

### A3 · Recibir la cotización

| Paso | Historia | Slice |
| :-- | :-- | :-- |
| A3.1 Ver el precio estimado en el chat | US-12 (AC-12.1) | MVP |
| A3.2 Ser derivado a un asesor si está fuera de rango | US-12 (AC-12.4) | MVP |
| A3.3 Pagar una seña para reservar | — | **v2** |

### A4 · Elegir horario

| Paso | Historia | Slice |
| :-- | :-- | :-- |
| A4.1 Ver los horarios realmente disponibles | US-05 (AC-05.1/2/3) | MVP |
| A4.2 Navegar cuando hay más horarios que el límite de Meta | US-05 (AC-05.4) | MVP |
| A4.3 Ver la hora en la zona horaria correcta | US-07 | MVP |
| A4.4 Elegir sucursal o profesional | — | **v2** |

### A5 · Confirmar la reserva

| Paso | Historia | Slice |
| :-- | :-- | :-- |
| A5.1 Confirmar el horario elegido | US-06 (AC-06.1) | MVP |
| A5.2 Recibir el comprobante por WhatsApp | US-06 (AC-06.1) | MVP |
| A5.3 Quedar registrado como lead en la planilla | US-13 | MVP |

### A6 · Recibir el recordatorio

| Paso | Historia | Slice |
| :-- | :-- | :-- |
| A6.1 Recibir el aviso de 24 horas antes | US-10 | MVP |
| A6.2 Recibir el segundo aviso de 2 horas antes | US-11 (AC-11.4) ⚠️ *sin RF* | MVP |

### A7 · Responder al recordatorio

| Paso | Historia | Slice |
| :-- | :-- | :-- |
| A7.1 Confirmar la asistencia | US-10 (AC-10.2) | MVP |
| A7.2 Cancelar y liberar el horario | US-10 (AC-10.3) | MVP |
| A7.3 Re-agendar sin repetir datos | US-11 (AC-11.1/2/3) | MVP |

### A8 · Asistir al turno

| Paso | Historia | Slice |
| :-- | :-- | :-- |
| A8.1 Presentarse en el local | *(fuera del sistema)* | — |
| A8.2 Que quede registrado si asistió o no | [US-17](stories/US-17-registro-de-asistencia.md) ✅ *hueco cerrado* | **MVP** |

> 🔴 **Era el hueco más grave del mapa.** El lean canvas promete bajar el ausentismo a menos del 8% y US-10 se compromete a una asistencia ≥ 92%, pero nada en el backlog registraba si la persona efectivamente fue: el KPI que sostiene el modelo de precios no se podía medir. **Cubierto por US-17**, que además correlaciona la asistencia con la respuesta al recordatorio — sin eso se observa la mejora pero no se le puede atribuir causa.

---

## Backbone — Perspectiva PyME / Admin (Dashboard & Calendario)

```
B1 Dar de     B2 Conectar     B3 Configurar   B4 Atender      B5 Supervisar   B6 Resolver
   alta      →   integraciones →   el negocio  →   día a día   →   la operación →   incidentes
```

### B1 · Dar de alta la cuenta

| Paso | Historia | Slice |
| :-- | :-- | :-- |
| B1.1 Crear el tenant y el usuario administrador | **HUECO — alta de tenant** | v2 |
| B1.2 Registrar el número de WhatsApp en Meta | US-01 | MVP |
| B1.3 Iniciar sesión en el panel | US-14 · `parcial` | MVP |

> B1.1 va a v2 porque en los pilotos damos de alta los tenants a mano. Deja de ser sostenible al cuarto cliente. B1.3 es `parcial`: la autenticación está enterrada dentro de US-14 en vez de ser historia propia.

### B2 · Conectar las integraciones

| Paso | Historia | Slice |
| :-- | :-- | :-- |
| B2.1 Conectar Google Calendar por OAuth | US-04 | MVP |
| B2.2 Conectar Google Sheets | US-13 · `parcial` | MVP |
| B2.3 Ver el estado de la conexión con Meta | US-04 (AC-04.1) · `parcial` | MVP |
| B2.4 Conectar Outlook / Microsoft 365 | — ⚠️ *contradicción abierta* | **v2** |

> B2.2 es `parcial`: US-13 menciona el alcance de OAuth como dependencia pero no construye la pantalla. B2.4 refleja la contradicción sin resolver — el lean canvas y el C4 L2 prometen Outlook; el PRD y el C4 L3 no lo contemplan. **El mapa lo ubica en v2, que es la lectura del PRD.**

### B3 · Configurar las reglas del negocio

| Paso | Historia | Slice |
| :-- | :-- | :-- |
| B3.1 Definir horario de atención y días laborables | [US-15](stories/US-15-configurar-reglas-del-negocio.md) (AC-15.1) ✅ | MVP |
| B3.2 Definir duración de turno y buffer | [US-15](stories/US-15-configurar-reglas-del-negocio.md) (AC-15.2) ✅ | MVP |
| B3.3 Definir la zona horaria | [US-15](stories/US-15-configurar-reglas-del-negocio.md) (AC-15.3) ✅ | MVP |
| B3.4 Cargar las fórmulas de precio | [US-15](stories/US-15-configurar-reglas-del-negocio.md) (AC-15.4) ✅ | MVP |
| B3.5 Editar los mensajes (bienvenida, fallback) | [US-15](stories/US-15-configurar-reglas-del-negocio.md) (AC-15.5) ✅ | MVP |
| B3.6 Editor visual de flujos conversacionales | — | **v2** |

> 🔴 **Era una actividad entera del backbone sin una sola historia.** El PRD tiene 13 requisitos funcionales y el Módulo E solo cubre dos cosas: conectar OAuth (RF-E1) y ver logs (RF-E2). Pero RF-B2 dice *"respetando horarios de atención"* y *"buffers de 10 minutos"*, y RNF-02 dice *"la zona horaria local configurada en el tenant"* — **ambos asumen una configuración que ningún requisito manda construir.** US-05, US-07, US-09 y US-12 la listaban como dependencia; ninguna la implementaba.
>
> **Cubierto por [US-15](stories/US-15-configurar-reglas-del-negocio.md).** Queda pendiente escribir el RF correspondiente en el PRD: era el tercer requisito huérfano del proyecto, después de Google Sheets y del aviso t-2h.

### B4 · Atender en el día a día

| Paso | Historia | Slice |
| :-- | :-- | :-- |
| B4.1 Responder a mano cuando quiero | US-08 | MVP |
| B4.2 Que el bot se calle mientras atiendo | US-08 (AC-08.1) | MVP |
| B4.3 Que el bot retome solo pasado el tiempo | US-08 (AC-08.2) | MVP |
| B4.4 Asignar conversaciones a distintos empleados | — | **v2** |

### B5 · Supervisar la operación

| Paso | Historia | Slice |
| :-- | :-- | :-- |
| B5.1 Ver la lista de chats con su estado | US-14 (AC-14.1) | MVP |
| B5.2 Ver el detalle de una conversación | US-14 (AC-14.3/4) | MVP |
| B5.3 Ver los leads en mi planilla de Google | US-13 | MVP |
| B5.4 Ver métricas de ausentismo y conversión | — | **v2** |

> B5.4 va a v2 como **panel**, pero el dato crudo que alimenta esa métrica es el hueco A8.2 y ese sí es MVP. Medir a mano sobre una planilla es aceptable con tres pilotos; no tener el dato, no.

### B6 · Resolver incidentes

| Paso | Historia | Slice |
| :-- | :-- | :-- |
| B6.1 Enterarme de que una integración falló | US-09 (AC-09.2) · `parcial` | MVP |
| B6.2 Reconectar la cuenta caída | **HUECO — flujo de reconexión** | v2 |
| B6.3 Que el cliente final no quede sin respuesta | US-09 | MVP |

> B6.2 va a v2 porque durante los pilotos reconectamos nosotros. B6.1 es `parcial`: US-09 marca el problema en el panel pero nadie construyó la vista que lo muestra.

---

## Resumen de huecos

| # | Hueco | Actividad | Slice | Estado |
| :-- | :-- | :-- | :-- | :-- |
| 1 | **Configuración del negocio** (5 pasos) | B3 | **MVP** | ✅ Cubierto por [US-15](stories/US-15-configurar-reglas-del-negocio.md) |
| 2 | **Registro de asistencia / no-show** | A8.2 | **MVP** | ✅ Cubierto por [US-17](stories/US-17-registro-de-asistencia.md) |
| 3 | Mensaje de bienvenida y menú inicial | A1.2 | **MVP** | ✅ Cubierto por [US-16](stories/US-16-primer-contacto-bienvenida.md) |
| 4 | Alta de tenant y usuario | B1.1 | v2 | Diferido — alta manual en pilotos |
| 5 | Flujo de reconexión de integraciones | B6.2 | v2 | Diferido — reconexión manual por soporte |
| 6 | Corregir un dato ya ingresado | A2.3 | v2 | Diferido — el cliente reinicia la conversación |

**Los tres huecos de MVP quedaron cubiertos.** El backlog pasó de 14 a **17 historias**. Los tres diferidos son deuda operativa consciente: se suplen con trabajo humano durante los pilotos y vencen alrededor del cuarto cliente.

> **Pendiente en el PRD:** US-15, US-16 y US-17 nacieron de huecos del mapa, así que **ninguna tiene requisito funcional**. Sumadas a Google Sheets y al aviso t-2h, son cinco funcionalidades del MVP sin RF. Conviene una pasada de escritura sobre el PRD antes de estimar el sprint 2.

---

## Justificación del recorte

### Por qué estas historias quedaron fuera del MVP

**Las cinco funcionalidades que el PRD ya declara fuera de alcance** —cobro de señas, editor visual de flujos, multi-sucursal, CRM propio, integraciones adicionales— comparten un rasgo: **ninguna valida un supuesto de riesgo, todas amplían un valor que todavía no probamos que exista.** Construir el cobro de señas antes de saber si el ausentismo baja es optimizar un mecanismo cuyo problema no confirmamos.

El caso de **Outlook** es distinto y merece decisión explícita, no omisión. No es una funcionalidad de escala: es un segundo proveedor de calendario que duplica OAuth, disponibilidad y sincronización inversa. El mapa lo ubica en v2 siguiendo al PRD, pero eso **contradice el lean canvas y el C4 nivel 2**, que lo prometen. Mientras la contradicción siga abierta, el equipo comercial puede vender algo que el MVP no hace.

**Los tres huecos diferidos a v2** se difieren por la misma lógica: los suplimos con trabajo humano durante los pilotos. Damos de alta tenants a mano, reconectamos cuentas a mano, y un cliente que se equivocó reinicia la conversación. Con tres pilotos eso cuesta horas nuestras; al cuarto cliente el margen de la tarifa plana no lo aguanta. **Son deuda operativa consciente con fecha de vencimiento conocida, no olvidos.**

### Por qué los otros tres huecos no se pueden diferir

**Configuración del negocio (B3)** no es diferible porque no es una funcionalidad sino un insumo: sin horarios, buffer, zona horaria y fórmulas cargadas, cuatro historias del MVP no tienen con qué funcionar. Lo que sí es negociable es *quién* los carga. En el MVP puede ser una configuración que cargamos nosotros por base de datos, sin pantallas. Pero conviene admitir el costo: **si la configuración inicial la hacemos nosotros, el KPI de "setup en menos de 60 minutos" lo estamos cumpliendo nosotros, no el cliente.** El número seguiría siendo cierto y a la vez engañoso.

**El registro de asistencia (A8.2)** no es diferible por una razón más dura: es el único dato que convierte el MVP en un experimento. Sin él construimos los recordatorios, los enviamos, medimos cuántos botones se tocaron — y seguimos sin saber si la gente fue. La hipótesis central del negocio quedaría sin instrumento de medición. Un botón "asistió / no asistió" en el panel es un día de trabajo y es la diferencia entre un piloto y una demo.

**El mensaje de bienvenida (A1.2)** es indiferible por evidencia: es el primer mensaje que ve todo cliente final. No hay recorrido sin él.

### Cómo este corte valida los supuestos de mayor riesgo primero

El corte responde al orden en que las cosas pueden matar el proyecto:

1. **Riesgo de habilitación** (US-01, US-02): si Meta no nos deja operar, nada más importa. Se prueba en la semana 1 y es lo más barato del plan.
2. **Riesgo de factibilidad** (US-03 a US-07): ¿podemos agendar de punta a punta en el tiempo prometido, con la hora correcta? Es la propuesta de valor entera.
3. **Riesgo de adopción** (US-08): el producto puede funcionar perfecto y aun así ser expulsado si el bot pisa al dueño delante de su cliente. Es la objeción número uno de venta y hoy tiene un mecanismo de detección sin probar.
4. **Riesgo de valor** (US-10, US-11 + el hueco A8.2): ¿el ausentismo baja de verdad? Es el número que sostiene el precio, tiene el ciclo de aprendizaje más lento y **hoy no tiene con qué medirse.**
5. **Riesgo de encaje** (US-12): ¿el cotizador paramétrico le sirve a los cuatro segmentos o solo a dos?

Todo lo que quedó en v2 pertenece a una categoría distinta: **riesgo de escala**, que solo importa si los cinco anteriores se resuelven a favor. Cobrar señas, escalar a multi-sucursal o construir un CRM son problemas que uno *quiere* tener. El MVP está diseñado para averiguar si vamos a tenerlos.
