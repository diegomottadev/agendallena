<?php

return [
    'meta' => [
        'app_id' => env('META_APP_ID'),
        'waba_id' => env('META_WABA_ID'),
        'phone_number_id' => env('META_PHONE_NUMBER_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'access_token' => env('META_ACCESS_TOKEN'),
        'verify_token' => env('META_VERIFY_TOKEN'),
    ],

    /*
     * T-003 / T-012 · Google Calendar y Sheets.
     *
     * Los tres scopes van en el MISMO consentimiento: pedirlos por separado
     * obliga al cliente a pasar dos veces por la pantalla de Google, y el
     * segundo consentimiento suele quedar sin dar.
     *
     * ⚠️ El redirect apunta a `localhost` a proposito y no al tunel de
     * cloudflared: Google exige coincidencia exacta y la URL del tunel cambia en
     * cada reinicio, asi que registrarla romperia el OAuth con
     * `redirect_uri_mismatch` al dia siguiente. Para produccion va el dominio fijo.
     */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI', 'http://localhost:8000/api/v1/oauth/google/callback'),
        'scopes' => [
            'https://www.googleapis.com/auth/calendar.events',
            'https://www.googleapis.com/auth/calendar.readonly',
            'https://www.googleapis.com/auth/spreadsheets',
        ],
    ],


    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
