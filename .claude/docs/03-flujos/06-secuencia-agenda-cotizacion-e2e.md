sequenceDiagram
    autonumber
    actor Client as Cliente (WhatsApp)
    participant Meta as Meta Cloud API
    participant Webhook as Webhook Controller & Middleware
    participant Queue as Redis Queue / Worker
    participant FSM as State Machine Engine
    participant Quote as Quote Calculator Engine
    participant DB as MySQL Database
    participant GCal as Google Calendar API
    participant GSheets as Google Sheets API

    Note over Client, Webhook: FASE 1: Ingesta Asincrona (menor a 200 ms)
    Client->>Meta: Envia mensaje ("Hola, presupuesto limpieza")
    Meta->>Webhook: POST /api/v1/webhooks/whatsapp (Payload JSON)
    Webhook->>Webhook: Validar HMAC SHA256 & Extraer tenant_id
    Webhook->>Queue: Push Job (ProcessMessageJob)
    Webhook-->>Meta: HTTP 200 OK (Respuesta ultra-rapida)

    Note over Queue, Quote: FASE 2: Evaluacion de Cotizacion
    Queue->>FSM: Pop Job & Evaluar estado actual
    FSM->>Queue: Verificar Human Takeover (Redis key)
    Note over FSM: Redis lock OK (Sin pausa humana)
    FSM->>DB: Obtener/Crear conversacion (State: GATHERING_PARAMS)
    FSM->>Quote: Evaluar formula JSON con parametros recibidos
    Quote-->>FSM: Monto calculated: 42.000,00 ARS
    FSM->>Meta: POST /messages (Enviar presupuesto + Boton 'Agendar Turno')
    Meta-->>Client: Muestra mensaje interactivo de presupuesto

    Note over Client, GCal: FASE 3: Disponibilidad en Tiempo Real
    Client->>Meta: Clic en boton 'Agendar Turno'
    Meta->>Webhook: POST Webhook (Button Payload)
    Webhook->>Queue: Push Job
    Queue->>FSM: Transicion a SELECTING_SLOT
    FSM->>GCal: GET /freeBusy (Timezone Tenant + Horarios + Buffer 10 min)
    GCal-->>FSM: Lista de slots libres (UTC)
    FSM->>FSM: Convertir slots UTC a Zona Horaria Local del Tenant
    FSM->>Meta: POST /messages (Enviar List Message con slots)
    Meta-->>Client: Renderiza Lista de Turnos Disponibles

    Note over Client, GSheets: FASE 4: Confirmacion y Persistencia
    Client->>Meta: Selecciona slot 'Viernes 7 Ago - 10:00 hs'
    Meta->>Webhook: POST Webhook (List Selection Payload)
    Webhook->>Queue: Push Job
    Queue->>FSM: Transicion a SLOT_SELECTED (Solicitar Nombre)
    Client->>Meta: Envia Nombre ('Martin Gomez')
    Meta->>Webhook: POST Webhook (Text Message)
    Webhook->>Queue: Push Job
    Queue->>FSM: Transicion a BOOKED

    critical Agendamiento Atomico
        FSM->>GCal: POST /events (Crear cita con datos del cliente)
        GCal-->>FSM: Retorna external_event_id
        FSM->>DB: INSERT INTO bookings (tenant_id, start_time, quote_amount, ...)
        FSM->>Queue: Push Job async (AppendToSheetsJob)
    end

    Queue->>GSheets: POST /values/append (Volcado de lead)
    GSheets-->>Queue: HTTP 200 OK

    FSM->>Meta: POST /messages (Enviar Confirmacion de Reserva)
    Meta-->>Client: Mensaje final: 'Cita Confirmada, Martin!'

    Note over DB, Client: FASE 5: Engine de Recordatorios (Cron Scheduler)
    loop Cada 10 Minutos (Laravel Scheduler)
        DB->>Queue: Query turnos en ventana t-24h (Status: scheduled)
        Queue->>Meta: POST /messages (Template interactivo: Confirmar / Re-agendar / Cancelar)
        Meta-->>Client: Llega recordatorio con botones interactivos
    end