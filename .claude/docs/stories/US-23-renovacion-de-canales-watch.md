# US-23 · Que la sincronización no se muera en silencio

| | |
| :-- | :-- |
| **Sección** | 2 — Probar la promesa central |
| **Prioridad** | Sprint 4, pegada a [US-06](US-06-reservar-y-liberar-sync-inversa.md) ([slices](../stories-maps/04-slices-y-secuencia.md)) |
| **Estimación** | **S** |
| **Trazabilidad** | **sin RF** · dependencia declarada en [US-06](US-06-reservar-y-liberar-sync-inversa.md) sin criterio de aceptación |
| **Origen** | Hueco [H-17](../stories-maps/05-huecos-y-trazabilidad.md#h-17) del story map |
| **Depende de** | [US-06](US-06-reservar-y-liberar-sync-inversa.md) |
| **Bloquea a** | — |

## Historia

> **Como** dueña de PyME que borra turnos desde el celular,
> **quiero** que eso siga liberando horarios el mes que viene igual que hoy,
> **para** no descubrir un día que el sistema dejó de escuchar mi calendario sin avisarme.

## Criterios de aceptación

**AC-23.1 — Renovación antes del vencimiento**
> **Dado que** un canal de notificaciones de Google está próximo a expirar,
> **cuando** corre la tarea de mantenimiento,
> **entonces** se registra un canal nuevo antes de que el anterior venza, sin ventana en la que el calendario quede sin escuchar.

**AC-23.2 — Detección de canal caído**
> **Dado que** un canal expiró o fue revocado sin renovarse,
> **cuando** corre la verificación,
> **entonces** el sistema lo detecta, intenta recrearlo y deja registro del hueco de escucha.

**AC-23.3 — Alerta cuando no se puede renovar**
> **Dado que** la renovación falla por credenciales vencidas,
> **cuando** se agotan los reintentos,
> **entonces** la integración queda marcada como caída en el panel y no se sigue intentando en vano.

**AC-23.4 — Baja al desconectar**
> **Dado que** un tenant desconecta su Google Calendar,
> **cuando** se procesa la desconexión,
> **entonces** los canales se dan de baja y dejan de llegar notificaciones huérfanas a nuestro endpoint.

## Requisitos técnicos

* Persistir por integración el `channel_id`, el `resource_id` y la fecha de expiración devueltos por `events.watch`. ⚠️ **No hay columnas:** `integrations` no contempla el canal. Candidato: `integrations.settings`, sin decidir.
* Tarea programada que renueve los canales con margen sobre su expiración —Google los caduca en el orden de días— y registre canal nuevo antes de dar de baja el viejo (AC-23.1).
* Verificación periódica que detecte canales sin renovar y los recree, dejando registro de la ventana en la que no hubo escucha (AC-23.2).
* Llamar a `channels.stop` con `channel_id` y `resource_id` al desconectar la integración o al renovar (AC-23.4).
* Validar el token del canal en cada notificación entrante, para descartar las que no correspondan a un canal vigente nuestro.
* Marcar `integrations.status='expired'` cuando la renovación falle por `invalid_grant`, reusando el mecanismo de [US-09](US-09-fallback-ante-caidas.md) y mostrándolo en [US-25](US-25-estado-de-las-integraciones.md) (AC-23.3).
* ⚠️ Sin definir si conviene un *polling* de respaldo para el período en que un canal estuvo caído. [US-06](US-06-reservar-y-liberar-sync-inversa.md) ya declara esa incertidumbre y tampoco la resuelve.

## Estimación de complejidad

**S** — Una tarea programada, dos llamadas a la API y unas columnas nuevas. Es chica y de las que se olvidan hasta que fallan.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ⚠️ | No existe sin la sincronización inversa de US-06. |
| **Negociable** | ✅ | El margen de renovación, la frecuencia de verificación y el respaldo por polling están abiertos. |
| **Valiosa** | ⚠️ | Valor por ausencia de defecto: sostiene una funcionalidad existente en vez de agregar una nueva. |
| **Estimable** | ✅ | La API de canales está documentada y su vencimiento es conocido. |
| **Pequeña** | ✅ | Uno o dos días. |
| **Testeable** | ✅ | Se prueba forzando la fecha de expiración y observando que el canal se recree. |

**Veredicto:** cumple 4 de 6.

## Por qué es urgente (o crítica)

**Un canal caducado no falla ruidosamente: simplemente deja de avisar.** No hay error, no hay excepción, no hay log. El sistema sigue funcionando de forma aparentemente normal y los turnos que la PyME borra a mano dejan de liberarse, uno por uno, hasta que alguien nota que la agenda tiene huecos que el bot no ofrece.

Y el que deja de funcionar es justo el diferencial: [US-06](US-06-reservar-y-liberar-sync-inversa.md) identifica la sincronización inversa como *"la parte que casi todos los competidores hacen mal"*, y el argumento entero de "seguí usando tu calendario" descansa sobre ella. Perderla en silencio a las pocas semanas del alta es peor que no haberla prometido.

La historia existe porque US-06 declara la renovación en sus dependencias y **ningún criterio de aceptación la verifica**: es exactamente el tipo de trabajo que se cae de una estimación.

## Qué esperamos aprender

* Cuánto duran realmente los canales de Google en la práctica, y si el margen de renovación elegido alcanza.
* Con qué frecuencia se cae un canal por causas ajenas a la expiración —revocación, cambio de permisos— que la renovación programada no cubre.

**Señal de éxito:** cero huecos de escucha registrados durante los pilotos, y ninguna liberación de turno perdida.

## De qué depende

* [US-06](US-06-reservar-y-liberar-sync-inversa.md) — los canales que hay que renovar.
* Columnas para el canal en `integrations`.
* Tarea programada del scheduler.
