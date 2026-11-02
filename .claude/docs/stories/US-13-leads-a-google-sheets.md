# US-13 · Los leads donde el equipo ya trabaja

| | |
| :-- | :-- |
| **Sección** | 5 — Extender el valor |
| **Prioridad** | 13 de 14 |
| **Estimación** | **S** (una vez escrito el RF que hoy falta) |
| **Trazabilidad** | **sin RF en el PRD** · validado por CA-02 |
| **Depende de** | [US-03](US-03-maquina-de-estados-conversacional.md) · [US-26](US-26-vincular-mi-planilla.md) |
| **Bloquea a** | — |

## Historia

> **Como** dueña de PyME que hace seguimiento comercial en una planilla,
> **quiero** que las consultas nuevas aparezcan solas en mi Google Sheet,
> **para** trabajar con la herramienta que mi equipo ya usa todos los días, sin aprender un CRM nuevo.

## Criterios de aceptación

> ⚠️ Los tres criterios de abajo son una **propuesta**, no una definición acordada: este requisito no tiene RF en el PRD. Hay que validarlos antes de comprometerlos.

**AC-13.1 — Alta del lead**
> **Dado que** un cliente completó sus datos en el chat,
> **cuando** la conversación sale de `GATHERING_PARAMS`,
> **entonces** se agrega una fila a la planilla con fecha, teléfono, nombre, monto cotizado y estado.

**AC-13.2 — Actualización sin duplicar**
> **Dado que** ese mismo lead termina reservando un turno,
> **cuando** se confirma la reserva,
> **entonces** su fila existente se actualiza con la fecha del turno y el enlace al evento, sin crear una fila nueva.

**AC-13.3 — Falla de Sheets no bloquea el chat**
> **Dado que** la API de Google Sheets devuelve un error,
> **cuando** se intenta escribir la fila,
> **entonces** se reintenta en segundo plano y la conversación con el cliente continúa sin interrupción.

## Requisitos técnicos

* Obtener el scope `https://www.googleapis.com/auth/spreadsheets`, idealmente dentro del mismo consentimiento de [US-04](US-04-conectar-google-calendar-oauth.md) para no pedir dos autorizaciones.
* Guardar `spreadsheet_id`, hoja y rango por tenant en `integrations.settings` con `provider='google_sheets'`. La pantalla donde el dueño elige su planilla es [US-26](US-26-vincular-mi-planilla.md).
* Usar `spreadsheets.values.append` para el alta del lead (AC-13.1) y `values.update` para la actualización posterior (AC-13.2).
* Localizar la fila a actualizar sin releer la planilla entera: guardar el número de fila o una clave estable devuelta por el `append`. ⚠️ No hay columna donde persistir esa referencia.
* Ejecutar todo en un job de cola (`AppendToSheetsJob`) con reintentos y backoff; nunca en el camino síncrono de la conversación (AC-13.3).
* Tolerar que el dueño edite o reordene la planilla a mano: es su archivo, no nuestro almacenamiento.
* ⚠️ **Sin RF en el PRD.** Quedan sin definir las columnas, el momento exacto de escritura y si es una planilla por tenant o una por rubro. Los tres criterios de aceptación son una propuesta, no una definición acordada. Es media hora de escritura y convierte la historia en estimable.
* ⚠️ **Conflicto con AC-13.1:** escribe el nombre al salir de `GATHERING_PARAMS`, pero según los flujos y la secuencia e2e el nombre se pide recién en `SLOT_SELECTED`. Si vale esa versión, cada lead se escribe con el campo vacío. Hay que cerrar la decisión antes de implementar.

## Estimación de complejidad

**S** — Una llamada de append y una de update, más reintentos. Es de las historias más chicas del backlog **siempre que el RF cierre el alcance**; sin él, "volcar leads" puede significar cualquier cosa.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ⚠️ | Necesita los datos capturados por US-03. |
| **Negociable** | ✅ | Columnas, formato y momento de escritura están todos abiertos. |
| **Valiosa** | ⚠️ | **La propia historia duda de su valor:** puede ser un pilar de la propuesta o algo que se pide en la demo y después nadie abre. |
| **Estimable** | ❌ | **No hay RF.** ¿Qué columnas? ¿Append o upsert? ¿Una planilla por tenant o una por rubro? Los AC de arriba son suposición nuestra. |
| **Pequeña** | ✅ | Acotada por naturaleza. |
| **Testeable** | ⚠️ | CA-02 la menciona pero no define qué significa "registrado". |

**Veredicto:** cumple 2 de 6, con una anomalía de proceso que vale señalar: **existe un criterio de aceptación en el PRD (CA-02) que valida una funcionalidad que ningún requisito define.**

> **Mejora recomendada:** escribir el RF antes de estimarla. Es media hora de trabajo y convierte una historia no estimable en una **S** de uno o dos días.

## Por qué es urgente (o crítica)

Es la prueba literal de "sin obligarte a cambiar de herramientas", que es la mitad de nuestra propuesta de valor y el diferencial frente a los CRMs que la PyME ya rechazó.

## Qué esperamos aprender

Si el equipo realmente abre la planilla —medible por ediciones sobre las filas que escribimos nosotros— o si es una funcionalidad de demo. Ese dato define si Sheets es un pilar o un adorno recortable.

## De qué depende

* [US-03](US-03-maquina-de-estados-conversacional.md) — datos del lead capturados.
* **Redacción del RF faltante.**
* Alcance de OAuth para Sheets, idealmente pedido en el mismo consentimiento de [US-04](US-04-conectar-google-calendar-oauth.md).
