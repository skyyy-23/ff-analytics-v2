<?php
class Env
{
    /**
     * Load environment variables from a .env file in the given directory.
     * This is a lightweight loader to avoid composer dependencies.
     *
     * @param string $dir Base directory containing .env
     */
    public static function load(string $dir): void
    {

        $path = rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($path)) {
            return;
        }

     
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);
            if ($key === '') {
                continue;
            }

            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                if (substr($value, -1) === $quote) {
                    $value = substr($value, 1, -1);
                }
            }

            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}
