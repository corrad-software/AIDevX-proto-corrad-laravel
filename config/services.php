<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'assistant_id' => env('OPENAI_ASSISTANT_ID'),
        'user_chat_assistant_id' => env('OPENAI_USER_CHAT_ASSISTANT_ID', ''),
        'vector_store_id' => env('OPENAI_VECTOR_STORE_ID'),
    ],

    'kerisi' => [
        'system_url' => env('KERISI_SYSTEM_URL', 'http://myfisv2-tourism.datasc.dev'),
    ],

    'desk365' => [
        'base_url' => rtrim(env('DESK365_BASE_URL', 'https://datasc.desk365.io/apis'), '/'),
        'api_key' => env('DESK365_API_KEY'),
    ],

];
