<?php
class Request
{
    private $query;
    private $post;
    private $server;

    public function __construct(?array $query = null, ?array $post = null, ?array $server = null)
    {
        $this->query = $query ?? $_GET;
        $this->post = $post ?? $_POST;
        $this->server = $server ?? $_SERVER;
    }

    public function query(string $key, $default = null)
    {
        return array_key_exists($key, $this->query) ? $this->query[$key] : $default;
    }

    public function input(string $key, $default = null)
    {
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }
        return $this->query($key, $default);
    }

    public function method(): string
    {
        return strtoupper((string)($this->server['REQUEST_METHOD'] ?? 'GET'));
    }
    public function path(): string
    {
        $uri = (string)($this->server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '/';
        }

        $normalized = rtrim($path, '/');
        return $normalized === '' ? '/' : $normalized;
    }
}
