# T-044 · Vincular la planilla de Google Sheets

| | |
| :-- | :-- |
| **Prioridad** | 44 |
| **Sprint** | 8 |
| **Tipo** | Feature |
| **Historia** | [US-26](../../stories/US-26-vincular-mi-planilla.md) |
| **Story points** | 3 |
| **Depende de** | T-003, T-012 |
| **Bloquea a** | T-045 |
| **Pareto** | **DIFERIDO** — fuera del MVP recortado |

> **Fuera del núcleo Pareto — DIFERIDO.** Cae con T-045. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Contexto

US-13 se llama *"Los leads donde el equipo ya trabaja"* y **no construye la pantalla donde el equipo dice cuál es su planilla**. Sin esto, el volcado técnicamente funciona y comercialmente no cumple: los leads caen en un archivo que creamos nosotros, que es exactamente el CRM nuevo que la PyME ya rechazó.

## Alcance

* Pantalla de vinculación con la URL de la planilla pegada por el usuario.
* Validación de acceso de escritura antes de dar la vinculación por buena.
* Creación de una planilla nueva con encabezados si el tenant no tiene.
* Cambio de planilla vinculada.

## Criterios de aceptación

- [ ] Indicada la planilla y la hoja, el sistema valida que puede escribir en ella y lo confirma *(AC-26.1)*.
- [ ] Sin planilla propia, se crea una en el Drive del tenant con las columnas correspondientes y queda vinculada *(AC-26.2)*.
- [ ] Con permisos insuficientes, el mensaje explica el problema y qué hacer, y **la vinculación no queda a medias** *(AC-26.3)*.
- [ ] Al cambiar de planilla, los leads nuevos van a la nueva y los ya escritos no se migran ni se duplican *(AC-26.4)*.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ✅ | ✅ | ✅ | ✅ | ✅ |

**5 de 6.** Nació de un hueco acotado del story map. **I** en ⚠️ por depender del consentimiento de Google y del shell del panel.

## Fuera de alcance

El selector visual de Google Drive: exigiría el scope de Drive y una librería de front adicional. Pegar la URL evita ambas cosas.

## Notas y riesgos

⚠️ Nada impide hoy que dos tenants vinculen la misma planilla. No debería poder pasar; decidir en este ticket si se valida.
