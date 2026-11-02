# Modelo de Datos Técnico (MySQL 8 / Laravel Eloquent)

**Documento:** `docs/02-arquitectura/01-modelo-de-datos-tecnico.md`  
**Motor de BD:** MySQL 8.0 (InnoDB, `utf8mb4_unicode_ci`)  
**Cache & Memory Store:** Redis 7.x  
**Estrategia Multi-Tenant:** Base de datos compartida con discriminador lógico (`tenant_id`) e indexación compuesta obligatoria.

---

## 1. Visión General y Definición Estratégica

El modelo de datos está diseñado bajo el patrón **Multi-Tenant de Base de Datos Compartida (Discriminador Clave)**. Todas las tablas relacionales de dominio e historial contienen una columna discriminadora `tenant_id` vinculada mediante clave foránea a la tabla `tenants`.

### Principios Fundamentales

* **Aislamiento Lógico:** Garantizado a nivel de ORM mediante *Global Scopes* en Laravel (`BelongsToTenantTrait`), forzando `WHERE tenant_id = ?` en cada consulta.
* **Estrategia de Claves Primarias (PK):**
  * **UUID v4 (`CHAR(36)`):** Utilizado en entidades raíz de configuración y seguridad (`tenants`, `users`) para evitar la enumeración de recursos y facilitar la federación.
  * **Big Integer Auto-incremental (`BIGINT UNSIGNED`):** Utilizado en tablas relacionales de alto volumen transaccional (`conversations`, `bookings`, `notification_logs`) para optimizar el espacio de índices B-Tree.
* **Optimización de Latencia:** Los cuatro índices compuestos que sostienen el RNF-04 (resolución de conversación en la Ingesta Webhook, consulta del Scheduler, resolución de notificación entrante de Google y el candado contra recordatorios duplicados) están definidos en el DDL de `conversations`, `bookings` y `notification_logs` más abajo (T-009).

---

## 2. Sentencias DDL de Creación de Tablas (MySQL 8.0)

```sql
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------
-- Table: tenants
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS tenants (
    id CHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    status ENUM('active', 'suspended', 'trial') NOT NULL DEFAULT 'trial',
    timezone VARCHAR(50) NOT NULL DEFAULT 'America/Argentina/Buenos_Aires',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE INDEX idx_tenants_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table: users
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('owner', 'admin', 'staff') NOT NULL DEFAULT 'staff',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE INDEX idx_users_email (email),
    CONSTRAINT fk_users_tenants FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table: integrations
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS integrations (
    id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    provider ENUM('meta_whatsapp', 'google_calendar', 'outlook_calendar', 'google_sheets') NOT NULL,
    account_identifier VARCHAR(255) NOT NULL,
    access_token TEXT NOT NULL,
    refresh_token TEXT NULL,
    expires_at TIMESTAMP NULL,
    settings JSON NULL,
    status ENUM('connected', 'disconnected', 'expired') NOT NULL DEFAULT 'connected',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_integrations_tenants FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table: quote_rules
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS quote_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    name VARCHAR(100) NOT NULL,
    formula_expression VARCHAR(255) NOT NULL,
    parameters_schema JSON NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_quote_rules_tenants FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table: business_settings   (T-014)
--
-- Configuracion operativa del negocio, una fila por tenant.
--
-- `business_hours` va en JSON y no en columnas por dia por decision de T-014:
-- la jornada partida es la norma en las PyMEs latinoamericanas (9 a 13 y 16 a
-- 20), y un par de columnas apertura/cierre no la puede expresar. Forma:
--   {"mon": [["09:00","13:00"], ["16:00","20:00"]], "sun": []}
-- Lista vacia = cerrado ese dia; no hace falta columna de dias laborables.
--
-- Las horas son LOCALES al tenant, no UTC: son reglas de negocio, no instantes.
-- La conversion la hace el motor de disponibilidad con `tenants.timezone`.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS business_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    business_hours JSON NOT NULL,
    slot_duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    buffer_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    welcome_message TEXT NOT NULL,
    fallback_message TEXT NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_business_settings_tenants FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE INDEX idx_business_settings_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table: messages   (T-048, adelantada del sprint 8)
--
-- AC-14.4 promete "el historial completo de mensajes" y `conversations` guarda
-- el ESTADO de la conversacion, no la conversacion. Tambien la necesita AC-08.1:
-- un mensaje recibido durante una pausa humana tiene que quedar registrado.
--
-- RETENCION: 12 meses. Es la tabla de mayor crecimiento y la unica que guarda
-- contenido de conversaciones de terceros — el cliente final de la PyME no es
-- usuario nuestro. Doce meses cubre un ciclo de estacionalidad completo sin
-- volverse un archivo indefinido de conversaciones ajenas.
-- ⚠️ La politica esta DEFINIDA, no APLICADA: no hay tarea de purgado todavia.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    direction ENUM('inbound', 'outbound') NOT NULL,
    -- VARCHAR y no ENUM: los tipos los define Meta y agrega nuevos. Un ENUM
    -- obligaria a migrar la tabla mas grande del sistema cada vez.
    type VARCHAR(50) NOT NULL,
    content TEXT NULL,
    payload JSON NULL,
    whatsapp_message_id VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_messages_tenants FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_messages_conversations FOREIGN KEY (conversation_id)
        REFERENCES conversations (id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_messages_tenant_conversation_created (tenant_id, conversation_id, created_at),
    -- Idempotencia del historial: un reintento del job no duplica la fila.
    -- En MySQL un unico admite varios NULL, asi que no estorba a los salientes
    -- que todavia no tienen `wamid`.
    UNIQUE INDEX idx_messages_tenant_wamid (tenant_id, whatsapp_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table: processed_messages   (T-011)
--
-- Candado de idempotencia de la ingesta. Meta reentrega todo webhook que no
-- recibio 200 a tiempo; sin esto el bot le responde dos veces al mismo mensaje.
-- Va en MySQL y no en Redis a proposito: Redis es cache en este proyecto, y un
-- FLUSHALL reabriria la ventana de duplicados.
--
-- ⚠️ Sin politica de purga: crece sin techo. Antes de escalar hay que borrar lo
-- anterior a la ventana de reintentos de Meta.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS processed_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    message_id VARCHAR(255) NOT NULL,
    processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_processed_messages_tenants FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE INDEX idx_processed_messages_tenant_message (tenant_id, message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table: conversations
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS conversations (
    id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    user_phone VARCHAR(50) NOT NULL,
    user_name VARCHAR(255) NULL,
    current_state VARCHAR(100) NOT NULL DEFAULT 'IDLE',
    context_data JSON NULL,
    last_interaction_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_conversations_tenants FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_conversations_tenant_phone (tenant_id, user_phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table: bookings
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS bookings (
    id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    conversation_id BIGINT UNSIGNED NULL,
    integration_id BIGINT UNSIGNED NOT NULL,
    external_event_id VARCHAR(255) NOT NULL,
    client_name VARCHAR(255) NOT NULL,
    client_phone VARCHAR(50) NOT NULL,
    service_name VARCHAR(150) NOT NULL,
    quote_amount DECIMAL(10, 2) NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    status ENUM('scheduled', 'confirmed', 'rescheduled', 'cancelled') NOT NULL DEFAULT 'scheduled',
    -- T-036 · Asistencia en COLUMNA PROPIA, no como valor de `status`.
    -- `status` responde "que paso con la reserva"; `attendance` responde "vino o
    -- no". Son ortogonales: un turno puede estar `confirmed` y `no_show` a la
    -- vez, y ese es el caso que sostiene AC-17.4. Si `no_show` fuera un valor de
    -- `status`, marcar la ausencia pisaria el dato de que habia confirmado, y
    -- sin ese cruce se observa que el ausentismo bajo pero no se le puede
    -- atribuir causa.
    attendance ENUM('pending', 'attended', 'no_show') NOT NULL DEFAULT 'pending',
    -- ⚠️ BIGINT y no CHAR(36): `users.id` es `bigint unsigned` en el codigo, pese
    -- a que el DDL de `users` de mas arriba lo declara UUID. La migracion de
    -- usuarios quedo con el default de Laravel. Unificarlos pertenece a T-046.
    attendance_marked_by BIGINT UNSIGNED NULL,
    attendance_marked_at TIMESTAMP NULL,
    -- T-030 · Columna GENERADA: vale `external_event_id` mientras el turno este
    -- vivo y NULL cuando deja de estarlo. Existe para que el unico de mas abajo
    -- se aplique solo entre turnos vivos.
    --
    -- ⚠️ T-042 · "Vivo" son DOS estados, no uno: cancelado y re-agendado son los
    -- dos casos en los que el evento de Google ya no nos pertenece —en el
    -- primero lo borramos al cancelar, en el segundo al mover el turno—. Cuando
    -- solo se listaba `cancelled`, la fila re-agendada seguia reclamando un
    -- evento borrado y el cliente que movia su turno y volvia al horario
    -- original recibia "quedo reservado" sin turno vivo.
    --
    -- El unico anterior era `(tenant_id, external_event_id)` a secas, y la fila
    -- `cancelled` lo seguia ocupando: el cliente que cancelaba y volvia a pedir
    -- el mismo horario recibia "quedo reservado" sin evento ni turno vivo,
    -- porque el INSERT chocaba contra la fila muerta y se leia como "ya estaba
    -- persistido". En MySQL dos NULL no chocan entre si en un unico, asi que la
    -- fila cancelada suelta el candado sola y nadie tiene que acordarse de
    -- limpiarla en cada camino de cancelacion.
    --
    -- ⚠️ La fila cancelada CONSERVA su `external_event_id`: que un cliente
    -- cancelo y volvio a agendar el mismo horario es justo el dato que el piloto
    -- va a querer mirar.
    live_event_id VARCHAR(255) GENERATED ALWAYS AS (
        CASE WHEN status IN ('cancelled', 'rescheduled') THEN NULL ELSE external_event_id END
    ) VIRTUAL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_bookings_users_attendance FOREIGN KEY (attendance_marked_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_bookings_tenants FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_bookings_conversations FOREIGN KEY (conversation_id)
        REFERENCES conversations (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_bookings_integrations FOREIGN KEY (integration_id)
        REFERENCES integrations (id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_bookings_tenant_start_status (tenant_id, start_time, status),
    -- T-030 · Un evento de Google no puede ser reclamado por dos turnos VIVOS
    -- del mismo tenant. El candado vive en el esquema y no en un `if` de PHP:
    -- una comprobacion en codigo deja la ventana de carrera abierta entre dos
    -- workers.
    UNIQUE INDEX idx_bookings_tenant_live_event (tenant_id, live_event_id),
    -- ⚠️ Este indice es de LECTURA y va aparte del unico de arriba. Resuelve la
    -- busqueda por (tenant_id, external_event_id) de la conciliacion (T-030) y
    -- de la notificacion entrante de Google (T-027). Cuando el unico paso a
    -- `live_event_id` dejo de cubrir esos planes, asi que se declara explicito.
    INDEX idx_bookings_tenant_external_event (tenant_id, external_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table: notification_logs
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    booking_id BIGINT UNSIGNED NOT NULL,
    type ENUM('reminder_24h', 'reminder_2h', 'confirmation') NOT NULL,
    whatsapp_message_id VARCHAR(255) NULL,
    status ENUM('queued', 'sent', 'delivered', 'read', 'failed') NOT NULL DEFAULT 'queued',
    sent_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_notification_logs_tenants FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_notification_logs_bookings FOREIGN KEY (booking_id)
        REFERENCES bookings (id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE INDEX idx_notification_logs_booking_type (booking_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;