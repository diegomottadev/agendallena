# US-15 · Configurar las reglas del negocio

| | |
| :-- | :-- |
| **Sección** | 2 — Probar la promesa central |
| **Prioridad** | 5 de 17 — **antes de US-05**, que consume esta configuración |
| **Estimación** | **L** |
| **Trazabilidad** | **sin RF en el PRD** · consumido por RF-B2, RF-C1 y RNF-02 |
| **Origen** | Hueco B3 detectado en el [story map](../01-producto/04-user-story-map.md) |
| **Depende de** | [US-04](US-04-conectar-google-calendar-oauth.md) (shell del panel) |
| **Bloquea a** | [US-05](US-05-horarios-realmente-libres.md) · [US-07](US-07-hora-correcta-timezones.md) · [US-09](US-09-fallback-ante-caidas.md) · [US-12](US-12-cotizador-parametrico.md) · [US-16](US-16-primer-contacto-bienvenida.md) |

## Historia

> **Como** dueña de PyME con una forma propia de trabajar,
> **quiero** definir mis horarios de atención, la duración y el colchón entre turnos, mi zona horaria y mis precios,
> **para** que el bot ofrezca turnos y cotice según cómo trabajo yo, y no según un valor por defecto que no me representa.

## Criterios de aceptación

**AC-15.1 — Horario de atención y días laborables**
> **Dado que** configuré atención de 09:00 a 18:00 de lunes a viernes,
> **cuando** un cliente pide turno para un sábado,
> **entonces** no se ofrece ningún horario y el bot le indica cuáles son los días de atención.

**AC-15.2 — Duración de turno y buffer**
> **Dado que** configuré turnos de 45 minutos con un buffer de 10,
> **cuando** se calculan los horarios disponibles desde las 09:00,
> **entonces** se ofrecen 09:00, 09:55, 10:50 y así sucesivamente.

**AC-15.3 — Zona horaria del tenant**
> **Dado que** selecciono `America/Bogota` como mi zona horaria,
> **cuando** guardo el cambio,
> **entonces** todos los horarios que se muestran al cliente y los textos de los recordatorios pasan a usar esa zona.

**AC-15.4 — Fórmula de precio sin despliegue**
> **Dado que** cargo la fórmula `Base + (metros_cuadrados × valor_unitario)` con sus valores,
> **cuando** la guardo,
> **entonces** el cotizador la aplica en la siguiente consulta sin necesidad de un despliegue de código.

**AC-15.5 — Mensajes editables**
> **Dado que** edito el texto de bienvenida y el de fallback,
> **cuando** los guardo,
> **entonces** el bot usa los textos nuevos a partir de la siguiente conversación.

**AC-15.6 — Validación de configuración incoherente**
> **Dado que** ingreso un horario de cierre anterior al de apertura, o un buffer mayor que la duración del turno,
> **cuando** intento guardar,
> **entonces** el sistema rechaza el cambio con un mensaje claro y no persiste nada.

## Requisitos técnicos

* ⚠️ **Bloqueante — crear la tabla de configuración por tenant.** Hoy solo persisten dos de los cinco dominios que esta historia configura: la zona horaria en `tenants.timezone` y las fórmulas en `quote_rules`. **Horario de atención, días laborables, duración de turno, buffer y los textos de bienvenida y fallback no tienen dónde vivir.** Es una migración de un día y bloquea a cinco historias del MVP.
* Migración `business_settings` con `tenant_id`, horario de apertura y cierre por día de la semana, días laborables, duración de turno en minutos, buffer en minutos, texto de bienvenida y texto de fallback (AC-15.1, AC-15.2, AC-15.5).
* Validar del lado del servidor que el cierre sea posterior a la apertura y que el buffer sea menor que la duración, rechazando el guardado completo sin persistir nada (AC-15.6).
* Invalidar la caché de configuración al guardar, para que el cambio aplique en la conversación siguiente y no al reiniciar el worker (AC-15.3, AC-15.4, AC-15.5).
* Persistir la fórmula en `quote_rules.formula_expression` y `parameters_schema` sin requerir despliegue (AC-15.4), evaluada con el motor seguro de [US-12](US-12-cotizador-parametrico.md).
* Actualizar `tenants.timezone` desde la lista de zonas IANA, validando contra el catálogo real (AC-15.3).
* CRUD en el panel Vue 3 sobre el shell de [US-04](US-04-conectar-google-calendar-oauth.md), con permisos restringidos al rol `owner`/`admin`. ⚠️ Los roles existen en `users.role` y ninguna historia define sus permisos.
* ⚠️ **Sin RF en el PRD.** Escribirlo antes de comprometerla: RF-B2 exige respetar horarios y buffers, RNF-02 exige la zona configurada en el tenant y RNF-03 exige un mensaje de cortesía configurable. Los tres consumen esta historia y ninguno la manda construir.
* ⚠️ **Decisión de alcance:** pantallas de configuración o carga por seeder durante los pilotos. Si la cargamos nosotros, el KPI de "setup en menos de 60 minutos" lo cumplimos nosotros, no el cliente.

## Estimación de complejidad

**L** — Son cinco dominios de configuración distintos (agenda, precios, mensajes, zona horaria, validaciones) con su UI y su persistencia por tenant.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ⚠️ | Necesita el shell del panel de US-04, pero es de las pocas que se puede desarrollar en paralelo a la agenda. |
| **Negociable** | ✅ | Cómo se agrupan los parámetros, y si en el MVP se cargan por pantalla o por base de datos, está totalmente abierto. |
| **Valiosa** | ✅ | Es la diferencia entre un bot genérico y uno que trabaja como trabaja el negocio. |
| **Estimable** | ⚠️ | Estimable con estos criterios, **pero el requisito no existe en el PRD.** Conviene escribirlo (¿RF-E3?) antes de comprometerla. |
| **Pequeña** | ❌ | **Son tres historias:** configuración de agenda (AC-15.1/2/3), de precios (AC-15.4) y de mensajes (AC-15.5). |
| **Testeable** | ✅ | Cada criterio se verifica cambiando un valor y observando el efecto en el chat. |

**Veredicto:** cumple 3 de 6.

> **Mejora recomendada:** partir en tres y decidir explícitamente si en el MVP la configuración tiene pantallas o la cargamos nosotros por base de datos. **Si la cargamos nosotros, el KPI de "setup en menos de 60 minutos" lo cumplimos nosotros, no el cliente** — el número seguiría siendo cierto y a la vez engañoso.

## Por qué es urgente (o crítica)

No es una funcionalidad: es un **insumo**. Cuatro historias del MVP la consumen y ninguna la construye. RF-B2 dice *"respetando horarios de atención"* y *"buffers de 10 minutos"*; RNF-02 dice *"la zona horaria local configurada en el `tenant`"*; RF-C1 evalúa una fórmula que alguien tiene que haber cargado. Los tres asumen una configuración que **ningún requisito manda construir**.

Es el tercer requisito huérfano del proyecto, después de Google Sheets ([US-13](US-13-leads-a-google-sheets.md)) y del aviso t-2h ([US-11](US-11-reagendar-y-aviso-t2h.md)).

## Qué esperamos aprender

* Si los valores por defecto sirven a la mayoría de los tenants. Si sí, la configuración se puede simplificar mucho y el setup se acorta.
* Qué parámetros toca realmente cada rubro — el insumo para saber qué merece pantalla y qué puede quedar en un archivo.
* Si las fórmulas de precio son autoservibles o si cada alta necesita que las carguemos nosotros. Se cruza con el descubrimiento de [US-12](US-12-cotizador-parametrico.md).

## De qué depende

* [US-04](US-04-conectar-google-calendar-oauth.md) — shell del panel.
* **Redacción del RF faltante en el PRD.**
* Decisión de alcance: pantallas de configuración o carga manual por nuestra parte durante los pilotos.
