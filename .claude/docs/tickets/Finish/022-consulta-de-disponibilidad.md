# T-022 · Consulta de disponibilidad: `freeBusy` y grilla de slots

| | |
| :-- | :-- |
| **Prioridad** | 22 |
| **Sprint** | 3 |
| **Tipo** | Feature |
| **Historia** | [US-05](../../stories/US-05-horarios-realmente-libres.md) |
| **Story points** | 5 |
| **Depende de** | T-005, T-012, T-015 |
| **Bloquea a** | T-023, T-026, T-029 |
| **Pareto** | **NÚCLEO** · ola 3 |

## Contexto

Es el corazón técnico de "sin cambiar tus herramientas": leemos el calendario del cliente tal como lo usa hoy. Ofrecer un horario ocupado es peor que no ofrecer nada — rompe la confianza en el primer contacto y obliga al equipo a hacer justo el trabajo manual que vinimos a eliminar.

## Alcance

* Consulta `POST /freeBusy` con ventana en UTC y `timeZone` del tenant.
* Generación de la grilla aplicando horario de atención, duración y buffer.
* Resta de los bloques ocupados devueltos por Google.
* Conversión a la zona local del tenant.

## Criterios de aceptación

- [ ] Con un evento de 10:00 a 11:00 en el calendario, ningún horario ofrecido se superpone con esa franja *(AC-05.1)*.
- [ ] Con buffer de 10 minutos y un turno que termina 11:00, el primer horario ofrecido es 11:10 o posterior *(AC-05.2)*.
- [ ] Con atención de 09:00 a 18:00, no se ofrece ningún horario fuera de esa franja ni en días no laborables *(AC-05.3)*.
- [ ] Un horario bloqueado a mano por la PyME en su calendario deja de ofrecerse *(cubre UC-2.8)*.
- [ ] La latencia de la consulta se mantiene dentro del presupuesto acordado en T-005.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ⚠️ | ✅ | ✅ | ✅ | ✅ |

**Corrección aplicada:** US-05 era la peor de *Pequeña* del backlog (2/6) porque mezclaba tres cosas: consultar disponibilidad, configurar horarios por tenant y renderizar la lista. La configuración salió a T-015, el renderizado a T-023 y este ticket queda con una sola preocupación. **E** pasó a ✅ con el resultado de T-005. **N** sigue en ⚠️: RF-B2 fija `freeBusy` y el buffer de 10 minutos.

## Fuera de alcance

El renderizado de la lista (T-023), el caso sin disponibilidad (T-024) y la colisión al confirmar (T-029).

## Notas y riesgos

⚠️ Si T-005 concluye que hace falta caché, este ticket crece y aparece una dependencia nueva: invalidar el caché con las notificaciones de T-027, o el sistema vuelve a ofrecer horarios ocupados.
