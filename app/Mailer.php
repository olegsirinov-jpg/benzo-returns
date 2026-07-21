<?php
declare(strict_types=1);

namespace App;

/**
 * Мінімальний SMTP-клієнт без залежностей.
 * Підтримує STARTTLS (порт 587) та неявний SSL (порт 465), AUTH LOGIN,
 * UTF-8 тему (MIME encoded-word) і HTML-тіло.
 */
class Mailer
{
    public static function enabled(): bool
    {
        return Config::bool('mail_enabled', false)
            && Config::str('mail_host') !== ''
            && Config::str('mail_from') !== '';
    }

    /**
     * @return array{ok:bool,error:string}
     */
    public static function send(string $toEmail, string $toName, string $subject, string $html): array
    {
        if (!self::enabled()) {
            self::log('Пропущено (вимкнено): ' . $subject);
            return ['ok' => false, 'error' => 'mail_disabled'];
        }

        $host   = Config::str('mail_host');
        $port   = Config::int('mail_port', 587);
        $user   = Config::str('mail_user');
        $pass   = Config::str('mail_pass');
        $secure = strtolower(Config::str('mail_secure', $port === 465 ? 'ssl' : 'tls'));
        $from   = Config::str('mail_from');
        $fromNm = Config::str('mail_from_name', Env::str('APP_NAME', ''));

        $transport = $secure === 'ssl' ? 'ssl://' : 'tcp://';
        $ctx = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true],
        ]);

        $errno = 0; $errstr = '';
        $conn = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx
        );
        if ($conn === false) {
            return self::fail('Зʼєднання: ' . $errstr . ' (' . $errno . ')');
        }
        stream_set_timeout($conn, 20);

        try {
            self::expect($conn, 220);

            $ehloHost = self::ehloHost();
            self::cmd($conn, 'EHLO ' . $ehloHost, 250);

            if ($secure === 'tls') {
                self::cmd($conn, 'STARTTLS', 220);
                $ok = @stream_socket_enable_crypto(
                    $conn, true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
                );
                if ($ok !== true) {
                    return self::fail('Не вдалося встановити TLS', $conn);
                }
                self::cmd($conn, 'EHLO ' . $ehloHost, 250);
            }

            if ($user !== '') {
                self::cmd($conn, 'AUTH LOGIN', 334);
                self::cmd($conn, base64_encode($user), 334);
                self::cmd($conn, base64_encode($pass), 235);
            }

            self::cmd($conn, 'MAIL FROM:<' . $from . '>', 250);
            self::cmd($conn, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
            self::cmd($conn, 'DATA', 354);

            $message = self::buildMessage($from, $fromNm, $toEmail, $toName, $subject, $html);
            // крапка на початку рядка екранується подвоєнням
            $message = preg_replace('/^\./m', '..', $message);
            fwrite($conn, $message . "\r\n.\r\n");
            self::expect($conn, 250);

            self::cmd($conn, 'QUIT', [221], true);
            fclose($conn);

            return ['ok' => true, 'error' => ''];
        } catch (\Throwable $e) {
            if (is_resource($conn)) {
                @fclose($conn);
            }
            return self::fail($e->getMessage());
        }
    }

    private static function buildMessage(
        string $from, string $fromName, string $to, string $toName, string $subject, string $html
    ): string {
        $boundary = 'b' . bin2hex(random_bytes(12));
        $date     = date('r');
        $msgId    = '<' . bin2hex(random_bytes(12)) . '@' . self::ehloHost() . '>';

        $text = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '<br/>', '</p>', '</li>'], "\n", $html))) ?? '');

        $headers = [
            'Date: ' . $date,
            'Message-ID: ' . $msgId,
            'From: ' . self::encodeName($fromName) . ' <' . $from . '>',
            'To: ' . self::encodeName($toName) . ' <' . $to . '>',
            'Subject: ' . self::encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $body = '--' . $boundary . "\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($text)) . "\r\n"
              . '--' . $boundary . "\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($html)) . "\r\n"
              . '--' . $boundary . "--";

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private static function encodeHeader(string $s): string
    {
        return '=?UTF-8?B?' . base64_encode($s) . '?=';
    }

    private static function encodeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        return preg_match('/[^\x20-\x7e]/', $name) ? self::encodeHeader($name) : '"' . $name . '"';
    }

    private static function ehloHost(): string
    {
        $host = parse_url(Env::str('APP_URL', 'localhost'), PHP_URL_HOST);
        return is_string($host) && $host !== '' ? $host : 'localhost';
    }

    /**
     * @param int|array<int,int> $expected
     */
    private static function cmd($conn, string $command, $expected, bool $ignoreFail = false): void
    {
        fwrite($conn, $command . "\r\n");
        if ($ignoreFail) {
            @self::read($conn);
            return;
        }
        self::expect($conn, $expected);
    }

    /**
     * @param int|array<int,int> $expected
     */
    private static function expect($conn, $expected): void
    {
        $line = self::read($conn);
        $code = (int)substr($line, 0, 3);
        $ok   = is_array($expected) ? in_array($code, $expected, true) : $code === $expected;
        if (!$ok) {
            throw new \RuntimeException('SMTP: очікувано ' . json_encode($expected) . ', отримано «' . trim($line) . '»');
        }
    }

    private static function read($conn): string
    {
        $data = '';
        while (($line = fgets($conn, 515)) !== false) {
            $data .= $line;
            // багаторядкова відповідь: "250-..." триває, "250 ..." завершує
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    }

    /**
     * @return array{ok:bool,error:string}
     */
    private static function fail(string $msg, $conn = null): array
    {
        if (is_resource($conn)) {
            @fclose($conn);
        }
        self::log('Помилка: ' . $msg);
        return ['ok' => false, 'error' => $msg];
    }

    private static function log(string $message): void
    {
        @file_put_contents(
            BASE_PATH . '/storage/logs/mail.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
            FILE_APPEND
        );
    }
}
