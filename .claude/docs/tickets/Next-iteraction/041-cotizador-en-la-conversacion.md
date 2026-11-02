# T-041 · Integración conversacional del cotizador

| | |
| :-- | :-- |
| **Prioridad** | 41 |
| **Sprint** | 7 |
| **Tipo** | Feature |
| **Historia** | [US-12](../../stories/US-12-cotizador-parametrico.md) |
| **Story points** | 3 |
| **Depende de** | T-018, T-040 |
| **Bloquea a** | — |
| **Pareto** | **DIFERIDO** — fuera del MVP recortado |

> **Fuera del núcleo Pareto — DIFERIDO.** Cae con T-040. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Contexto

Precio antes de agendar: sube la conversión y filtra las consultas que no iban a cerrar. Es la mitad de la propuesta de valor —"cotizar y agendar"— y va después de la agenda porque el producto es vendible agendando sin cotizar, y no al revés.

## Alcance

* Recolección de parámetros en `GATHERING_PARAMS`, guiada por `parameters_schema`.
* Transición a `CALCULATING_QUOTE` y presentación del desglose.
* Botón de "agendar turno" que lleva a `SELECTING_SLOT`.
* Manejo de entradas no numéricas sin romper el estado.

## Criterios de aceptación

- [ ] Informados los parámetros en el chat, llega el precio estimado calculado con la fórmula del tenant *(AC-12.1)*.
- [ ] Respondiendo con texto donde se pedía un número, el bot vuelve a pedir el dato **con un ejemplo**, y la conversación no se rompe ni pierde el estado *(AC-12.2)*.
- [ ] Recién después de la cotización se ofrecen los horarios disponibles *(AC-12.3)*.
- [ ] Un valor fuera de rango deriva la consulta a una persona en vez de cotizar *(AC-12.4, vía T-035)*.
- [ ] Los estados `CALCULATING_QUOTE` y `SELECTING_SLOT` transicionan según el catálogo de estados.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ✅ | ✅ | ✅ | ✅ | ✅ |

**Corrección aplicada:** separado del motor (T-040), este ticket es estimable y testeable con una fórmula de prueba, sin depender del resultado del discovery de catálogos.

## Fuera de alcance

El motor de evaluación (T-040) y el catálogo de precios fijos, si T-006 concluye que hace falta: sería un ticket nuevo y una decisión de roadmap.

## Notas y riesgos

⚠️ `CALCULATING_QUOTE` está en el catálogo de estados de los flujos y **no** en RF-A3. Confirmar que T-018 lo implementó.
