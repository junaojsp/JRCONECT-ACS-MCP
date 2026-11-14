<?php
declare(strict_types=1);

/**
 * Read-only IXC x VoiceCopy reconciliation. The snapshot must be obtained from
 * the authorized IXC MCP tool by an operator; this class never calls IXC,
 * persists PII, writes stock, links contracts or frees any number.
 */
final class VoipReconcile
{
    private static function validId($value): bool
    {
        return is_string($value) && preg_match('/^[0-9]{1,12}$/D', $value) === 1;
    }

    private static function normalize(string $name): string
    {
        $name = trim($name);
        if ($name === '') return '';
        // Portuguese accents are normalized only for candidate suggestions.
        $name = strtr($name, [
            'Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','á'=>'A','à'=>'A','â'=>'A','ã'=>'A','ä'=>'A',
            'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','é'=>'E','è'=>'E','ê'=>'E','ë'=>'E',
            'Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I','í'=>'I','ì'=>'I','î'=>'I','ï'=>'I',
            'Ó'=>'O','Ò'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','ó'=>'O','ò'=>'O','ô'=>'O','õ'=>'O','ö'=>'O',
            'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','ú'=>'U','ù'=>'U','û'=>'U','ü'=>'U',
            'Ç'=>'C','ç'=>'C'
        ]);
        $name = strtoupper($name);
        return trim((string)preg_replace('/\\s+/', ' ', (string)preg_replace('/[^A-Z0-9 ]/', ' ', $name)));
    }

    public static function parseSnapshot(string $json): array
    {
        if (strlen($json) > 100000 || trim($json) === '') {
            throw new RuntimeException('Snapshot IXC ausente ou muito grande.');
        }
        try { $raw = json_decode($json, true, 64, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new RuntimeException('JSON IXC inválido.'); }
        if (!is_array($raw) || ($raw['produto_id'] ?? null) !== '74' ||
            ($raw['somente_leitura'] ?? null) !== true ||
            ($raw['liberacao_automatica'] ?? null) !== false ||
            !is_array($raw['contratos'] ?? null) ||
            !isset($raw['total_contratos'], $raw['total_registros_produto']) ||
            !is_int($raw['total_contratos']) || !is_int($raw['total_registros_produto'])) {
            throw new RuntimeException('Snapshot incompatível com a consulta IXC do produto 74.');
        }
        if (count($raw['contratos']) > 200 || count($raw['contratos']) !== $raw['total_contratos']) {
            throw new RuntimeException('Contagem de contratos IXC divergente.');
        }
        $seenContracts = [];
        $seenProducts = [];
        $contracts = [];
        foreach ($raw['contratos'] as $row) {
            if (!is_array($row) || !self::validId($row['contrato_id'] ?? null) ||
                isset($seenContracts[$row['contrato_id']]) ||
                !is_string($row['status_ixc_bruto'] ?? null) ||
                trim($row['status_ixc_bruto']) === '' ||
                strlen($row['status_ixc_bruto']) > 40 ||
                !is_array($row['registros_produto_74'] ?? null) ||
                count($row['registros_produto_74']) < 1) {
                throw new RuntimeException('Registro de contrato IXC inválido ou duplicado.');
            }
            $seenContracts[$row['contrato_id']] = true;
            $name = $row['nome_cliente'] ?? null;
            if ($name !== null && (!is_string($name) || strlen($name) > 250 ||
                preg_match('/[\\x00-\\x1f]/', $name))) {
                throw new RuntimeException('Nome do titular IXC inválido.');
            }
            foreach ($row['registros_produto_74'] as $rec) {
                if (!self::validId($rec) || isset($seenProducts[$rec])) {
                    throw new RuntimeException('Registro do produto 74 inválido ou duplicado.');
                }
                $seenProducts[$rec] = true;
            }
            if (isset($row['voip_cancelar']) && !is_bool($row['voip_cancelar'])) {
                throw new RuntimeException('Sinal de cancelamento IXC inválido.');
            }
            $contracts[] = [
                'contract_id' => $row['contrato_id'],
                'customer_name' => $name,
                'status' => $row['status_ixc_bruto'],
                'product_ids' => $row['registros_produto_74'],
                'cancel_requested' => (bool)($row['voip_cancelar'] ?? false),
            ];
        }
        if (count($seenProducts) !== $raw['total_registros_produto']) {
            throw new RuntimeException('Contagem de produtos 74 divergente.');
        }
        return $contracts;
    }

    public static function compare(array $stockRows, array $contracts): array
    {
        $byName = [];
        foreach ($stockRows as $row) {
            $norm = self::normalize((string)($row['holder'] ?? ''));
            if ($norm !== '') $byName[$norm][] = (string)$row['number'];
        }
        $contractNames = [];
        foreach ($contracts as $contract) {
            $norm = self::normalize((string)($contract['customer_name'] ?? ''));
            if ($norm !== '') $contractNames[$norm] = ($contractNames[$norm] ?? 0) + 1;
        }
        $out = [];
        $stats = ['unica' => 0, 'ambigua' => 0, 'sem_correspondencia' => 0, 'sem_nome' => 0];
        foreach ($contracts as $contract) {
            $norm = self::normalize((string)($contract['customer_name'] ?? ''));
            $numbers = $norm === '' ? [] : ($byName[$norm] ?? []);
            if ($norm === '') $classification = 'sem_nome';
            elseif (count($numbers) === 1 && $contractNames[$norm] === 1) $classification = 'unica';
            elseif (count($numbers) > 0) $classification = 'ambigua';
            else $classification = 'sem_correspondencia';
            $stats[$classification]++;
            $out[] = [
                'contract_id' => $contract['contract_id'],
                'customer_name' => $contract['customer_name'],
                'status' => $contract['status'],
                'product_ids' => $contract['product_ids'],
                'candidates' => $numbers,
                'classification' => $classification,
            ];
        }
        return ['rows' => $out, 'stats' => $stats, 'total_stock' => count($stockRows)];
    }
}
