# Roles, permisos y pantallas del panel

**Cierra [H-13](../stories-maps/05-huecos-y-trazabilidad.md#h-13)** · Insumo de T-046, T-015, T-017, T-025, T-038, T-047 y T-049.

`users.role ENUM('owner','admin','staff')` existía en el modelo de datos desde el principio y **ninguna historia decía qué puede hacer cada uno**. Este documento lo define.

Nada acá se inventó: el reparto sale del backbone de [`02-mapa-equipo-pyme.md`](../stories-maps/02-mapa-equipo-pyme.md), que ya asigna cada actividad a su actor, y del diagrama de casos de uso de [`03-flujos/03-casos-de-uso-equipo-pyme.md`](../03-flujos/03-casos-de-uso-equipo-pyme.md).

---

## 1. El principio que ordena todo

La historia de H-13 lo dice en una línea:

> **Como** dueña de PyME, **quiero** que mi staff vea los chats pero no toque la configuración ni las integraciones, **para** delegar la atención sin delegar el control.

De ahí sale el corte: **atender no es configurar.** Un empleado tiene que poder responderle a un cliente sin poder cambiar el horario del negocio, desconectar el calendario ni dar de alta usuarios.

## 2. Los tres roles

| Rol | Quién es | En una frase |
| :-- | :-- | :-- |
| **`owner`** | La dueña o el dueño | Manda. Único que gestiona usuarios y da de baja la cuenta |
| **`admin`** | Encargado, socio, mano derecha | Todo lo operativo y la configuración. **No** gestiona usuarios |
| **`staff`** | Empleado, vendedor, profesional | Atiende y consulta. **No** configura ni toca integraciones |

**`admin` existe para delegar sin ceder la cuenta.** La diferencia con `owner` es una sola cosa —quién puede crear y borrar usuarios— pero es la que impide que un encargado que se va se lleve el acceso.

⚠️ **El diagrama de casos de uso nombra cuatro actores** (dueño, admin, staff de ventas, empleado/profesional) y el `ENUM` tiene tres. *Profesional* se mapea a **`staff`**: en el MVP la diferencia entre "quien vende" y "quien atiende" no cambia ningún permiso. Si aparece B4.8 (asignar conversaciones a empleados) esa distinción vuelve, y ahí sí hará falta un cuarto rol o un mecanismo de asignación.

## 3. Qué puede hacer cada rol

Derivado actividad por actividad del backbone. `✅ puede` · `👁️ solo lectura` · `❌ no`

| # | Actividad | `owner` | `admin` | `staff` |
| :-- | :-- | :-: | :-: | :-: |
| **B1** | **Dar de alta** | | | |
| B1.3 | Iniciar sesión | ✅ | ✅ | ✅ |
| B1.4 | Dar de alta y quitar usuarios | ✅ | ❌ | ❌ |
| **B2** | **Integraciones** | | | |
| B2.1 | Conectar / reconectar Google Calendar | ✅ | ✅ | ❌ |
| B2.4 | Vincular la planilla de Sheets | ✅ | ✅ | ❌ |
| B2.5 | Ver el estado de las conexiones | ✅ | ✅ | 👁️ |
| **B3** | **Configurar el negocio** | | | |
| B3.1–2 | Horario, días, duración y buffer | ✅ | ✅ | ❌ |
| B3.3 | Zona horaria | ✅ | ✅ | ❌ |
| B3.4 | Fórmulas de precio | ✅ | ✅ | ❌ |
| B3.5 | Textos de bienvenida y fallback | ✅ | ✅ | ❌ |
| **B4** | **Atender el día a día** | | | |
| B4.4 | Pausar o reactivar el bot | ✅ | ✅ | ✅ |
| B4.7 | Atender una consulta derivada | ✅ | ✅ | ✅ |
| **B5** | **Supervisar** | | | |
| B5.1 | Lista de conversaciones | ✅ | ✅ | ✅ |
| B5.3 | Detalle e historial de mensajes | ✅ | ✅ | ✅ |
| B5.6 | Marcar asistencia | ✅ | ✅ | ✅ |
| **B6** | **Incidentes** | | | |
| B6.2 | Ver que una integración se cayó | ✅ | ✅ | 👁️ |
| B6.5 | Personalizar el mensaje de cortesía | ✅ | ✅ | ❌ |

### Las tres reglas que resumen la tabla

1. **Configurar es de `owner` y `admin`.** Todo B3, más conectar integraciones.
2. **Atender y supervisar es de los tres.** Todo B4 y B5, incluida la pausa del bot: si un empleado está atendiendo a mano y no puede callar al bot, la funcionalidad no sirve.
3. **Gestionar usuarios es solo de `owner`.** Es la única diferencia entre `owner` y `admin`.

⚠️ **`staff` ve las conversaciones completas**, con teléfono y contenido de los mensajes. Es lo que la historia de H-13 pide explícitamente ("que mi staff vea los chats"), pero conviene que el dueño lo sepa: no hay vista censurada.

## 4. Las pantallas del panel

| Pantalla | Ruta | Quién entra | Ticket |
| :-- | :-- | :-- | :-- |
| Login | `/login` | todos | T-046 |
| Conversaciones (lista) | `/panel/conversaciones` | todos | T-047 |
| Conversación (detalle) | `/panel/conversaciones/{id}` | todos | T-049 |
| Turnos y asistencia | `/panel/turnos` | todos | T-038 |
| Integraciones | `/panel/integraciones` | `owner`, `admin` | **T-012 ✅ hecha** |
| Configuración › Agenda | `/panel/configuracion/agenda` | `owner`, `admin` | T-015 |
| Configuración › Mensajes | `/panel/configuracion/mensajes` | `owner`, `admin` | T-017 |
| Configuración › Precios | `/panel/configuracion/precios` | `owner`, `admin` | T-016 *(diferido)* |
| Usuarios | `/panel/usuarios` | `owner` | 🕳️ B1.4 — **v2** |

**La pantalla de inicio depende del rol:** `owner` y `admin` entran a Integraciones mientras haya algo sin conectar, y a Conversaciones cuando todo está en verde. `staff` entra siempre a Conversaciones — no tiene nada que hacer en Integraciones.

⚠️ **La pantalla de Usuarios queda en v2.** Es B1.4, marcada 🕳️ y v2 en el story map, y en los pilotos los usuarios los cargamos nosotros por seeder. **Pero la columna `role` se crea igual ahora**: agregarla después, con datos, es una migración con ventana.

## 5. Cómo se aplica

**En el servidor, siempre.** Ocultar un botón en Vue no es un permiso: es una sugerencia. Toda ruta de configuración valida el rol del lado del servidor y responde `403`, con el intento registrado — igual que exige AC-14.2 para el acceso cruzado entre tenants.

El orden de las dos comprobaciones importa: **primero el tenant, después el rol.** El Global Scope de `BelongsToTenant` filtra antes de que la autorización mire nada, así que un `owner` del tenant A pidiendo un recurso del tenant B no encuentra el recurso — no llega a la comprobación de rol.

## 6. Decisiones que este documento **no** toma

- ⚠️ **`users.email` es único global, no por tenant** ([H-14](../stories-maps/05-huecos-y-trazabilidad.md#h-14)). Una misma persona no puede tener cuenta en dos PyMEs. Se nota cuando un gestor administra dos negocios, o un profesional trabaja en dos locales. **Se resuelve en T-046**, junto con la migración de `users`.
- ⚠️ **No hay rol de plataforma.** Los tres son internos a una PyME: ningún usuario nuestro puede asistir a un cliente desde el panel. Hoy el soporte se hace en la base. Cuando exista, **no debería ser un valor más de este `ENUM`**: un bug de asignación de rol daría acceso a todos los tenants a la vez.
- ⚠️ **`users` en el código no tiene `tenant_id` ni `role`** — es la tabla por defecto de Laravel. La migración es parte de T-046 y **no está en su alcance de 3 puntos**.
