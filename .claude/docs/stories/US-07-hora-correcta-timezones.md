# US-07 · La hora correcta, siempre

| | |
| :-- | :-- |
| **Sección** | 2 — Probar la promesa central |
| **Prioridad** | 7 de 14 |
| **Estimación** | **M** |
| **Trazabilidad** | RNF-02 · CA-04 |
| **Depende de** | [US-06](US-06-reservar-y-liberar-sync-inversa.md) |
| **Bloquea a** | — (transversal: toca cotizador, agenda, recordatorios y logs) |

## Historia

> **Como** cliente final que reserva desde un país distinto al del servidor,
> **quiero** que el horario que elijo sea exactamente el que queda agendado,
> **para** no presentarme a la hora equivocada y perder el turno.

## Criterios de aceptación

**AC-07.1 — Conversión en UTC-3**
> **Dado que** el tenant está configurado en `America/Argentina/Buenos_Aires`,
> **cuando** el cliente elige las 15:00,
> **entonces** la base de datos guarda `18:00 UTC` y el evento de Google Calendar muestra las 15:00 locales.

**AC-07.2 — Conversión en UTC-5**
> **Dado que** el tenant está configurado en `America/Bogota`,
> **cuando** el cliente elige las 15:00,
> **entonces** la base de datos guarda `20:00 UTC` y el evento muestra las 15:00 locales.

**AC-07.3 — Cambio de horario estacional**
> **Dado que** el tenant está en `America/Santiago`, que aplica horario de verano,
> **cuando** se agenda un turno posterior al cambio de horario,
> **entonces** la hora local mostrada al cliente y la del calendario coinciden exactamente, sin desplazamiento de una hora.

**AC-07.4 — Recordatorios en hora local**
> **Dado que** un turno tiene recordatorio programado,
> **cuando** se dispara el mensaje,
> **entonces** el texto muestra la hora en la zona local del tenant, nunca en UTC.

## Requisitos técnicos

* Almacenar todas las columnas `DATETIME` y `TIMESTAMP` en UTC (RNF-02) y convertir únicamente en el borde de salida: mensaje a WhatsApp, evento de Google, vista del panel (AC-07.1, AC-07.2).
* Fijar `APP_TIMEZONE=UTC` y no depender nunca de la zona horaria del sistema operativo del servidor.
* Leer la zona desde `tenants.timezone` y usarla en la consulta `freeBusy`, en la creación del evento y en todo texto saliente (AC-07.4).
* Usar Carbon sobre una base `tzdata` actualizada y **fijar su versión en el pipeline**: un `tzdata` viejo produce exactamente el desplazamiento de una hora que AC-07.3 prohíbe, y lo hace solo unas semanas al año.
* Convertir con la zona nombrada (`America/Santiago`), nunca con el desplazamiento fijo (`-03:00`): el desplazamiento no sabe de horario de verano.
* Suite de tests con `America/Argentina/Buenos_Aires`, `America/Bogota` y `America/Santiago`, incluyendo al menos una fecha posterior al cambio estacional (AC-07.1 a AC-07.3).
* Replicar estos requisitos **dentro** de [US-05](US-05-horarios-realmente-libres.md), [US-06](US-06-reservar-y-liberar-sync-inversa.md), [US-10](US-10-recordatorio-t24h.md) y [US-11](US-11-reagendar-y-aviso-t2h.md) en vez de tratarlos como un ítem que se termina.
* ⚠️ Una sola zona por tenant. Un cliente con sucursales en dos husos rompe el modelo antes de lo previsto, y eso anticiparía el OUT-OF-SCOPE de multi-sucursal.

## Estimación de complejidad

**M** — La lógica es acotada, pero la superficie afectada es amplia: toda escritura y toda lectura de fecha en el sistema.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ❌ | Es transversal por naturaleza: no es un incremento separable, es una propiedad que deben cumplir las demás historias. |
| **Negociable** | ❌ | Prescribe la solución (almacenar en UTC, convertir en el borde) en lugar del problema. |
| **Valiosa** | ⚠️ | Su valor es la **ausencia de un defecto**. Ningún usuario la pide; todos la sufren si falta. |
| **Estimable** | ✅ | La lógica de conversión es conocida y acotada. |
| **Pequeña** | ⚠️ | Chica en código, enorme en superficie de verificación. |
| **Testeable** | ✅ | Los criterios son los más precisos del backlog: husos concretos y un caso de cambio de horario real. |

**Veredicto:** cumple 2 de 6, y **la conclusión es que no debería ser una historia.** Como ítem de backlog invita a que alguien la marque "hecha" en un sprint y después se viole en silencio en cada historia nueva.

> **Mejora recomendada:** convertir estos cuatro criterios en **criterios de aceptación transversales** que se repiten en US-05, US-06, US-10 y US-11, más una suite de tests dedicada. Que sea imposible de "terminar" es exactamente el punto.

## Por qué es urgente (o crítica)

Es el error más caro del producto porque es **silencioso**: nadie lo detecta hasta que un paciente llega tres horas tarde. Y como es transversal, arreglarlo después obliga a revisar todo lo construido.

## Qué esperamos aprender

Si el modelo de una sola zona horaria por tenant aguanta, o si vamos a chocar antes de lo previsto con clientes que operan en más de un huso — lo cual anticiparía el OUT-OF-SCOPE de múltiples sucursales.

## De qué depende

* [US-06](US-06-reservar-y-liberar-sync-inversa.md) — turnos reales que verificar.
* Campo de zona horaria en `tenants`.
* Disciplina de UTC en todas las escrituras (RNF-02).
