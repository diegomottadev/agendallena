# T-014 · Migración: tabla `business_settings`

| | |
| :-- | :-- |
| **Prioridad** | 14 |
| **Sprint** | 2 |
| **Tipo** | Migración |
| **Historia** | habilita [US-15](../../stories/US-15-configurar-reglas-del-negocio.md) |
| **Story points** | 2 |
| **Depende de** | — |
| **Bloquea a** | T-015, T-017, T-021, T-022, T-024, T-033 |
| **Pareto** | **NÚCLEO** · ola 1 |

## Contexto

**Cinco historias del MVP leen una configuración que no tiene dónde vivir.** De los cinco dominios que configura US-15, solo dos persisten: la zona horaria en `tenants.timezone` y las fórmulas en `quote_rules`. Horario de atención, días laborables, duración de turno, buffer y los textos de bienvenida y fallback no tienen tabla.

RF-B2 exige *"respetando horarios de atención"* y *"buffers de 10 minutos"*; RNF-03 exige un mensaje de cortesía configurable. Los dos consumen algo que ningún requisito manda construir.

## Alcance

* Migración `business_settings` con `tenant_id` y clave foránea a `tenants`.
* Horario de apertura y cierre por día de la semana, y días laborables.
* Duración de turno y buffer, ambos en minutos.
* Textos de bienvenida y de fallback.
* Valores por defecto razonables para que un tenant nuevo funcione sin configurar nada.

## Criterios de aceptación

- [ ] La tabla existe con `tenant_id`, su foránea y borrado en cascada, igual que el resto del modelo.
- [ ] Un tenant recién creado tiene configuración por defecto utilizable sin intervención.
- [ ] El modelo aplica el Global Scope de tenant, como el resto de las entidades.
- [ ] `04-modelo-de-datos.md` documenta la tabla nueva.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ✅ | ✅ | ⚠️ | ✅ | ✅ | ✅ |

**V** en ⚠️ por ser un enabler puro: el valor lo entregan T-015 a T-017. Se separó de ellos justamente para que esas tres puedan estimarse y desarrollarse en paralelo una vez que la tabla existe.

## Fuera de alcance

Las pantallas de configuración (T-015 a T-017) y las fórmulas de precio, que ya viven en `quote_rules`.

## Notas y riesgos

⚠️ Decidir en este ticket si los horarios van como columnas por día o como JSON. Un JSON es más flexible y hace imposible consultarlo desde SQL, lo que importa si en v2 se quiere reportar por franja horaria.
