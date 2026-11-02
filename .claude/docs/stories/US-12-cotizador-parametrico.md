# US-12 · Cotizar antes de ofrecer la agenda

| | |
| :-- | :-- |
| **Sección** | 5 — Extender el valor |
| **Prioridad** | 12 de 14 |
| **Estimación** | **L** |
| **Trazabilidad** | RF-C1 |
| **Depende de** | [US-03](US-03-maquina-de-estados-conversacional.md) |
| **Bloquea a** | [US-14](US-14-panel-logs-y-estado.md) · [US-20](US-20-derivar-a-una-persona.md) |

## Historia

> **Como** cliente final que consulta por un servicio,
> **quiero** saber cuánto me va a costar antes de comprometerme a un turno,
> **para** decidir si me sirve sin tener que esperar a que alguien me responda con el precio.

## Criterios de aceptación

**AC-12.1 — Cálculo por fórmula**
> **Dado que** el tenant configuró la fórmula `Base + (metros_cuadrados × valor_unitario)`,
> **cuando** informo 80 metros cuadrados en el chat,
> **entonces** recibo el precio estimado calculado con esa fórmula, en la moneda del tenant.

**AC-12.2 — Entrada no numérica**
> **Dado que** el bot me pidió una cantidad,
> **cuando** respondo con texto que no es un número,
> **entonces** el bot me vuelve a pedir el dato con un ejemplo y la conversación no se rompe ni pierde el estado.

**AC-12.3 — La cotización precede a la agenda**
> **Dado que** recibí la cotización,
> **cuando** el flujo continúa,
> **entonces** recién ahí se me ofrecen los horarios disponibles.

**AC-12.4 — Valor fuera de rango**
> **Dado que** informo un valor fuera del rango configurado por el tenant,
> **cuando** se procesa,
> **entonces** el bot no cotiza y deriva la consulta a un asesor humano.

## Requisitos técnicos

* Evaluar `quote_rules.formula_expression` con un parser acotado o una librería de expresiones matemáticas con lista blanca de operadores. ⚠️ **Nunca con `eval()`:** es una expresión que carga el tenant, y con multi-tenancy por columna una evaluación dinámica da acceso a los datos de todos los demás. Ningún requisito del PRD lo menciona.
* Validar la entrada del cliente contra `quote_rules.parameters_schema` antes de evaluar, rechazando lo no numérico sin romper el estado (AC-12.2).
* Definir el rango válido por parámetro dentro de `parameters_schema` y derivar a una persona cuando el valor lo excede (AC-12.4). El circuito de derivación es [US-20](US-20-derivar-a-una-persona.md).
* Implementar las transiciones `GATHERING_PARAMS → CALCULATING_QUOTE → SELECTING_SLOT` (AC-12.3). ⚠️ `CALCULATING_QUOTE` está en el catálogo de estados de los flujos y **no** en RF-A3.
* Persistir el resultado en `bookings.quote_amount`. ⚠️ No hay columna de moneda en ninguna tabla, y AC-12.1 promete el precio "en la moneda del tenant".
* Aplicar la fórmula vigente sin necesidad de despliegue: leerla en cada evaluación o invalidar caché al guardarla ([US-15](US-15-configurar-reglas-del-negocio.md), AC-15.4).
* ⚠️ **Descubrimiento previo, un día, sin código:** conseguir las listas de precios reales de un negocio por segmento e intentar expresarlas como fórmula en papel. Si salud, estética o las distribuidoras B2B no se modelan así, el MVP necesita también un catálogo de precios fijos y eso es una decisión de roadmap, no un ajuste.

## Estimación de complejidad

**L** — El evaluador de fórmulas es chico. Lo grande es el modelado de catálogos para cuatro segmentos con lógicas de precio distintas, y la integración con el flujo conversacional.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ⚠️ | Requiere el estado `GATHERING_PARAMS` de US-03. |
| **Negociable** | ✅ | La forma del esquema JSON y la interacción quedan abiertas. |
| **Valiosa** | ✅ | Precio antes de agendar: sube la conversión y filtra consultas que no iban a cerrar. |
| **Estimable** | ⚠️ | El motor sí. El modelado de catálogos por segmento **no**, porque todavía no hicimos ese descubrimiento. |
| **Pequeña** | ❌ | Motor de fórmulas, modelado de catálogos e integración conversacional son tres cosas. |
| **Testeable** | ✅ | Los cuatro criterios se verifican con una fórmula de prueba. |

**Veredicto:** cumple 3 de 6, pero su problema real no aparece en la grilla: **el riesgo no es técnico sino de encaje.**

> **Mejora recomendada — invertir la secuencia.** Hoy la historia dice "construir el motor y ver si modela 3 de 4 segmentos". Pero el motor JSON es la parte fácil; lo caro es descubrir que no encaja *después* de construirlo. La versión Lean es sin código: conseguir las listas de precios reales de cuatro negocios, uno por segmento, e intentar expresarlas como fórmula en papel. Un día de trabajo, cero líneas escritas, responde la pregunta más cara del backlog. Recién ahí se construye el motor sabiendo qué tiene que soportar.

## Por qué es urgente (o crítica)

La fórmula paramétrica encaja natural con **inmobiliarias** y **servicios de mantenimiento**, que cotizan por metro cuadrado o por ambiente. Pero **salud y estética** cotizan por catálogo de precio fijo por tratamiento, y las **distribuidoras B2B** por SKU y lista de precios. Si el modelo de fórmula no los cubre, el 50% de los segmentos declarados en el lean canvas necesita otro motor — y eso es una decisión de roadmap, no un bug.

## Qué esperamos aprender

Si el motor de fórmulas es la abstracción correcta o si el MVP necesita también un catálogo de precios fijos.

**Señal de éxito:** poder modelar el catálogo real de al menos 3 de los 4 segmentos sin escribir código específico por tenant.

## De qué depende

* [US-03](US-03-maquina-de-estados-conversacional.md) — estado `GATHERING_PARAMS`.
* Esquema JSON de reglas y evaluador con validación estricta de entradas.
* Listas de precios reales de los cuatro segmentos (para el descubrimiento previo).
