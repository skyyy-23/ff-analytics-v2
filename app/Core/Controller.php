<?php
class Controller
{
    protected function view(string $viewName, array $data = []): void
    {
        $relativePath = str_replace('.', DIRECTORY_SEPARATOR, $viewName) . '.php';
        $viewPath = APP_BASE_PATH . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . $relativePath;

        if (!is_file($viewPath)) {
            throw new RuntimeException('View not found: ' . $viewName);
        }

        extract($data, EXTR_SKIP);
        require $viewPath;
    }

    protected function json(array $payload, int $statusCode = 200, array $headers = []): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }

        $jsonOptions = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $jsonOptions |= JSON_INVALID_UTF8_SUBSTITUTE;
        }

        $json = json_encode($payload, $jsonOptions);
        if ($json === false) {
            http_response_code(500);
            echo '{"error":"Failed to encode JSON response."}';
            return;
        }

        echo $json;
    }
}
