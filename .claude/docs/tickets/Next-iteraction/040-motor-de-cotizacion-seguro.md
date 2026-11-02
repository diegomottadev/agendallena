# T-040 · Motor de cotización con evaluación segura

| | |
| :-- | :-- |
| **Prioridad** | 40 |
| **Sprint** | 7 |
| **Tipo** | Feature |
| **Historia** | [US-12](../../stories/US-12-cotizador-parametrico.md) |
| **Story points** | 5 |
| **Depende de** | **T-006**, T-016 |
| **Bloquea a** | T-041 |
| **Pareto** | **DIFERIDO** — fuera del MVP recortado |

> **Fuera del núcleo Pareto — DIFERIDO.** ⚠️ **Es el recorte más discutible del análisis:** la UVP dice «Cotizá y agendá» y esto es la mitad. A favor de diferirlo: ninguno de los tres KPIs de impacto del PRD lo toca, la cotización paramétrica aplica solo a algunos de los cuatro segmentos, y arrastra el riesgo de multi-tenancy de la evaluación segura de fórmulas. Decidir con el resultado de T-006. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Contexto

El evaluador de fórmulas es la parte fácil; lo caro es descubrir *después* de construirlo que no modela el negocio. Por eso T-006 va primero y sin código.

Y hay un requisito que ningún documento menciona: **la fórmula la carga el tenant**, así que evaluarla dinámicamente es ejecutar entrada de usuario en el servidor. Con multi-tenancy por columna, eso es acceso a los datos de todos los demás clientes.

## Alcance

* Parser acotado o librería de expresiones con lista blanca de operadores — **nunca `eval()`**.
* Validación de la entrada contra `parameters_schema`.
* Aplicación de rangos válidos por parámetro.
* Persistencia del monto calculado.

## Criterios de aceptación

- [ ] Con la fórmula `Base + (metros_cuadrados × valor_unitario)` y una entrada de 80 m², devuelve el precio calculado en la moneda del tenant *(AC-12.1)*.
- [ ] Una expresión maliciosa cargada como fórmula **no ejecuta código**: el evaluador solo admite los operadores de la lista blanca.
- [ ] Una entrada fuera del rango configurado no cotiza y marca la consulta para derivación *(AC-12.4)*.
- [ ] El monto se persiste en `bookings.quote_amount` al reservar.
- [ ] El motor modela sin código específico por tenant al menos los catálogos que T-006 determinó viables.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ✅ | ✅ | ⚠️ | ✅ | ✅ |

**Corrección aplicada:** US-12 fallaba *Pequeña* con tres cosas juntas —motor, modelado de catálogos e integración conversacional—. El modelado salió a T-006 (discovery, sin código) y la integración a T-041. **E** en ⚠️ hasta que T-006 devuelva resultados: si dos de los cuatro segmentos no entran, cambia la forma del esquema.

## Fuera de alcance

La conversación que recolecta los parámetros (T-041) y la pantalla de carga de fórmulas (T-016).

## Notas y riesgos

⚠️ **No hay columna de moneda en ninguna tabla** y AC-12.1 promete el precio "en la moneda del tenant". Agregarla en este ticket o en T-014.
