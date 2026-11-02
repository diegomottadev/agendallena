flowchart TB
    subgraph LaravelApp["Contenedor: Laravel Backend Application"]
        
        subgraph HttpLayer["1. Capa HTTP & Ingesta"]
            WebhookCtrl["Webhook Controller<br><i>(Handshake & Fast Ingestion)</i>"]
            AuthMiddleware["SignatureValidation &<br>TenantScope Middleware"]
        end

        subgraph AppLayer["2. Capa de Aplicación & Colas"]
            QueueWorker["ProcessMessageJob Worker<br><i>(Procesamiento Asíncrono)</i>"]
            HumanDetector["Human Takeover Detector<br><i>(Verifica pausas en Redis)</i>"]
            SchedulerService["Notification Scheduler<br><i>(Cron t-24h / t-2h)</i>"]
        end

        subgraph DomainCore["3. Capa de Dominio (Core)"]
            StateMachine["State Machine Engine<br><i>(Gestión de Estados de Chat)</i>"]
            QuoteEngine["Quote Calculator Engine<br><i>(Evaluador de Fórmulas JSON)</i>"]
        end

        subgraph InfraAdapters["4. Capa de Infraestructura (Adapters)"]
            MetaAdapter["Meta Cloud API Client<br><i>(Outbound Messages & Templates)</i>"]
            CalendarAdapter["Google Calendar Adapter<br><i>(OAuth 2.0, FreeBusy & Sync)</i>"]
            SheetsAdapter["Google Sheets Adapter<br><i>(Exportación de Leads)</i>"]
            EloquentRepo["Eloquent Repositories<br><i>(Aislamiento con tenant_id)</i>"]
        end
    end

    subgraph ExternalServices["Sistemas Externos & Data Stores"]
        MetaAPI["Meta WhatsApp Cloud API"]
        GCalAPI["Google Calendar API"]
        GSheetAPI["Google Sheets API"]
        MySQL[("MySQL 8 DB")]
        RedisDB[("Redis Store / Queues")]
    end

    %% Relaciones de Flujo Entrante
    MetaAPI -->|1. Webhook Payload| WebhookCtrl
    GCalAPI -->|Sync Inversa Watch Event| WebhookCtrl
    WebhookCtrl --> AuthMiddleware
    AuthMiddleware -->|2. Fast Push Job| RedisDB
    
    %% Procesamiento Asíncrono
    RedisDB -->|3. Pop Job| QueueWorker
    QueueWorker --> HumanDetector
    HumanDetector -->|Sin Pausa Humana| StateMachine
    
    %% Orquestación de Dominio
    StateMachine --> QuoteEngine
    StateMachine --> CalendarAdapter
    StateMachine --> SheetsAdapter
    StateMachine --> MetaAdapter
    QueueWorker --> EloquentRepo
    
    SchedulerService -->|Dispara Recordatorios| QueueWorker

    %% Conexiones de Salida a Infraestructura
    CalendarAdapter -->|HTTP OAuth / FreeBusy| GCalAPI
    MetaAdapter -->|HTTP Outbound Message| MetaAPI
    SheetsAdapter -->|HTTP Append Row| GSheetAPI
    EloquentRepo -->|Queries SQL| MySQL