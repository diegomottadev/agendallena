flowchart TD
    Start([Cliente envía mensaje entrante]) --> CheckHuman{"¿Operador respondió en menos de 60 min?"}
    
    CheckHuman -- Sí (Pausa Activa) --> HumanState[Ignorar automatización / PAUSED_HUMAN]
    CheckHuman -- No --> CheckState{Consultar estado actual en DB}

    CheckState -- Estado: IDLE / Nuevo --> SendGreeting[UC-1.1: Saludo + Pedido de datos de cotización]
    SendGreeting --> StateGathering[Estado: GATHERING_PARAMS]
    
    StateGathering --> WaitParams[Cliente ingresa parámetros]
    WaitParams --> ValidParams{¿Datos válidos?}
    ValidParams -- No --> ReAskParam[Enviar mensaje de corrección de formato] --> WaitParams
    ValidParams -- Sí --> CalcQuote[UC-1.2: Evaluar regla JSON + Calcular monto]
    
    CalcQuote --> SendQuoteMsg["Enviar desglose de presupuesto + Botón 'Agendar Turno'"]
    SendQuoteMsg --> StateCalc[Estado: CALCULATING_QUOTE]
    
    StateCalc --> UserClicksBook{"¿Cliente presiona 'Agendar Turno'?"}
    UserClicksBook -- No / Pregunta libre --> PauseHuman[Activar Human Takeover si responde operador]
    UserClicksBook -- Sí --> FetchSlots[UC-1.3: Consultar FreeBusy de Google Calendar API]
    
    FetchSlots --> HasSlots{¿Existen slots libres?}
    HasSlots -- No --> NoSlotsMsg[Enviar mensaje de disculpa + Contacto con asesor]
    HasSlots -- Sí --> SendListMsg[Enviar Lista Interactiva de Horarios]
    
    SendListMsg --> StateSelecting[Estado: SELECTING_SLOT]
    StateSelecting --> UserSelectsSlot[UC-1.4: Cliente selecciona ítem de la Lista]
    
    UserSelectsSlot --> CheckCollision{¿Slot sigue disponible?}
    CheckCollision -- No (Conflicto) --> CollisionMsg[Notificar colisión] --> FetchSlots
    CheckCollision -- Sí --> AskName[Solicitar Nombre y Apellido]
    
    AskName --> ReceiveName[Cliente envía nombre]
    ReceiveName --> BookEvent[UC-1.5: Crear evento en Google Calendar + Insertar en DB + Sync Sheets]
    
    BookEvent --> SendConfirmation[Enviar mensaje de Confirmación con datos del turno]
    SendConfirmation --> StateBooked[Estado: BOOKED]

    %% Flujo Asíncrono de Recordatorio
    StateBooked -. Cron t-24h .-> SchedulerCheck[UC-1.6: Cron detecta cita a 24 hs]
    SchedulerCheck --> SendReminder["Enviar Plantilla Interactiva: Confirmar / Re-agendar / Cancelar"]
    
    SendReminder --> ReminderAction{Respuesta del Cliente}
    ReminderAction -- 'Confirmar' --> ConfirmBooking[Estado: CONFIRMED + Log en BD]
    ReminderAction -- 'Re-agendar' --> UC17[UC-1.7: Liberar evento en Google + Retomar a SELECTING_SLOT] --> FetchSlots
    ReminderAction -- 'Cancelar' --> UC18[UC-1.8: Eliminar evento en Google + Estado: CANCELLED]