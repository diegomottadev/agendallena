# T-002 · Solicitud de aprobación de plantillas de mensaje

| | |
| :-- | :-- |
| **Prioridad** | 2 |
| **Sprint** | 0 — Habilitación |
| **Tipo** | Trámite externo |
| **Historia** | [US-10](../../stories/US-10-recordatorio-t24h.md) · [US-11](../../stories/US-11-reagendar-y-aviso-t2h.md) |
| **Story points** | 2 |
| **Depende de** | T-001 |
| **Bloquea a** | T-037 |
| **Pareto** | **NÚCLEO** · ola 0 |

## Contexto

Los recordatorios salen fuera de la ventana de 24 horas de Meta, así que **obligatoriamente** viajan como plantilla aprobada. La aprobación tiene demora propia y es el cuello de botella real del MVP: hoy está escondida dentro de la estimación de US-10.

## Alcance

* Redactar la plantilla del recordatorio t-24h con tres botones: Confirmar, Re-agendar, Cancelar.
* Redactar la plantilla del acuse de confirmación de reserva.
* Definir las variables de la plantilla (nombre, servicio, fecha y hora local).
* Presentarlas a aprobación y gestionar los rechazos hasta obtener el visto bueno.

## Criterios de aceptación

- [ ] La plantilla de recordatorio t-24h figura aprobada en el portal de Meta.
- [ ] La plantilla soporta los tres botones interactivos requeridos por AC-10.1.
- [ ] Las variables cubren nombre, servicio, fecha y hora en la zona del tenant.
- [ ] Los identificadores de plantilla están documentados para el equipo de desarrollo.

## Fuera de alcance

La plantilla del aviso t-2h: depende de un requisito funcional que todavía no está escrito (ver T-043).

## Notas y riesgos

⚠️ Meta rechaza plantillas por motivos de copy que no son evidentes de antemano. Presentar temprano y con margen para al menos dos iteraciones.
