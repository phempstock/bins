<?php
declare(strict_types=1);

namespace Bins\Web;

use Bins\Mail\MailerFactory;
use Bins\Park\Streets;
use Bins\Storage\Database;
use Bins\Storage\Settings;
use Bins\Subscribers\SignupService;
use Bins\Subscribers\SubscriberStore;
use PDO;

/**
 * Shared set-up for the PHP pages in public/: errors never shown to visitors,
 * services built from config, and simple HTML pages in the site's style.
 */
final class Web
{
    private static ?array $config = null;
    private static ?PDO $pdo = null;

    public static function start(): void
    {
        ini_set('display_errors', '0');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: frame-ancestors 'none'");
        header('Cache-Control: no-store');
        set_exception_handler(static function (\Throwable $e): void {
            error_log('BINS web error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            if (self::wantsJson()) {
                self::json(['ok' => false, 'code' => 'error', 'message' => 'Something went wrong on our side. Please try again later.'], 500);
            }
            self::page('Something went wrong', '<p>Something went wrong on our side. Please try again later.</p>', 500);
        });
    }

    public static function config(): array
    {
        return self::$config ??= bins_config();
    }

    public static function pdo(): PDO
    {
        return self::$pdo ??= Database::connect(self::config()['db']);
    }

    public static function settings(): Settings
    {
        return new Settings(self::pdo());
    }

    public static function signup(): SignupService
    {
        $config = self::config();
        return new SignupService(new SubscriberStore(self::pdo()), self::settings(),
            MailerFactory::fromConfig($config), $config['site']['url']);
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function e(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** <option>s for the street list, with one selected. */
    public static function streetOptions(?string $selected = null): string
    {
        $html = '<option value="">Choose your street…</option>';
        foreach (Streets::names() as $slug => $name) {
            $html .= '<option value="' . self::e($slug) . '"' . ($slug === $selected ? ' selected' : '') . '>' . self::e($name) . '</option>';
        }
        return $html;
    }

    /** A complete page: masthead, a short body, a link back to the home page. */
    public static function page(string $title, string $bodyHtml, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        $t = self::e($title);
        echo <<<HTML
<!doctype html>
<html lang="en-GB">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>{$t} – BINS</title>
  <meta name="theme-color" content="#1f3d34">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
  <header class="masthead">
    <a class="wordmark-link" href="./"><span class="wordmark">BINS</span></a>
    <p class="strap">Bin day reminders for The Park</p>
  </header>
  <main class="page">
    <h1 class="page-title">{$t}</h1>
    {$bodyHtml}
    <p class="page-back"><a href="./">Back to BINS</a></p>
  </main>
</body>
</html>
HTML;
        exit;
    }
}
