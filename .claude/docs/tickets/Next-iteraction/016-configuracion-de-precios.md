# T-016 · Configuración de precios: fórmulas paramétricas

| | |
| :-- | :-- |
| **Prioridad** | 16 |
| **Sprint** | 2 |
| **Tipo** | Feature |
| **Historia** | [US-15](../../stories/US-15-configurar-reglas-del-negocio.md) |
| **Story points** | 3 |
| **Depende de** | T-012 |
| **Bloquea a** | T-040 |
| **Pareto** | **DIFERIDO** — fuera del MVP recortado |

> **Fuera del núcleo Pareto — DIFERIDO.** Cae con el cotizador (T-040, T-041). Se retoma si T-006 confirma que la cotización cierra ventas. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Contexto

RF-C1 evalúa una fórmula que alguien tiene que haber cargado, y ningún requisito dice quién ni dónde. `quote_rules` existe en el modelo de datos; la pantalla que la escribe, no.

## Alcance

* CRUD de reglas de cotización sobre `quote_rules`.
* Editor de `formula_expression` y de `parameters_schema` con sus rangos válidos.
* Activación y desactivación de reglas sin borrarlas.
* Invalidación de caché al guardar.

## Criterios de aceptación

- [ ] Cargada la fórmula `Base + (metros_cuadrados × valor_unitario)` con sus valores, el cotizador la aplica en la consulta siguiente **sin despliegue de código** *(AC-15.4)*.
- [ ] Cada parámetro define su tipo y su rango válido, que es lo que consume AC-12.4.
- [ ] Una fórmula con sintaxis inválida se rechaza al guardar, no al evaluarla frente a un cliente.
- [ ] Desactivar una regla la saca del flujo sin perder su histórico.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ✅ | ✅ | ✅ | ⚠️ | ✅ | ✅ |

**Corrección aplicada:** separado de T-015 y T-017 según el corte que US-15 señala. **E** en ⚠️ hasta que T-006 devuelva los catálogos reales: si dos de los cuatro segmentos no se modelan con fórmulas, la forma del `parameters_schema` cambia.

## Fuera de alcance

El motor que evalúa la fórmula (T-040) y la integración conversacional (T-041).

## Notas y riesgos

⚠️ La validación de sintaxis de este ticket y el evaluador seguro de T-040 comparten el parser. Conviene escribirlo una sola vez y que este ticket lo consuma en modo validación.
