# Slices, secuencia y justificación del recorte

**Qué es este documento:** el eje vertical del mapa. Los tres mapas anteriores dicen *qué* pasos existen y quién los cubre; este dice **qué entra en el MVP, en qué orden se construye y por qué**.

**Regla del corte:** un ítem entra al Slice 1 solo si sin él **no se puede validar un supuesto de riesgo**. Que sea útil no alcanza. Que sea rápido, tampoco.

---

## Slice 0 — Habilitación (no es código, bloquea código)

Ítems que no son historias y que bloquean historias. Corren en paralelo desde el día 1.

| Ítem | Tipo | Bloquea | Por qué va primero |
| :-- | :-- | :-- | :-- |
| Verificación de Meta Business + número | Trámite | US-01 | Demora externa impredecible, de días a semanas |
| **Aprobación de plantillas de mensaje** | Trámite | US-10 | El cuello de botella real del MVP |
| Pantalla de consentimiento de Google | Trámite | US-04 | Revisión de Google con demora propia |
| **Spike:** ¿Meta entrega los mensajes salientes manuales? | Spike, 2 días | US-08 | Sin esto US-08 no es estimable |
| **Spike:** latencia real de `freeBusy` | Spike, 1 día | US-05 | Si no entra en el RNF-04, hay que cachear y cambia el diseño |
| **Descubrimiento:** listas de precios reales de 4 rubros | Discovery, 1 día | US-12 | Un día de trabajo responde la pregunta más cara del backlog |
| **Decisión:** cerrar persistencia del estado | Escritura, 30 min | US-03 | Ya está respondida ([C-06](05-huecos-y-trazabilidad.md#c-06)); falta declararla |
| **Decisión:** cerrar estrategia multi-tenant | Escritura, 30 min | US-14 | Ya está respondida ([C-06](05-huecos-y-trazabilidad.md#c-06)); falta declararla |
| **Decisión:** Outlook dentro o fuera, por escrito en el PRD | Decisión | US-04, US-05, US-06 | Única de las tres que sigue abierta ([C-01](05-huecos-y-trazabilidad.md#c-01)) |
| **Decisión:** dónde se pide el nombre | Decisión, 15 min | US-03, US-06, US-13 | [H-02](05-huecos-y-trazabilidad.md#h-02): tres documentos, dos respuestas |
| **Decisión:** cuánto dura una sesión inactiva | Decisión, 15 min | US-03, US-11 | [H-07](05-huecos-y-trazabilidad.md#h-07): tres números para la misma idea |
| **Escritura:** 5 RF faltantes (t-2h, Sheets, config, bienvenida, asistencia) | Escritura | US-11, US-13, US-15, US-16, US-17 | Cinco historias del MVP sin requisito |

> **Cinco de estos doce ítems son media hora de escritura cada uno** y destraban cuatro historias. Las decisiones de [C-06](05-huecos-y-trazabilidad.md#c-06) en particular no requieren investigación: la respuesta ya está escrita en `03-flujos/` y en `02-arquitectura/`, y el backlog la sigue listando como abierta porque nunca se cruzaron los documentos.

---

## Slice 1 — MVP (Release 1.0)

### Lo que estaba y sigue estando

Las 17 historias de [`03-user-stories-mvp.md`](../01-producto/03-user-stories-mvp.md), con dos ajustes de alcance que los mapas justifican:

* **[US-07](../stories/US-07-hora-correcta-timezones.md) no se ejecuta como historia.** Sus cuatro criterios se incorporan a US-05, US-06, US-10 y US-11. Se mantiene la recomendación previa; los mapas la confirman: aparece como *transversal* en A4.3, A6.3 y en la matriz.
* **[US-14](../stories/US-14-panel-logs-y-estado.md) se descopea a lista de solo lectura.** No por criterio de producto sino porque [H-10](05-huecos-y-trazabilidad.md#h-10) lo impone: sin tabla `messages`, AC-14.4 no se puede construir. La autenticación sale como ítem propio.

### Lo que entra nuevo

| Hueco | Por qué es MVP y no v2 | Est. |
| :-- | :-- | :-: |
| [H-09](05-huecos-y-trazabilidad.md#h-09) 🔴 Tabla de configuración | Cinco historias del MVP leen datos que no tienen dónde vivir | S |
| [H-11](05-huecos-y-trazabilidad.md#h-11) 🔴 Enum de asistencia | Sin esto, el KPI que sostiene el precio no se puede escribir | S |
| [H-01](05-huecos-y-trazabilidad.md#h-01) 🔴 Colisión de horario | US-05 promete "no reservar uno ya ocupado" y hoy no lo garantiza | M |
| [H-15](05-huecos-y-trazabilidad.md#h-15) 🔴 Índices compuestos | El RNF-04 se sostiene sobre índices que el DDL no tiene | S |
| [H-10](05-huecos-y-trazabilidad.md#h-10) 🔴 Tabla de mensajes | Bloquea AC-14.4; su ausencia es lo que fuerza el descope de US-14 | M |
| [H-06](05-huecos-y-trazabilidad.md#h-06) Pausa manual del bot | Plan B de US-08, el riesgo más grave del backlog | S |
| [H-02](05-huecos-y-trazabilidad.md#h-02) Captura del nombre | Decide si US-13 escribe filas incompletas | S |
| [H-04](05-huecos-y-trazabilidad.md#h-04) Compensación del agendamiento | Un evento huérfano bloquea un horario y no se puede cancelar | M |
| [H-03](05-huecos-y-trazabilidad.md#h-03) Agenda llena | Camino sin definir en el escenario más favorable del negocio | S |
| [H-05](05-huecos-y-trazabilidad.md#h-05) Derivación al asesor | Tres caminos prometen un humano que nadie convoca | S |
| [H-08](05-huecos-y-trazabilidad.md#h-08) Recordatorio fallido | El ROI depende de que ese mensaje llegue | S |
| [H-16](05-huecos-y-trazabilidad.md#h-16) Pregunta libre | Camino de abandono más probable, hoy sin medir | S |
| [H-17](05-huecos-y-trazabilidad.md#h-17) Renovación de canales *watch* | La sincronización inversa muere en silencio sin esto | S |
| [H-18](05-huecos-y-trazabilidad.md#h-18) Fórmulas seguras | Con multi-tenancy por columna, un `eval()` expone a todos | S |
| [H-19](05-huecos-y-trazabilidad.md#h-19) Logging estructurado | Tres historias lo asumen; conviene una sola definición | S |
| [H-20](05-huecos-y-trazabilidad.md#h-20) Vincular la planilla | Sin esto, "tu planilla" es una planilla nuestra | S |
| [H-21](05-huecos-y-trazabilidad.md#h-21) Estado de integraciones | US-09 escribe el dato y nadie construye dónde leerlo | S |

**Trece S, cuatro M, cero L.** Los huecos son chicos porque son *ramas* y *migraciones*, no funcionalidades. Es exactamente lo contrario del backlog original, donde 8 de 17 son L. La lectura no es "hay más trabajo": es que **el trabajo que faltaba era el barato, y por eso nadie lo vio**.

### Lo que sale del MVP

| Ítem | Adónde va | Por qué |
| :-- | :-- | :-- |
| [US-11](../stories/US-11-reagendar-y-aviso-t2h.md) AC-11.4 (aviso t-2h) | **MVP condicionado** | Probarlo a mano en un piloto una semana antes de escribir código. Si no mueve la aguja, se cae |
| [H-12](05-huecos-y-trazabilidad.md#h-12) Alta de credenciales Meta | v2 | En pilotos las cargamos nosotros, igual que el alta de tenant |
| [H-13](05-huecos-y-trazabilidad.md#h-13) Roles y permisos | v2 | Un usuario por tenant durante los pilotos |
| [H-14](05-huecos-y-trazabilidad.md#h-14) `users.email` por tenant | v2, **decidir ya** | Barato ahora, caro con datos |

---

## Slice 2 — v2 (Post-MVP)

| Ítem | Origen |
| :-- | :-- |
| Cobro de señas (Mercado Pago / Stripe) | OUT-OF-SCOPE del PRD · A3.4 |
| Editor visual de flujos conversacionales | OUT-OF-SCOPE del PRD · B3.7 |
| Multi-sucursal y elección de profesional | OUT-OF-SCOPE del PRD · A4.5 |
| CRM propio o integración con CRMs pesados | OUT-OF-SCOPE del PRD |
| Outlook / Microsoft 365 | [C-01](05-huecos-y-trazabilidad.md#c-01) · B2.6 · C4.7 |
| Alta autoservicio de tenant y usuario | B1.1 |
| Roles y permisos diferenciados | [H-13](05-huecos-y-trazabilidad.md#h-13) · B1.4 |
| Alta de credenciales de Meta por panel | [H-12](05-huecos-y-trazabilidad.md#h-12) · B1.2 |
| Flujo de reconexión de integraciones | B6.4 |
| Panel de métricas de conversión y ausentismo | B5.8 · UC-2.6 |
| Asignar conversaciones a empleados | B4.8 |
| Corregir un dato ya ingresado en el chat | A2.6 |
| Vista de detalle rica de conversación | Descope de [US-14](../stories/US-14-panel-logs-y-estado.md) |

---

## Secuencia de ejecución

```
Slice 0 (paralelo, desde el día 1)
   Trámites Meta + Google · 2 spikes · 1 discovery · 5 decisiones · 5 RF faltantes

Sprint 1   US-01 → US-02 → US-04 → H-15
Sprint 2   H-09 → US-15 → US-03 → H-19
Sprint 3   US-16 → US-05 → H-03 → H-06
Sprint 4   US-06 → H-01 → H-04 → H-17          (+ US-07 embebida)
Sprint 5   US-08 → US-09 → H-21 → H-05 → H-16
Sprint 6   US-10 → H-11 → US-17 → H-08
Sprint 7   US-12 → H-18 → US-11a → H-02
Sprint 8   US-13 → H-20 → US-14 (lista) → H-10
```

### Qué cambió respecto de la priorización vigente y por qué

| Movimiento | De → a | Por qué se movió |
| :-- | :-- | :-- |
| **[H-15](05-huecos-y-trazabilidad.md#h-15) sube al sprint 1** | nuevo | Los índices se agregan junto con las tablas, no después. Retrofitear un índice sobre datos es una migración con downtime; ponerlo el día 1 es una línea |
| **[H-09](05-huecos-y-trazabilidad.md#h-09) abre el sprint 2, antes que US-15** | nuevo | Es la tabla que US-15 necesita para existir. La historia sin la tabla no arranca |
| **[US-15](../stories/US-15-configurar-reglas-del-negocio.md) sube por delante de US-03** | 5.º → sprint 2 | Ya estaba adelantada por ser insumo de cuatro historias; ahora son cinco (se suma US-16) y además es el consumidor directo de H-09 |
| **[US-16](../stories/US-16-primer-contacto-bienvenida.md) sube al sprint 3** | 9.º → sprint 3 | Es la primera vez que el KPI de "respuesta en menos de 15 segundos" se mide punta a punta. Medirlo antes de construir cinco historias más es más barato que después |
| **[H-06](05-huecos-y-trazabilidad.md#h-06) va al sprint 3, antes que US-08** | nuevo | Es su cobertura. Si el spike de Slice 0 sale mal, US-08 se rediseña y el producto igual tiene takeover manual. Construir el plan B antes del plan A es raro y acá está justificado por la incertidumbre |
| **[H-01](05-huecos-y-trazabilidad.md#h-01) pegada a US-06** | nuevo | La colisión solo se puede probar cuando existe la reserva real. Un sprint después ya hay pilotos generando el caso |
| **[H-11](05-huecos-y-trazabilidad.md#h-11) antes que US-17** | nuevo | Migración de una línea que la historia necesita. Mismo patrón que H-09 → US-15 |
| **[US-17](../stories/US-17-registro-de-asistencia.md) pegada a US-10** | 13.º → sprint 6 | Sin cambio de criterio: es su instrumento de medición. Se confirma |
| **[US-14](../stories/US-14-panel-logs-y-estado.md) al final y descopeada** | 17.º → sprint 8 | No por prioridad sino por [H-10](05-huecos-y-trazabilidad.md#h-10): la vista de detalle no se puede construir. Se entrega la lista, que sí destraba el soporte |
| **[US-11](../stories/US-11-reagendar-y-aviso-t2h.md) baja al sprint 7, solo la parte "a"** | 14.º → sprint 7 | El re-agendamiento hereda todos los huecos de la reserva (A5 completa). Construirlo antes de [H-01](05-huecos-y-trazabilidad.md#h-01) es construir el mismo bug dos veces |

### Qué no se movió, y por qué

**US-01, US-02 y US-04 siguen en el sprint 1.** Es el único riesgo que no se controla con talento sino con permisos. Si Meta rechaza la cuenta, todo el plan cambia, y es mejor saberlo en la semana 1 que en la 6. US-04 sigue siendo la única historia sin dependencias internas: desperdiciar esa oportunidad de paralelizar dejaría a medio equipo esperando.

**La agenda sigue antes que el cotizador.** El producto es vendible agendando sin cotizar; no es vendible cotizando sin agendar. La secuencia sigue a la propuesta de valor, no al orden del flujo conversacional.

**US-10 sigue tarde pese a ser la de mayor valor comercial.** Necesita turnos reales acumulados y tiene el ciclo de aprendizaje más lento del MVP: cada dato tarda 24 horas. Por eso su dependencia externa —las plantillas de Meta— arranca en el Slice 0.

---

## Resumen ejecutivo

### Por qué lo que quedó afuera puede quedar afuera

**Las cinco funcionalidades que el PRD ya declara fuera de alcance** —cobro de señas, editor visual, multi-sucursal, CRM, integraciones adicionales— comparten un rasgo: ninguna valida un supuesto de riesgo, todas amplían un valor que todavía no probamos que exista. Construir el cobro de señas antes de saber si el ausentismo baja es optimizar un mecanismo cuyo problema no confirmamos.

**Outlook es el caso que merece decisión explícita, no omisión.** No es una funcionalidad de escala: es un segundo proveedor de calendario que duplica OAuth, disponibilidad y sincronización inversa, y cuya sola posibilidad hace no estimables a tres historias. El mapa lo ubica en v2 siguiendo al PRD, pero lo importante es lo otro: **el PRD ni lo incluye ni lo excluye**, y mientras eso siga así nadie tiene con qué desmentir a un vendedor que lo prometa. Escribirlo en el OUT-OF-SCOPE cuesta una línea.

**Lo diferido del recorrido de la PyME** —alta de tenant, alta de credenciales, roles, reconexión— se difiere por una lógica común: lo suplimos con trabajo humano durante los pilotos. Damos de alta tenants a mano, cargamos credenciales a mano, reconectamos a mano, y hay un usuario por cuenta. Con tres pilotos eso cuesta horas nuestras; **al cuarto cliente el margen de la tarifa plana no lo aguanta**. Son deuda operativa consciente con fecha de vencimiento conocida, no olvidos.

**Y hay un costo que conviene admitir:** si durante los pilotos la configuración inicial la cargamos nosotros, el KPI de *"setup en menos de 60 minutos"* lo estamos cumpliendo nosotros, no el cliente. El número sería cierto y engañoso a la vez.

### Cómo este corte valida los supuestos de mayor riesgo primero

El orden responde a la secuencia en que las cosas pueden matar el proyecto:

1. **Riesgo de habilitación** (US-01, US-02) — si Meta no nos deja operar, nada más importa. Se prueba en la semana 1 y es lo más barato del plan.
2. **Riesgo de fundación** (H-09, H-15, H-11, H-10) — **capa nueva, aportada por el cruce con arquitectura.** Cuatro migraciones baratas que cinco historias del MVP asumen y que hoy no existen. No validan una hipótesis: evitan que las que sí la validan se construyan sobre algo que no las sostiene. Van temprano porque una tabla se crea antes que su historia, no después.
3. **Riesgo de factibilidad** (US-03 a US-07, H-01, H-04) — ¿podemos agendar de punta a punta, en el tiempo prometido, con la hora correcta y sin doble reserva? Es la propuesta de valor entera. La colisión y la atomicidad se suman acá porque son las dos formas en que "agendamos bien" puede ser falso sin que se note en una demo.
4. **Riesgo de adopción** (US-08, H-06) — el producto puede funcionar perfecto y aun así ser expulsado si el bot pisa al dueño delante de su cliente. Es la objeción número uno de venta y su mecanismo de detección sigue sin probar; H-06 la convierte de riesgo abierto en riesgo acotado.
5. **Riesgo de valor** (US-10, US-17, H-11, H-08) — ¿el ausentismo baja de verdad? Es el número que sostiene el precio y tiene el ciclo de aprendizaje más lento. Ahora **sí** tiene con qué medirse: US-17 le da el instrumento, H-11 le da la columna y H-08 asegura que el estímulo que se está midiendo efectivamente se envió.
6. **Riesgo de encaje** (US-12) — ¿el cotizador paramétrico le sirve a los cuatro segmentos o solo a dos? Se responde en el Slice 0 con un día de discovery, antes de escribir el motor.

Todo lo que quedó en v2 pertenece a una categoría distinta: **riesgo de escala**, que solo importa si los seis anteriores se resuelven a favor. Cobrar señas, escalar a multi-sucursal o construir un CRM son problemas que uno *quiere* tener. El MVP está diseñado para averiguar si vamos a tenerlos.

**Lo que este ejercicio agregó al corte anterior** es la capa 2. El mapa previo saltaba de habilitación a factibilidad, y ese salto asumía que la fundación estaba puesta. No lo está: cinco historias del MVP leen o escriben datos que no tienen tabla, columna o índice. Son cuatro migraciones de un día en total, y son la diferencia entre estimar sobre lo que existe y estimar sobre lo que creíamos que existía.
