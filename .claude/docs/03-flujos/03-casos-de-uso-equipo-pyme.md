flowchart TD
    subgraph Config["1. Configuración e Integraciones (Admin PyME)"]
        UC21["UC-2.1: Conectar Google Calendar (OAuth 2.0)"]
        UC22["UC-2.2: Parametrizar Reglas y Fórmulas JSON"]
        UC23["UC-2.3: Configurar Horarios, Duración y Buffers"]
        UC24["UC-2.4: Registrar Credenciales Meta WhatsApp API"]
        UC25["UC-2.5: Vincular Planilla de Google Sheets"]

        Admin([Dueño / Admin PyME]) --> UC21
        Admin --> UC22
        Admin --> UC23
        Admin --> UC24
        Admin --> UC25
    end

    subgraph Operation["2. Monitoreo y Operación (Dashboard Vue 3)"]
        UC26["UC-2.6: Visualizar Métricas de Conversión y No-Shows"]
        UC27["UC-2.7: Monitorear Logs de Chats y Auditoría"]
        UC210["UC-2.10: Forzar Pausa o Reactivación del Bot"]

        Staff([Equipo de Ventas / Staff]) --> UC26
        Staff --> UC27
        Staff --> UC210
    end

    subgraph MobileSync["3. Operación Nativa Móvil y Sync Inversa"]
        UC28["UC-2.8: Bloquear Horario Manual en App Calendar"]
        UC29["UC-2.9: Eliminar Cita desde App Calendar"]

        Pro([Empleado / Profesional]) --> UC28
        Pro --> UC29
    end

    %% Conexiones con Infraestructura y APIs Externa
    UC21 --> GCalAPI[(Google Calendar API)]
    UC24 --> MetaAPI[(Meta Cloud API)]
    UC25 --> GSheetsAPI[(Google Sheets API)]

    UC28 -->|Sincronización FreeBusy| GCalAPI
    UC29 -->|Dispara Watch Webhook| LaravelBackend[Laravel Backend Middleware]
    LaravelBackend -->|Actualiza Reserva a CANCELLED| MySQL[(MySQL Database)]

    UC210 -->|Guarda Key con TTL en Cache| Redis[(Redis Store)]