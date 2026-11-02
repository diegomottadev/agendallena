# T-015 · Configuración de agenda: horarios, días, duración y buffer

| | |
| :-- | :-- |
| **Prioridad** | 15 |
| **Sprint** | 2 |
| **Tipo** | Feature |
| **Historia** | [US-15](../../stories/US-15-configurar-reglas-del-negocio.md) |
| **Story points** | 5 |
| **Depende de** | T-012, T-014 |
| **Bloquea a** | T-022, T-024 |
| **Pareto** | **NÚCLEO** · ola 2 · con recorte, ver *Fuera de alcance* |

## Contexto

No es una funcionalidad, es un **insumo**: cuatro historias del MVP la consumen y ninguna la construye. Es la diferencia entre un bot genérico y uno que trabaja como trabaja el negocio.

## Alcance

* Pantalla de configuración de agenda en el panel.
* Horario de apertura y cierre por día, y selección de días laborables.
* Duración de turno y buffer entre turnos.
* Selección de zona horaria contra el catálogo IANA.
* Validación de coherencia del lado del servidor.

## Criterios de aceptación

- [ ] Configurada la atención de 09:00 a 18:00 de lunes a viernes, pedir turno un sábado no ofrece horarios e indica los días de atención *(AC-15.1)*.
- [ ] Con turnos de 45 minutos y buffer de 10, los horarios ofrecidos desde las 09:00 son 09:00, 09:55, 10:50 y siguientes *(AC-15.2)*.
- [ ] Al seleccionar `America/Bogota`, los horarios mostrados y los textos de recordatorio pasan a esa zona *(AC-15.3)*.
- [ ] Un cierre anterior a la apertura, o un buffer mayor que la duración, se rechaza con mensaje claro y no persiste nada *(AC-15.6)*.
- [ ] El cambio aplica en la conversación siguiente sin reiniciar el worker.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ✅ | ✅ | ✅ | ✅ | ✅ |

**Corrección aplicada:** US-15 fallaba *Pequeña* con cinco dominios de configuración. Se partió en tres tickets —agenda, precios y mensajes— siguiendo el corte que la propia historia señala. **E** pasó de ⚠️ a ✅ porque T-014 resolvió la persistencia y T-007 escribe el RF.

## Fuera de alcance

Fórmulas de precio (T-016) y textos del bot (T-017).

**Recorte Pareto.** Se difiere el horario partido por día de la semana: en el núcleo hay un solo rango para todos los días laborables. *(AC-15.1)* y *(AC-15.2)* se cumplen igual; el turno mañana/tarde aparece después del primer cliente. Las validaciones de coherencia *(AC-15.6)* **no se recortan**. ⚠️ Si la carga inicial la hacemos nosotros por seeder, el KPI de «setup < 60 min» lo cumplimos nosotros y no el cliente. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Notas y riesgos

⚠️ **Decisión de alcance:** si en el MVP esta configuración la cargamos nosotros por seeder en vez de por pantalla, el KPI de "setup en menos de 60 minutos" lo cumplimos nosotros, no el cliente. El número sería cierto y engañoso a la vez.
