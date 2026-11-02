# US-25 · Ver si una integración se cayó

| | |
| :-- | :-- |
| **Sección** | 6 — Que el cliente arranque y se sostenga solo |
| **Prioridad** | Sprint 5, pegada a [US-09](US-09-fallback-ante-caidas.md) ([slices](../stories-maps/04-slices-y-secuencia.md)) |
| **Estimación** | **S** |
| **Trazabilidad** | **sin RF** · completa AC-09.2 · `integrations.status='expired'` |
| **Origen** | Hueco [H-21](../stories-maps/05-huecos-y-trazabilidad.md#h-21) del story map |
| **Depende de** | [US-04](US-04-conectar-google-calendar-oauth.md) · [US-09](US-09-fallback-ante-caidas.md) |
| **Bloquea a** | — |

## Historia

> **Como** dueña de PyME,
> **quiero** ver en el panel que una integración dejó de funcionar,
> **para** enterarme antes de que me lo diga un cliente que no pudo sacar turno.

## Criterios de aceptación

**AC-25.1 — Estado visible de cada integración**
> **Dado que** entro al panel,
> **cuando** abro la sección de integraciones,
> **entonces** veo el estado de Google Calendar, Google Sheets y Meta WhatsApp, con la cuenta vinculada y la fecha de la última operación exitosa.

**AC-25.2 — Una caída se ve sin buscarla**
> **Dado que** una integración quedó marcada como vencida,
> **cuando** entro a cualquier pantalla del panel,
> **entonces** aparece un aviso visible que indica qué se rompió y qué consecuencia tiene, no un código de error.

**AC-25.3 — El aviso se limpia solo**
> **Dado que** la integración vuelve a funcionar,
> **cuando** se completa una operación exitosa,
> **entonces** el estado vuelve a normal y el aviso desaparece sin intervención manual.

**AC-25.4 — Qué hacer al respecto**
> **Dado que** veo una integración caída,
> **cuando** leo el aviso,
> **entonces** me indica el paso siguiente concreto, aunque en el MVP ese paso sea contactarnos.

## Requisitos técnicos

* Endpoint que exponga `integrations.status`, `provider`, `account_identifier` y `expires_at` por tenant, con el aislamiento del panel (AC-25.1).
* Registrar la fecha de la última operación exitosa por integración. ⚠️ No hay columna: `integrations` tiene `updated_at`, que cambia por cualquier motivo y no distingue éxito de escritura.
* Consumir la marca `status='expired'` que escriben [US-09](US-09-fallback-ante-caidas.md) (AC-09.2) y [US-23](US-23-renovacion-de-canales-watch.md) (AC-23.3); esta historia solo lee, no detecta (AC-25.2).
* Volver el estado a `connected` ante la primera operación exitosa posterior, para que el aviso no requiera limpieza manual (AC-25.3).
* Traducir el motivo técnico a una consecuencia entendible: "no podemos leer tu agenda, los clientes no ven horarios" en vez de `invalid_grant` (AC-25.2, AC-25.4).
* Mostrar el aviso de forma persistente en el layout del panel, no solo en la pantalla de integraciones (AC-25.2).
* ⚠️ **El flujo de reconexión es v2.** En el MVP el paso siguiente de AC-25.4 es contactarnos: reconectamos nosotros. Conviene que el texto lo diga con todas las letras en vez de ofrecer un botón que no existe.

## Estimación de complejidad

**S** — Una consulta, un componente de aviso y la transición de vuelta a `connected`. Todo el trabajo de detección ya está hecho en US-09 y US-23.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ⚠️ | Necesita que alguien escriba el estado: US-04 lo crea y US-09 lo marca. |
| **Negociable** | ✅ | Ubicación del aviso, nivel de detalle y textos están abiertos. |
| **Valiosa** | ✅ | Convierte una falla silenciosa en una falla visible. Es la diferencia entre que nos llame el dueño y que nos llame su cliente. |
| **Estimable** | ✅ | Alcance cerrado y sin incógnitas. |
| **Pequeña** | ✅ | Un día de trabajo. |
| **Testeable** | ✅ | Se prueba revocando credenciales desde la consola de Google. |

**Veredicto:** cumple 5 de 6.

## Por qué es urgente (o crítica)

AC-09.2 de [US-09](US-09-fallback-ante-caidas.md) dice *"marca en el panel que hay que reconectar la cuenta"* y el modelo de datos ya admite `integrations.status='expired'`. **El dato se escribe y nadie construyó dónde leerlo.** Es un circuito abierto de una sola pieza.

Sin esto, una credencial vencida se manifiesta como un producto que dejó de funcionar sin explicación: el bot manda el mensaje de cortesía a todos, la PyME no entiende por qué, y la primera señal llega por un cliente enojado. Con el aviso, la misma falla se lee como "hay que reconectar" — que es un problema chico y resoluble.

Es además una de las dos piezas mínimas de observabilidad —junto con [US-24](US-24-recordatorio-que-no-salio.md)— que hacen innecesaria una llamada nuestra, y ahí es donde se defiende el margen de la tarifa plana.

## Qué esperamos aprender

* Con qué frecuencia se vencen o revocan los `refresh_token` en la práctica — el dato que decide si la reconexión autoservicio puede esperar a v2 o hay que adelantarla.
* Si el dueño reacciona al aviso pasivo o hace falta escribirle. Se cruza con la misma decisión de [US-20](US-20-derivar-a-una-persona.md) y [US-24](US-24-recordatorio-que-no-salio.md).

## De qué depende

* [US-04](US-04-conectar-google-calendar-oauth.md) — la integración y su estado inicial.
* [US-09](US-09-fallback-ante-caidas.md) (AC-09.2) y [US-23](US-23-renovacion-de-canales-watch.md) (AC-23.3) — quienes marcan la caída.
* Shell del panel con autenticación.
