<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

final class GenieACS_DashboardLean
{
    private string $baseUrl;
    private string $username;
    private string $password;

    public function __construct(
        string $host,
        int $port = 7557,
        string $username = '',
        string $password = ''
    ) {
        $this->baseUrl = 'http://' . trim($host) . ':' . $port;
        $this->username = $username;
        $this->password = $password;
    }

    private function applyAuth($ch): void
    {
        if ($this->username !== '' || $this->password !== '') {
            curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        }
    }

    private function buildUrl(array $params = []): string
    {
        $url = $this->baseUrl . '/devices/';
        if ($params) {
            $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }
        return $url;
    }

    public function count(array $query = []): int
    {
        $params = [];
        if ($query) {
            $params['query'] = json_encode(
                $query,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        }

        $headers = [];
        $ch = curl_init($this->buildUrl($params));
        if ($ch === false) {
            throw new RuntimeException('Falha ao iniciar contagem no GenieACS.');
        }

        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $len = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
        ]);
        $this->applyAuth($ch);

        curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($http < 200 || $http >= 300) {
            throw new RuntimeException(
                'Contagem GenieACS falhou HTTP ' . $http . ($error ? ': ' . $error : '')
            );
        }

        foreach (['total', 'x-total-count'] as $header) {
            if (isset($headers[$header]) && ctype_digit((string)$headers[$header])) {
                return (int)$headers[$header];
            }
        }

        throw new RuntimeException('GenieACS não retornou o total da coleção.');
    }

    public function devices(
        int $limit = 0,
        int $skip = 0,
        array $query = [],
        array $sort = [],
        array $projection = []
    ): array {
        $params = [];

        if ($query) {
            $params['query'] = json_encode(
                $query,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        }
        if ($limit > 0) $params['limit'] = $limit;
        if ($skip > 0) $params['skip'] = $skip;
        if ($sort) {
            $params['sort'] = json_encode(
                $sort,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        }
        if ($projection) {
            $params['projection'] = implode(',', array_values(array_unique($projection)));
        }

        $ch = curl_init($this->buildUrl($params));
        if ($ch === false) {
            throw new RuntimeException('Falha ao iniciar consulta no GenieACS.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $this->applyAuth($ch);

        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $http < 200 || $http >= 300) {
            throw new RuntimeException(
                'Consulta GenieACS falhou HTTP ' . $http . ($error ? ': ' . $error : '')
            );
        }

        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Resposta inválida do GenieACS.');
        }

        return array_values(array_filter($data, 'is_array'));
    }
}
