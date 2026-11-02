%% 2026-08-25 · El Scheduler se separo del Worker, y no es cosmetico.
%% Hasta hoy los dos vivian en una sola caja rotulada "Async Workers / Redis
%% (State Machine, Schedulers)". El docker-compose.yml levantaba solo
%% `queue:work`: el planificador que esta caja prometia NO EXISTIA en ningun
%% despliegue, y las cinco tareas de routes/console.php nunca se ejecutaron
%% fuera de los tests. Como el worker si estaba, faltaba la mitad de una caja
%% que se veia presente. Son dos procesos distintos, se caen por separado y
%% ahora se dibujan por separado. Detalle en CLAUDE.md, seccion 2026-08-25.
flowchart TB
    subgraph ExternalSystems["Sistemas Externos"]
        WA["Meta WhatsApp API"]
        GC["Google Calendar API"]
        GS["Google Sheets API"]
    end

    subgraph ClientLayer["Capa de Cliente"]
        VueApp["Vue 3 SPA Admin<br>(Dashboard Tenant)"]
    end

    subgraph LaravelBackend["Laravel Backend (Multi-tenant)"]
        API["REST API Gateway<br>(Auth, CRUD Config)"]
        Webhook["Webhook Receiver<br>(Fast Ingestion)"]
        Workers["Async Workers<br>(State Machine, Jobs de la cola)"]
        Scheduler["Scheduler<br>(5 tareas periodicas)"]
    end

    subgraph Storage["Almacenamiento"]
        DB[("MySQL 8<br>(Multi-tenant with tenant_id)")]
        Redis[("Redis<br>(Queues, State, Cache)")]
    end

    VueApp -->|HTTPS / JSON| API
    WA -->|HTTP POST Webhook| Webhook
    GC -->|HTTP POST Watch Webhook| Webhook
    Webhook -->|Push Job| Redis
    Redis -->|Pop Job| Workers
    Workers -->|Read/Write| DB
    API -->|Read/Write| DB
    Workers -->|OAuth / FreeBusy / Sync| GC
    Workers -->|Send Message| WA
    Workers -->|Append Lead| GS
    Scheduler -->|Read/Write| DB
    Scheduler -->|FreeBusy / Sync| GC
    Scheduler -->|Send Template| WA