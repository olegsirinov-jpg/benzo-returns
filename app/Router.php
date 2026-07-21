<?php
declare(strict_types=1);

namespace App;

use Throwable;

class Router
{
    /** @var array<string,array<int,array{pattern:string,handler:mixed}>> */
    private $routes = ['GET' => [], 'POST' => []];

    /** @param string|callable $handler */
    public function get(string $path, $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    /** @param string|callable $handler */
    public function post(string $path, $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    /** @param string|callable $handler */
    private function add(string $method, string $path, $handler): void
    {
        $this->routes[$method][] = ['pattern' => $path, 'handler' => $handler];
    }

    public static function uri(): string
    {
        $uri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $uri = rawurldecode($uri);
        $uri = '/' . trim($uri, '/');
        return $uri === '//' ? '/' : $uri;
    }

    public function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        $uri = self::uri();

        foreach ($this->routes[$method] ?? [] as $route) {
            $params = $this->match($route['pattern'], $uri);
            if ($params === null) {
                continue;
            }
            try {
                $this->call($route['handler'], $params);
            } catch (Throwable $e) {
                $this->error($e);
            }
            return;
        }

        Response::status(404);
        View::render('errors/404', ['title' => 'Сторінку не знайдено']);
    }

    /** @return array<int,string>|null */
    private function match(string $pattern, string $uri): ?array
    {
        if (strpos($pattern, '{') === false) {
            return $pattern === $uri ? [] : null;
        }
        $regex = preg_replace('#\{[a-zA-Z_][a-zA-Z0-9_]*\}#', '([^/]+)', $pattern);
        if (!preg_match('#^' . $regex . '$#u', $uri, $m)) {
            return null;
        }
        array_shift($m);
        return $m;
    }

    /**
     * @param string|callable   $handler
     * @param array<int,string> $params
     */
    private function call($handler, array $params): void
    {
        if (is_callable($handler)) {
            call_user_func_array($handler, $params);
            return;
        }
        list($controller, $action) = explode('@', $handler);
        $class = 'App\\Controllers\\' . $controller;
        if (!class_exists($class)) {
            throw new \RuntimeException('Контролер не знайдено: ' . $class);
        }
        $instance = new $class();
        if (!method_exists($instance, $action)) {
            throw new \RuntimeException('Метод не знайдено: ' . $class . '::' . $action);
        }
        call_user_func_array([$instance, $action], $params);
    }

    private function error(Throwable $e): void
    {
        error_log('[' . date('Y-m-d H:i:s') . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        Response::status(500);
        if (Env::bool('APP_DEBUG', false)) {
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage() . "\n\n" . $e->getTraceAsString();
            return;
        }
        View::render('errors/500', ['title' => 'Помилка сервера']);
    }
}
