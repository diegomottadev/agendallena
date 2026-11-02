erDiagram
    TENANTS ||--o{ USERS : "posee"
    TENANTS ||--o{ INTEGRATIONS : "configura"
    TENANTS ||--o{ QUOTE_RULES : "define"
    TENANTS ||--o{ CONVERSATIONS : "procesa"
    TENANTS ||--o{ BOOKINGS : "registra"
    TENANTS ||--o{ NOTIFICATION_LOGS : "mantiene"

    CONVERSATIONS o{--o{ BOOKINGS : "genera"
    INTEGRATIONS ||--o{ BOOKINGS : "sincroniza"
    BOOKINGS ||--o{ NOTIFICATION_LOGS : "dispara"

    TENANTS {
        uuid id PK
        string name
        string slug
        enum status
        string timezone
        timestamp created_at
        timestamp updated_at
    }

    USERS {
        uuid id PK
        uuid tenant_id FK
        string name
        string email
        string password
        enum role
        timestamp created_at
        timestamp updated_at
    }

    INTEGRATIONS {
        bigint id PK
        uuid tenant_id FK
        enum provider
        string account_identifier
        text access_token
        text refresh_token
        timestamp expires_at
        json settings
        enum status
    }

    QUOTE_RULES {
        bigint id PK
        uuid tenant_id FK
        string name
        string formula_expression
        json parameters_schema
        boolean is_active
    }

    CONVERSATIONS {
        bigint id PK
        uuid tenant_id FK
        string user_phone
        string user_name
        string current_state
        json context_data
        timestamp last_interaction_at
    }

    BOOKINGS {
        bigint id PK
        uuid tenant_id FK
        bigint conversation_id FK
        bigint integration_id FK
        string external_event_id
        string client_name
        string client_phone
        string service_name
        decimal quote_amount
        datetime start_time
        datetime end_time
        enum status
    }

    NOTIFICATION_LOGS {
        bigint id PK
        uuid tenant_id FK
        bigint booking_id FK
        enum type
        string whatsapp_message_id
        enum status
        timestamp sent_at
    }