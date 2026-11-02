# T-033 · Fallback graceful ante caídas de terceros

| | |
| :-- | :-- |
| **Prioridad** | 33 |
| **Sprint** | 5 |
| **Tipo** | Feature |
| **Historia** | [US-09](../../stories/US-09-fallback-ante-caidas.md) |
| **Story points** | 5 |
| **Depende de** | T-017, T-020, T-026 |
| **Bloquea a** | T-034, T-039 |
| **Pareto** | **NÚCLEO** · ola 4 · con recorte, ver *Fuera de alcance* |

## Contexto

**Un chat que se queda mudo es una venta perdida y una queja garantizada.** La resiliencia no es una mejora posterior: es la condición para poner esto en un número real con clientes reales. Sin ella, cualquier caída de un tercero se lee como "el sistema no anda".

## Alcance

* Manejador que convierte fallos de terceros en resultados tipados en vez de excepciones.
* Mensaje de cortesía configurable por tenant.
* Backoff exponencial con jitter ante rate limit de Meta.
* Marca de integración vencida ante credenciales revocadas.
* Simuladores de falla para pruebas.

## Criterios de aceptación

- [ ] Con Google Calendar devolviendo `5xx`, el cliente recibe el mensaje de cortesía del tenant y la excepción queda registrada con `tenant_id`, timestamp e integración *(AC-09.1)*.
- [ ] Con el `refresh_token` revocado, se envía el fallback y la integración queda marcada para reconectar *(AC-09.2)*.
- [ ] Ante rate limit de Meta se reintenta con backoff exponencial, y solo al agotar reintentos se registra el fallo definitivo *(AC-09.3)*.
- [ ] **Ningún camino del worker termina en silencio:** ante cualquiera de los fallos anteriores el cliente siempre recibe un mensaje *(AC-09.4)*.
- [ ] Los tres modos de falla se pueden simular desde el entorno de pruebas.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ✅ | ✅ | ✅ | ⚠️ | ✅ |

**4 de 6.** *Estimable* pasó a ✅ porque enumerar los modos de falla concretos en los criterios convirtió un alcance abierto —"cualquier excepción"— en uno cerrado. **S** sigue en ⚠️: toca varias integraciones, pero los cuatro criterios le ponen borde.

## Fuera de alcance

Mostrar el estado de la integración caída en el panel (T-034) y el manejo de recordatorios fallidos (T-039).

**Recorte Pareto.** Se difieren los simuladores de falla del entorno de pruebas y el backoff con jitter fino ante rate limit *(AC-09.3)*: alcanza con el reintento estándar de la cola de T-011, y los tres modos de falla comparten un solo patrón. **La garantía de *(AC-09.4)* —ningún camino del worker termina en silencio— no se recorta.** Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Notas y riesgos

⚠️ **Sin definir si el fallback escala activamente a un operador o solo informa al cliente.** Se cruza con la misma decisión de T-035 y T-039; conviene resolverla una vez para las tres.
