<?php
declare(strict_types=1);

/**
 * Historical reconciliation is advisory. Compare the original VoiceCopy
 * inventory against the full IXC product-74 snapshot, from DID to contract.
 * Missing from the product-74 snapshot never proves a number is free.
 */
final class VoipHistoricalReview
{
    private static function normalize(string $value): string
    {
        $value = trim($value);
        $value = strtr($value, [
            'Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A',
            'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I',
            'Ó'=>'O','Ò'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O',
            'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','Ç'=>'C',
            'á'=>'A','à'=>'A','â'=>'A','ã'=>'A','ä'=>'A',
            'é'=>'E','è'=>'E','ê'=>'E','ë'=>'E','í'=>'I','ì'=>'I','î'=>'I','ï'=>'I',
            'ó'=>'O','ò'=>'O','ô'=>'O','õ'=>'O','ö'=>'O',
            'ú'=>'U','ù'=>'U','û'=>'U','ü'=>'U','ç'=>'C'
        ]);
        return trim((string)preg_replace('/\\s+/', ' ', (string)preg_replace('/[^A-Z0-9 ]/', ' ', strtoupper($value))));
    }

    public static function analyze(array $inventory, array $contracts): array
    {
        $byName = [];
        $byId = [];
        foreach ($contracts as $contract) {
            $id = (string)($contract['contract_id'] ?? '');
            if (!preg_match('/^[0-9]{1,12}$/D', $id) ||
                isset($byId[$id]) ||
                !is_array($contract['product_ids'] ?? null) ||
                !$contract['product_ids']) {
                throw new RuntimeException('Snapshot IXC inconsistente.');
            }
            $byId[$id] = $contract;
            $norm = self::normalize((string)($contract['customer_name'] ?? ''));
            if ($norm !== '') $byName[$norm][] = $contract;
        }
        $rows = [];
        $summary = [
            'historical_occupied'=>0,
            'active_candidate'=>0,
            'inactive_candidate'=>0,
            'missing_product74'=>0,
            'ambiguous'=>0,
            'already_linked'=>0,
            'unknown_status'=>0,
            'other_review'=>0,
        ];
        $seenNumbers = [];
        foreach ($inventory as $entry) {
            $number = (string)($entry['number'] ?? '');
            if (!preg_match('/^[1-9][0-9]{9}$/D', $number) || isset($seenNumbers[$number])) {
                throw new RuntimeException('Estoque com DID inválido ou duplicado.');
            }
            $seenNumbers[$number] = true;
            if (($entry['status'] ?? null) !== 'ocupado') continue;
            $summary['historical_occupied']++;
            $linkedId = (string)($entry['contract_id'] ?? '');
            $linkedProduct = (string)($entry['product_record_id'] ?? '');
            $norm = self::normalize((string)($entry['holder'] ?? ''));
            $matched = $norm === '' ? [] : ($byName[$norm] ?? []);
            if ($linkedId !== '' || $linkedProduct !== '') {
                $category = 'already_linked';
                $candidate = $byId[$linkedId] ?? null;
                $reason = 'Vínculo existente protegido; conferir divergências antes de qualquer baixa.';
            } elseif (count($matched) > 1) {
                $category = 'ambiguous';
                $candidate = null;
                $reason = 'Titular possui mais de um contrato produto 74; não atribuir DID pelo nome.';
            } elseif (count($matched) === 0) {
                $category = 'missing_product74';
                $candidate = null;
                $reason = 'Não consta no snapshot produto 74; verificar contrato cancelado, produto removido e VoiceCopy.';
            } else {
                $candidate = $matched[0];
                $status = (string)($candidate['status'] ?? '');
                if ($status === 'A') {
                    $category = 'active_candidate';
                    $reason = 'Status bruto A: indício de ativo; conferir atribuição antes do vínculo.';
                } elseif ($status === 'I') {
                    $category = 'inactive_candidate';
                    $reason = 'Status bruto I: candidato à liberação após confirmação na VoiceCopy.';
                } else {
                    $category = 'unknown_status';
                    $reason = 'Status IXC não classificado; revisão obrigatória.';
                }
            }
            $summary[$category]++;
            $rows[] = [
                'number'=>$number,
                'holder'=>(string)($entry['holder'] ?? ''),
                'category'=>$category,
                'contract_id'=>$candidate === null ? null : (string)$candidate['contract_id'],
                'status_raw'=>$candidate === null ? null : (string)$candidate['status'],
                'product_id'=>$candidate !== null && count($candidate['product_ids']) === 1
                    ? (string)$candidate['product_ids'][0] : null,
                'reason'=>$reason,
                'requires_voicecopy_confirmation'=>true,
                'automatic_release'=>false,
            ];
        }
        usort($rows, static fn(array $a,array $b): int => strcmp($a['number'],$b['number']));
        return ['rows'=>$rows,'summary'=>$summary,'read_only'=>true,'no_automatic_release'=>true,
            'note'=>'A/I são códigos brutos; ausência do produto 74 não prova que DID está livre.'];
    }
}
