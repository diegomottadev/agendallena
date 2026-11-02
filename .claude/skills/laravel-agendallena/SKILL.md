---
name: laravel-agendallena
description: Use when writing, reviewing or planning any backend code for AgendaLlena — migrations, Eloquent models, queries, queued jobs, Redis usage, the conversational state machine, the Vue 3 admin panel, or error handling. Triggers on Laravel, PHP, Eloquent, migration, Global Scope, tenant_id, multi-tenancy, Laravel Queues, Redis, Sanctum, state machine, conversations, bookings, business_settings, quote_rules, integrations, notification_logs, and on any ticket T-001 to T-049.
---

# AgendaLlena — convenciones de backend

Middleware de agendamiento y cotización por WhatsApp para PyMEs latinoamericanas de 3 a 20 empleados. **Laravel 13 + PHP 8.4 + MySQL 8.4 + Redis 7 + Vue 3**, integrado con Meta Cloud API, Google Calendar y Google Sheets.

`composer.json` pide `php: ^8.4` y fija `config.platform.php = 8.4.24`, la misma versión de la imagen `php:8.4-cli`. **El código se escribe para 8.4**: no bajes sintaxis para contentar a un linter.

## Entorno — dos trampas que cuestan una sesión

1. **Todo PHP corre en el contenedor:** `docker compose exec -T app php artisan ...`. El `php` del PATH de Windows es **7.4.1** (`C:\xampp74\`) y aborta en el `platform_check` de Composer. Si el IDE marca un error de sintaxis sobre código válido de 8.x —promoción de propiedades en constructor, `readonly`— es el linter apuntando al 7.4: confirmá con `docker compose exec -T app php -l <archivo>` antes de tocar nada.
2. **`opcache.validate_timestamps=0`** en el `Dockerfile`. **Un cambio de código no se ve hasta `docker compose restart app worker`** (~12 s). Si algo "no hace nada", sospechá de esto antes que de un bug. Los tests no están afectados: cada `artisan test` arranca un proceso nuevo.

## Trampas que ya costaron tiempo

Las cuatro salieron construyendo, no de la documentación. Cada una **pasa los tests o no da error** — por eso están acá.

| Trampa | Qué pasa | Cómo se evita |
| :-- | :-- | :-- |
| **`(int)` sobre un `tenant_id`** | Los `tenants.id` son **UUID**: `(int)` devuelve `1` para todos. Un chequeo de aislamiento escrito así **pasa siempre** y un usuario ve datos de otra PyME | Comparar como strings. Fue un bug real en T-025 |
| **`Http::fake()` acumula** | Llamarlo dos veces **no reemplaza** el primero: sigue ganando el que coincida primero. Un test que re-fakea para simular un fallo recibe la respuesta exitosa del `setUp` y **pasa por la razón equivocada** | Declararlo **una sola vez por test**, o `Http::sequence()` |
| **`(int) $x` no cubre `Carbon::diffInDays`** | En Carbon 3 devuelve **float con signo** | `(int) round(abs(...))` |
| **`end($this->metodo())`** | `end()` necesita una variable, no una expresión: es error fatal en PHP 8 | Asignar a una variable primero |

## Silencios frente al cliente que siguen abiertos

T-033 recorrió el worker buscando caminos donde el cliente queda sin respuesta. Cerró cuatro. **Estos tres siguen abiertos** porque necesitan copy que nadie definió:

| Código en el log | Cuándo pasa | De quién es |
| :-- | :-- | :-- |
| `FSM_SIN_INTERPRETE` | El cliente escribe en un estado sin intérprete | T-019 |
| `FSM_RECORDAR_PASO` | Tocó un botón viejo: AC-03.2 pide responder con el paso actual, hoy solo se registra | T-023 |
| `FSM_TRANSICION_INVALIDA` | Entrada que no corresponde al estado | T-018 |

**Es lo primero que hay que revisar** al tomar esos tickets: son silencios reales, aunque no sean fallos de terceros.

## Correr agentes en paralelo

Los subagentes comparten el contenedor **y la base `agendallena_testing`**. Dos `migrate:fresh` simultáneos se pisan, y el síntoma —`Unknown column`, `Table doesn't exist`, `Duplicate entry`— **parece un bug de esquema y no lo es**.

Si vas a correr dos agentes a la vez, dale a cada uno **su propia base y su propio índice de Redis**. El `-e` gana sobre `phpunit.xml` — verificado:

```
docker compose exec -T -e DB_DATABASE=agendallena_t030 -e REDIS_DB=13 app php artisan test --filter=X
```

Las bases se crean así (el `phpunit.xml` no las crea):

```
docker compose exec -T mysql mysql -uroot -psecret -e "CREATE DATABASE IF NOT EXISTS agendallena_t030 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON agendallena_t030.* TO 'agendallena'@'%'; FLUSH PRIVILEGES;"
```

### ⚠️ Aislar la base no alcanza — hay tres recursos compartidos más

Se aprendió construyendo T-029/030/038/050 en paralelo, **midiendo mal cuatro veces seguidas**. Los síntomas son idénticos a un bug real y por eso hacen perder horas:

| Recurso | Qué pasa | Síntoma |
| :-- | :-- | :-- |
| **`REDIS_DB`** | Dos agentes comparten el índice 15 | Tests de pausa, sesión o apartado que fallan sin patrón |
| **El código en `app/`** | Medís mientras otro agente escribe | Rojos en tests que no tienen nada que ver, distintos en cada corrida |
| **`docker compose restart`** | **Mata el proceso de test de cualquiera**, no solo el tuyo | `exit 137`, o una corrida que termina sin imprimir el total |
| **Otro `artisan test` vivo** | Su `RefreshDatabase` hace `migrate:fresh` bajo tus pies | `Table 'agendallena_testing.migrations' doesn't exist` a mitad de suite |

**Reglas que salieron de eso:**

1. **La suite completa se corre cuando nadie más escribe.** A cada agente se le dice qué archivos de test son ajenos y que se quede en su `--filter`.
2. **Antes de medir, verificá que no haya otro test corriendo.** `ps` no está en la imagen; se ve por `/proc`:
   ```
   docker compose exec -T app sh -c "for p in \$(ls /proc | grep -E '^[0-9]+\$'); do tr '\0' ' ' < /proc/\$p/cmdline 2>/dev/null | grep -l phpunit; done"
   ```
3. **Un proceso ajeno no se mata sin confirmar que está huérfano** — mirá hace cuánto arrancó. Ya se mató uno vivo en esta sesión creyéndolo colgado.
4. **Para la verificación final, usá una base que nadie más tenga asignada.** Es más barato que coordinar.

## Tests

Corren contra **MySQL 8.4 en la base `agendallena_testing`**, no contra SQLite. Es el mismo motor que producción a propósito: en SQLite los índices compuestos, los `ENUM`, `ON UPDATE CURRENT_TIMESTAMP` y el bloqueo de filas (`FOR UPDATE`) se comportan distinto, y un test verde ahí no prueba nada sobre producción. Redis usa los índices 15 y 14, separados de los de desarrollo.

La base la crea `docker/mysql/init/01-create-testing-db.sql`, montado en `docker-entrypoint-initdb.d`. Ese script **solo corre al inicializar el volumen**: sobre uno existente hay que aplicarlo a mano (instrucciones en su encabezado).

`RefreshDatabase` deja la base de desarrollo intacta. Aun así, verificá la afirmación en vez de asumirla cuando toques configuración de base.

## Estado del código

**El producto agenda turnos de punta a punta.** Un cliente escribe por WhatsApp, recibe la bienvenida con el nombre del negocio, toca «Reservar», da su nombre, elige entre horarios **realmente libres** del Google Calendar del negocio, y el turno aparece en el calendario con su confirmación.

| Bloque | Tickets | Qué quedó |
| :-- | :-- | :-- |
| **Canal** | T-008, T-010, T-011 | Handshake, ingesta con firma HMAC sobre cuerpo crudo, idempotencia por `wamid` con lock |
| **Datos** | T-009, T-014, T-036, T-048 | `conversations`, `bookings`, `notification_logs`, `business_settings`, `messages` y los índices compuestos |
| **Google** | T-005, T-012, T-013, T-022, T-026 | OAuth, refresco automático, `freeBusy` sin caché, creación del evento |
| **Conversación** | T-018a/b/c, T-019, T-021, T-023, T-024 | FSM de 9 estados, interactivos con sello, bienvenida, lista de horarios, los tres vacíos distinguidos |
| **Panel** | T-046, T-015, T-017, T-025 | Login con roles, configuración de agenda y mensajes, pausar el bot |
| **Calidad** | T-020, T-031, T-033 | Logging estructurado con redacción de secretos, suite de husos, fallback ante caídas |

⚠️ **RNF-04 es incumplible como está escrito.** Medido con envíos reales: `freeBusy` (440 ms p95) más el envío de Meta (1.921 ms p95) suman **2.361 ms** contra un presupuesto de 1.500. Las dos llamadas a terceros solas lo superan. El KPI de negocio —respuesta en menos de 15 s— **sí se cumple**, con margen. Ver `plan-for-diego/resultado-spike-t005-freebusy.md`.

⚠️ **Meta acepta el número argentino sin el 9 y devuelve el `wa_id` con el 9.** Responder al `from` del webhook tal cual **falla**. Usá siempre `NumeroDeWhatsApp::paraEnviar()` del namespace `App\Meta`.

Antes de escribir código para un ticket, leé `.claude/docs/tickets/0NN-*.md` completo — trae su alcance, sus criterios de aceptación y lo que quedó fuera.

## Decisiones cerradas — no las vuelvas a discutir

| Decisión | Resolución | Dónde está escrito |
| :-- | :-- | :-- |
| Multi-tenancy | **Por columna `tenant_id`** con Global Scopes. No por schema | RNF-01, C4 L2, C4 L3, modelo de datos |
| Estado conversacional | **Las dos:** `conversations` es fuente de verdad, Redis es caché y locks | `03-flujos/05-estados-flujo-conversacional.md` §1 |
| Zonas horarias | Base **siempre en UTC**. Conversión a la zona del tenant solo en el borde | RNF-02 |

## Las dos invariantes

Se violan en silencio: el sistema sigue funcionando y devuelve datos mal.

### `tenant_id` en todo

- Toda tabla de negocio lleva `tenant_id`, clave foránea a `tenants` y borrado en cascada.
- Todo modelo aplica el Global Scope de tenant. Un modelo sin scope queda expuesto a la próxima query escrita sin filtro.
- **Los Global Scopes no aplican** a `DB::table()`, `DB::raw()`, `whereRaw()`, joins manuales ni subconsultas. Ahí el filtro va explícito.
- Todo índice compuesto **lidera con `tenant_id`**. Los dos obligatorios: `conversations(tenant_id, user_phone)` y `bookings(tenant_id, start_time, status)`.
- Jobs, listeners, tareas del scheduler y comandos artisan **no heredan el tenant**: viaja en el payload y se aplica antes de la primera query.
- Toda llave de Redis lleva el tenant adentro: `session:tenant:{id}:phone:{phone}`, `pause:...`. Sin eso, colisionan entre PyMEs.

### UTC en la base

- `APP_TIMEZONE=UTC`. Ninguna escritura depende de la zona del proceso.
- Se convierte a la zona del tenant en dos lugares y en ninguno más: al llamar a Google y al redactar el texto que sale por WhatsApp.
- Aritmética de fechas con Carbon y zona, nunca sumando o restando horas fijas — `America/Santiago` tiene cambio estacional.
- Columnas `datetime` con su `$cast` en el modelo.
- Todo recordatorio muestra la hora local del tenant, nunca UTC.

## Ingesta de webhooks

El camino crítico del producto. RNF-04 fija **latencia total interna ≤ 1,5 s** y el KPI de negocio es **respuesta < 15 s**.

1. El endpoint valida la firma sobre el **cuerpo crudo**, antes de cualquier parseo de Laravel.
2. Responde `200` en **< 200 ms**, antes de procesar.
3. Encola el payload íntegro en Redis. **Ninguna lógica de negocio corre dentro del ciclo del request.**
4. El job deduplica por el `id` de mensaje de Meta: Meta reintenta, y sin deduplicar un reintento crea un turno duplicado.
5. Los jobs que agotan reintentos quedan en `failed_jobs` con payload y causa.

## Máquina de estados

- `conversations.current_state` y `context_data` son la fuente de verdad. Redis `session:` es caché de lectura y lock.
- Lock por `(tenant_id, user_phone)` durante la transición: dos mensajes en ráfaga no ejecutan la transición dos veces.
- Las transiciones no declaradas se rechazan; el bot recuerda qué se espera y no cambia de estado.
- Redis y MySQL nunca divergen: **MySQL manda**.
- ⚠️ RF-A3 declara 4 estados y el catálogo de `03-flujos/` declara 11. El catálogo es el vigente; la estimación de T-018 quedó sobre los 4.

## Manejo de errores

RNF-03 y CA-06. **Ningún camino del worker termina en silencio.**

- Los fallos de terceros se convierten en resultados tipados, no en excepciones que cuelgan el chat.
- Ante cualquier fallo, el cliente recibe el mensaje de cortesía configurado por tenant.
- La excepción queda en el log estructurado con `tenant_id`, timestamp e integración.
- **Ningún** `access_token`, `refresh_token`, `verify_token` ni firma HMAC aparece en el log, ni parcialmente.
- Credenciales revocadas: la integración queda en `status='expired'` y se deja de intentar.

## Panel Vue 3

- Autenticación con Sanctum y sesión por tenant.
- El Global Scope filtra igual una consulta escrita sin filtro explícito.
- Acceso cruzado entre tenants: `HTTP 403` y registro del intento.
- El token de sesión no queda en almacenamiento accesible por scripts.

## Cómo trabajar un ticket

1. Leé `.claude/docs/tickets/0NN-*.md` completo, incluido `## Fuera de alcance` y su bloque `**Recorte Pareto.**` si lo tiene.
2. Si el ticket está clasificado `DIFERIDO` o `RECORTADO` en su fila `Pareto`, **no lo construyas** sin preguntar: quedó fuera del MVP recortado.
3. Construí solo lo que piden sus criterios de aceptación. Lo que el bloque de recorte declara diferido **no se construye**.
4. Respetá `Depende de`: si un ticket previo no está, decilo antes de empezar.
5. Toda decisión abierta o dato faltante se marca `⚠️`. **No se inventa el detalle.**

⚠️ Cinco decisiones siguen abiertas y bloquean tickets del núcleo: Outlook en el MVP, dónde se captura el nombre, el TTL de inactividad, compensar o conciliar, y cuándo se toma la reserva temporal. Todas viven en T-007.
