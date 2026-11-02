# T-028 · Renovación de canales *watch*

| | |
| :-- | :-- |
| **Prioridad** | 28 |
| **Sprint** | 4 |
| **Tipo** | Feature |
| **Historia** | [US-23](../../stories/US-23-renovacion-de-canales-watch.md) |
| **Story points** | 3 |
| **Depende de** | T-027 |
| **Bloquea a** | — |
| **Pareto** | **DIFERIDO** — fuera del MVP recortado |

> **Fuera del núcleo Pareto — DIFERIDO.** Sin T-027 no hay canales que renovar. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Contexto

**Un canal caducado no falla ruidosamente: simplemente deja de avisar.** No hay error, no hay excepción, no hay log. El sistema parece funcionar y los turnos que la PyME borra a mano dejan de liberarse, uno por uno, hasta que alguien nota los huecos.

US-06 declara la renovación en sus dependencias y ningún criterio de aceptación la verifica: es exactamente el tipo de trabajo que se cae de una estimación.

## Alcance

* Persistencia de `channel_id`, `resource_id` y fecha de expiración por integración.
* Tarea programada que renueve con margen sobre el vencimiento.
* Verificación periódica de canales caídos y recreación.
* Baja de canales al desconectar la integración.

## Criterios de aceptación

- [ ] Un canal próximo a expirar se reemplaza por uno nuevo **antes** del vencimiento, sin ventana sin escucha *(AC-23.1)*.
- [ ] Un canal expirado o revocado se detecta, se recrea y queda registrado el hueco de escucha *(AC-23.2)*.
- [ ] Si la renovación falla por credenciales vencidas, la integración queda marcada como caída y se deja de intentar *(AC-23.3)*.
- [ ] Al desconectar Google Calendar, los canales se dan de baja y dejan de llegar notificaciones huérfanas *(AC-23.4)*.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ✅ | ⚠️ | ✅ | ✅ | ✅ |

**V** en ⚠️: su valor es la ausencia de un defecto, igual que T-031. Sostiene una funcionalidad existente en vez de agregar una nueva — y sin ella esa funcionalidad muere sola.

## Fuera de alcance

El respaldo por *polling* para el período en que un canal estuvo caído.

## Notas y riesgos

⚠️ **Sin columnas para el canal:** `integrations` no contempla `channel_id` ni expiración. Resolver en este ticket, candidato `integrations.settings`.
