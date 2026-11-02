# T-006 · Discovery: modelar catálogos de precios de cuatro rubros

| | |
| :-- | :-- |
| **Prioridad** | 6 |
| **Sprint** | 0 — Habilitación |
| **Tipo** | Discovery |
| **Historia** | [US-12](../../stories/US-12-cotizador-parametrico.md) |
| **Story points** | 3 · **timebox 1 día, sin código** |
| **Depende de** | — |
| **Bloquea a** | T-040 |
| **Pareto** | **DIFERIDO** — fuera del MVP recortado |

> **Fuera del núcleo Pareto — DIFERIDO.** Cae con el cotizador (T-016, T-040, T-041). ⚠️ **Recomendación explícita: hacerlo igual durante la ola 1.** Es un día sin código y su output es conocimiento de negocio que no caduca: convierte la decisión sobre el cotizador en informada, en vez de una apuesta. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Contexto

El cotizador paramétrico encaja natural con inmobiliarias y servicios de mantenimiento, que cotizan por metro cuadrado o por ambiente. **Salud y estética cotizan por catálogo de precio fijo, y las distribuidoras B2B por SKU y lista.** Si el motor de fórmulas no los cubre, el 50% de los segmentos del lean canvas necesita otro motor — y eso es una decisión de roadmap, no un bug.

Construir el motor primero y descubrirlo después es la forma cara de responder esta pregunta.

## Alcance

* Conseguir la lista de precios real de un negocio por cada uno de los cuatro segmentos.
* Intentar expresar cada una como fórmula paramétrica, en papel.
* Documentar cuáles entran, cuáles no y qué les faltaría.
* Concluir si el MVP necesita también un catálogo de precios fijos.

## Criterios de aceptación

- [ ] Hay cuatro listas de precios reales, una por segmento.
- [ ] Está documentado, por segmento, si se modela con fórmula paramétrica y con qué parámetros.
- [ ] Hay una conclusión explícita sobre si hace falta un segundo motor de catálogo fijo.
- [ ] El `parameters_schema` de T-040 se define con esa evidencia y no por suposición.

## Fuera de alcance

Escribir código. La señal de éxito de US-12 —modelar 3 de 4 segmentos sin código específico por tenant— se responde en papel.

## Notas y riesgos

⚠️ Un día de trabajo responde la pregunta más cara del backlog. Si la respuesta es "solo dos segmentos entran", conviene saberlo antes de comprometer el cotizador y probablemente antes de vender.
