<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

class Upload
{
    const MAX_SIZE     = 10485760; // 10 MB
    const MAX_DIMENSION = 1800;    // px по довшій стороні після стиснення

    /** @return array<int,string> */
    public static function allowedExtensions(): array
    {
        return ['jpg', 'jpeg', 'png', 'webp'];
    }

    public static function dir(): string
    {
        $dir = BASE_PATH . '/uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * Перетворює $_FILES['photos'] (multiple) у плоский список файлів.
     *
     * @param array<string,mixed> $field
     * @return array<int,array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    public static function normalize(array $field): array
    {
        $out = [];
        if (!isset($field['name'])) {
            return $out;
        }
        if (!is_array($field['name'])) {
            return [[
                'name'     => (string)$field['name'],
                'type'     => (string)$field['type'],
                'tmp_name' => (string)$field['tmp_name'],
                'error'    => (int)$field['error'],
                'size'     => (int)$field['size'],
            ]];
        }
        $count = count($field['name']);
        for ($i = 0; $i < $count; $i++) {
            if ((int)$field['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = [
                'name'     => (string)$field['name'][$i],
                'type'     => (string)$field['type'][$i],
                'tmp_name' => (string)$field['tmp_name'][$i],
                'error'    => (int)$field['error'][$i],
                'size'     => (int)$field['size'][$i],
            ];
        }
        return $out;
    }

    /**
     * Зберігає одне зображення. Повертає ім'я файлу.
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @throws RuntimeException
     */
    public static function saveImage(array $file): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::errorMessage($file['error']));
        }
        if ($file['size'] > self::MAX_SIZE) {
            throw new RuntimeException('Файл «' . $file['name'] . '» більший за 10 MB.');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Некоректне завантаження файлу.');
        }

        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            throw new RuntimeException('Файл «' . $file['name'] . '» не є зображенням.');
        }

        $extByType = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_WEBP => 'webp',
        ];
        $type = (int)$info[2];
        if (!isset($extByType[$type])) {
            throw new RuntimeException('Дозволені формати: jpg, png, webp.');
        }
        $ext = $extByType[$type];

        $name = date('Ymd') . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = self::dir() . '/' . $name;

        if (!self::compress($file['tmp_name'], $dest, $type)) {
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                throw new RuntimeException('Не вдалося зберегти файл.');
            }
        }
        @chmod($dest, 0644);

        return $name;
    }

    /**
     * Стиснення зображення через GD. Повертає false, якщо GD недоступний.
     */
    private static function compress(string $src, string $dest, int $type): bool
    {
        if (!function_exists('imagecreatetruecolor')) {
            return false;
        }

        switch ($type) {
            case IMAGETYPE_JPEG:
                $img = @imagecreatefromjpeg($src);
                break;
            case IMAGETYPE_PNG:
                $img = @imagecreatefrompng($src);
                break;
            case IMAGETYPE_WEBP:
                $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false;
                break;
            default:
                return false;
        }
        if ($img === false) {
            return false;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $max = max($w, $h);

        if ($max > self::MAX_DIMENSION) {
            $ratio = self::MAX_DIMENSION / $max;
            $nw    = (int)round($w * $ratio);
            $nh    = (int)round($h * $ratio);
            $new   = imagecreatetruecolor($nw, $nh);
            if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
                imagealphablending($new, false);
                imagesavealpha($new, true);
            }
            imagecopyresampled($new, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $img = $new;
        }

        $ok = false;
        switch ($type) {
            case IMAGETYPE_JPEG:
                $ok = imagejpeg($img, $dest, 82);
                break;
            case IMAGETYPE_PNG:
                $ok = imagepng($img, $dest, 6);
                break;
            case IMAGETYPE_WEBP:
                $ok = function_exists('imagewebp') ? imagewebp($img, $dest, 82) : false;
                break;
        }
        imagedestroy($img);

        return $ok;
    }

    public static function delete(string $file): void
    {
        $path = self::dir() . '/' . basename($file);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function errorMessage(int $code): string
    {
        $map = [
            UPLOAD_ERR_INI_SIZE   => 'Файл перевищує допустимий розмір сервера.',
            UPLOAD_ERR_FORM_SIZE  => 'Файл перевищує допустимий розмір форми.',
            UPLOAD_ERR_PARTIAL    => 'Файл завантажено частково.',
            UPLOAD_ERR_NO_FILE    => 'Файл не завантажено.',
            UPLOAD_ERR_NO_TMP_DIR => 'Немає тимчасової папки на сервері.',
            UPLOAD_ERR_CANT_WRITE => 'Не вдалося записати файл на диск.',
            UPLOAD_ERR_EXTENSION  => 'Завантаження зупинено розширенням PHP.',
        ];
        return $map[$code] ?? 'Помилка завантаження файлу.';
    }
}
