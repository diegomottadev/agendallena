# T-021 · Bienvenida y menú inicial

| | |
| :-- | :-- |
| **Prioridad** | 21 |
| **Sprint** | 3 |
| **Tipo** | Feature |
| **Historia** | [US-16](../../stories/US-16-primer-contacto-bienvenida.md) |
| **Story points** | 3 |
| **Depende de** | T-017, T-018, T-019 |
| **Bloquea a** | — |
| **Pareto** | **NÚCLEO** · ola 3 · con recorte, ver *Fuera de alcance* |

## Contexto

Es el único mensaje que recibe el **100%** de los clientes finales, y hasta el story map no estaba definido en ningún lado: US-03 declara el estado `IDLE` y nadie especificó qué se contesta en él.

Es además la primera vez que el KPI de "respuesta en menos de 15 segundos" se puede medir punta a punta y no por tramos.

## Alcance

* Transición `IDLE → GATHERING_PARAMS` ante cualquier mensaje sin conversación activa.
* Mensaje de bienvenida con nombre del negocio y opciones, leído de la configuración.
* Palabra clave de reinicio reconocida desde cualquier estado.
* Variante del mensaje fuera del horario de atención.
* Instrumentación del tiempo webhook → envío.

## Criterios de aceptación

- [ ] Escribiendo por primera vez desde un teléfono sin historial, llega en menos de 15 segundos un saludo con el nombre del negocio y las opciones *(AC-16.1)*.
- [ ] Tocar una opción avanza al estado correspondiente sin pedir que se escriba nada *(AC-16.2)*.
- [ ] La palabra clave de reinicio vuelve al menú inicial y limpia el estado anterior, desde cualquier punto *(AC-16.3)*.
- [ ] Escribiendo fuera del horario configurado, el mensaje aclara cuándo atienden y **permite igual** iniciar una consulta o reservar *(AC-16.4)*.
- [ ] El tiempo entre la recepción del webhook y el envío queda instrumentado y es consultable.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ✅ | ✅ | ✅ | ✅ | ✅ |

**5 de 6, la mejor puntuación del backlog junto con T-038.** Precisamente porque nació de un hueco acotado del story map y no de un módulo del PRD. **I** en ⚠️ es inevitable: necesita el estado `IDLE` y el texto configurable.

## Fuera de alcance

El contenido concreto del menú de opciones, que depende de qué flujos existan al momento de implementarlo.

**Recorte Pareto.** Se difiere la variante del mensaje fuera del horario de atención *(AC-16.4)*: el propio AC exige que fuera de horario igual se pueda reservar, así que el flujo funciona sin ella. **La instrumentación del tiempo webhook → envío no se recorta:** es el único lugar donde se mide el KPI de 15 segundos. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Notas y riesgos

Si la medición de AC-16.1 da por encima de 15 segundos, el problema es de arquitectura de la cadena entera y no de este ticket — pero es acá donde se detecta por primera vez.
