<?php

class Router
{
    private const FIELD_KEY_VIEW = 'view';

    private const FIELD_KEY_CONTROLLER = 'controller';

    /**
     * Конфигурация маршрутов:
     * 'путь' => [
     *     'title' => '...',
     *     'view' => '...',
     *     'controller' => [Класс::class, 'метод']
     * ]
     */
    private static array $routes = [
        '' => [
            'title' => '__HOME__',
            self::FIELD_KEY_VIEW => '/views/home.php',
        ],
        'home' => [
            'title' => '__HOME__',
            self::FIELD_KEY_VIEW => '/views/home.php',
        ],
    ];

    /**
     * Устанавливает мета-теги (например, title) на основе маршрута.
     *
     * @param string $uri
     */
    public static function setMetaFromUri(string $uri): void
    {
        $segments = self::getUriSegments($uri);
        $key = $segments[0] ?? '';
    }

    /**
     * Подключает нужный view-файл или вызывает контроллер.
     *
     * @param string $uri
     */
    public static function renderViewFromUri(string $uri): void
    {
        $segments = self::getUriSegments($uri);
        $page = $segments[2] ?? "home";

        // Массив страниц в зависимости от роли
        $pages = HOME_PAGES;
        $baseDir = $_SERVER["DOCUMENT_ROOT"] . "/app";

        // Проверяем, есть ли такая страница
        $found = false;
        foreach ($pages as $item) {
            // сравниваем без ведущего слеша
            if (ltrim($item['link'], '/') === $page) {
                $found = true;
                $file = $baseDir . "/pages/{$page}.php";
                if ($page == "settings") {
                    $file = $baseDir . "/pages/settings.php";
                }
                if (file_exists($file)) {
                    require_once $baseDir . '/views/home.php';
                } else {
                    require_once $baseDir . '/views/404.php';
                }
                break;
            }
        }

        if ($found)
            return;

        // Если стандартные маршруты определены
        if (isset(self::$routes[$page])) {
            $route = self::$routes[$page];
            if (isset($route[self::FIELD_KEY_CONTROLLER])) {
                [$class, $method] = $route[self::FIELD_KEY_CONTROLLER];
                call_user_func([$class, $method]);
            } elseif (isset($route['view'])) {
                require_once $baseDir . $route['view'];
            }
            return;
        }

        // Фолбэк на 404
        require_once $baseDir . '/views/404.php';
    }

    /**
     * Основной метод: сначала мета, потом отображение.
     */
    public static function route(string $uri): void
    {
        self::setMetaFromUri($uri);
        self::renderViewFromUri($uri);
    }

    /**
     * Возвращает URI-сегменты.
     */
    private static function getUriSegments(string $uri): array
    {
        $path = trim(parse_url($uri, PHP_URL_PATH), '/');
        return ($path === '') ? [] : explode('/', $path);
    }

    /**
     * То же, но из текущего URL (можно использовать в контроллерах).
     */
    public static function getParams(): array
    {
        return self::getUriSegments($_SERVER['REQUEST_URI']);
    }
}
