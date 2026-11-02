# T-034 · Estado de las integraciones en el panel

| | |
| :-- | :-- |
| **Prioridad** | 34 |
| **Sprint** | 5 |
| **Tipo** | Feature |
| **Historia** | [US-25](../../stories/US-25-estado-de-las-integraciones.md) |
| **Story points** | 2 |
| **Depende de** | T-013, T-033 |
| **Bloquea a** | — |
| **Pareto** | **DIFERIDO** — fuera del MVP recortado |

> **Fuera del núcleo Pareto — DIFERIDO.** T-013 ya persiste `integrations.status='expired'`: mostrarlo en el panel es conveniencia, no promesa. Se retoma cuando exista más panel. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Contexto

AC-09.2 dice *"marca en el panel que hay que reconectar la cuenta"* y `integrations.status` ya admite `'expired'`. **El dato se escribe y nadie construyó dónde leerlo.** Es un circuito abierto de una sola pieza.

Sin esto, una credencial vencida se manifiesta como un producto que dejó de funcionar sin explicación, y la primera señal llega por un cliente enojado.

## Alcance

* Sección de integraciones en el panel con el estado de cada una.
* Aviso persistente en el layout cuando alguna está caída.
* Traducción del error técnico a una consecuencia entendible.
* Vuelta automática a estado normal tras una operación exitosa.

## Criterios de aceptación

- [ ] La sección muestra el estado de Google Calendar, Google Sheets y Meta, con la cuenta vinculada *(AC-25.1)*.
- [ ] Con una integración vencida, aparece un aviso visible desde cualquier pantalla que dice **qué se rompió y qué consecuencia tiene**, no un código de error *(AC-25.2)*.
- [ ] Al volver a funcionar, el estado se normaliza y el aviso desaparece sin intervención manual *(AC-25.3)*.
- [ ] El aviso indica el paso siguiente concreto, aunque en el MVP ese paso sea contactarnos *(AC-25.4)*.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ✅ | ✅ | ✅ | ✅ | ✅ |

**5 de 6.** Todo el trabajo de detección ya está hecho en T-013 y T-033: este ticket solo lee. Es de los mejores del backlog en relación entre esfuerzo y cierre de circuito.

## Fuera de alcance

El flujo de reconexión autoservicio, que es v2. En el MVP reconectamos nosotros.

## Notas y riesgos

⚠️ Como la reconexión es v2, el texto de AC-25.4 tiene que decir "contactanos" con todas las letras, en vez de ofrecer un botón que no existe.
