<?php
define('APP_BASE_PATH', dirname(__DIR__));

spl_autoload_register(function ($className) {
    $class = ltrim((string)$className, '\\');
    if ($class === '') {
        return;
    }

    $classFile = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $class) . '.php';
    $paths = [
        APP_BASE_PATH . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . $classFile,
        APP_BASE_PATH . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . $classFile,
        APP_BASE_PATH . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . $classFile,
        APP_BASE_PATH . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . $classFile,
    ];

    foreach ($paths as $path) {
        if (is_file($path)) {
            require_once $path;
            return;
        }
    }
});
