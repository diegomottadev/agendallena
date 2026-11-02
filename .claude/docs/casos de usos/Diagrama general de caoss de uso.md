graph LR
    subgraph Actores
        CF["👤 Cliente / Prospecto<br>(WhatsApp)"]
        EMP["👩‍💼 Empleado / Profesional<br>(Calendario Movil)"]
        ADM["👨‍💻 Dueño / Gerente PyME<br>(Dashboard Admin)"]
    end

    subgraph SystemBoundary["Middleware WhatsApp-First (Sistema Core)"]
        UC1("(UC-01: Cotizar Servicio Paramétrico)")
        UC2("(UC-02: Consultar Disponibilidad)")
        UC3("(UC-03: Reservar Turno en Chat)")
        UC4("(UC-04: Confirmar / Re-agendar Turno)")
        UC5("(UC-05: Sincronizar Agenda Inversa)")
        UC6("(UC-06: Vincular Cuentas OAuth)")
        UC7("(UC-07: Configurar Reglas y Flujos)")
        UC8("(UC-08: Consultar Logs y Métricas)")
    end

    subgraph ExternalSystems["Sistemas Externos"]
        API_WA["💬 Meta WhatsApp API"]
        API_CAL["📅 Google Calendar API"]
        API_SHT["📊 Google Sheets API"]
    end

    %% Relaciones Cliente
    CF --> UC1
    CF --> UC2
    CF --> UC3
    CF --> UC4

    %% Relaciones Empleado
    EMP --> UC5

    %% Relaciones Admin
    ADM --> UC6
    ADM --> UC7
    ADM --> UC8

    %% Integraciones Sistemas
    UC1 -.-> API_WA
    UC3 -.-> API_WA
    UC3 -.-> API_CAL
    UC3 -.-> API_SHT
    UC4 -.-> API_WA
    UC4 -.-> API_CAL
    UC5 -.-> API_CAL
    UC6 -.-> API_CAL