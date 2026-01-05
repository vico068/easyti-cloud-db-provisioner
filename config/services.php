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

    /*
    |--------------------------------------------------------------------------
    | API Key para autenticação entre serviços
    |--------------------------------------------------------------------------
    */
    'api_key' => env('PROVISIONER_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | AWS S3 para Backups
    |--------------------------------------------------------------------------
    */
    'backup' => [
        'enabled' => env('BACKUP_ENABLED', true),
        's3' => [
            'key' => env('BACKUP_AWS_ACCESS_KEY_ID'),
            'secret' => env('BACKUP_AWS_SECRET_ACCESS_KEY'),
            'region' => env('BACKUP_AWS_REGION', 'us-east-1'),
            'bucket' => env('BACKUP_S3_BUCKET', 'easyticloud-db-backups'),
        ],
        'retention_days' => env('BACKUP_RETENTION_DAYS', 30),
        'max_concurrent' => env('BACKUP_MAX_CONCURRENT', 6),
    ],

];
