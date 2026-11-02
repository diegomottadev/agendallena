flowchart TD
    CF["👤 Cliente / Prospecto"]

    subgraph BoundaryWA["Módulo Conversacional WhatsApp"]
        UC_Cotizar["(UC-1.1: Ingresar Datos de Cotización)"]
        UC_VerPrecio["(UC-1.2: Recibir Presupuesto Estimado)"]
        UC_PedirTurno["(UC-1.3: Solicitar Horarios Disponibles)"]
        UC_ElegirTurno["(UC-1.4: Seleccionar Turno con Botón)"]
        UC_RecibirConf["(UC-1.5: Recibir Confirmación de Reserva)"]
        UC_Recordatorio["(UC-1.6: Recibir Recordatorio a t-24h)"]
        UC_Reagendar["(UC-1.7: Solicitar Re-agendamiento)"]
        UC_Cancelar["(UC-1.8: Cancelar Cita)"]
    end

    CF --> UC_Cotizar
    UC_Cotizar -->|include| UC_VerPrecio
    CF --> UC_PedirTurno
    UC_PedirTurno -->|include| UC_ElegirTurno
    UC_ElegirTurno -->|include| UC_RecibirConf

    UC_Recordatorio --> CF
    CF -->|Responde Botón| UC_Reagendar
    CF -->|Responde Botón| UC_Cancelar
    
    UC_Reagendar -.->|extends| UC_PedirTurno