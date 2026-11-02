# Modelo Relacional de Base de Datos (MySQL 8 / Laravel Eloquent)

Este modelo aplica una arquitectura Multi-Tenant compartida con clave discriminadora (`tenant_id`), indexación compuesta para consultas de baja latencia y aislamiento lógico de datos.

---

## 1. Diccionario de Entidades y Atributos

### A. `tenants` (Organizaciones / PyMEs)
Almacena las empresas clientes de la plataforma.

| Atributo | Tipo de Dato | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | `CHAR(36)` | `PK` | UUID único de la PyME |
| `name` | `VARCHAR(255)` | `NOT NULL` | Nombre comercial de la empresa |
| `slug` | `VARCHAR(100)` | `NOT NULL, UNIQUE` | Identificador único para subdominios/rutas |
| `status` | `ENUM` | `NOT NULL` | `'active'`, `'suspended'`, `'trial'` |
| `timezone` | `VARCHAR(50)` | `DEFAULT 'America/Argentina/Buenos_Aires'` | Zona horaria operativa del cliente |
| `created_at` | `TIMESTAMP` | `DEFAULT CURRENT_TIMESTAMP` | Fecha de alta |
| `updated_at` | `TIMESTAMP` | `ON UPDATE CURRENT_TIMESTAMP` | Fecha de actualización |

---

### B. `users` (Usuarios Administrativos del Dashboard)
Personal de la PyME que accede al panel Vue.js.

| Atributo | Tipo de Dato | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | `CHAR(36)` | `PK` | UUID único del usuario |
| `tenant_id` | `CHAR(36)` | `FK (tenants.id), NOT NULL` | Pertenencia a la PyME |
| `name` | `VARCHAR(255)` | `NOT NULL` | Nombre completo |
| `email` | `VARCHAR(255)` | `NOT NULL` | Correo electrónico de acceso |
| `password` | `VARCHAR(255)` | `NOT NULL` | Hash de clave (Bcrypt) |
| `role` | `ENUM` | `DEFAULT 'staff'` | `'owner'`, `'admin'`, `'staff'` |
| `created_at` | `TIMESTAMP` | `DEFAULT CURRENT_TIMESTAMP` | Fecha de alta |
| `updated_at` | `TIMESTAMP` | `ON UPDATE CURRENT_TIMESTAMP` | Fecha de actualización |

---

### C. `integrations` (Credenciales y Conexiones Externas)
Conexiones OAuth 2.0 y claves de API por tenant.

| Atributo | Tipo de Dato | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | `BIGINT UNSIGNED` | `PK, AUTO_INCREMENT` | ID interno |
| `tenant_id` | `CHAR(36)` | `FK (tenants.id), NOT NULL` | Pertenencia a la PyME |
| `provider` | `ENUM` | `NOT NULL` | `'meta_whatsapp'`, `'google_calendar'`, `'outlook_calendar'`, `'google_sheets'` |
| `account_identifier` | `VARCHAR(255)` | `NOT NULL` | ID de Teléfono WhatsApp / Email de Google |
| `access_token` | `TEXT` | `NOT NULL` | Encriptado en reposo (Laravel Encrypter) |
| `refresh_token` | `TEXT` | `NULLABLE` | Token de refresco OAuth (Encriptado) |
| `expires_at` | `TIMESTAMP` | `NULLABLE` | Expiración del token |
| `settings` | `JSON` | `NULLABLE` | Webhook secrets, Sheet ID, Calendar ID objetivo |
| `status` | `ENUM` | `DEFAULT 'connected'` | `'connected'`, `'disconnected'`, `'expired'` |

---

### D. `quote_rules` (Reglas y Fórmulas de Cotización)
Configuración del motor paramétrico de presupuestos.

| Atributo | Tipo de Dato | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | `BIGINT UNSIGNED` | `PK, AUTO_INCREMENT` | ID de la regla |
| `tenant_id` | `CHAR(36)` | `FK (tenants.id), NOT NULL` | Pertenencia a la PyME |
| `name` | `VARCHAR(100)` | `NOT NULL` | Ej: "Cotizador Limpieza m²" |
| `formula_expression` | `VARCHAR(255)` | `NOT NULL` | Ej: `base_price + (surface * unit_price)` |
| `parameters_schema` | `JSON` | `NOT NULL` | Estructura de preguntas/opciones en WhatsApp |
| `is_active` | `BOOLEAN` | `DEFAULT TRUE` | Estado de la regla |

---

### E. `conversations` (Máquina de Estados de WhatsApp)
Persistencia del estado conversacional por usuario final.

| Atributo | Tipo de Dato | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | `BIGINT UNSIGNED` | `PK, AUTO_INCREMENT` | ID de sesión |
| `tenant_id` | `CHAR(36)` | `FK (tenants.id), NOT NULL` | Pertenencia a la PyME |
| `user_phone` | `VARCHAR(50)` | `NOT NULL` | Teléfono del cliente final con código de país |
| `user_name` | `VARCHAR(255)` | `NULLABLE` | Nombre capturado en el chat |
| `current_state` | `VARCHAR(100)` | `NOT NULL` | Ej: `'IDLE'`, `'CALCULATING_QUOTE'`, `'SELECTING_SLOT'` |
| `context_data` | `JSON` | `NULLABLE` | Payload temporal (variables de cotización, slots) |
| `last_interaction_at` | `TIMESTAMP` | `NOT NULL` | Control de ventana de 24 hs de Meta |

---

### F. `bookings` (Reservas y Turnos Confirmados)
Citas agendadas e integradas con Google Calendar / Outlook.

| Atributo | Tipo de Dato | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | `BIGINT UNSIGNED` | `PK, AUTO_INCREMENT` | ID del turno |
| `tenant_id` | `CHAR(36)` | `FK (tenants.id), NOT NULL` | Pertenencia a la PyME |
| `conversation_id` | `BIGINT UNSIGNED` | `FK (conversations.id), NULLABLE` | Origen de la conversación |
| `integration_id` | `BIGINT UNSIGNED` | `FK (integrations.id), NOT NULL` | Calendario donde se creó |
| `external_event_id` | `VARCHAR(255)` | `NOT NULL` | ID devuelto por la API de Google/Outlook |
| `client_name` | `VARCHAR(255)` | `NOT NULL` | Nombre del paciente/cliente |
| `client_phone` | `VARCHAR(50)` | `NOT NULL` | WhatsApp de contacto |
| `service_name` | `VARCHAR(150)` | `NOT NULL` | Tipo de servicio reservado |
| `quote_amount` | `DECIMAL(10,2)` | `NULLABLE` | Monto cotizado |
| `start_time` | `DATETIME` | `NOT NULL` | Inicio de la cita (UTC) |
| `end_time` | `DATETIME` | `NOT NULL` | Fin de la cita (UTC) |
| `status` | `ENUM` | `DEFAULT 'scheduled'` | `'scheduled'`, `'confirmed'`, `'rescheduled'`, `'cancelled'` |

---

### G. `notification_logs` (Recordatorios y Notificaciones)
Trazabilidad de plantillas oficiales enviadas por WhatsApp.

| Atributo | Tipo de Dato | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | `BIGINT UNSIGNED` | `PK, AUTO_INCREMENT` | ID del log |
| `tenant_id` | `CHAR(36)` | `FK (tenants.id), NOT NULL` | Pertenencia a la PyME |
| `booking_id` | `BIGINT UNSIGNED` | `FK (bookings.id), NOT NULL` | Cita asociada |
| `type` | `ENUM` | `NOT NULL` | `'reminder_24h'`, `'reminder_2h'`, `'confirmation'` |
| `whatsapp_message_id` | `VARCHAR(255)` | `NULLABLE` | ID de mensaje devuelto por Meta |
| `status` | `ENUM` | `DEFAULT 'queued'` | `'queued'`, `'sent'`, `'delivered'`, `'read'`, `'failed'` |
| `sent_at` | `TIMESTAMP` | `NULLABLE` | Fecha/hora efectiva de envío |

---

## 2. Mapa de Relaciones (Para trasladar a ERD)

* **`tenants (1) -> (N) users`**
  * **Relación:** Una PyME tiene múltiples usuarios de panel.
  * **FK:** `users.tenant_id` referencia a `tenants.id`.
* **`tenants (1) -> (N) integrations`**
  * **Relación:** Una PyME configura múltiples conexiones externas (Meta, Google, Sheets).
  * **FK:** `integrations.tenant_id` referencia a `tenants.id`.
* **`tenants (1) -> (N) quote_rules`**
  * **Relación:** Una PyME define una o más reglas de cotización.
  * **FK:** `quote_rules.tenant_id` referencia a `tenants.id`.
* **`tenants (1) -> (N) conversations`**
  * **Relación:** Una PyME procesa múltiples chats concurrentes.
  * **FK:** `conversations.tenant_id` referencia a `tenants.id`.
* **`tenants (1) -> (N) bookings`**
  * **Relación:** Una PyME posee un historial de turnos reservados.
  * **FK:** `bookings.tenant_id` referencia a `tenants.id`.
* **`conversations (1) -> (N) bookings`**
  * **Relación:** Un cliente desde un chat puede generar uno o varios turnos a lo largo del tiempo.
  * **FK:** `bookings.conversation_id` referencia a `conversations.id`.
* **`integrations (1) -> (N) bookings`**
  * **Relación:** Un calendario integrado contiene múltiples turnos creados.
  * **FK:** `bookings.integration_id` referencia a `integrations.id`.
* **`bookings (1) -> (N) notification_logs`**
  * **Relación:** Un turno genera notificaciones de recordatorio ($t-24\text{ hs}$, $t-2\text{ hs}$).
  * **FK:** `notification_logs.booking_id` referencia a `bookings.id`.

---

## 3. Índices Críticos de Rendimiento (MySQL)

Para garantizar respuestas en $< 15$ milisegundos en el procesamiento de webhooks:

```sql
-- Búsqueda ultrarrápida del estado conversacional por teléfono y PyME
CREATE UNIQUE INDEX idx_tenant_phone ON conversations (tenant_id, user_phone);

-- Filtro del Worker de Colas para recordatorios temporizados
CREATE INDEX idx_bookings_reminder ON bookings (tenant_id, status, start_time);

-- Detección de colisiones de eventos en el calendario
CREATE INDEX idx_bookings_time_slots ON bookings (tenant_id, integration_id, start_time, end_time);