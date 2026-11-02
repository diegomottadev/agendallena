# T-010 · Ingesta asíncrona con validación de firma

| | |
| :-- | :-- |
| **Prioridad** | 10 |
| **Sprint** | 1 |
| **Tipo** | Enabler |
| **Historia** | [US-02](../../stories/US-02-ingesta-asincrona-rafaga.md) |
| **Story points** | 5 |
| **Depende de** | T-008, T-009 |
| **Bloquea a** | T-018 |
| **Pareto** | **NÚCLEO** · ola 1 · con recorte, ver *Fuera de alcance* |

## Contexto

Meta reintenta y luego **desactiva** las suscripciones que responden lento. Es una falla que no aparece en desarrollo con un mensaje por vez y que se manifiesta justo el día que el cliente tiene volumen real.

## Alcance

* Endpoint `POST /api/v1/webhooks/whatsapp` que responda `200` antes de procesar.
* Validación de `X-Hub-Signature-256` sobre el cuerpo crudo, con comparación en tiempo constante.
* Encolado de `ProcessMessageJob` en Redis con el payload íntegro.
* Arnés de carga sintética con webhooks firmados.

## Criterios de aceptación

- [ ] Con 50 webhooks concurrentes, el p95 del endpoint se mantiene bajo 200 ms y los 50 quedan encolados *(AC-02.1)*.
- [ ] Un payload con firma inválida recibe `401`, no encola nada y deja registro del intento *(AC-02.2)*.
- [ ] El cuerpo crudo se lee antes de cualquier parseo de Laravel: la firma valida sobre el byte exacto que Meta envió.
- [ ] Ninguna lógica de negocio se ejecuta dentro del ciclo del request.
- [ ] El arnés de carga es reproducible y queda en el repositorio.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ⚠️ | ✅ | ✅ | ✅ | ✅ |

**Corrección aplicada:** US-02 fallaba *Pequeña* porque mezclaba firma, encolado y resiliencia. Este ticket toma firma + encolado; la resiliencia de la cola sale a T-011. **N** sigue en ⚠️: RF-A2 y RNF-04 prescriben Laravel Queues, Redis y el umbral de 200 ms, y eso no es negociable desde el ticket.

## Fuera de alcance

Idempotencia, reintentos y comportamiento con el worker caído: T-011.

**Recorte Pareto.** Se difiere el arnés de carga sintética con 50 webhooks concurrentes *(AC-02.1)*: un piloto de una PyME no genera esa concurrencia, y el p95 se verifica con la instrumentación de T-021 sobre tráfico real. **La validación de firma no se recorta** — es piso de seguridad. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Notas y riesgos

⚠️ **Decisión abierta:** si la validación HMAC corre en el request o se difiere al worker. Afecta el presupuesto de 200 ms y hoy no está medida. Resolver con el resultado del arnés dentro de este mismo ticket.
