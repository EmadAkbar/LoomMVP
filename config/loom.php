<?php

$adminEmails = array_values(array_filter(array_map(
    static fn (string $email) => trim($email),
    explode(',', (string) env('LOOM_ADMIN_EMAILS', env('ADMIN_EMAIL', '')))
)));

return [
    'notifications' => [
        'admin_emails' => $adminEmails,
    ],
];
