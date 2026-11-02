# T-046 · Autenticación del panel

| | |
| :-- | :-- |
| **Prioridad** | 46 |
| **Sprint** | 8 *(adelantable — ver notas)* |
| **Tipo** | Enabler |
| **Historia** | [US-14](../../stories/US-14-panel-logs-y-estado.md) |
| **Story points** | 3 |
| **Depende de** | — |
| **Bloquea a** | T-047 |
| **Pareto** | **NÚCLEO** · ola 1 |

## Contexto

La autenticación está enterrada dentro de US-14, que la propia historia marca como falla de INVEST: *"autenticación y vista de logs son historias separadas"*. Y no bloquea solo al panel de logs: T-015, T-025, T-034, T-035 y T-038 también viven detrás del login.

## Alcance

* Autenticación con Laravel Sanctum y sesión por tenant.
* Global Scope `BelongsToTenant` sobre las entidades del panel.
* Rechazo y registro de accesos cruzados entre tenants.
* Pantalla de login en el panel Vue 3.

## Criterios de aceptación

- [ ] Un usuario autenticado solo ve datos de su propio tenant, sin que cada consulta tenga que filtrar a mano.
- [ ] Intentando abrir por URL directa un recurso del tenant B siendo usuario del tenant A, se recibe `HTTP 403` y el intento queda registrado *(AC-14.2)*.
- [ ] Una consulta escrita sin filtro explícito de tenant **igual** queda filtrada por el Global Scope.
- [ ] La sesión expira y se renueva sin exponer el token en almacenamiento accesible por scripts.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ✅ | ✅ | ⚠️ | ✅ | ✅ | ✅ |

**Corrección aplicada:** US-14 puntúa 3/6 y falla *Independiente*, *Estimable* y *Pequeña*. Sacar la autenticación resuelve las tres de un solo corte: este ticket **no depende de nada** —el único del sprint 8 en esa condición— y *Estimable* pasó a ✅ porque T-007 cerró la decisión de multi-tenancy a favor de la columna.

## Fuera de alcance

Roles y permisos diferenciados: `users.role` admite `owner`, `admin` y `staff` y **ninguna historia define qué puede hacer cada uno**. Es v2.

## Notas y riesgos

⚠️ **Está en la posición 46 por dependencia lógica, no por prioridad real.** Cinco tickets anteriores (T-015, T-025, T-034, T-035, T-038) necesitan un panel con login. En la práctica hay que adelantarlo al sprint 2, junto con el shell de T-012. Se deja acá para no romper la trazabilidad con US-14, y **se recomienda moverlo en la planificación**.

⚠️ `users.email` tiene único **global** en vez de `(tenant_id, email)`: una misma persona no puede trabajar en dos tenants. Barato de cambiar ahora, caro con datos.
