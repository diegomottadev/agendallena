# T-036 · Migración: registro de asistencia en `bookings`

| | |
| :-- | :-- |
| **Prioridad** | 36 |
| **Sprint** | 6 |
| **Tipo** | Migración |
| **Historia** | habilita [US-17](../../stories/US-17-registro-de-asistencia.md) |
| **Story points** | 1 |
| **Depende de** | — |
| **Bloquea a** | T-038 |
| **Pareto** | **NÚCLEO** · ola 5 |

## Contexto

`bookings.status` es `ENUM('scheduled','confirmed','rescheduled','cancelled')`: **no admite `attended` ni `no_show`**. La historia mejor puntuada del backlog —5/6 INVEST, estimada S, la que instrumenta el KPI que sostiene el modelo de precios— no tiene hoy dónde escribir su resultado.

## Alcance

* Columna `attendance` como enum `pending` / `attended` / `no_show`, **separada de `status`**.
* Columnas de auditoría: quién marcó y cuándo.
* Valor por defecto para los turnos existentes.
* Documentación del cambio en el modelo de datos.

## Criterios de aceptación

- [ ] La asistencia se persiste en una columna **propia**, no como valor adicional de `status`.
- [ ] Un turno puede estar `confirmed` y `no_show` simultáneamente sin que un dato pise al otro.
- [ ] Los turnos existentes quedan en `pending` tras la migración.
- [ ] `04-modelo-de-datos.md` documenta la columna nueva.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ✅ | ⚠️ | ⚠️ | ✅ | ✅ | ✅ |

**Enabler puro**, y el más barato del plan: 1 punto que desbloquea la medición de toda la hipótesis central del negocio.

## Fuera de alcance

La pantalla de marcado y el cálculo de la tasa, que son T-038.

## Notas y riesgos

⚠️ **La decisión de columna separada es la parte importante de este ticket, no la migración.** Si la asistencia se mete como un valor más de `status`, un turno confirmado que termina en ausencia pierde el dato de que había confirmado — y eso rompe directamente AC-17.4, que es el criterio que la historia declara más importante: sin correlacionar asistencia con respuesta al recordatorio, se observa la mejora pero no se le puede atribuir causa.
