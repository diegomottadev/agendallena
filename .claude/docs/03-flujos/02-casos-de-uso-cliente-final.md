flowchart TD
    subgraph Cotizacion["1. Cotización Inicial"]
        UC11["UC-1.1: Ingresar Datos de Cotización<br/><i>(Estado: GATHERING_PARAMS)</i>"]
        UC12["UC-1.2: Recibir Presupuesto Estimado<br/><i>(Estado: CALCULATING_QUOTE)</i>"]
    end

    subgraph Reserva["2. Selección y Reserva de Turno"]
        UC13["UC-1.3: Solicitar Horarios Disponibles<br/><i>(Estado: SELECTING_SLOT)</i>"]
        UC14["UC-1.4: Seleccionar Turno con Botón<br/><i>(Estado: SLOT_SELECTED)</i>"]
        UC15["UC-1.5: Recibir Confirmación de Reserva<br/><i>(Estado: BOOKED)</i>"]
    end

    subgraph PostReserva["3. Recordatorios y Gestión de Cita"]
        UC16["UC-1.6: Recibir Recordatorio t-24h / t-2h<br/><i>(Estado: NOTIFIED)</i>"]
        UC17["UC-1.7: Solicitar Re-agendamiento<br/><i>(Estado: RESCHEDULED)</i>"]
        UC18["UC-1.8: Cancelar Cita<br/><i>(Estado: CANCELLED)</i>"]
    end

    subgraph ControlExcepciones["4. Control Manual y Resguardo"]
        UC19["UC-1.9: Intervención Humana Human Takeover<br/><i>(Estado: PAUSED_HUMAN)</i>"]
        UC110["UC-1.10: Recibir Mensaje Fallback Graceful<br/><i>(Estado: ERROR_HANDLED)</i>"]
    end

    %% Flujo Principal
    Client([Cliente / Prospecto]) -->|Mensaje entrante por WhatsApp| UC11
    UC11 -->|Parámetros completos| UC12
    UC12 -->|Presiona 'Agendar Turno'| UC13
    UC13 -->|Selecciona ítem de la lista| UC14
    UC14 -->|Ingresa nombre| UC15

    %% Flujo de Recordatorios y Cambios
    UC15 -.->|Laravel Cron Scheduler| UC16
    UC16 -->|Presiona 'Re-agendar'| UC17
    UC17 -->|Libera cupo anterior| UC13
    UC16 -->|Presiona 'Cancelar'| UC18

    %% Flujos de Excepción e Interrupción
    Client -.->|Respuesta manual del vendedor| UC19
    UC11 -.->|Fallo de API / Excepción| UC110
    UC13 -.->|Fallo de API / Excepción| UC110
    UC15 -.->|Fallo de API / Excepción| UC110

    %% Integraciones Externas
    UC13 <-->|Consulta FreeBusy| GCal[(Google Calendar API)]
    UC15 -->|Crear Evento| GCal
    UC15 -->|Volcar Lead| GSheets[(Google Sheets API)]
    UC19 -->|Set Key TTL 60 min| Redis[(Redis Store)]