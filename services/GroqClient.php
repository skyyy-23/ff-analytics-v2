<?php

class GroqClient
{
    private $apiKey;
    private $model;
    private $fallbackModel;

    public function __construct()
    {
        Env::load(__DIR__ . '/..');
        $this->apiKey = $this->getEnvValue([
            'GROQ_API_KEY',
            'GLOBAL_GROQ_API_KEY',
            'GROQ_API_KEY_GLOBAL',
        ]);
        $this->model = $this->getEnvValue([
            'GROQ_MODEL',
        ], 'meta-llama/llama-4-scout-17b-16e-instruct');
        $this->fallbackModel = $this->getEnvValue([
            'GROQ_FALLBACK_MODEL',
        ], 'llama-3.1-8b-instant');
        if (trim((string)$this->fallbackModel) === trim((string)$this->model)) {
            $this->fallbackModel = '';
        }

        if ($this->apiKey === '') {
            throw new Exception(
                'Groq API key not set. Add GROQ_API_KEY to .env or environment ' .
                '(fallbacks: GLOBAL_GROQ_API_KEY or GROQ_API_KEY_GLOBAL).'
            );
        }
    }

    private function getEnvValue(array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            $value = getenv($key);
            if ($value !== false && $value !== '') {
                return (string)$value;
            }
            if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
                return (string)$_ENV[$key];
            }
        }
        return $default;
    }

    /**
     * Call Groq Chat Completions.
     *
     * @param array $messages Chat messages array
     * @param float $temperature Model temperature
     * @param bool $jsonMode Request JSON object output mode when supported.
     * @return array ['content' => string, 'error' => string|null]
     */
    public function chat(array $messages, float $temperature = 0.3, bool $jsonMode = false): array
    {
        $primary = $this->requestChat($this->model, $messages, $temperature, $jsonMode);
        if (empty($primary['error'])) {
            return $primary;
        }

        // Auto-fallback when primary model is rate limited or temporarily unavailable.
        $errorText = strtolower((string)$primary['error']);
        $shouldFallback = (
            $this->fallbackModel !== '' &&
            (
                strpos($errorText, '429') !== false ||
                strpos($errorText, 'rate limit') !== false ||
                strpos($errorText, 'too many requests') !== false
            )
        );

        if (!$shouldFallback) {
            return $primary;
        }

        $fallback = $this->requestChat($this->fallbackModel, $messages, $temperature, $jsonMode);
        if (empty($fallback['error'])) {
            return $fallback;
        }

        // Preserve primary model error context when fallback also fails.
        return $primary;
    }

    private function requestChat(string $model, array $messages, float $temperature, bool $jsonMode): array
    {
        $url = 'https://api.groq.com/openai/v1/chat/completions';
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
        ];
        if ($jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['content' => '', 'error' => 'cURL error: ' . $err];
        }

        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        if (!is_array($data)) {
            $snippet = trim(substr((string)$response, 0, 240));
            return ['content' => '', 'error' => 'Invalid JSON response from Groq. HTTP ' . $httpCode . '. ' . $snippet];
        }

        if (isset($data['error'])) {
            $message = $data['error']['message'] ?? 'Unknown Groq API error.';
            return ['content' => '', 'error' => 'API error (HTTP ' . $httpCode . '): ' . $message];
        }

        if ($httpCode >= 400) {
            return ['content' => '', 'error' => 'HTTP error from Groq: ' . $httpCode];
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        return ['content' => (string)$content, 'error' => null];
    }
}
