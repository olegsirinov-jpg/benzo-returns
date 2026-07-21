<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

class View
{
    /** @param array<string,mixed> $data */
    public static function render(string $template, array $data = [], string $layout = 'layout'): void
    {
        echo self::capture($template, $data, $layout);
    }

    /** @param array<string,mixed> $data */
    public static function capture(string $template, array $data = [], string $layout = 'layout'): string
    {
        $content = self::partial($template, $data);

        if ($layout === '') {
            return $content;
        }

        $layoutFile = BASE_PATH . '/views/' . $layout . '.php';
        if (!is_file($layoutFile)) {
            return $content;
        }

        $data['content'] = $content;
        extract($data, EXTR_SKIP);
        ob_start();
        require $layoutFile;
        return (string)ob_get_clean();
    }

    /** @param array<string,mixed> $data */
    public static function partial(string $template, array $data = []): string
    {
        $file = BASE_PATH . '/views/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Шаблон не знайдено: ' . $template);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string)ob_get_clean();
    }
}
