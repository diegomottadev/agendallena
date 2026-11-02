# T-004 · Spike: ¿Meta entrega los mensajes salientes manuales?

> ## 🔒 Cerrado el 2026-08-21
>
> **Se quedó sin pregunta que responder.** Este spike existía **solo para decidir
> T-032**, y T-032 quedó cubierto por **T-025** —la pausa manual desde el panel—
> por 3 puntos en vez de 11. Sin T-032 no hay nada que este spike destrabe.
>
> Lo que hacía falta no era información nuestra sino una prueba a mano contra Meta;
> **no se hizo y ya no hace falta hacerla.**
>
> **Qué lo reabriría:** que se decida volver a un solo número y haya que construir
> T-032 de verdad. Ver el cierre de ese ticket.


| | |
| :-- | :-- |
| **Prioridad** | 4 |
| **Sprint** | 0 — Habilitación |
| **Tipo** | Spike |
| **Historia** | [US-08](../../stories/US-08-pausa-intervencion-humana.md) |
| **Story points** | 3 · **timebox 2 días** |
| **Depende de** | T-001 |
| **Bloquea a** | T-032 |
| **Pareto** | **RECORTADO** — fuera del MVP recortado |

> **Fuera del núcleo Pareto — RECORTADO.** Existe únicamente para decidir T-032, que también se recorta: sin T-032 no hay pregunta que responder. Se replantea junto con él. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

> # 🔴 CERRADO SIN EJECUTARSE — 2026-08-20
>
> **La pregunta de este spike dejó de aplicar.** Preguntaba si Meta entrega por
> webhook los mensajes que el operador escribe a mano desde la app móvil.
> **No hay app móvil:** un número registrado en la Cloud API deja de poder
> usarse como chat humano — o es bot, o es persona.
>
> Verificado contra la plataforma. **Se ahorran los 3 puntos y los 2 días de
> timebox**, y con él caen T-032 (8 pts) y la premisa de US-08.
>
> Lo que rige en su lugar: **modelo de dos números**, escrito en RF-A4.

## Contexto

US-08 —la objeción número uno en la mesa de venta— depende entera de un supuesto sin probar: que Meta entregue por webhook los mensajes que el operador escribe a mano desde la app móvil o WhatsApp Web. Si no los entrega, **la historia se rediseña de cero** y su estimación actual no vale nada.

## Alcance

* Suscribir la app de Meta a los campos de mensajes salientes y de estados.
* Enviar mensajes a mano desde la app móvil y desde WhatsApp Web al número de prueba.
* Capturar y documentar los payloads que llegan (o la ausencia de ellos).
* Documentar la conclusión y su consecuencia sobre el diseño de US-08.

## Criterios de aceptación

- [ ] Está documentado si Meta entrega el evento, con qué estructura y con qué latencia.
- [ ] Si lo entrega: está identificado el campo que distingue un mensaje manual de uno enviado por la API.
- [ ] Si no lo entrega: está escrita la propuesta de rediseño de US-08.
- [ ] La estimación de T-032 se confirma o se rehace con el resultado.

## Fuera de alcance

Implementar la pausa. Este ticket produce una respuesta, no código de producción.

## Notas y riesgos

⚠️ Resultado negativo es un resultado válido y valioso: T-025 (pausa manual desde el panel) ya cubre el escenario, y por eso va **antes** que T-032 en el plan.
