# US-26 · Que los leads caigan en mi planilla, no en una suya

| | |
| :-- | :-- |
| **Sección** | 5 — Extender el valor |
| **Prioridad** | Sprint 8, antes de [US-13](US-13-leads-a-google-sheets.md) ([slices](../stories-maps/04-slices-y-secuencia.md)) |
| **Estimación** | **S** |
| **Trazabilidad** | **sin RF** · UC-2.5 de [`03-casos-de-uso-equipo-pyme.md`](../03-flujos/03-casos-de-uso-equipo-pyme.md) |
| **Origen** | Hueco [H-20](../stories-maps/05-huecos-y-trazabilidad.md#h-20) del story map |
| **Depende de** | [US-04](US-04-conectar-google-calendar-oauth.md) |
| **Bloquea a** | [US-13](US-13-leads-a-google-sheets.md) |

## Historia

> **Como** dueña de PyME que hace seguimiento comercial en una planilla,
> **quiero** elegir cuál de mis planillas recibe los leads,
> **para** que aparezcan donde mi equipo ya trabaja y no en un archivo nuevo que nadie abre.

## Criterios de aceptación

**AC-26.1 — Elegir una planilla existente**
> **Dado que** ya autoricé el acceso a Google,
> **cuando** indico cuál es mi planilla y en qué hoja quiero los leads,
> **entonces** el sistema valida que puede escribir en ella y me lo confirma.

**AC-26.2 — Empezar sin planilla**
> **Dado que** no tengo ninguna planilla preparada,
> **cuando** pido que se cree una,
> **entonces** el sistema crea una en mi Drive con las columnas correspondientes y queda vinculada.

**AC-26.3 — Permisos insuficientes**
> **Dado que** indico una planilla sobre la que no tengo permiso de escritura,
> **cuando** se valida,
> **entonces** recibo un mensaje que explica el problema y qué hacer, y la vinculación no queda a medias.

**AC-26.4 — Cambiar de planilla**
> **Dado que** ya tengo una planilla vinculada,
> **cuando** la cambio por otra,
> **entonces** los leads nuevos van a la nueva y los ya escritos no se migran ni se duplican.

## Requisitos técnicos

* Solicitar el scope `https://www.googleapis.com/auth/spreadsheets` dentro del mismo consentimiento de [US-04](US-04-conectar-google-calendar-oauth.md), para no obligar al cliente a autorizar dos veces.
* Persistir `spreadsheet_id`, nombre de hoja y rango en `integrations.settings` con `provider='google_sheets'` (AC-26.1, AC-26.4).
* Aceptar la URL de la planilla pegada por el usuario y extraer el `spreadsheet_id`, que es lo más simple y no exige scopes adicionales.
* Validar la vinculación escribiendo y borrando una fila de prueba, o leyendo la metadata de la hoja, antes de darla por buena (AC-26.1, AC-26.3).
* Crear la planilla con `spreadsheets.create` y escribir la fila de encabezados cuando el tenant no tiene una (AC-26.2).
* Traducir los errores de permiso de la API a un mensaje accionable, distinguiendo "no existe" de "no tenés acceso" (AC-26.3).
* Al cambiar de planilla, limpiar la referencia de fila que usa [US-13](US-13-leads-a-google-sheets.md) para el upsert, de modo que los leads nuevos no intenten actualizar filas de la planilla vieja (AC-26.4).
* ⚠️ **Fuera del stack declarado:** el selector visual de Google (Drive Picker) exigiría el scope de Drive y una librería de front adicional. Pegar la URL evita ambas cosas; si se quiere el picker, es una decisión de alcance y no un detalle de UI.
* ⚠️ Sin definir si una planilla puede ser compartida entre tenants. No debería, y nada lo impide hoy.

## Estimación de complejidad

**S** — Un formulario, una validación y una creación opcional. Es chica y desbloquea la mitad literal de la propuesta de valor.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ⚠️ | Necesita el consentimiento de Google y el shell del panel de US-04. |
| **Negociable** | ✅ | URL pegada o picker, planilla propia o creada, columnas: todo abierto. |
| **Valiosa** | ✅ | Sin esto, "los leads donde tu equipo ya trabaja" es falso: caen en una planilla nuestra. |
| **Estimable** | ✅ | Alcance chico y cerrado. |
| **Pequeña** | ✅ | Un día de trabajo. |
| **Testeable** | ✅ | Los cuatro criterios se verifican con una cuenta de Google de prueba. |

**Veredicto:** cumple 5 de 6.

## Por qué es urgente (o crítica)

[US-13](US-13-leads-a-google-sheets.md) se llama *"Los leads donde el equipo ya trabaja"* y **no construye la pantalla donde el equipo dice cuál es su planilla**. Menciona el scope de OAuth como dependencia y ahí termina. Sin esta historia, el volcado de leads técnicamente funciona y comercialmente no cumple: los leads caen en un archivo que creamos nosotros, que es exactamente el CRM nuevo que la PyME ya rechazó.

Es la mitad literal de la propuesta de valor —"sin obligarte a cambiar de herramientas"— y cuesta un día.

## Qué esperamos aprender

* Si los dueños tienen ya una planilla de seguimiento o hay que crearla. Si la mayoría no tiene ninguna, la premisa de "trabajan en Sheets" es más débil de lo que asume el lean canvas, y eso afecta el valor de [US-13](US-13-leads-a-google-sheets.md) entera.
* Cuántos completan la vinculación sin ayuda — insumo directo del KPI de setup en menos de 60 minutos.

## De qué depende

* [US-04](US-04-conectar-google-calendar-oauth.md) — consentimiento de Google con el scope de Sheets y shell del panel.
* Columnas de configuración en `integrations.settings`.
