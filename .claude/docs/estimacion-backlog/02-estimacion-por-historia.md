# Estimación consolidada por historia

La tabla del backlog con sus factores de priorización, ahora con los puntos de sus tickets sumados. Sirve para dos cosas: ver **cuánto cuesta realmente cada historia** una vez rebanada, y contrastar esa cifra contra la estimación S/M/L original.

**Factores de priorización considerados:** riesgo que mitiga · valor comercial · dependencias · salud INVEST · disponibilidad de requisito.

---

## Las 26 historias

| Orden | Historia | Riesgo que mitiga | INVEST | Est. original | **Pts** | Tickets |
| :-: | :-- | :-- | :-: | :-: | :-: | :-- |
| 1 | [US-01](../stories/US-01-handshake-webhook-meta.md) Handshake de Meta | Habilitación externa | 4/6 | S | **4** | T-001, T-008 |
| 2 | [US-02](../stories/US-02-ingesta-asincrona-rafaga.md) Ingesta asíncrona | La plataforma nos corta | 3/6 | M | **10** | T-009, T-010, T-011 |
| 3 | [US-04](../stories/US-04-conectar-google-calendar-oauth.md) OAuth Calendar | Fricción de setup | 4/6 | M | **10** | T-003, T-012, T-013 |
| 4 | [US-15](../stories/US-15-configurar-reglas-del-negocio.md) Config del negocio | Insumo de cinco historias | 3/6 | L | **12** | T-014, T-015, T-016, T-017 |
| 5 | [US-03](../stories/US-03-maquina-de-estados-conversacional.md) Máquina de estados | Coherencia del diálogo | 3/6 | L | **13** | T-018, T-019 |
| 6 | [US-16](../stories/US-16-primer-contacto-bienvenida.md) Bienvenida | Entrada del recorrido | 5/6 | S | **3** | T-021 |
| 7 | [US-05](../stories/US-05-horarios-realmente-libres.md) Horarios libres | Doble reserva | 2/6 | L | **10** | T-005, T-022, T-023 |
| 8 | [US-19](../stories/US-19-agenda-sin-disponibilidad.md) Sin disponibilidad | Abandono con agenda llena | 5/6 | S | **3** | T-024 |
| 9 | [US-21](../stories/US-21-pausar-el-bot-desde-el-panel.md) Pausa manual | **Cobertura de US-08** | 5/6 | S | **3** | T-025 |
| 10 | [US-06](../stories/US-06-reservar-y-liberar-sync-inversa.md) Reservar y liberar | Pérdida de confianza | 3/6 | L | **13** | T-026, T-027 |
| 11 | [US-23](../stories/US-23-renovacion-de-canales-watch.md) Canales *watch* | Falla silenciosa | 4/6 | S | **3** | T-028 |
| 12 | [US-18](../stories/US-18-colision-de-horario-al-confirmar.md) Colisión de horario | Doble reserva real | 4/6 | M | **5** | T-029 |
| 13 | [US-22](../stories/US-22-reserva-consistente.md) Reserva consistente | Turnos fantasma | 4/6 | M | **5** | T-030 |
| 14 | [US-07](../stories/US-07-hora-correcta-timezones.md) Timezones | Killer silencioso | 2/6 | M | **3** | T-031 |
| 15 | [US-08](../stories/US-08-pausa-intervencion-humana.md) Human takeover | Objeción de venta #1 | 2/6 | L | **11** | T-004, T-032 |
| 16 | [US-09](../stories/US-09-fallback-ante-caidas.md) Fallback | Chat colgado | 4/6 | M | **7** | T-020, T-033 |
| 17 | [US-25](../stories/US-25-estado-de-las-integraciones.md) Estado integraciones | Falla invisible | 5/6 | S | **2** | T-034 |
| 18 | [US-20](../stories/US-20-derivar-a-una-persona.md) Derivación | Promesa incumplida | 5/6 | S | **3** | T-035 |
| 19 | [US-17](../stories/US-17-registro-de-asistencia.md) Asistencia | **Mide el ROI** | 5/6 | S | **4** | T-036, T-038 |
| 20 | [US-10](../stories/US-10-recordatorio-t24h.md) Recordatorio t-24h | **El ROI que vendemos** | 3/6 | M | **7** | T-002, T-037 |
| 21 | [US-24](../stories/US-24-recordatorio-que-no-salio.md) Recordatorio fallido | Medición contaminada | 5/6 | S | **3** | T-039 |
| 22 | [US-12](../stories/US-12-cotizador-parametrico.md) Cotizador | Encaje producto-segmento | 3/6 | L | **11** | T-006, T-040, T-041 |
| 23 | [US-11](../stories/US-11-reagendar-y-aviso-t2h.md) Re-agendar + t-2h | Recuperar el turno | 0/6 | L | **8** | T-042, T-043 |
| 24 | [US-26](../stories/US-26-vincular-mi-planilla.md) Vincular planilla | "Sin cambiar herramientas" | 5/6 | S | **3** | T-044 |
| 25 | [US-13](../stories/US-13-leads-a-google-sheets.md) Leads a Sheets | "Sin cambiar herramientas" | 2/6 | S | **3** | T-045 |
| 26 | [US-14](../stories/US-14-panel-logs-y-estado.md) Panel de logs | Time-to-value | 3/6 | L | **15** | T-046, T-047, T-048, T-049 |
| — | *Transversal* | Cinco RF y cinco decisiones | — | — | **3** | T-007 |
| | | | | | **177** | **49 tickets** |

---

## Calibración: la estimación S/M/L contra los puntos

| Talla original | Historias | Rango de puntos | Promedio |
| :-: | :-: | :-: | :-: |
| **S** | 10 | 2 – 4 | **3,1** |
| **M** | 8 | 3 – 11 | **7,0** |
| **L** | 8 | 8 – 15 | **11,6** |

**La escala S/M/L era coherente**, con dos observaciones que importan:

**US-07 es el único desvío grande.** Estimada **M** y resuelta en **3 puntos**, porque dejó de ser una historia funcional y pasó a ser un ticket de calidad cuyo entregable es una suite de tests. No es que la estimación estuviera mal: es que la historia no debía existir como tal.

**El rango de M se solapa con el de L.** US-08 (L) cuesta 11 y US-12 (L) cuesta 11, contra US-02 (M) que cuesta 10. La talla gruesa no distinguía bien en la franja media, que es exactamente donde vive la mitad del backlog. Los puntos por ticket sí lo hacen.

---

## Dónde se concentra el costo

| Historia | Pts | % del total | Por qué cuesta tanto |
| :-- | :-: | :-: | :-- |
| [US-14](../stories/US-14-panel-logs-y-estado.md) Panel de logs | 15 | 8,5% | Cuatro tickets: autenticación, listado, migración de `messages` y detalle. Era la historia menos independiente del backlog |
| [US-03](../stories/US-03-maquina-de-estados-conversacional.md) Máquina de estados | 13 | 7,3% | **Once** estados, no los cuatro de RF-A3. El requisito quedó desactualizado frente a los flujos |
| [US-06](../stories/US-06-reservar-y-liberar-sync-inversa.md) Reservar y liberar | 13 | 7,3% | La sincronización inversa (8 pts) es el ticket con más riesgo de desborde del plan |
| [US-15](../stories/US-15-configurar-reglas-del-negocio.md) Config del negocio | 12 | 6,8% | Cinco dominios de configuración más la tabla que no existía |
| [US-08](../stories/US-08-pausa-intervencion-humana.md) Human takeover | 11 | 6,2% | 8 de esos 11 son provisorios: dependen de que el spike salga bien |
| [US-12](../stories/US-12-cotizador-parametrico.md) Cotizador | 11 | 6,2% | Discovery, motor seguro e integración conversacional |

**Estas seis historias son el 42% del esfuerzo total** y las seis venían marcadas como no-*Pequeñas* en la auditoría INVEST. La correlación no es casual: la falla de *Small* era un indicador honesto del costo.

## Dónde está el mejor retorno

| Historia | Pts | Qué desbloquea |
| :-- | :-: | :-- |
| [US-17](../stories/US-17-registro-de-asistencia.md) Asistencia | **4** | El único instrumento que mide la hipótesis central del negocio. 1 de esos 4 puntos es la migración |
| [US-25](../stories/US-25-estado-de-las-integraciones.md) Estado integraciones | **2** | Convierte una falla silenciosa en una visible. Solo lee un dato que ya se escribe |
| [US-21](../stories/US-21-pausar-el-bot-desde-el-panel.md) Pausa manual | **3** | Convierte el riesgo más grave del backlog (US-08, 11 pts inciertos) en uno acotado |
| [US-16](../stories/US-16-primer-contacto-bienvenida.md) Bienvenida | **3** | El primer mensaje que ve el 100% de los clientes, y la primera medición punta a punta del KPI de 15 s |
| *Transversal* T-007 | **3** | Cinco RF y cinco decisiones. Destraba cuatro historias que hoy no son estimables |

**Quince puntos —el 8% del plan— cubren el instrumento de medición del negocio, el riesgo técnico más grave y las cuatro historias no estimables.** Es el mejor retorno por punto de todo el backlog.
