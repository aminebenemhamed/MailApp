<?php
// Copy this file to config.php and fill in your real credentials.
// Never share config.php: it contains your passwords.
//
// Gmail, iCloud and Yahoo require an "app password" (not your normal password).
// See README.md for the links.

return [
    // Optional: the name recipients see when you send mail.
    // 'from_name' => 'Your Name',

    'accounts' => [
        [
            'id' => 'gmail', 'name' => 'Gmail', 'color' => '#ea4335',
            'host' => 'imap.gmail.com', 'port' => 993,
            'user' => 'you@gmail.com', 'pass' => 'app-password',
        ],
        [
            'id' => 'icloud', 'name' => 'iCloud', 'color' => '#3693f3',
            'host' => 'imap.mail.me.com', 'port' => 993,
            'user' => 'you@icloud.com', 'pass' => 'app-password',
        ],
        [
            'id' => 'yahoo', 'name' => 'Yahoo', 'color' => '#6001d2',
            'host' => 'imap.mail.yahoo.com', 'port' => 993,
            'user' => 'you@yahoo.com', 'pass' => 'app-password',
        ],
        [
            // Any IMAP mailbox on your own domain (example: DreamHost).
            'id' => 'mydomain', 'name' => 'example.com', 'color' => '#1f9d55',
            'host' => 'imap.dreamhost.com', 'port' => 993,
            'user' => 'you@example.com', 'pass' => 'mailbox-password',
        ],
        [
            // Hotmail/Outlook: Microsoft sign-in (OAuth), no password here.
            // client_id = the "Application (client) ID" of your Azure app (see README).
            'id' => 'hotmail', 'name' => 'Hotmail', 'color' => '#0078d4',
            'host' => 'outlook.office365.com', 'port' => 993, 'auth' => 'oauth',
            'user' => 'you@hotmail.com', 'client_id' => '00000000-0000-0000-0000-000000000000',
        ],
    ],
];
