<?php
declare(strict_types=1);

/**
 * Shared start-up for command-line scripts and web pages:
 * autoloads classes from src/ and loads config.php.
 */

date_default_timezone_set('Europe/London');

// PHPMailer, vendored in lib/phpmailer/ (see lib/phpmailer/VERSION.txt).
spl_autoload_register(static function (string $class): void {
    $prefix = 'PHPMailer\\PHPMailer\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = __DIR__ . '/lib/phpmailer/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

spl_autoload_register(static function (string $class): void {
    $prefix = 'Bins\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/**
 * Load the configuration array. Throws if config.php is missing so a
 * misconfigured server fails loudly rather than silently doing nothing.
 * config.mail.php, next to it, may add or override the mail settings.
 */
function bins_config(?string $path = null): array
{
    $path ??= __DIR__ . '/config.php';
    if (!is_file($path)) {
        throw new RuntimeException("Configuration file not found: {$path}. Copy config.example.php to config.php.");
    }
    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException("Configuration file must return an array: {$path}");
    }
    $mailFile = dirname($path) . '/config.mail.php';
    if (is_file($mailFile)) {
        $mail = require $mailFile;
        if (is_array($mail)) {
            $config = array_replace_recursive($config, $mail);
        }
    }
    $config['site'] = ($config['site'] ?? []) + [
        'url'           => 'https://bins.parknews.co.uk',
        'organisation'  => 'the Nottingham Park Residents\' Association (NPRA)',
        'contact_email' => 'bins@parknews.co.uk',
    ];
    $config['paths'] = ($config['paths'] ?? []) + [
        'public_data' => __DIR__ . '/public/data',
        'cache'       => __DIR__ . '/data/cache',
    ];
    $config['recollect'] = ($config['recollect'] ?? []) + [
        'base_url'   => 'https://api.eu.recollect.net',
        'service_id' => 50003,
        'pause_ms'   => 500,
    ];
    return $config;
}
