<?php

return [
    'issuer'   => env('JWT_ISSUER', 'user-service'),
    'audience' => env('JWT_AUDIENCE', 'notification-platform'),

    'keys' => [
        // Either a file path to the public key, or the PEM content via env.
        'public'         => env('JWT_PUBLIC_KEY', storage_path('app/keys/jwt-public.pem')),
        'public_content' => env('JWT_PUBLIC_KEY_CONTENT'),
    ],
];
