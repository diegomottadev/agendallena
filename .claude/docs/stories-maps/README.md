# Story Maps — AgendaLlena MVP

Mapas de historias por recorrido de usuario, construidos cruzando el backlog de 17 historias contra **toda** la documentación del proyecto: producto, arquitectura y flujos.

**Generado con:** [`prompts/aprobados/2026-08-05-story-maps-mvp-desde-documentacion.md`](../../prompts/aprobados/2026-08-05-story-maps-mvp-desde-documentacion.md)
**Fecha:** 2026-08-05 · **Backlog referenciado:** [`stories/`](../stories/) US-01 a US-17, sin historias nuevas

---

## Índice

| Doc | Contenido |
| :-- | :-- |
| [01-mapa-cliente-final.md](01-mapa-cliente-final.md) | Recorrido por WhatsApp: A1 iniciar contacto → A9 cuando algo sale del carril. 44 pasos |
| [02-mapa-equipo-pyme.md](02-mapa-equipo-pyme.md) | Recorrido en panel, calendario y celular: B1 alta → B6 incidentes. 38 pasos |
| [03-mapa-plataforma-y-datos.md](03-mapa-plataforma-y-datos.md) | Enablers técnicos: C1 recibir → C8 persistir, cruzados contra el C4 y el DDL. 46 capacidades |
| [04-slices-y-secuencia.md](04-slices-y-secuencia.md) | Corte vertical Slice 0/1/2, orden por sprint y justificación del recorte por riesgo |
| [05-huecos-y-trazabilidad.md](05-huecos-y-trazabilidad.md) | 21 huecos con historia candidata, 6 contradicciones abiertas y matriz de trazabilidad |

**Por dónde empezar:** si venís a decidir el próximo sprint, [04](04-slices-y-secuencia.md). Si venís a escribir historias, [05](05-huecos-y-trazabilidad.md). Si venís a entender el producto, [01](01-mapa-cliente-final.md).

---

## Cómo leer los mapas

Cada actividad del backbone tiene una tabla con cuatro columnas:

| Columna | Qué significa |
| :-- | :-- |
| **Paso** | Algo que la persona hace o recibe. No es un módulo del sistema |
| **Historia** | La historia del backlog que lo resuelve, enlazada a su archivo |
| **Cobertura** | `✅ cubre` · `⚠️ parcial` · `🕳️ HUECO` · `🔴 hueco bloqueante` |
| **Slice** | `MVP` (Release 1.0) o `v2` (Post-MVP) |

`🔴` es la distinción que más importa: significa que **falta el soporte de datos o de arquitectura**, no solo la historia. Escribir la historia no lo cierra.

Un `🕳️ HUECO` nunca se rellenó con una historia inventada. Cada uno tiene su ficha en [05](05-huecos-y-trazabilidad.md) con una historia candidata en formato de post-it, para que escribirla sea un paso posterior y deliberado.

---

## Qué agrega este conjunto sobre el mapa anterior

Ya existe [`01-producto/04-user-story-map.md`](../01-producto/04-user-story-map.md), que cruzó las 14 historias originales contra el PRD y encontró 6 huecos —tres de los cuales se cerraron con US-15, US-16 y US-17. **Ese mapa sigue vigente y este no lo reemplaza.** La diferencia está en las fuentes:

| | Mapa anterior | Este conjunto |
| :-- | :-- | :-- |
| **Fuentes** | PRD + backlog | PRD + backlog + **arquitectura + flujos** |
| **Perspectivas** | Cliente final, PyME | Cliente final, PyME, **plataforma** |
| **Pasos mapeados** | ~40 | **128** |
| **Huecos** | 6 | **21**, de los cuales 5 bloqueantes |
| **Contradicciones** | 1 (Outlook) | **6** |

### Los cuatro hallazgos que solo aparecen con arquitectura y flujos

1. **Cinco historias del MVP no tienen dónde persistir lo que prometen.** No hay tabla para el horario de atención, la duración de turno, el buffer ni los textos del bot ([H-09](05-huecos-y-trazabilidad.md#h-09)); no hay tabla de mensajes para el historial que el panel muestra ([H-10](05-huecos-y-trazabilidad.md#h-10)); y `bookings.status` no admite `attended` ni `no_show`, así que la historia que instrumenta el KPI del negocio no tiene dónde escribir su resultado ([H-11](05-huecos-y-trazabilidad.md#h-11)). Son cuatro migraciones baratas que llevan meses invisibles porque nadie cruzó el backlog contra el DDL.

2. **Dos de las tres decisiones "abiertas y bloqueantes" ya estaban respondidas.** La persistencia del estado (MySQL como fuente de verdad, Redis como caché) está escrita en `03-flujos/05-estados-flujo-conversacional.md`; la estrategia multi-tenant (por columna `tenant_id`) la afirman los cuatro documentos que la mencionan. Declararlas cerradas es media hora de escritura y convierte a US-03 y US-14 en estimables. Ver [C-06](05-huecos-y-trazabilidad.md#c-06).

3. **El flujo tiene ramas que el backlog nunca escribió.** Colisión de horario al confirmar, agenda sin disponibilidad, pregunta libre fuera del flujo, agendamiento sin compensación. Están dibujadas en `03-flujos/04-flujo-whatsapp-cliente-final.md` y en la secuencia e2e, y ninguna tiene criterio de aceptación. Se concentran en A5 — el momento exacto en que el producto entrega el valor que cobramos.

4. **Hay un caso de uso de la PyME que resuelve el peor riesgo del backlog.** UC-2.10 —pausar o reactivar el bot a mano desde el panel— está en los flujos y no en el backlog. Es el plan B de US-08, la historia con más valor comercial y más incertidumbre técnica: si el spike confirma que Meta no entrega los mensajes salientes manuales, con UC-2.10 construido la degradación es "el dueño aprieta un botón" en vez de "no hay takeover". Ver [H-06](05-huecos-y-trazabilidad.md#h-06).

### Una corrección al mapa anterior

Atribuye al C4 nivel 2 la promesa de Outlook. Contra el archivo actual eso no se sostiene: el C4 L2 solo muestra Meta, Google Calendar y Google Sheets. Quien promete Outlook es el `provider ENUM` del modelo de datos, junto con el lean canvas. El detalle importa porque cambia qué documento hay que corregir. Ver [C-01](05-huecos-y-trazabilidad.md#c-01).

---

## Cobertura consolidada

| Recorrido | Pasos | Cubiertos | Parciales | Huecos |
| :-- | :-: | :-: | :-: | :-: |
| Cliente final (A) | 44\* | 26 | 7 | 10 |
| Equipo PyME (B) | 38 | 13 | 9 | 16 |
| Plataforma (C) | 46 | 23 | 10 | 13 |
| **Total** | **128** | **62** | **26** | **39** |

`*` Un paso —A8.1, presentarse en el local— queda fuera del sistema y no entra en ninguna de las tres columnas.

Los 39 huecos de la grilla se consolidan en **21 fichas** porque varios aparecen en más de un recorrido: la derivación al asesor ([H-05](05-huecos-y-trazabilidad.md#h-05)) es un hueco en tres pasos distintos, y la configuración sin tabla ([H-09](05-huecos-y-trazabilidad.md#h-09)) en cuatro.

**El diagnóstico central se mantiene y se afila:** el backlog cubre bien al cliente final y mal a la PyME —26 de 44 contra 13 de 38—, pero ahora se ve *cómo* se distinguen esas dos deudas. Del lado del cliente los huecos son **ramas de casos de uso que sí existen**: falta robustez. Del lado de la PyME son **actividades enteras sin producto**: falta funcionalidad, y en tres casos falta hasta la tabla.
