<?php
namespace App;

/** Individual firmware operations; transport injectable for offline verification. */
final class FirmwareUpdates
{
    private $dir;
    private $transport;

    public function __construct(string $dir, ?callable $transport = null)
    {
        if (!is_dir($dir) || is_link($dir) || !is_writable($dir)) {
            throw new \RuntimeException('Repositório de firmware indisponível.');
        }
        $this->dir = $dir;
        $this->transport = $transport ?: [self::class, 'http'];
    }

    public static function authorize(array $session): void
    {
        if (empty($session['user_id']) || !in_array($session['role'] ?? '', ['admin', 'noc'], true)) {
            throw new \RuntimeException('Atualizações exigem perfil admin ou noc.');
        }
    }

    public static function value(array $source, string $path): string
    {
        $v = $source;
        foreach (explode('.', $path) as $key) $v = is_array($v) ? ($v[$key] ?? null) : null;
        if (is_array($v)) $v = $v['_value'] ?? null;
        return is_scalar($v) ? trim((string)$v) : '';
    }

    public static function identity(array $d): array
    {
        $pick = static function (array $paths) use ($d): string {
            foreach ($paths as $p) if (($v = self::value($d, $p)) !== '') return $v;
            return '';
        };
        $v = ['id'=>self::value($d, '_id'), 'lastInform'=>self::value($d, '_lastInform')];
        foreach (['manufacturer'=>'Manufacturer', 'model'=>'ModelName', 'hardware'=>'HardwareVersion', 'version'=>'SoftwareVersion', 'serial'=>'SerialNumber'] as $key=>$param) {
            $paths = ["Device.DeviceInfo.$param", "InternetGatewayDevice.DeviceInfo.$param"];
            if ($key === 'model') $paths[] = '_deviceId._ProductClass';
            if ($key === 'manufacturer') $paths[] = '_deviceId._Manufacturer';
            if ($key === 'serial') $paths[] = '_deviceId._SerialNumber';
            $v[$key] = $pick($paths);
        }
        $v['oui'] = self::value($d, '_deviceId._OUI');
        $v['productClass'] = self::value($d, '_deviceId._ProductClass');
        $versionRoot = self::value($d, 'Device.DeviceInfo.SoftwareVersion') !== '' ? 'Device' : 'InternetGatewayDevice';
        $v['versionTime'] = self::value($d, $versionRoot . '.DeviceInfo.SoftwareVersion._timestamp');
        return $v;
    }

    public function read(string $name): array
    {
        $path = $this->dir . '/' . $name . '.json';
        if (is_link($path)) throw new \RuntimeException('Arquivo de dados inválido.');
        if (!is_file($path)) return [];
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !array_is_list($data)) throw new \RuntimeException('Índice inválido.');
        return $data;
    }

    private function save(string $name, array $data): void
    {
        $tmp = $this->dir . '/.update-' . bin2hex(random_bytes(12));
        try {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (file_put_contents($tmp, $json, LOCK_EX) !== strlen($json)) throw new \RuntimeException('Falha ao gravar histórico.');
            chmod($tmp, 0640);
            if (!rename($tmp, $this->dir . '/' . $name . '.json')) throw new \RuntimeException('Falha ao publicar histórico.');
        } finally { if (is_file($tmp)) unlink($tmp); }
    }

    private function locked(string $name, callable $fn)
    {
        $path = $this->dir . '/' . $name . '.lock';
        if (is_link($path)) throw new \RuntimeException('Lock inválido.');
        $lock = fopen($path, 'c');
        if (!$lock) throw new \RuntimeException('Falha no bloqueio.');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) throw new \RuntimeException('Operação em andamento. Aguarde e consulte o histórico.');
            return $fn();
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function request(string $method, string $path, ?array $body = null, ?string $file = null, array $headers = []): array
    {
        return ($this->transport)($method, $path, $body, $file, $headers);
    }

    private function query(string $resource, array $query): array
    {
        $r = $this->request('GET', '/' . $resource . '/?' . http_build_query(['query'=>json_encode($query), 'limit'=>100]));
        if (($r['status'] ?? 0) !== 200 || !is_array($r['data'] ?? null) || !array_is_list($r['data'])) {
            throw new \RuntimeException('Consulta ao GenieACS indisponível.');
        }
        return $r['data'];
    }

    public function device(string $id): array
    {
        if ($id === '' || strlen($id) > 512) throw new \RuntimeException('ID de equipamento inválido.');
        $rows = $this->query('devices', ['_id'=>$id]);
        if (count($rows) !== 1 || ($rows[0]['_id'] ?? '') !== $id) throw new \RuntimeException('Equipamento não localizado.');
        return self::identity($rows[0]);
    }

    public function preflight(string $firmwareId, string $deviceId, bool $bench): array
    {
        $firmware = null;
        foreach ($this->read('catalog') as $f) if (($f['id'] ?? '') === $firmwareId) $firmware = $f;
        if (!$firmware || !preg_match('/^[a-f0-9]{32}$/D', $firmwareId)) throw new \RuntimeException('Firmware não localizado.');
        if (!$bench && ($firmware['status'] ?? '') !== 'homologado') throw new \RuntimeException('Imagem não homologada: utilize uma ONU de bancada.');
        $d = $this->device($deviceId);
        foreach (['manufacturer'=>'manufacturer', 'model'=>'model', 'hardware'=>'hw_revision'] as $key=>$fk) {
            if ($d[$key] === '' || strcasecmp($d[$key], trim((string)($firmware[$fk] ?? ''))) !== 0) {
                throw new \RuntimeException('Fabricante, modelo ou revisão de hardware incompatível.');
            }
        }
        foreach (['serial','version','oui','productClass'] as $key) {
            if ($d[$key] === '' || preg_match('/[\r\n\x00]/', $d[$key])) throw new \RuntimeException('Inventário incompleto: ' . $key);
        }
        if (empty($firmware['version']) || preg_match('/[\r\n\x00]/', $firmware['version'])) throw new \RuntimeException('Versão alvo inválida.');
        if ($d['version'] === $firmware['version']) throw new \RuntimeException('Equipamento já utiliza a versão selecionada.');
        $last = strtotime($d['lastInform']);
        if (!$last || time() - $last > 900 || $last > time() + 60) throw new \RuntimeException('ONU sem comunicação recente (últimos 15 minutos). Solicite comunicação e tente novamente.');
        $path = $this->dir . '/' . $firmwareId . '.blob';
        if (($firmware['storage_name'] ?? '') !== $firmwareId . '.blob' || !is_file($path) || is_link($path)
            || filesize($path) !== (int)($firmware['bytes'] ?? 0) || !hash_equals((string)($firmware['sha256'] ?? ''), hash_file('sha256', $path))) {
            throw new \RuntimeException('Arquivo ausente ou integridade SHA-256 inválida.');
        }
        if ($this->query('tasks', ['device'=>$deviceId, 'name'=>'download'])) throw new \RuntimeException('Já existe uma tarefa de download para esta ONU.');
        return ['firmware'=>$firmware, 'device'=>$d, 'bench'=>$bench];
    }

    public function execute(array $ticket, string $actor): array
    {
        return $this->locked('updates', function () use ($ticket, $actor) {
            $jobs = $this->read('updates');
            foreach ($jobs as $j) {
                if ($j['id'] === $ticket['id']) return $j; // same confirmation never sends twice
                if ($j['device_id'] === $ticket['device_id'] && $j['state'] !== 'concluido') {
                    throw new \RuntimeException('Há uma execução anterior sem conclusão. Verifique o histórico antes de outro envio.');
                }
            }
            if (($ticket['expires'] ?? 0) < time()) throw new \RuntimeException('Confirmação expirada. Valide novamente.');
            $p = $this->preflight($ticket['firmware_id'], $ticket['device_id'], $ticket['bench']);
            $f = $p['firmware']; $d = $p['device'];
            if ($d['version'] !== $ticket['previous_version'] || $d['serial'] !== $ticket['serial'] || $f['sha256'] !== $ticket['sha256']) {
                throw new \RuntimeException('Equipamento ou imagem mudou após a validação. Valide novamente.');
            }
            $j = ['id'=>$ticket['id'], 'device_id'=>$d['id'], 'serial'=>$d['serial'], 'firmware_id'=>$f['id'],
                'sha256'=>$f['sha256'], 'previous_version'=>$d['version'], 'target_version'=>$f['version'],
                'bench'=>$ticket['bench'], 'actor'=>$actor, 'created_at'=>gmdate('c'), 'state'=>'preparando', 'task_id'=>null];
            $jobs[] = $j; $index = count($jobs)-1;
            $this->save('updates', $jobs); // durable intent before any external mutation
            try {
                $extension = strtolower(pathinfo($f['original_name'], PATHINFO_EXTENSION));
                if (!in_array($extension, ['bin','img','fw','trx','zip','tar','npk'], true)) throw new \RuntimeException('Extensão inválida.');
                $filename = 'jrc-' . $f['id'] . '-' . $j['id'] . '.' . $extension;
                $r = $this->request('PUT', '/files/' . rawurlencode($filename), null, $this->dir . '/' . $f['storage_name'], [
                    'fileType: 1 Firmware Upgrade Image', 'oui: ' . $d['oui'], 'productClass: ' . $d['productClass'], 'version: ' . $f['version']
                ]);
                if (!in_array($r['status'] ?? 0, [200,201,204], true)) throw new \RuntimeException('Publicação do arquivo no GenieACS falhou.');
                $j['state'] = 'envio_incerto'; $j['file'] = $filename;
                $jobs[$index] = $j; $this->save('updates', $jobs);
                $r = $this->request('POST', '/devices/' . rawurlencode($d['id']) . '/tasks', ['name'=>'download', 'file'=>$filename]);
                if (!in_array($r['status'] ?? 0, [200,202], true) || empty($r['data']['_id'])) {
                    throw new \RuntimeException('Resposta de envio inconclusiva. Consulte o histórico; não reenvie a tarefa.');
                }
                $j['task_id'] = (string)$r['data']['_id']; $j['state'] = 'aguardando_confirmacao';
            } catch (\Throwable $e) {
                $j['error'] = $e->getMessage();
                if ($j['state'] === 'preparando') $j['state'] = 'falha_publicacao';
            }
            $jobs[$index] = $j; $this->save('updates', $jobs);
            return $j;
        });
    }

    public function refresh(string $id): array
    {
        return $this->locked('updates', function () use ($id) {
            $jobs = $this->read('updates');
            foreach ($jobs as $i=>$j) {
                if ($j['id'] !== $id) continue;
                if ($j['state'] === 'concluido') return $j;
                $d = $this->device($j['device_id']);
                $tasks = $this->query('tasks', ['device'=>$j['device_id'], 'name'=>'download']);
                $faults = $this->query('faults', ['device'=>$j['device_id']]);
                $matchingFault = false;
                foreach ($faults as $fault) {
                    if ($j['task_id'] && ($fault['_id'] ?? '') === $j['device_id'] . ':task_' . $j['task_id']) $matchingFault = true;
                }
                $sent = strtotime($j['created_at']);
                if ($j['task_id'] && !$tasks && !$matchingFault && $d['serial'] === $j['serial']
                    && $d['version'] === $j['target_version'] && strtotime($d['versionTime']) > $sent && strtotime($d['lastInform']) > $sent) {
                    $j['state'] = 'concluido'; $j['completed_at'] = gmdate('c'); unset($j['error']);
                } elseif ($matchingFault) {
                    $j['state'] = 'falha_dispositivo'; $j['error'] = 'O GenieACS registrou falha para esta tarefa. Consulte o diagnóstico antes de tentar novamente.';
                }
                $j['observed_version'] = $d['version']; $j['checked_at'] = gmdate('c');
                $jobs[$i] = $j; $this->save('updates', $jobs); return $j;
            }
            throw new \RuntimeException('Execução não encontrada.');
        });
    }

    public function approve(string $id, string $actor): void
    {
        $this->locked('catalog', function () use ($id, $actor) {
            $job = null;
            foreach ($this->read('updates') as $j) if ($j['id'] === $id) $job = $j;
            if (!$job || !$job['bench'] || $job['state'] !== 'concluido') throw new \RuntimeException('Homologação exige teste de bancada concluído.');
            $catalog = $this->read('catalog');
            foreach ($catalog as $i=>$f) {
                if ($f['id'] !== $job['firmware_id']) continue;
                if ($f['sha256'] !== $job['sha256'] || is_link($this->dir . '/' . $f['id'] . '.blob')
                    || !hash_equals($f['sha256'], (string)@hash_file('sha256', $this->dir . '/' . $f['id'] . '.blob'))) {
                    throw new \RuntimeException('Imagem mudou desde o teste.');
                }
                $catalog[$i]['status'] = 'homologado'; $catalog[$i]['approved_by'] = $actor;
                $catalog[$i]['approved_at'] = gmdate('c'); $catalog[$i]['approval_job'] = $id;
                $this->save('catalog', $catalog); return;
            }
            throw new \RuntimeException('Firmware não encontrado.');
        });
    }

    public static function http(string $method, string $path, ?array $body, ?string $file, array $headers): array
    {
        $base = rtrim(getenv('JRC_FW_NBI_URL') ?: 'http://127.0.0.1:7557', '/');
        if (!preg_match('~^http://(?:127\.0\.0\.1|localhost):[0-9]{2,5}$~D', $base)) throw new \RuntimeException('NBI deve usar o endpoint local autorizado.');
        if (!function_exists('curl_init')) throw new \RuntimeException('PHP cURL indisponível.');
        $ch = curl_init($base . $path); $handle = null;
        try {
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_TIMEOUT=>90,
                CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTP]);
            if ($file !== null) {
                $handle = fopen($file, 'rb');
                if (!$handle) throw new \RuntimeException('Arquivo indisponível.');
                curl_setopt_array($ch, [CURLOPT_UPLOAD=>true, CURLOPT_INFILE=>$handle, CURLOPT_INFILESIZE=>filesize($file)]);
                $headers[] = 'Content-Type: application/octet-stream';
            } elseif ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
                $headers[] = 'Content-Type: application/json';
            }
            $headers[] = 'Accept: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $raw = curl_exec($ch);
            if ($raw === false) throw new \RuntimeException('Falha de comunicação com o GenieACS. Consulte o histórico antes de repetir.');
            return ['status'=>curl_getinfo($ch, CURLINFO_HTTP_CODE), 'data'=>json_decode($raw, true)];
        } finally { curl_close($ch); if (is_resource($handle)) fclose($handle); }
    }
}
