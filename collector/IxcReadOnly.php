<?php
declare(strict_types=1);

namespace JRC\Collector;

/** IXC /webservice/v1 "ixcsoft: listar" only. No create/update/delete routes. */
final class IxcReadOnly
{
    public function __construct(
        private string $baseUrl,
        private string $token,
        private bool $loadUsers = false
    ) {
        $this->baseUrl = Config::requireSafeUrl($baseUrl);
        if ($token === '') throw new \InvalidArgumentException('IXC token vazio');
    }

    private function records(string $table): array
    {
        if (!in_array($table, ['radpop_radio_cliente_fibra', 'radusuarios'], true)) {
            throw new \InvalidArgumentException('Tabela IXC nao permitida');
        }
        $items = [];
        $seen = [];
        $perPage = 100;
        for ($page = 1; $page <= 200; $page++) {
            $payload = json_encode([
                'qtype' => $table . '.id',
                'query' => '0', 'oper' => '>', 'page' => (string)$page,
                'rp' => (string)$perPage,
                'sortname' => $table . '.id', 'sortorder' => 'asc'
            ], JSON_THROW_ON_ERROR);
            $curl = curl_init($this->baseUrl . '/webservice/v1/' . $table);
            if ($curl === false) throw new \RuntimeException('Falha inicializando IXC');
            curl_setopt_array($curl, [
                CURLOPT_POST => true, // Read-only IXC API listing requires POST
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json', 'Accept: application/json',
                    'ixcsoft: listar',
                    'Authorization: Basic ' . base64_encode($this->token)
                ],
                CURLOPT_POSTFIELDS => $payload
            ]);
            $response = curl_exec($curl);
            $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            if (!is_string($response) || $http !== 200) {
                throw new \RuntimeException('IXC listar falhou (HTTP ' . $http . ')');
            }
            $decoded = json_decode($response, true);
            if (!is_array($decoded) || ($decoded['type'] ?? '') === 'error') {
                throw new \RuntimeException('IXC retornou JSON invalido');
            }
            $batch = $decoded['registros'] ?? $decoded['records'] ??
                (array_is_list($decoded) ? $decoded : null);
            if (!is_array($batch)) throw new \RuntimeException('IXC sem registros');
            if (count($batch) === 0) return $items;
            foreach ($batch as $entry) {
                if (!is_array($entry)) throw new \RuntimeException('Registro IXC malformado');
                $id = (string)($entry['id'] ?? '');
                if ($id === '' || isset($seen[$id])) {
                    throw new \RuntimeException('Pagina IXC duplicada/incompleta');
                }
                $seen[$id] = true;
                $items[] = $entry;
            }
            if (count($batch) < $perPage) {
                $total = $decoded['total'] ?? null;
                if ($total !== null && is_numeric($total) && count($items) < (int)$total) {
                    throw new \RuntimeException('IXC pagina final incompleta');
                }
                return $items;
            }
        }
        throw new \RuntimeException('Limite de paginas IXC atingido');
    }

    public static function serial(string $raw): string
    {
        return strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', $raw));
    }

    /** Returns a privacy-restricted map keyed by exact normalized serial/MAC. */
    public function fleet(): array
    {
        $logins = [];
        if ($this->loadUsers) {
            foreach ($this->records('radusuarios') as $login) {
                $id = (string)($login['id'] ?? '');
                if ($id !== '') $logins[$id] = (string)($login['login'] ?? '');
            }
        }
        $bySerial = [];
        foreach ($this->records('radpop_radio_cliente_fibra') as $fiber) {
            $serial = self::serial((string)($fiber['mac'] ?? ''));
            if ($serial === '') continue;
            $date = (string)($fiber['data_sinal'] ?? '');
            $when = $date !== '' ? strtotime($date) : false;
            $row = [
                'customer_name' => trim((string)($fiber['nome'] ?? '')),
                'id_login' => (string)($fiber['id_login'] ?? ''),
                'pppoe_username' => $logins[(string)($fiber['id_login'] ?? '')] ?? '',
                'rx' => Diagnostics::number($fiber['sinal_rx'] ?? null, -80, -0.00001),
                'tx' => Diagnostics::number($fiber['sinal_tx'] ?? null, -80, 20),
                'temperature' => Diagnostics::number($fiber['temperatura'] ?? null, -30, 130),
                'voltage' => Diagnostics::number($fiber['voltagem'] ?? null, 0.01, 100),
                'measured_at_epoch' => $when === false ? null : $when,
            ];
            // Same identifier should not be silently reassigned to a different customer.
            if (isset($bySerial[$serial]) &&
                $bySerial[$serial]['id_login'] !== $row['id_login']) {
                throw new \RuntimeException('Serial IXC associado a multiplos clientes');
            }
            if (!isset($bySerial[$serial]) ||
                ($bySerial[$serial]['rx'] === null && $row['rx'] !== null)) {
                $bySerial[$serial] = $row;
            }
        }
        return $bySerial;
    }
}
