# Documentación — AgendaLlena

Middleware de agendamiento y cotización por WhatsApp para PyMEs (Laravel 13 + PHP 8.4 + Vue 3 + Meta Cloud API + Google Calendar).

## Índice

### 01 — Producto
Qué construimos y por qué. Empezá acá.

| Doc | Contenido |
| :-- | :-- |
| [01-lean-canvas.md](01-producto/01-lean-canvas.md) | Problema, segmentos, propuesta de valor, costos e ingresos |
| [02-prd.md](01-producto/02-prd.md) | Alcance del MVP, KPIs, requisitos funcionales (RF-*), NFR (RNF-*) y criterios de aceptación (CA-*) |
| [03-user-stories-mvp.md](01-producto/03-user-stories-mvp.md) | Índice del backlog: 14 historias priorizadas por riesgo, con estimación, INVEST y trazabilidad al PRD |
| [04-user-story-map.md](01-producto/04-user-story-map.md) | Mapa por recorrido de usuario, corte MVP vs. v2 y los 6 huecos de cobertura detectados |

### 02 — Arquitectura
Cómo está construido el sistema.

| Doc | Contenido |
| :-- | :-- |
| [01-c4-nivel-2-contenedores.md](02-arquitectura/01-c4-nivel-2-contenedores.md) | C4 L2: SPA, backend Laravel, storage y sistemas externos |
| [02-c4-nivel-3-componentes.md](02-arquitectura/02-c4-nivel-3-componentes.md) | C4 L3: capas HTTP, aplicación, dominio e infraestructura del backend |
| [03-der-entidad-relacion.md](02-arquitectura/03-der-entidad-relacion.md) | Diagrama entidad-relación |
| [04-modelo-de-datos.md](02-arquitectura/04-modelo-de-datos.md) | Tablas, columnas e índices (incluye `tenant_id` por RNF-01) |

### 03 — Flujos
Cómo se comporta el sistema de cara al usuario.

| Doc | Contenido |
| :-- | :-- |
| [01-casos-de-uso-general.md](03-flujos/01-casos-de-uso-general.md) | Diagrama general de casos de uso |
| [02-casos-de-uso-cliente-final.md](03-flujos/02-casos-de-uso-cliente-final.md) | Casos de uso del cliente final |
| [03-casos-de-uso-equipo-pyme.md](03-flujos/03-casos-de-uso-equipo-pyme.md) | Casos de uso del equipo de la PyME |
| [04-flujo-whatsapp-cliente-final.md](03-flujos/04-flujo-whatsapp-cliente-final.md) | Recorrido del cliente final por WhatsApp |
| [05-estados-flujo-conversacional.md](03-flujos/05-estados-flujo-conversacional.md) | Máquina de estados: `IDLE` → `GATHERING_PARAMS` → `SELECTING_SLOT` → `BOOKED` |
| [06-secuencia-agenda-cotizacion-e2e.md](03-flujos/06-secuencia-agenda-cotizacion-e2e.md) | Secuencia end-to-end de agendamiento y cotización |

### Backlog y mapas
El detalle historia por historia y el cruce contra todo lo anterior.

| Doc | Contenido |
| :-- | :-- |
| [stories/](stories/) | Las 17 historias del MVP, una por archivo, con criterios BDD, INVEST y notas Lean |
| [stories-maps/](stories-maps/README.md) | Story maps por recorrido (cliente final, PyME, plataforma), corte por slices, 21 huecos y matriz de trazabilidad |

## Convención de nombres

Regla única: **`NN-kebab-case.md`**, todo en minúsculas y ASCII.

- **Prefijo numérico `NN-`** — fija el orden de lectura. El orden alfabético no sabe que el PRD va antes que un diagrama de secuencias; el número sí.
- **`kebab-case`** — palabras separadas por guiones, sin espacios. Los espacios obligan a comillas en cada comando de shell, rompen scripts y ensucian las URLs de Git.
- **Sin acentos ni `ñ`** en nombres de archivo. En el contenido, todos los que hagas falta. Un `ó` en el nombre viaja mal entre Windows, Linux y macOS, que normalizan Unicode distinto.
- **Siempre `.md`**, sin espacio antes de la extensión.
- **Carpetas `NN-tema/`** con la misma regla.

Ejemplos:

```
correcto:   02-arquitectura/01-c4-nivel-2-contenedores.md
incorrecto: Diagrama de Contenedores C4 - Nivel 2.md
incorrecto: diagrama de secuencias - agenda y cotizacion e2e   (sin extensión)
incorrecto: lean canvas .md                                     (espacio antes de .md)
```

## Cómo crecer

- Documento nuevo en una sección: seguí la numeración (`05-...`, `06-...`) y agregá la fila al índice.
- Sección nueva: `04-operaciones/`, `05-decisiones/` (ADRs), etc.
- Para dejar hueco a futuras inserciones sin renumerar todo, numerá de 10 en 10 (`10-`, `20-`, `30-`).
