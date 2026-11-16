<?php
declare(strict_types=1);

namespace JRC\Collector;

final class Config
{
    public static function env(string $name, string $default = ''): string
    {
        $value = getenv($name);
        return $value === false ? $default : trim((string)$value);
    }

    public static function secret(string $name): string
    {
        $path = self::env($name . '_FILE');
        if ($path === '') return '';
        $real = realpath($path);
        if ($real === false || !is_file($real) ||
            !str_starts_with($real, '/run/jrc-collector-secrets/')) {
            throw new \RuntimeException('Caminho de segredo nao permitido: ' . $name);
        }
        $value = file_get_contents($real);
        if ($value === false || trim($value) === '') {
            throw new \RuntimeException('Segredo ausente/vazio: ' . $name);
        }
        return trim($value);
    }

    public static function db(): \mysqli
    {
        $host = self::env('JRC_DB_HOST', '127.0.0.1');
        $port = filter_var(self::env('JRC_DB_PORT', '3306'), FILTER_VALIDATE_INT);
        $user = self::env('JRC_DB_USER');
        $name = self::env('JRC_DB_NAME');
        if ($port === false || $port < 1 || $port > 65535 || $user === '' || $name === '') {
            throw new \RuntimeException('Configuracao do banco incompleta');
        }
        $password = self::secret('JRC_DB_PASSWORD');
        $db = new \mysqli($host, $user, $password, $name, (int)$port);
        $db->set_charset('utf8mb4');
        return $db;
    }

    public static function requireSafeUrl(string $url, bool $allowLocalHttp = false): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) ||
            isset($parts['fragment'])) {
            throw new \RuntimeException('URL de origem invalida');
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if ($scheme !== 'https' &&
            !($allowLocalHttp && $scheme === 'http' &&
                in_array($host, ['127.0.0.1', 'localhost', '::1'], true))) {
            throw new \RuntimeException('URL deve usar HTTPS ou localhost em HTTP');
        }
        if (isset($parts['path']) && !in_array($parts['path'], ['', '/'], true)) {
            throw new \RuntimeException('URL base nao deve conter caminho');
        }
        return rtrim($url, '/');
    }
}
