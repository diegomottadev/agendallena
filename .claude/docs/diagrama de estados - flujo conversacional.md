stateDiagram-v2
    [*] --> IDLE: Inicia Chat
    IDLE --> GATHERING_PARAMS: Solicita Cotización
    GATHERING_PARAMS --> CALCULATING_QUOTE: Parámetros Completos
    CALCULATING_QUOTE --> SHOWING_QUOTE: Muestra Presupuesto Estimado
    
    IDLE --> CHECKING_AVAILABILITY: Solicita Turno Directo
    SHOWING_QUOTE --> CHECKING_AVAILABILITY: Acepta Presupuesto
    
    CHECKING_AVAILABILITY --> SELECTING_SLOT: Horarios Disponibles
    SELECTING_SLOT --> CONFIRMING_BOOKING: Elige Horario
    CONFIRMING_BOOKING --> BOOKED: Evento Creado en Calendar
    
    BOOKED --> WAITING_REMINDER: Job Scheduled (t-24h)
    WAITING_REMINDER --> CONFIRMED: Responde "Confirmar"
    WAITING_REMINDER --> CHECKING_AVAILABILITY: Responde "Re-agendar"
    WAITING_REMINDER --> CANCELLED: Responde "Cancelar" / Timeout
    
    CONFIRMED --> [*]
    CANCELLED --> [*]