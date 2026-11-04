<?php
declare(strict_types=1);

namespace App;

class Csrf
{
    public static function token(): string
    {
        $t = Session::get('_csrf');
        if (!is_string($t) || $t === '') {
            $t = bin2hex(random_bytes(32));
            Session::set('_csrf', $t);
        }
        return $t;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token()) . '">';
    }

    public static function check(): bool
    {
        $sent = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $real = Session::get('_csrf');
        return is_string($real) && is_string($sent) && $sent !== '' && hash_equals($real, $sent);
    }

    /**
     * Перевірити або зупинити виконання.
     */
    public static function verify(): void
    {
        if (self::check()) {
            return;
        }

        // Запит більший за post_max_size: PHP мовчки викидає ВСІ поля (разом із токеном).
        // Це не «застаріла сесія», а завеликі фото — кажемо клієнту правду.
        $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        $max = Upload::postMaxBytes();
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $len > 0 && $max > 0 && $len > $max
            && $_POST === [] && $_FILES === []
        ) {
            $msg = sprintf(
                'Не вдалося надіслати: завеликий обсяг фото (%.1f МБ, допустимо до %.0f МБ). '
                . 'Додайте менше фото або зробіть їх меншими й надішліть ще раз.',
                $len / 1048576,
                $max / 1048576
            );
            if (strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false) {
                Response::json(['ok' => false, 'error' => $msg], 413);
            }
            Session::flash('error', $msg);
            Response::redirect(self::backPath());
        }

        Response::status(419);
        if (strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false) {
            Response::json(['ok' => false, 'error' => 'Сесія застаріла. Оновіть сторінку.'], 419);
        }
        View::render('errors/419', ['title' => 'Сесія застаріла']);
        exit;
    }

    /** Шлях сторінки, з якої прийшов запит (лише з нашого сайту), інакше — головна. */
    private static function backPath(): string
    {
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        $p   = $ref !== '' ? parse_url($ref) : false;
        if (is_array($p)
            && (($p['host'] ?? '') === ($_SERVER['HTTP_HOST'] ?? ''))
            && !empty($p['path'])
        ) {
            return $p['path'] . (isset($p['query']) ? '?' . $p['query'] : '');
        }
        return '/returns';
    }
}
