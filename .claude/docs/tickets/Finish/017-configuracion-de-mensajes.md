# T-017 · Configuración de mensajes del bot

| | |
| :-- | :-- |
| **Prioridad** | 17 |
| **Sprint** | 2 |
| **Tipo** | Feature |
| **Historia** | [US-15](../../stories/US-15-configurar-reglas-del-negocio.md) |
| **Story points** | 2 |
| **Depende de** | T-014 |
| **Bloquea a** | T-021, T-024, T-033 |
| **Pareto** | **NÚCLEO** · ola 2 · con recorte, ver *Fuera de alcance* |

## Contexto

RNF-03 exige un mensaje de cortesía *"predeterminado"* y US-16 promete una bienvenida con el nombre del negocio. Los dos textos son la voz del cliente frente a su propio cliente, y hoy estarían escritos en el código.

## Alcance

* Edición del texto de bienvenida.
* Edición del texto de fallback ante caídas.
* Edición del texto de "no hay disponibilidad".
* Aplicación inmediata en la conversación siguiente.

## Criterios de aceptación

- [ ] Editados los textos de bienvenida y de fallback, el bot usa los nuevos a partir de la conversación siguiente *(AC-15.5)*.
- [ ] Los textos admiten las variables del negocio (nombre, días de atención) y se rechaza una variable inexistente.
- [ ] Un texto vacío cae al valor por defecto en vez de enviar un mensaje en blanco.
- [ ] El cambio no requiere reiniciar el worker ni desplegar.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

**Limpio en los seis.** Es el resultado de partir US-15 en tres: el dominio de mensajes es independiente de agenda y precios, chico, negociable en su totalidad y verificable cambiando un texto y mirando el chat.

## Fuera de alcance

El editor visual de flujos conversacionales, que el PRD declara fuera del MVP.

**Recorte Pareto.** Se difiere el sistema de variables del negocio con validación de variable inexistente: el nombre del negocio se interpola desde `tenants`, y un motor de variables genérico es flexibilidad especulativa con un solo caller. Los tres textos editables y la caída al valor por defecto de T-014 sí se construyen. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Notas y riesgos

Ninguno significativo. Es el ticket más barato del sprint 2 y desbloquea tres.
