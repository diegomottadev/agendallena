erDiagram
    TENANTS ||--o{ USERS : "posee"
    TENANTS ||--o{ INTEGRATIONS : "configura"
    TENANTS ||--o{ QUOTE_RULES : "define"
    TENANTS ||--o{ CONVERSATIONS : "registra"
    TENANTS ||--o{ BOOKINGS : "administra"
    TENANTS ||--o{ NOTIFICATION_LOGS : "aisla"
    CONVERSATIONS ||--o{ BOOKINGS : "genera"
    INTEGRATIONS ||--o{ BOOKINGS : "sincroniza"
    BOOKINGS ||--o{ NOTIFICATION_LOGS : "audita"

    TENANTS {
        char36 id PK
        string name
        string slug UK
        enum status "'active' | 'suspended' | 'trial'"
        string timezone
        timestamp created_at
        timestamp updated_at
    }

    USERS {
        char36 id PK
        char36 tenant_id FK
        string name
        string email
        string password
        enum role "'owner' | 'admin' | 'staff'"
        timestamp created_at
        timestamp updated_at
    }

    INTEGRATIONS {
        bigint_unsigned id PK
        char36 tenant_id FK
        enum provider "'meta_whatsapp' | 'google_calendar' | 'outlook_calendar' | 'google_sheets'"
        string account_identifier
        text access_token
        text refresh_token
        timestamp expires_at
        json settings
        enum status "'connected' | 'disconnected' | 'expired'"
    }

    QUOTE_RULES {
        bigint_unsigned id PK
        char36 tenant_id FK
        string name
        string formula_expression
        json parameters_schema
        boolean is_active
    }

    CONVERSATIONS {
        bigint_unsigned id PK
        char36 tenant_id FK
        string user_phone
        string user_name
        string current_state
        json context_data
        timestamp last_interaction_at
    }

    BOOKINGS {
        bigint_unsigned id PK
        char36 tenant_id FK
        bigint_unsigned conversation_id FK
        bigint_unsigned integration_id FK
        string external_event_id
        string client_name
        string client_phone
        string service_name
        decimal quote_amount
        datetime start_time
        datetime end_time
        enum status "'scheduled' | 'confirmed' | 'rescheduled' | 'cancelled'"
    }

    NOTIFICATION_LOGS {
        bigint_unsigned id PK
        char36 tenant_id FK
        bigint_unsigned booking_id FK
        enum type "'reminder_24h' | 'reminder_2h' | 'confirmation'"
        string whatsapp_message_id
        enum status "'queued' | 'sent' | 'delivered' | 'read' | 'failed'"
        timestamp sent_at
    }