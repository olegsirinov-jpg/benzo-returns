<?php
declare(strict_types=1);

namespace App;

class Response
{
    public static function status(int $code): void
    {
        http_response_code($code);
    }

    public static function redirect(string $path, int $code = 302): void
    {
        $location = strpos($path, 'http') === 0 ? $path : url($path);
        header('Location: ' . $location, true, $code);
        exit;
    }

    public static function back(): void
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? '/';
        header('Location: ' . $ref, true, 302);
        exit;
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function download(string $filename, string $content, string $mime = 'text/csv'): void
    {
        header('Content-Type: ' . $mime . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: no-store');
        echo $content;
        exit;
    }
}
