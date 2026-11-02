# T-035 · Derivación a una persona

| | |
| :-- | :-- |
| **Prioridad** | 35 |
| **Sprint** | 5 |
| **Tipo** | Feature |
| **Historia** | [US-20](../../stories/US-20-derivar-a-una-persona.md) |
| **Story points** | 3 |
| **Depende de** | T-018, T-024, T-025 |
| **Bloquea a** | — |
| **Pareto** | **DIFERIDO** — fuera del MVP recortado |

> **Fuera del núcleo Pareto — DIFERIDO.** La válvula de escape ya existe entre T-025 (pausa manual) y T-033 (fallback); esta es la versión pulida. Se retoma en la tercera release. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Contexto

**Tres caminos distintos del flujo terminan en "un asesor te va a atender" y ninguno le avisa al asesor:** valor fuera de rango (AC-12.4), agenda llena (T-024) y pregunta libre que el bot no entiende.

Desde el lado del cliente la promesa se hizo. Desde el lado de la PyME no pasó nada. Y en los tres casos se activa con clientes que **querían comprar**.

## Alcance

* Marca de derivación en la conversación con motivo y hora.
* Silenciamiento del bot al derivar, reusando la llave de pausa de T-025.
* Lista de derivaciones pendientes en el panel, por antigüedad.
* Acción de resolver que devuelve la conversación al flujo automático.
* Umbral de intentos fallidos que dispara la derivación por entrada no reconocida.

## Criterios de aceptación

- [ ] Al derivar por cualquiera de los tres motivos, la conversación queda marcada con el motivo y la hora *(AC-20.1)*.
- [ ] Las conversaciones derivadas aparecen en el panel como pendientes, ordenadas por antigüedad *(AC-20.2)*.
- [ ] Mientras está derivada, el bot no intenta retomar el flujo automático *(AC-20.3)*.
- [ ] Una segunda condición de derivación sobre la misma conversación no genera un pendiente duplicado ni repite el mensaje *(AC-20.4)*.
- [ ] Tras N intentos con entrada no reconocida, la conversación se deriva en vez de repetir el recordatorio del paso.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ✅ | ✅ | ✅ | ✅ | ✅ |

**5 de 6.** Cierra dos huecos del story map con una sola pieza porque los dos terminan en el mismo lugar. **I** en ⚠️ por necesitar al menos uno de los tres orígenes construido.

## Fuera de alcance

La bandeja de atención con respuesta desde el panel.

⚠️ **La razón que daba este bullet era falsa.** Decía *"en el MVP el equipo responde desde WhatsApp, que es donde ya trabaja"*, y **ahí ya no trabaja nadie**: el número del bot no se puede usar como chat humano.

Sigue fuera de alcance, pero por otra razón: se eligió el **modelo de dos números** (RF-A4). El bot deriva diciendo a qué número escribir, y las personas atienden ahí con la app normal. ⚠️ **El costo aceptado es que el cliente tiene que cambiar de chat y volver a explicar su caso, y una parte no lo va a hacer** — cuántos se pierden es lo primero que el piloto tiene que medir.

## Notas y riesgos

⚠️ **Decisión abierta — aviso pasivo o activo.** El panel exige que alguien lo abra; avisar por WhatsApp consume una plantilla aprobada. Mismo dilema que T-039; resolver una vez para las dos.

⚠️ Sin definir qué pasa con una derivación que nadie atiende.
