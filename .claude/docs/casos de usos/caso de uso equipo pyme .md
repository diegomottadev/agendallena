flowchart TD
    ADM["👨‍💻 Dueño / Gerente PyME"]
    EMP["👩‍💼 Empleado / Profesional"]

    subgraph BoundaryAdmin["Dashboard Vue.js & Calendar Engine"]
        UC_OAuth["(UC-2.1: Conectar Google Calendar vía OAuth 2.0)"]
        UC_Reglas["(UC-2.2: Parametrizar Precios y Fórmulas)"]
        UC_Horarios["(UC-2.3: Definir Días, Horarios y Buffers)"]
        UC_Metrics["(UC-2.4: Visualizar Conversión y No-Shows)"]
        UC_BlockCal["(UC-2.5: Bloquear/Crear Evento en Google Calendar)"]
        UC_DeleteCal["(UC-2.6: Borrar Evento Manualmente)"]
    end

    subgraph DirectEffects["Efecto Automático en el Bot"]
        UC_FreeSlot["(Liberar Slot en WhatsApp)"]
        UC_LockSlot["(Bloquear Slot en WhatsApp)"]
    end

    %% Acciones Admin
    ADM --> UC_OAuth
    ADM --> UC_Reglas
    ADM --> UC_Horarios
    ADM --> UC_Metrics

    %% Acciones Empleado en su celular
    EMP --> UC_BlockCal
    EMP --> UC_DeleteCal

    %% Sincronización Automática
    UC_BlockCal -.->|Trigger Webhook| UC_LockSlot
    UC_DeleteCal -.->|Trigger Webhook| UC_FreeSlot