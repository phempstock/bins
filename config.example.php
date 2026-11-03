<?php
/**
 * BINS configuration.
 *
 * Copy this file to config.php (in the project root, outside the web root)
 * and fill in the real values. config.php is ignored by git.
 * Mail settings go in config.mail.php next to it (see README).
 */
return [
    // Database (IONOS: Hosting > Databases). MySQL and MariaDB both use 'mysql:'. Examples:
    //   mysql:host=db5000000000.hosting-data.io;dbname=dbs0000000;charset=utf8mb4
    //   sqlite:/path/to/bins.sqlite   (local development only)
    'db' => [
        'dsn'      => 'mysql:host=localhost;dbname=bins;charset=utf8mb4',
        'user'     => 'bins',
        'password' => 'change-me',
    ],

    // Failure alerts from the scheduled jobs, and where test reminders go.
    'alerts' => [
        'admin_email' => 'bins@parknews.co.uk',
        'from_email'  => 'bins@parknews.co.uk',
    ],
];
