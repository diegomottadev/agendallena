# AgendaLlena

Middleware de agendamiento y cotización por WhatsApp para PyMEs latinoamericanas de 3 a 20 empleados. Stack: **Laravel 13 + PHP 8.4 + MySQL 8.4 + Redis 7 + Vue 3**, integrado con Meta Cloud API, Google Calendar y Google Sheets.

**Todo PHP corre dentro del contenedor** (`docker compose exec -T app php ...`). El `php` del PATH de Windows es 7.4 y el proyecto exige 8.4: usarlo aborta en el `platform_check` de Composer y hace que el IDE marque errores de sintaxis falsos sobre código válido de 8.x. El `Dockerfile` fija además `opcache.enable_cli=1` y `opcache.validate_timestamps=0`.

⚠️ **Eso NO obliga a reiniciar para correr tests, y durante meses se creyó que sí.** Medido el 2026-08-21 en las dos direcciones —verde → rojo → verde editando el código sin reiniciar nada—: `docker compose exec app php artisan test` **ve el cambio al instante**, porque cada `exec` es un proceso nuevo y el opcache del CLI muere con él.

Lo que **sí** hay que reiniciar es lo que corre en un proceso largo, que retiene el bytecode en memoria compartida: **`artisan serve` (el contenedor `app`), el `worker` y el `scheduler`**. O sea, `docker compose restart app worker scheduler` hace falta para probar a mano por HTTP, para que la cola tome código nuevo o para que una tarea agendada cambie de cadencia — **no** para un ciclo TDD.

**El repositorio dejó de ser solo documentación:** hay código desde el 2026-08-19, y a esta altura el código pesa más que los documentos.

## Levantar el proyecto (PROTECTED)

```bash
cp .env.example .env                                  # hosts del compose y claves de Google ya puestos
docker compose up -d                                  # app · worker · scheduler · mysql · redis
docker compose exec -T app php artisan key:generate
docker compose exec -T app php artisan migrate
npm install && npm run build                          # Tailwind purga: sin esto, las clases de un Blade nuevo no existen
```

`app` sirve HTTP en **:8000**. Los cinco servicios importan: sin `scheduler` no sale un recordatorio y **no hay ningún síntoma** — la suite queda verde igual.

**Qué reiniciar y cuándo.** `opcache.validate_timestamps=0` retiene el bytecode solo en procesos largos:

| Cambiaste código y querés… | Reiniciar |
| :-- | :-- |
| Correr tests | **Nada.** Cada `exec` es un proceso nuevo |
| Probar por HTTP o el panel | `docker compose restart app` |
| Que la cola tome el cambio | `docker compose restart worker` |
| Cambiar la cadencia de una tarea | `docker compose restart scheduler` |

```bash
docker compose exec -T app php artisan test --filter=NombreDelTest     # ciclo TDD
docker compose exec -T -e DB_DATABASE=agendallena_deriva app php artisan test   # suite, base propia
```

⚠️ **Base propia siempre que pueda haber otra sesión de Claude Code abierta.** Compartir `agendallena_testing` produce rojos masivos que no significan nada. `ListAgents` **antes** de leer un rojo masivo, no después.

### Para que el bot conteste de verdad, además del código

Estos cuatro son datos y configuración, **no** código, y con la suite en verde no se ven:

1. **Túnel al webhook.** Meta necesita una URL pública que llegue a `/api/v1/webhooks/whatsapp`. Hoy es un `trycloudflare.com`, que es **efímero**: cuando muere, el bot se queda mudo **sin ningún error**.
2. **Token de Meta.** Va en `integrations.access_token` (cifrado en la base) — **el `.env` no alcanza**, los envíos leen la base. ⚠️ Tiene que ser de **System User**, con caducidad *Nunca*: se reconoce por `expires_at: 0` en `debug_token`. Un token del Graph Explorer dura una o dos horas y mata las cinco tareas agendadas. ⚠️ **Falta el comando `integracion:token` que escriba los dos lugares.**
3. **Alta de la cuenta:** `php artisan cuenta:dar-de-alta {tenant} --waba-id=`, y después confirmar que `plantillas:sincronizar` no la reporta fallida.
4. **Google Calendar conectado** para el tenant.

**Diagnóstico rápido de «el bot no contesta»:** el token primero, el túnel segundo, el código último.

## ✅ Estado al 2026-08-26: **645 tests en verde · 42 de los 50 tickets construidos**

⚠️ **Los tickets terminados se movieron a `.claude/docs/tickets/Finish/`** con los enlaces reescritos (46 archivos). En `tickets/` quedan solo los 9 frenados por decisión.

⚠️ **Los 9 tickets que quedan están frenados por decisión, no por falta de trabajo.** No hay ningún ticket pendiente que alguien pueda simplemente ponerse a hacer:

| Bloque | Tickets | Pts | Por qué está frenado |
| :-- | :-- | :-: | :-- |
| **Cotizador** | T-006, T-016, T-040, T-041 | 14 | Decisión § 10: posponer hasta después del piloto. El piloto contesta gratis lo que el discovery costaría un día |
| **Watch** | T-027, T-028 | 11 | Decisión § 9: la conciliación de 15 min cubre el hueco sin pagar T-027. La diferencia es latencia, no cobertura |
| **Recortados** | T-004, T-032 | 14 | «Se replantea, no se pospone». T-025 ya cubre el riesgo de T-032 por 3 pts en vez de 11. ⚠️ **T-043 salió de esta lista el 2026-08-25**: se construyó por decisión, sin el piloto que el propio ticket pedía como precondición |

**Lo que sí queda por hacer no tiene ticket** — está listado abajo y en `plan-for-diego/decisiones-tomadas.md` §§ 15-17.

**Los 32 tickets del núcleo están implementados** (los 31 del recorte Pareto más T-050, que salió el mismo día de decidir una cuenta de WhatsApp por PyME), **más T-048 y T-042**, que estaban clasificados como diferidos y se construyeron igual.

**Quedan 16 tickets de los 50:** 12 diferidos (cotizador, Sheets, panel, sincronización inversa, operación) y 3 recortados (T-004, T-032, T-043), más T-016 que cae con el cotizador. Ninguno del núcleo.

El camino funciona punta a punta: llega el WhatsApp → máquina de estados → horarios reales de Google Calendar → **apartado del horario con TTL** → re-consulta de disponibilidad → turno creado con **clave de idempotencia** → confirmación → recordatorio t-24h con botones Confirmar/Re-agendar/Cancelar → **registro de asistencia y tasa de ausentismo**.

Las tres plantillas de Meta están aprobadas y **verificadas contra la plataforma real**: se mandó el recordatorio a un celular, se tocó Confirmar, y el sello volvió intacto.

⚠️ **Ningún ticket registra su estado de implementación**, así que este párrafo es la única fuente. Si construís algo, actualizá la cuenta.

### 2026-08-25 · La primera prueba punta a punta contra Meta real, y lo que destapó

**Se mandó un recordatorio t-24h de prueba a un celular real y se tocó Confirmar.** El circuito cerró entero, pero para llegar hasta ahí hubo que arreglar tres cosas que estaban rotas — y **ninguna estaba en el código**: dos eran datos de producción y la tercera era el `docker-compose.yml`.

Lo verificado, con números:

| Tramo | Evidencia |
| :-- | :-- |
| Envío de plantilla fuera de la ventana de 24 h | `wamid` devuelto por Meta, `notification_logs.status = sent` |
| Entrega y lectura | El webhook de `statuses` movió la fila `sent` → `delivered` → `read` sola |
| Normalización del teléfono | Guardado `549376XXXXXXX`, enviado `54376XXXXXXX`. **El 9 se saca y funciona**, tal como decía `NumeroDeWhatsApp` |
| Botón *Confirmar* de una plantilla | Llegó `inbound button`, el turno pasó a `confirmed`, salió el acuse |
| Latencia punta a punta | **1.890 ms** · dentro del KPI de 15 s, fuera del RNF-04 de 1.500 ms — o sea, confirma lo que ya estaba documentado como incumplible |

⚠️ **La prueba se deshizo entera** al terminar: se borró el `notification_log` y el turno volvió a `scheduled`. Soltar solo el candado no alcanzaba —`EnvioProgramadoDeAviso` filtra `status = scheduled`, así que un turno en `confirmed` quedaba fuera del barrido igual— y esa es la trampa de cualquier prueba futura sobre un turno real.

#### ⚠️ El séptimo caso de «suite verde, circuito muerto»: no había quién corriera el scheduler

**`routes/console.php` declaraba cinco tareas agendadas y ninguna se ejecutó nunca fuera de los tests.** El `docker-compose.yml` levantaba `app`, `worker`, `mysql` y `redis` — **no había ningún `schedule:work` ni `schedule:run`**, ni en el compose ni como tarea del host.

**Es el peor de los siete casos hasta ahora**, porque no deja ni un síntoma: la suite está verde —cada comando se prueba llamándolo directo—, los comandos funcionan cuando los corrés a mano, y en producción simplemente no pasa nada. Sin scheduler no sale un recordatorio, no sale un aviso, no se concilia un huérfano, no se expira una conversación y la PyME que Meta acaba de aprobar queda trabada en `PENDING` para siempre. **Los cinco tickets que dependían de esto estaban "terminados".**

⚠️ **Y el C4 nivel 2 lo prometía desde el principio:** la caja `Async Workers / Redis` dice textual *«(State Machine, Schedulers)»*. El diagrama declaraba un planificador que ningún despliegue proveía, y como estaba dentro de la misma caja que el worker —que sí existía— nadie notó que faltaba la mitad. Corregido en `02-arquitectura/01-c4-nivel-2-contenedores.md`, que ahora los dibuja separados.

Corregido con un servicio `scheduler` en el compose, corriendo `php artisan schedule:work`. **Verificado ejecutando, no solo arrancado**: a las 22:15:09 despachó `conversaciones:expirar`, `agendamientos:conciliar` y `plantillas:sincronizar` en background.

#### Las cinco tareas agendadas, qué hace cada una y qué se rompe sin ella

La cadencia vive en `routes/console.php`, pero **los números salen de constantes del propio comando** cuando la política es del comando y no del cron. Las cinco corren con `withoutOverlapping()` y `runInBackground()`.

| Tarea | Cada | Qué hace | Qué pasa si no corre |
| :-- | :-: | :-- | :-- |
| `conversaciones:expirar`<br>*(T-018b)* | 5 min | Devuelve a `IDLE` las conversaciones sin actividad. El TTL son **30 min** (`MINUTOS_POR_DEFECTO`), así que expiran entre los 30 y los 35 — indistinguible para el cliente | El cliente que volvió tres días después retoma un flujo a medias y el bot le contesta sobre un turno que ya no está pidiendo |
| `recordatorios:enviar`<br>*(T-037)* | 10 min | Manda el recordatorio interactivo **t-24h ± 10 min** con los botones Confirmar / Re-agendar / Cancelar | **Muere el número del pitch.** Es el único que se traduce directo a plata: sin recordatorio no hay caída del ausentismo que vender |
| `avisos:enviar`<br>*(T-043)* | 10 min | Manda el aviso **t-2h ± 10 min** a los turnos que **todavía no confirmaron**. Reusa la plantilla de 24 h, que es la única aprobada con botones | Se pierde la segunda oportunidad sobre justo el cliente que más riesgo de ausencia tiene: el que no contestó el primero |
| `agendamientos:conciliar`<br>*(T-030)* | 15 min<br>`MINUTOS_ENTRE_CORRIDAS` | Cruza Google contra `bookings` **en las dos direcciones**: reporta el evento sin fila, y deja en duda el turno cuyo evento el dueño borró | El horario huérfano queda ocupado sin turno real detrás **para siempre**. Los 15 min son exactamente cuánto se aceptó pagar por haber elegido conciliar en vez de compensar |
| `plantillas:sincronizar`<br>*(T-050)* | 15 min<br>`MINUTOS_ENTRE_CORRIDAS` | Le pregunta a Meta el estado de las tres plantillas de cada PyME y destraba a la que ya fue aprobada | **La PyME nueva no manda un solo mensaje, nunca.** No hay handler del webhook `message_template_status_update`, así que esta consulta es lo único que saca una plantilla de `PENDING` |

⚠️ **La cadencia y la ventana son el mismo número, y no por casualidad:** con `t-24h ± 10 min` y una corrida cada 10, ningún turno puede colarse entre dos pasadas. Espaciar el cron sin ensanchar la ventana deja turnos sin avisar, en silencio.

#### ⚠️ Dos datos de producción que frenaban todo, con la suite en verde

Los dos frenos que T-050 construyó para evitar el fallo silencioso **estaban haciendo exactamente su trabajo**, y por eso no hubo error: paraban el envío antes de intentarlo.

| Dato | Estado real | Consecuencia |
| :-- | :-- | :-- |
| `integrations.settings.waba_id` | **Vacío.** Solo había `verify_token` | `AltaDeCuenta::tieneCuenta()` frenaba con `RECORDATORIO_SIN_CUENTA_DE_WHATSAPP` |
| `integrations.access_token` | **Vencido: Meta devolvía `401`.** El de `.env` sí servía, y eran distintos | `plantillas:sincronizar` fallaba entero, así que las tres quedaban en `pendientes` |

**La lección operativa, que es nueva:** el proyecto ya sabía que una suite verde no prueba que el código se use. Esto agrega que **tampoco prueba que los datos que el código necesita estén cargados.** Un `.env` correcto y una base a medio poblar se ven idénticos desde los tests, porque los tests siembran sus propios datos.

⚠️ **Lo del token lo encontró también otra sesión el mismo día, por su cuenta** — ver *«una variante peor, porque se ve al revés de lo que es»* más abajo. Que dos caminos independientes tropiecen con lo mismo en un día es la señal de que falta el comando `integracion:token`, no de que alguien se olvidó de cargar un dato.

#### ⚠️ El `401` de Meta vuelve todos los días, y la causa es el tipo de token

El `access_token` vencido de arriba **no fue un evento aislado: volvió a fallar el mismo día con un token recién generado.** Preguntándole a `debug_token`, Meta lo dice sin ambigüedad: `code 190, subcode 463` · *«Session has expired on … 14:00:00 PDT»*.

**Los tokens de Graph API Explorer duran una o dos horas y vencen en punto.** Generar otro no arregla nada — el siguiente dura lo mismo. Mientras `integrations.access_token` sea de ese tipo, **las cinco tareas agendadas mueren todos los días**, siempre con el mismo `190`.

**Lo que falta cargar es un token de System User** (Business Settings → Usuarios del sistema → Admin → asignar la WABA → token con `whatsapp_business_messaging` + `whatsapp_business_management`, caducidad **Nunca**). ⚠️ **Todavía no está hecho.**

⚠️ **Antes de diagnosticar un envío que no sale, preguntá por el token**, no por el código: `Http::withToken($t)->get('.../v21.0/debug_token', ['input_token' => $t])`. Un `expires_at: 0` es lo único que no caduca; cualquier otro número es una bomba de tiempo.

#### ✅ El aviso t-2h, verificado contra Meta real

Salió a un celular real y **el webhook lo movió a `delivered` solo**. El `{{4}}` resolvió *«hoy a las 21:12»*: la rama de mismo-día-local, que hasta ahí solo existía en tests.

⚠️ **Cómo probar un envío real sin fabricar datos:** mover el `start_time` de un turno vivo a `now()+2h`, correr el comando y **restaurarlo**. Entre intento e intento hay que borrar el candado de `notification_logs`, o la corrida siguiente no manda nada y se lee como un bug del comando.

#### ✅ La advertencia sobre `statuses` quedó desmentida

La nota de más abajo —*«la suscripción del webhook en la consola de Meta entrega `messages`, no `statuses`»*— **ya no es cierta**, y se comprobó sin ambigüedad: `ProcessMessageJob` escribió los tres estados sobre un `notification_log` real. **El último AC de T-039 queda verificado contra la plataforma, no solo contra la suite.** La nota original se conserva tachada como acta de cuándo se creyó lo contrario.

#### ⚠️ Hueco nuevo sin ticket: el historial se parte entre conversaciones

El recordatorio salió en la conversación **7** y la respuesta del cliente entró en la **8**. Ese teléfono tenía seis conversaciones: cada expiración cierra una y abre otra, `EnvioProgramadoDeAviso` manda contra `booking.conversation_id` —la de cuando se agendó, ya cerrada— y el webhook entrante resuelve contra la **viva**.

**Funcionalmente está bien y es el diseño elegido a propósito:** `RespuestaAlRecordatorio` resuelve el turno por `context.id` contra `notification_logs` y no por el estado de la conversación, así que funcionó aunque la 8 estaba en `GATHERING_PARAMS v1` y el sello venía de la 7 en `BOOKED v6`. Es justo el caso que el docblock nombra.

**Lo que se rompe es el panel de T-047/T-049.** El operador que abre la 7 ve un recordatorio que nadie contestó; el que abre la 8 ve un *Confirmar* y un acuse que salieron de la nada. **La conversación completa no existe en ninguna pantalla**, y no lo cubre ningún ticket ni ningún test. Planteado como pregunta 8 en `plan-for-diego/preguntas-que-salieron-construyendo.md`.

### Lo que quedó declarado sin cubrir, y por qué

No son olvidos: dependen de funcionalidad que el recorte dejó afuera, y un test sobre un flujo que no existe es peor que un criterio marcado.

| Criterio | Estado |
| :-- | :-- |
| **AC-18.4** · El control de colisión aplica al re-agendamiento | ✅ **Cerrado el 2026-08-21 con T-042.** Salió sin escribir código: el re-agendamiento recorre el mismo `agendar()` que ya tenía el control de T-029, que es exactamente por qué el ticket dependía de él. Verificado por mutación |
| **AC-17.4** · Distinguir cuatro respuestas al recordatorio | ✅ **Cerrado el 2026-08-21 con T-042.** `bookings.status` distingue `rescheduled` de `cancelled`: un turno cancelado es un turno perdido y uno movido es un turno conservado |
| **AC-22.4** · Un fallo de Sheets no impide el turno | ⚠️ **Sigue abierto.** No hay integración con Sheets: T-044 y T-045 diferidos |

✅ **El hueco de cobertura de `EnviarRecordatorios` se cerró el 2026-08-21, y era un defecto real, no solo falta de test.** El freno preguntaba por `PlantillasDelTenant::pendientes()` —las registradas que no están aprobadas—, y una PyME a la que nunca se le corrió el alta no tiene ninguna registrada: la lista salía vacía y se leía como *"están todas aprobadas"*. Pasaba el freno y el envío moría contra Meta con `template not found`, o sea el fallo silencioso que T-050 AC4 existe para impedir.

⚠️ **Y es la tercera vez que aparece la misma forma.** `todasAprobadas()` —el método estricto, que sí contestaba bien— **no tenía ningún llamador en producción**: sus únicos invocadores eran los tests, igual que `AltaDeCuenta` el día anterior. Dos métodos de la misma clase contestaban distinto sobre el mismo estado y el que decidía el envío era el laxo. Ahora `pendientes()` recorre el juego completo de `NOMBRES`, así que las dos respuestas coinciden.

### T-043 · Aviso t-2h — 2026-08-25

**El ticket estaba cerrado a propósito y se reabrió por decisión, no por evidencia.** Decía textual *«es el único ticket del plan que conviene no hacer hasta tener evidencia»* y recomendaba mandarlo a mano una semana de piloto antes de escribir código. **No hay RF: los criterios que se construyeron son propuesta, no definición acordada**, y eso quedó escrito en la cabecera de `AvisoT2hTest` para que no se confunda dentro de seis meses.

**13 tests, todos con su rojo previo verificado.** Comando `avisos:enviar`, ventana `t-2h ± 10 min`, cadencia cada 10 minutos. El filtro `status = scheduled` hace acá **dos** cosas: filtra cancelados como en t-24h, y además deja afuera a los `confirmed` — que es el criterio propio del segundo aviso.

⚠️ **La decisión que hizo que esto funcione hoy: reusa `recordatorio_turno_24h`, no `aviso_turno_2h`.** El aviso tiene que llevar los tres botones —el que no confirmó a 24 horas es justo el que todavía puede confirmar, mover o cancelar a 2— y la única plantilla aprobada **con** botones es la de 24 horas. Agregarle botones a `aviso_turno_2h` costaba re-crearla en cada WABA y esperar la re-aprobación de Meta, y mientras tanto `todasAprobadas()` habría frenado **todos** los avisos de esa cuenta, incluido el de 24 horas que ya anda: se pagaba con el aviso que funciona para conseguir el que no existía.

⚠️ **El costo aceptado es que `aviso_turno_2h` queda creada y sin ningún envío que la use.** Se sigue creando en el alta y `todasAprobadas()` la sigue exigiendo. Sacarla de `NOMBRES` es otra decisión y trabaría a las cuentas que ya la tienen.

**El `{{4}}` dice «hoy a las 15:30» y no la fecha completa.** Con la misma plantilla y el mismo parámetro, al cliente le llegan dos mensajes de texto idéntico con 22 horas de diferencia y el segundo se lee como un error nuestro. Y **«hoy» no siempre es hoy**: un turno a las 00:30 recibe su aviso a las 22:30 del día anterior, así que la comparación se hace por día local del tenant. **Verificado por mutación** — forzar `isSameDay` a `true` mata ese test y solo ese.

### ⚠️ El sexto caso de «suite verde, circuito muerto», y lo encontró un test escrito para buscarlo

`RespuestaAlRecordatorio::recordatorioQueLoOrigino()` filtraba `type = reminder_24h` a secas. Con eso, **el botón del aviso t-2h no resolvía nunca**: el `context.id` no encontraba su fila, `manejar()` devolvía `false` y el mensaje caía al flujo normal. El cliente tocaba *Confirmar* dos horas antes del turno y el turno seguía en `scheduled`, sin ningún error. Corregido con un `whereIn` sobre los dos tipos; `confirmation` queda afuera a propósito porque esa plantilla no lleva botones.

### El refactor que salió de construirlo

T-043 nació como una copia de doscientas líneas de T-037. Se extrajo `EnvioProgramadoDeAviso` con la suite en verde: los dos comandos quedaron en 95 y 84 líneas y fijan solo **cuándo** se manda, **qué** se manda y **cómo se nombra en el log**. La duplicación tenía un modo de falla concreto: todos los frenos de ese comando —cuenta sin `waba_id`, plantillas sin aprobar, evento borrado en Google, candado antes de mandar— son defectos que se descubrieron de a uno, y con dos copias el arreglo del siguiente llegaba a una sola.

⚠️ **Trampa de la corrida sucia, otra vez.** Un `artisan test` completo murió por OOM a mitad de camino y dejó `agendallena_testing` sin la tabla `migrations`: la corrida siguiente dio **47 rojos**, cuatro de ellos con mensajes de aserción perfectamente creíbles sobre un default que sí había cambiado. Ninguno era real. `migrate:fresh` sobre la base de test y volvieron los 625 verdes. **Un mensaje de aserción plausible no descarta la corrida sucia.**

### Selector de semana → día → hora — 2026-08-26 · **terminado y verificado contra Meta real**

`ListaDeSemanas` + `ListaDeDias` + enganche en `ProcessMessageJob`. **20 tests nuevos**, todos con su rojo previo; 4 verificaciones por mutación. Una sola consulta a `freeBusy` del horizonte entero, semanas y días derivados en memoria y **sin cachear**: mostrar un horario que otro tomó hace 40 segundos es peor que la llamada de más, y solo la paga el que navega.

**Probado con un celular real:** semana → día → hora → turno creado el día pedido, no el primero disponible. Eso es lo que ningún test podía contestar.

| Decisión de Diego | Por qué |
| :-- | :-- |
| Anticipación **configurable**, default **4 semanas**, tope 12 | Con 7 días el paso «semana» elige entre una. El tope es el de Meta: pasadas 10 filas hay que paginar |
| `SELECTING_DATE` **un solo estado** para semana y día | El panel y la conciliación leen `current_state`. Un paso que existe y no se ve deja al cliente colgado figurando como «eligiendo horario» |
| Semanas **de calendario**, solo las que tienen lugar | Es lo que la gente quiere decir con «la semana que viene». Una semana vacía no se lista: tocar algo que no lleva a ningún lado es el callejón sin salida que el flujo no puede tener |
| ⚠️ **Por dónde arranca el flujo: configurable** (`start_with_date_picker`, default **apagado**) | Diego **invirtió el camino rápido** al ver el selector andando, y después lo hizo configurable. Arrancar por el selector suma 2 toques a **todos**; el default apagado deja verde la suite entera y permite **medir** el abandono en vez de decidirlo de memoria. El piloto quedó **prendido** |
| Los textos del pie se distinguen **por el sustantivo** | «Ver más opciones» y «Elegir otro día» quedaban pegadas, las dos empezaban con «ver» y la primera era **la única fila sin descripción**. Ahora: `⏭️ Ver más horarios` contra `📅 Elegir otra fecha`, las dos con descripción. **Lo detectó Diego mirando el celular, no un test** |
| Re-agendar (T-042) y «ese horario ya no está» **no** pasan por el selector | En los dos el cliente ya eligió cuándo. Mandarlo a elegir semana le haría repetir una decisión que ya tomó |

**El día elegido se recuerda en `context_data`, no viaja en cada botón**, y se olvida al volver al camino rápido: si no, «ver más» devolvía la ventana entera perdiendo el día en silencio, y la lista decía «los primeros horarios disponibles» mostrando los de un día ya descartado.

**Refactor previo, no posterior:** `ofrecerHorarios` y `ofrecerFechas` comparten `conLaAgenda()`, así que los cuatro frenos que se descubrieron de a uno —sin calendario, Google caído, horario apartado, agenda llena— valen para los dos. Es la lección de T-043.

#### ⚠️ El noveno caso de «código que nadie recorre», y el primero que ve el cliente

`ListaDeHorarios::pideOtraFecha()` **no tenía ningún llamador en producción** —su único invocador era un test—, así que la fila «Elegir otro día» se mostraba y el bot contestaba **«no entendí»** a una opción que le habíamos ofrecido nosotros. Y **paginar las semanas volvió a crear el mismo agujero con otra fila**: el test lo agarró rojo antes de entregarlo.

#### Tres defectos que encontraron los tests, no la revisión

| Defecto | Por qué no se veía |
| :-- | :-- |
| **Elegías la semana del 31 y llegaban los días del 24.** `semanaDe()` lee la fecha del botón sin huso (UTC); convertirla a Buenos Aires da las 21:00 del domingo anterior y `startOfWeek()` devuelve la semana previa | Los títulos («miércoles 2») se leen igual de bien: el error aparecía **en la confirmación, con el turno creado**. Se **reinterpreta** la fecha en la zona del tenant, no se convierte |
| **Truncación silenciosa de semanas.** 12 semanas de ventana abarcan hasta **13 de calendario**; Meta corta en 10 filas y `lista()` recorta con `array_slice` sin decir nada | La PyME que configura el máximo deja de ofrecer parte de su agenda **sin un error en ningún lado**. Ahora pagina |
| **Un checkbox destildado no viaja en el formulario**, así que `filled()` lo leía como «no lo tocaron»: el selector se podía **encender y nunca apagar** | Se resuelve con `$request->boolean()` + `<input type="hidden" value="0">`, con un test que destilda |

⚠️ **`dia_elegido` no se limpia al reservar.** No es alcanzable hoy —el re-agendamiento siempre cae en una conversación nueva— pero está confirmado con datos reales.

### ⚠️ El séptimo y el octavo caso de «el dato vive y nadie tiene dónde tocarlo»

`business_settings.human_phone` y `search_window_days` existían en la base, con su columna y su consumidor, y **ninguna pantalla los exponía**: la única forma de cargarlos era `tinker`. Sin `human_phone`, el bot derivaba sin decir a qué número escribir. Los dos ya tienen input.

⚠️ **Y una variante peor, porque se ve al revés de lo que es:** cambiar `META_ACCESS_TOKEN` en el `.env` **no cambia nada**. Los envíos leen `integrations.access_token`, que está cifrado en la base. Frenó la prueba manual dos veces el mismo día con `401 / error 190`, con el `.env` correcto a la vista. **Falta un comando `integracion:token` que escriba los dos**, en la línea de `cuenta:dar-de-alta`.

### ⚠️ Y el paralelismo que nadie estaba buscando: **otra sesión de Claude Code**

El 2026-08-25 se tiraron **tres corridas completas a la basura** persiguiendo 42 y después 48 rojos —`Table 'tenants' doesn't exist`, `Unknown column`, `Table definition has changed`— que eran **otra sesión de Claude Code abierta en el mismo proyecto**, corriendo su suite contra `agendallena_testing`. Se llegó a matar cuatro procesos ajenos creyéndolos fantasmas propios.

La regla ya existía —*«un rojo masivo con paralelismo es sospecha de corrida sucia»*— y aun así costó tres vueltas, porque se buscó el paralelismo **adentro** de la sesión. Lo que faltaba:

- **`ListAgents` ANTES de leer un rojo masivo, no después.** Cuesta un segundo y descarta la causa más probable.
- **Base propia siempre**, no solo cuando uno despacha agentes. `docker compose exec -T -e DB_DATABASE=… app php artisan test`.
- **Un `artisan test` con padre 0 adentro del contenedor no es tuyo.** Antes de matar nada, mirar si hay otra sesión.
- **`git status` delata a la otra sesión mejor que cualquier `ps`:** archivos que uno no escribió, y una cuenta de tests que creció más de lo que uno agregó.

### Lo que se aprendió corriendo cinco agentes TDD en paralelo

- **El reinicio del contenedor no hacía falta**, y eso desbloqueó el paralelismo. Ver la nota de `opcache` arriba.
- **Cada agente necesita su propia base.** Se usaron `agendallena_test_a` … `_e`; el `-e DB_DATABASE=` del `docker compose exec` **gana** sobre `phpunit.xml`.
- **Tres de los cinco encontraron tests propios que pasaban en vacío**, y los arreglaron antes de entregar. Un negativo puro —«no toca lo sano»— no distingue de «no hace nada»: la solución fue un **canario** en la misma corrida, algo que sí tiene que cambiar. Ninguno se habría detectado mirando el verde.
- **Un rojo masivo con paralelismo es sospecha de corrida sucia, no de regresión.** Correr la suite mientras otro agente tenía archivos a medio escribir dio 16 rojos que no significaban nada, incluidos siete tests perfectos.
- **Una base por agente no alcanza si el coordinador después verifica en esa misma base.** Corrí la suite de un agente en la base que le había asignado y `RefreshDatabase` le voló las tablas en pleno test: 18 fallas con `Table doesn't exist` que no eran suyas.
- ⚠️ **Con agentes en paralelo, `git diff --stat` NO sirve para verificar límites de fase, aunque haya commit base.** Mide el trabajo de todos contra `HEAD`, no el de uno. **La foto por hash sigue siendo la única prueba individual**: se toma al arrancar la sesión del agente y su `diff` vacío prueba que entre ese instante y la entrega no cambió un byte — ni por su mano ni por ninguna otra. Con **un solo** agente, `git diff --stat` sí es más barato y exacto.
- ⚠️ **La foto por hash tampoco atribuye: detecta.** El árbol es compartido, así que la foto captura también lo ajeno: en el segundo bloque, tres agentes reportaron `diff` con líneas que no eran suyas y tuvieron que demostrar su inocencia archivo por archivo. **Sigue sirviendo para detectar que alguien tocó algo**, no para probar quién. Con un commit base, `git diff --stat` tampoco resuelve esto — lo que resuelve es un worktree por agente.
- **Un rojo masivo con paralelismo casi siempre es corrida sucia.** Un agente vio `64 failed` a mitad de camino, se detuvo a revisar, comprobó que ninguno de los ocho archivos afectados mencionaba lo suyo, y **no tocó nada**. Se arreglaron solos cuando los otros verdes entregaron. Si los hubiera "arreglado", habría roto trabajo ajeno en vuelo.
- **La foto por hash sobre `app/ routes/ config/ database/` funcionó**: los cinco entregaron `diff` vacío sobre 119 archivos, y los que vieron líneas ajenas las reportaron en vez de taparlas.

### Dos trampas de verificación que costaron caro el 2026-08-26

- **Un `grep` al CSS compilado puede dar falso negativo por mirar el archivo equivocado.** `ls -1t` devolvió `fonts-*.css` como el más reciente y `h-4` no estaba ahí; el build sí lo tenía en `app-*.css`. **Grepear todos los bundles, no el último por fecha.**
- **Una mutación que no mata un test no siempre acusa al test.** Subir el tope de filas a 1000 dejó todo verde porque `paginar()` acota con `min(..., MAX_FILAS_LISTA)`: la mutación era inútil, no el test flojo. Antes de dar por débil un test, **verificar que la mutación realmente cambió el comportamiento**.

### Lo que falta para vender, que ya no es código

- **Ningún commit todavía.** Todo lo anterior está sin trackear, y ya hay bastante en juego.
- 🔴 **Token de System User, sin hacer.** El del 2026-08-26 era `type: USER` y vencía en 1 h. Con eso **las cinco tareas agendadas mueren todos los días**. Se reconoce por `expires_at: 0`.
- 🔴 **El túnel del webhook es efímero** (`trycloudflare.com`). Cuando muere, el bot queda mudo **sin ningún error**: se ve idéntico a un bug de código.
- Las preguntas de `plan-for-diego/preguntas-que-salieron-construyendo.md`, ninguna bloqueante. Las nueve originales están respondidas; **la 8 quedó abierta el 2026-08-25**.
- ⚠️ **Cargar bien los datos de cada PyME, que no es lo mismo que tener el código.** El 2026-08-25 el piloto tenía el `waba_id` vacío y el `access_token` vencido, y con eso **no salía un solo recordatorio** aunque los 42 tickets estuvieran construidos y la suite verde. Antes de dar por lista a una PyME: `cuenta:dar-de-alta` y confirmar que `plantillas:sincronizar` no la reporta fallida.

## Paths (PROTECTED)

| Ruta | Contenido |
| :-- | :-- |
| `.claude/docs/README.md` | Índice maestro de toda la documentación |
| `.claude/docs/01-producto/` | Lean canvas, PRD (RF-*, RNF-*, CA-*), backlog índice, story map original |
| `.claude/docs/02-arquitectura/` | C4 L2 y L3, DER, modelo de datos con DDL |
| `.claude/docs/03-flujos/` | Casos de uso, flujo WhatsApp, catálogo de estados, secuencia e2e |
| `.claude/docs/stories/` | 26 historias (US-01 a US-26), una por archivo |
| `.claude/docs/stories-maps/` | 3 backbones + slices + 21 huecos y trazabilidad |
| `.claude/docs/tickets/` | **50** tickets por prioridad + auditoría INVEST en su README. El 50 salió el 2026-08-20 de decidir una cuenta de WhatsApp por PyME |
| `.claude/docs/estimacion-backlog/` | 177 pts, tabla maestra, plan de capacidad y **análisis Pareto** (núcleo de 107 pts) |
| `.claude/prompts/aprobados/` | Prompts aprobados con pedido original, supuestos y resultado |
| `.claude/agents/` | 8 subagentes. Cuatro read-only: `invariantes-auditor`, `flujo-conversacional`, `ticket-verificador`, `trazabilidad-docs`. Cuatro escriben: `ticket-implementador` y los tres del ciclo TDD (`tdd-test-writer`, `tdd-implementer`, `tdd-refactorer`), cada uno limitado a su fase |
| `.claude/skills/` | 3 skills de stack: `laravel-agendallena`, `meta-cloud-api`, `google-calendar`. Encierran las decisiones cerradas para no re-deducirlas |

**Los archivos de `agents/` y `skills/` no siguen la convención `NN-kebab-case.md`**: su nombre lo fija Claude Code (el `name` del frontmatter, y `SKILL.md` dentro de una carpeta por skill). No los renumeres.

## Convenciones (PROTECTED)

- Archivos: **`NN-kebab-case.md`**, minúsculas, ASCII, sin acentos ni `ñ`, siempre `.md`. Carpetas `NN-tema/` con la misma regla. Numerar de 10 en 10 deja hueco para insertar sin renumerar.
- Contenido en **español rioplatense**, con lenguaje técnico preciso.
- Nombres de archivo sin acentos; en el contenido, todos los que hagan falta.

## Flujo de trabajo (PROTECTED)

- **`/meta-prompt`** reescribe un prompt crudo sin ampliar ni recortar su objetivo. **No guarda nada al generar**: solo al aprobarlo se escribe en `.claude/prompts/aprobados/YYYY-MM-DD-<slug>.md` con el pedido original, el prompt final, los supuestos y el resultado. Si el archivo existe, se agrega `-2`; un prompt aprobado no se sobrescribe.
- **Cadena de derivación:** PRD + arquitectura + flujos → story maps → stories → tickets → estimación. Cada nivel referencia al anterior por ID y **no lo regenera**. Los huecos se marcan, no se rellenan con contenido inventado.
- **Las historias llevan sección `## Requisitos técnicos`** entre los criterios de aceptación y la estimación, derivada de sus propios AC. Ver `prompts/aprobados/2026-08-05-user-stories-con-requisitos-tecnicos.md`.
- **Regla de reparto de huecos:** los que son enablers de datos o decisiones entran como *requisitos técnicos* de la historia que los consume; los que son comportamiento nuevo salen como historia propia.
- Marcar con `⚠️` toda decisión abierta o información faltante en vez de inventar el detalle.

## Decisiones cerradas

Las dos figuraban como "abiertas y bloqueantes" en `01-producto/03-user-stories-mvp.md` porque nadie había cruzado los documentos. **Falta escribirlas en el PRD** (ticket T-007).

| Decisión | Resolución | Dónde está escrito | Destraba |
| :-- | :-- | :-- | :-- |
| ¿Estado en MySQL o Redis? | **Las dos:** `conversations` es fuente de verdad, Redis `session:tenant:{id}:phone:{phone}` es caché y locks | `03-flujos/05-estados-flujo-conversacional.md` §1 | US-03 (falla de *Estimable*) |
| ¿Multi-tenancy por columna o schema? | **Por columna `tenant_id`** con Global Scopes | RNF-01, C4 L2, C4 L3 y modelo de datos coinciden | US-14 (falla de *Estimable*) |

## Decisiones abiertas

✅ **Las doce que quedaban se respondieron el 2026-08-21** — nueve de `preguntas-que-salieron-construyendo.md` y tres de `decisiones-pendientes.md`. Están en **`plan-for-diego/decisiones-tomadas.md`**, con lo que habilita cada una y el plan de avance que sale de ellas.

**De las que había hasta el 2026-08-21 no queda ninguna abierta.** Tres de las seis se cerraron el 2026-08-19 (T-007), más una cuarta que no estaba registrada; dos más el 2026-08-20 y la última el 2026-08-21. La tabla queda como acta.

🔴 **Pero el 2026-08-25 se abrió una nueva, y salió de mandar el primer mensaje de verdad:** el recordatorio y la respuesta del cliente quedan en **conversaciones distintas**, así que el historial completo no se ve en ninguna pantalla del panel. No frena nada. Es la pregunta 8 de `plan-for-diego/preguntas-que-salieron-construyendo.md`, con tres salidas y una sugerencia.

| Decisión | Resolución | Dónde quedó escrita |
| :-- | :-- | :-- |
| **Outlook en el MVP** | **Fuera.** Duplicaba toda la superficie de calendario para un segmento sin validar. El `ENUM` conserva el valor sin código detrás | OUT-OF-SCOPE del PRD |
| **Dónde se captura el nombre** | **`GATHERING_PARAMS`.** Con `SLOT_SELECTED`, los leads abandonados llegan a Sheets sin nombre — que es justo el lead que US-13 existe para capturar | RF-A3 |
| **TTL de inactividad** | **30 minutos.** Coincidían dos de los tres documentos. AC-03.1 quedó corregido | RF-A3 y US-03 |
| **Comportamiento de `ERROR_FALLBACK`** | **Libera lo reservado y vuelve a `IDLE`.** Conservar exigiría una reserva temporal con TTL inexistente, y un horario bloqueado por un turno incompleto **es H-04** | `03-flujos/05-estados` §4 |
| **Compensar o conciliar** | ✅ **Cerrada 2026-08-20: conciliar.** El evento no se borra en el acto; lo limpia una revisión periódica | `plan-for-diego/decisiones-pendientes.md` |
| **Cuándo se toma la reserva temporal** | ✅ **Cerrada 2026-08-20: al elegir**, TTL 5 min, y **un horario por cliente** — elegir otro libera el anterior | `plan-for-diego/decisiones-pendientes.md` |
| **Rebanadas verticales** | ✅ **Cerrada 2026-08-21: no se reorganiza por ahora.** El flujo principal ya está terminado, así que la cadena más larga ya se recorrió; con una sola persona programando el encadenamiento no cuesta. Se replantea si suma una segunda. *Independiente* queda en 2/26 a sabiendas. ⚠️ El corte de T-018 sigue pendiente, pero es corrección de estimación | `plan-for-diego/decisiones-tomadas.md` § 12 |

## Huecos bloqueantes

Salieron de cruzar el backlog contra el DDL. **Cuatro de los cinco se cerraron el 2026-08-19**; llevaban meses invisibles y eran migraciones baratas, como se sospechaba.

| Hueco | Estado |
| :-- | :-- |
| `business_settings` no existía | ✅ **Cerrado (T-014).** Horario en JSON con rangos por día —soporta jornada partida—, duración, buffer, ventana de búsqueda y los tres textos del bot |
| Tabla `messages` no existía | ✅ **Cerrado (T-048, adelantado).** Con índice `(tenant_id, conversation_id, created_at)` y único por `wamid`. Retención definida en 12 meses, **no aplicada todavía** |
| `bookings.status` sin asistencia | ✅ **Cerrado (T-036).** Columna `attendance` propia, con auditoría de quién marcó y cuándo. Un turno puede ser `confirmed` y `no_show` a la vez |
| Índices compuestos ausentes | ✅ **Cerrado (T-009).** Los cuatro existen y su plan de ejecución se verificó con `EXPLAIN` sobre datos sembrados |
| Colisión de horario | ✅ **Cerrado (T-029, completado con T-042 el 2026-08-21).** Apartado en Redis al elegir con TTL de 5 min, un horario por cliente, y re-consulta a Google inmediatamente antes de crear el evento. **AC-18.4 —que el control valga también al re-agendar— quedó cubierto con T-042 y verificado por mutación** |

⚠️ **Dos huecos nuevos aparecieron construyendo**, ninguno registrado antes:

| Hueco | Qué falta |
| :-- | :-- |
| **Normalización de teléfonos** | Meta acepta el número argentino **sin el 9** y devuelve el `wa_id` **con el 9**. Responder al `from` del webhook tal cual **falla**. Ningún ticket lo contempla y T-018 lo necesita el día uno. Ver `plan-for-diego/numeros-argentinos-el-9.md` |
| **Un número no puede ser bot y chat humano a la vez** | Verificado el 2026-08-20. Invalidaba RF-A4, US-08, T-004 y el "fuera de alcance" de T-035, que afirmaban que el operador responde desde la app. **Resuelto con el modelo de dos números** (RF-A4): el bot deriva diciendo a qué número escribir. ⚠️ El costo aceptado es que el cliente cambia de chat y vuelve a explicar; cuántos se pierden lo mide el piloto |
| **RNF-04 incumplible como está escrito** | Medido con envíos reales: `freeBusy` + envío de Meta suman **2.361 ms en p95** contra un presupuesto de 1.500 ms. Las dos llamadas a terceros solas lo superan. Hay que reescribir el requisito, no optimizar código. Ver ese documento *(documento borrado por innecesario el 2026-08-21)* |

## Advertencias para sesiones futuras

- **RF-A3 declara 4 estados; el catálogo de flujos declara 11 y el código implementa 10.** US-03 está estimada sobre los 4: hay que rehacerla antes de comprometerla. El que falta es `CALCULATING_QUOTE`, que cae con el cotizador; `RESCHEDULED` volvió el 2026-08-21 con T-042.
- **Cinco funcionalidades del MVP no tienen requisito funcional:** aviso t-2h, volcado a Sheets, configuración del negocio, bienvenida y registro de asistencia. Sus criterios de aceptación son propuestas nuestras, no definiciones acordadas.
- **UC-2.10 (pausar el bot desde el panel) está en los flujos y no estaba en el backlog.** Es el plan B de US-08, cuya viabilidad entera depende de un spike sin resolver: si Meta no entrega por webhook los mensajes salientes manuales, US-08 se rediseña de cero.
- **El flujo tiene ramas sin historia** que solo aparecen leyendo `03-flujos/`: colisión al confirmar, agenda sin disponibilidad, pregunta libre fuera del flujo y agendamiento sin compensación. Se concentran en el momento en que el producto entrega el valor que se cobra.
- **Corrección al story map original:** `01-producto/04-user-story-map.md` atribuye al C4 nivel 2 la promesa de Outlook. El archivo actual no la contiene; quien la sostiene es el `provider ENUM` del modelo de datos.
- **Las 17 historias originales conservan en su cabecera "Prioridad N de 14/17".** La numeración vigente vive en `docs/tickets/README.md`; las cabeceras quedaron desactualizadas a propósito para no generar churn.
- **177 puntos es el punto medio de un rango 165–215.** Ocho tickets tienen confianza media o baja y cuatro dependen de un spike, un discovery o una decisión del bloque 0: **16 puntos sin código eliminan hasta 50 puntos de incertidumbre.**


## Reglas de desarrollo (PROTECTED)

- Sigue siempre el ciclo TDD: Red → Green → Refactor
- Escribe el test más simple que falle primero
- Implementa solo el código mínimo para pasar el test (puedes hardcodear — Fake It)
- Nunca modifiques tests existentes sin aprobación explícita
- Un test a la vez, commits frecuentes

⚠️ **Dos de estas reglas endurecen lo que se venía haciendo, y conviene saberlo:**

- **«Un test a la vez»** contra la práctica de despachar un `tdd-test-writer` que escribe ocho o doce tests de una y recién después se implementa. Eso pasó todo el 2026-08-21 y **encontró defectos reales**, pero el rojo llega en lote y no de a uno.
- **«Nunca modifiques tests existentes sin aprobación explícita»** es más estricto que la regla de la *expectativa vieja* de más abajo, que permitía actualizar un test cuando el comportamiento cambiaba a propósito, con la condición de decirlo. **Ahora primero se pide permiso.** Lo que no cambia: sigue prohibido borrarlo, comentarlo o marcarlo `skipped`.

## TDD (PROTECTED)

**Regla de hierro: no se escribe código de implementación sin un test que ya haya fallado.**

Vale igual para vos y para cualquier subagente que despaches.

### El ciclo, con los comandos de este proyecto

**🔴 ROJO — escribir el test y verlo fallar**

1. Escribí **un** test que exprese el criterio de aceptación, no la implementación que tenés en la cabeza.
2. Corrélo y **mirá la salida**:
   ```
   docker compose exec -T app php artisan test --filter=NombreDelTest
   ```
3. **Leé por qué falló.** Un `Class not found` es rojo legítimo; un `Undefined array key` en tu propio test es un test roto, no un test que falla. No sirve.
4. **Nada de código de implementación en esta etapa.** Ni el esqueleto de la clase, ni "solo la firma del método".

**🟢 VERDE — el código mínimo**

5. Escribí lo mínimo para que ese test pase. Si sabés que falta más, eso es el próximo ciclo rojo.
6. **No hace falta reiniciar** para correr los tests: el opcache del CLI muere con cada `exec`. Reiniciá solo si vas a probar por HTTP o a través del worker.

**🔵 REFACTOR — solo en verde**

7. Limpiá con la suite en verde. Si se pone roja, el refactor rompió algo: volvé atrás.

### Qué hacer si ya escribiste el código primero

**Borralo y empezá de nuevo.** No lo dejes "de referencia", no lo comentes, no lo adaptes mientras escribís el test.

El motivo no es ritual: un test escrito mirando la implementación pregunta *"¿qué hace este código?"* en vez de *"¿qué tiene que hacer?"*, y por eso pasa a la primera. **Un test que nunca estuvo rojo no probó nada** — solo confirmó que el código hace lo que hace.

### Los tests que fallan no se apagan

Nunca borres, comentes ni marques como `skipped` un test que falla para que la suite quede verde. Si un test dejó de valer porque cambió el comportamiento a propósito, **actualizalo diciendo por qué en el commit y en el informe**. Silenciarlo es esconder una regresión.

⚠️ Distinguí siempre estas dos y decilo explícitamente:

| | Qué es | Qué hacer |
| :-- | :-- | :-- |
| **Regresión** | El código se rompió | Arreglar el código |
| **Expectativa vieja** | El comportamiento cambió a propósito | Actualizar el test **y decirlo** |

### Al despachar un subagente

Hay **un agente por fase**, y el aislamiento es el punto: el que escribe el test no ve la implementación, así que no puede escribir un test que la describa.

| Fase | Agente | Escribe | Tiene prohibido |
| :-- | :-- | :-- | :-- |
| 🔴 ROJO | `tdd-test-writer` | Solo `tests/` | Cualquier cosa en `app/` |
| 🟢 VERDE | `tdd-implementer` | Solo `app/` | Tocar un test |
| 🔵 REFACTOR | `tdd-refactorer` | Solo estructura | Cambiar comportamiento o tests |

Cada uno verifica su límite con una **foto por hash** (`find ... -exec md5sum {} + | sort`) tomada antes de empezar y comparada al entregar, y reporta el `diff` vacío como prueba.

⚠️ **No sirve `git status` ni `git diff` para esto mientras el repo no tenga un commit base:** con todo sin trackear, `git status --porcelain` colapsa a `?? app/` y `git diff --stat tests/` sale vacío hayas editado o no. Los tres agentes tuvieron un control decorativo hasta que se detectó. **Cuando haya primer commit, volver a `git diff --stat`**, que es más barato y más exacto.

**Despachá el rojo y el verde por separado.** Un solo agente con las dos fases escribe el código primero: tiene todo el contexto y nadie lo mira.

Si igual usás un agente genérico, en el prompt va textual:

> Trabajá con TDD. Para cada criterio de aceptación: **escribí el test, corrélo, y confirmá que falla antes de escribir una línea de implementación.** Reportá qué viste fallar. **No escribas código de implementación hasta tener el rojo.**

Sin eso, un subagente escribe el código y los tests después — y el informe va a decir "todos verdes" igual.

### Racionalizaciones que ya usamos acá

Si estás pensando alguna de estas, estás por saltearte el rojo:

| Excusa | Realidad |
| :-- | :-- |
| *"El test sería trivial"* | Lo trivial se rompe igual. El ciclo cuesta 30 segundos con `--filter`. |
| *"Ya sé que funciona, lo probé a mano"* | Probaste una vez. El test lo prueba en cada cambio, para siempre. |
| *"Primero exploro la forma de la API"* | Explorá en `tinker`, no en el archivo final. Lo que quede, se borra. |
| *"Es solo una migración"* | Cuatro migraciones de este proyecto tenían bugs: `attendance` a medias, el `(int)` sobre UUID, el cast que guardaba hora local. |
| *"Los escribo igual, al toque"* | Escribirlos después **es** saltarse el rojo. Es lo que pasó en T-021, T-023 y T-026. |
| *"La suite tarda dos minutos"* | Con `--filter` son 30 segundos. La suite completa va al final, no en cada ciclo. |

### Banderas rojas — pará y volvé al rojo

- Escribiste una clase antes que su test
- Un test tuyo pasó **la primera vez que lo corriste**
- Estás por comentar o borrar un test que falla
- Vas a reportar "todo verde" sin haber visto ninguno en rojo
- Un subagente te entregó código y tests juntos sin decir qué vio fallar

### Lo que este proyecto ya aprendió

Tres tests **pasaban por la razón equivocada** y solo se detectó al mirarlos de cerca:

- Tres tests de fallo de T-026 no fallaban de verdad: `Http::fake()` **acumula** stubs, así que el doble que simulaba el error nunca se usaba.
- Un test de zona horaria de T-031 podía pasar por casualidad, porque Santiago después del cambio estacional tiene el mismo offset que Buenos Aires.

Por eso, cuando un test cubra algo que importa, vale **verificarlo por mutación**: rompé el código de producción a propósito y confirmá que el test se pone rojo. Si no se pone, no estaba probando nada. Después restaurá y verificá con `diff`.
