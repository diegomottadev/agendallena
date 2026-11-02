# US-05 · Ofrecer solo horarios que existen de verdad

| | |
| :-- | :-- |
| **Sección** | 2 — Probar la promesa central |
| **Prioridad** | 5 de 14 |
| **Estimación** | **L** |
| **Trazabilidad** | RF-B2 · RF-A5 |
| **Depende de** | [US-04](US-04-conectar-google-calendar-oauth.md) |
| **Bloquea a** | [US-06](US-06-reservar-y-liberar-sync-inversa.md) · [US-11](US-11-reagendar-y-aviso-t2h.md) · [US-18](US-18-colision-de-horario-al-confirmar.md) · [US-19](US-19-agenda-sin-disponibilidad.md) |

## Historia

> **Como** cliente final que pide un turno por WhatsApp,
> **quiero** ver solamente horarios que estén realmente disponibles,
> **para** no reservar uno que ya estaba ocupado y tener que reorganizar mi día después.

## Criterios de aceptación

**AC-05.1 — Respetar lo ocupado**
> **Dado que** el calendario tiene un evento de 10:00 a 11:00,
> **cuando** pido turnos para ese día,
> **entonces** no aparece ningún horario que se superponga con esa franja.

**AC-05.2 — Buffer entre turnos**
> **Dado que** el tenant configuró un buffer de 10 minutos y hay un turno que termina a las 11:00,
> **cuando** se calculan los horarios disponibles,
> **entonces** el primero ofrecido es a las 11:10 o posterior.

**AC-05.3 — Horario de atención**
> **Dado que** el tenant atiende de 09:00 a 18:00,
> **cuando** pido turnos,
> **entonces** no se ofrece ningún horario fuera de esa franja, ni en días no laborables configurados.

**AC-05.4 — Lista interactiva de WhatsApp**
> **Dado que** hay más horarios disponibles que el máximo permitido por Meta en un List Message,
> **cuando** se envía la lista,
> **entonces** se muestran los primeros dentro del límite y se ofrece una opción para ver más, sin que el mensaje falle.

## Requisitos técnicos

* Consultar `POST /freeBusy` de Google Calendar con la ventana expresada en UTC y el `timeZone` del tenant (AC-05.1).
* Leer horario de atención, días laborables, duración de turno y buffer desde la configuración del tenant. ⚠️ **Bloqueante: no hay tabla.** `tenants` solo tiene `timezone` y `quote_rules` solo fórmulas de precio. Ver [US-15](US-15-configurar-reglas-del-negocio.md), que es quien debe crearla.
* Generar la grilla de slots aplicando duración + buffer sobre el horario de atención y restando los bloques `busy` devueltos (AC-05.2, AC-05.3).
* Convertir los slots de UTC a la zona del tenant **antes** de renderizarlos, contemplando el cambio de horario estacional (criterios de [US-07](US-07-hora-correcta-timezones.md)).
* Renderizar un List Message respetando el límite de filas por sección de Meta, con una opción de paginado que no haga fallar el mensaje (AC-05.4).
* Definir la respuesta cuando el resultado es vacío: hoy los cuatro criterios asumen que siempre hay algo que ofrecer. Ver [US-19](US-19-agenda-sin-disponibilidad.md).
* Verificar que el slot siga libre en el momento de confirmar: `freeBusy` al listar no garantiza disponibilidad al elegir. Ver [US-18](US-18-colision-de-horario-al-confirmar.md).
* Medir la latencia real de `freeBusy` contra el presupuesto de 1,5 s del RNF-04. ⚠️ Sin medir; si no entra, hay que cachear disponibilidad y eso cambia el diseño.
* Respetar la cuota por proyecto de la API de Calendar cuando varios tenants consulten en paralelo.

## Estimación de complejidad

**L** — Consulta `freeBusy`, configuración por tenant de horarios y buffer, filtrado con conversión horaria y renderizado de listas interactivas. Son cuatro preocupaciones distintas.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ⚠️ | Requiere el token de US-04. |
| **Negociable** | ⚠️ | El buffer de 10 minutos y el uso de `freeBusy` vienen fijados por RF-B2. |
| **Valiosa** | ✅ | Valor directo y evidente para el cliente final y para la PyME. |
| **Estimable** | ⚠️ | Sujeta a la decisión de Outlook y a la latencia real de `freeBusy`, todavía sin medir. |
| **Pequeña** | ❌ | **Son tres historias:** consultar disponibilidad, configurar horarios y buffer por tenant, y renderizar la lista en WhatsApp. Cada AC apunta casi a un ítem distinto. |
| **Testeable** | ✅ | Los cuatro criterios se verifican con un calendario de prueba. |

**Veredicto:** cumple 2 de 6. **Es la candidata más clara a partirse**, y los propios criterios de aceptación marcan dónde cortar.

## Por qué es urgente (o crítica)

Ofrecer un horario ocupado es peor que no ofrecer nada: rompe la confianza en el primer contacto y obliga al equipo a hacer justo el trabajo manual que vinimos a eliminar. Es el corazón técnico de "sin cambiar herramientas": leemos su calendario tal como lo usa hoy.

## Qué esperamos aprender

* Cuánto tarda realmente `freeBusy` y si entra en el presupuesto de 1,5 s del RNF-04, o si hay que cachear disponibilidad.
* Si el buffer fijo de 10 minutos sirve a todos los rubros o cada segmento necesita el suyo.

## De qué depende

* [US-04](US-04-conectar-google-calendar-oauth.md) — token válido.
* Configuración por tenant de horario de atención, duración de turno y buffer.
* Listas dinámicas de WhatsApp (RF-A5).
