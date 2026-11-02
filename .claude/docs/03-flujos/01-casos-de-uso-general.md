flowchart TD
    subgraph Actores["Actores del Sistema"]
        Client([Cliente / Prospecto])
        Admin([Dueño / Gerente PyME])
        Pro([Empleado / Profesional])
    end

    subgraph Mod1["Módulo 1: Conversacional WhatsApp (Cliente)"]
        UC11["UC-1.1: Ingresar Datos de Cotización"]
        UC12["UC-1.2: Recibir Presupuesto Estimado"]
        UC13["UC-1.3: Solicitar Horarios Disponibles"]
        UC14["UC-1.4: Seleccionar Turno con Botón"]
        UC15["UC-1.5: Recibir Confirmación de Reserva"]
        UC16["UC-1.6: Recibir Recordatorio a t-24h"]
        UC17["UC-1.7: Solicitar Re-agendamiento"]
        UC18["UC-1.8: Cancelar Cita"]
    end

    subgraph Mod2["Módulo 2: Dashboard Vue.js & Calendar Engine (PyME)"]
        UC21["UC-2.1: Conectar Google Calendar vía OAuth 2.0"]
        UC22["UC-2.2: Parametrizar Precios y Fórmulas"]
        UC23["UC-2.3: Definir Días, Horarios y Buffers"]
        UC24["UC-2.4: Visualizar Conversión y No-Shows"]
        UC25["UC-2.5: Bloquear/Crear Evento en Google Calendar"]
        UC26["UC-2.6: Borrar Evento Manualmente (Sync Inversa)"]
    end

    subgraph ExternalServices["Servicios e Integraciones"]
        GCal[(Google Calendar API)]
        GSheets[(Google Sheets API)]
        DB[(Base de Datos & Colas)]
    end

    %% Interacciones Cliente (Flujo Conversacional)
    Client --> UC11
    UC11 --> UC12
    UC12 --> UC13
    UC13 --> UC14
    UC14 --> UC15

    UC15 -.->|Disparo temporizado t-24h| UC16
    UC16 -->|Presiona Re-agendar| UC17
    UC17 -->|Liberación de turno previo| UC13
    UC16 -->|Presiona Cancelar| UC18

    %% Interacciones Admin / PyME (Dashboard)
    Admin --> UC21
    Admin --> UC22
    Admin --> UC23
    Admin --> UC24

    %% Interacciones Profesional (App Nativa Móvil)
    Pro -->|Desde App Móvil| UC25
    Pro -->|Desde App Móvil| UC26

    %% Conexiones del Sistema
    UC13 <-->|Consulta FreeBusy| GCal
    UC15 -->|Crear Evento| GCal
    UC15 -->|Volcado de datos| GSheets
    UC15 -->|Registro de cita| DB
    UC25 -->|Actualiza FreeBusy| GCal
    UC26 -->|Watch Webhook Notification| DB
    UC18 -->|Eliminar Evento| GCal