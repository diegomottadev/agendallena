# T-009 · Migración: índices compuestos de `conversations` y `bookings`

| | |
| :-- | :-- |
| **Prioridad** | 9 |
| **Sprint** | 1 |
| **Tipo** | Migración |
| **Historia** | sostiene [US-02](../../stories/US-02-ingesta-asincrona-rafaga.md), [US-10](../../stories/US-10-recordatorio-t24h.md), [US-17](../../stories/US-17-registro-de-asistencia.md) |
| **Story points** | 2 |
| **Depende de** | — |
| **Bloquea a** | T-010, T-037, T-038 |
| **Pareto** | **NÚCLEO** · ola 1 |

## Contexto

`04-modelo-de-datos.md` afirma estar *"diseñado con índices compuestos específicos para garantizar tiempos de consulta inferiores a 15 ms"* y **su propio DDL no define ninguno**: solo claves primarias, dos únicos y los índices implícitos de las foráneas. El RNF-04 descansa sobre índices que no existen.

Va en el sprint 1 por una razón operativa: agregar un índice junto con la tabla es una línea; retrofitearlo sobre una tabla con datos es una migración con ventana de indisponibilidad.

## Alcance

* `conversations(tenant_id, user_phone)` — resolución de conversación en cada webhook, camino crítico del RNF-04.
* `bookings(tenant_id, start_time, status)` — consulta del scheduler cada 10 minutos y de turnos pendientes de marcar.
* `bookings(tenant_id, external_event_id)` — resolución de la notificación entrante de Google.
* `notification_logs(booking_id, type)` único — candado contra recordatorios duplicados.
* Corregir el texto del documento de modelo de datos para que coincida con el DDL.

## Criterios de aceptación

- [ ] Los cuatro índices existen en la migración y en el esquema generado.
- [ ] El plan de ejecución (`EXPLAIN`) de la consulta del scheduler usa el índice y no escanea la tabla.
- [ ] El plan de la resolución de conversación por teléfono usa el índice.
- [ ] El único de `notification_logs` impide insertar dos recordatorios del mismo tipo para el mismo turno.
- [ ] `04-modelo-de-datos.md` ya no afirma tener índices que no define.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ✅ | ⚠️ | ⚠️ | ✅ | ✅ | ✅ |

**N/V:** los índices los dicta el patrón de consulta, y su valor es de plataforma, no de usuario. **Enabler**, igual que T-008. Se corrigió su *Testeable*: en vez de "que sea rápido", los criterios verifican el plan de ejecución, que es binario.

## Fuera de alcance

Optimización de otras consultas. Solo los cuatro caminos que las historias del MVP recorren.

## Notas y riesgos

⚠️ El único de `notification_logs(booking_id, type)` es también el mecanismo de AC-10.4. Si se decide permitir reenvíos manuales (T-039), hay que revisar si el único debe incluir un número de intento.
