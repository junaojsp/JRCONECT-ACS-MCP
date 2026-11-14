<?php
declare(strict_types=1);

/**
 * Operational indicators derived from the currently imported VoiceCopy stock
 * and the validated read-only IXC snapshot. No persistence or automatic DID
 * release. "A" is only a raw code, not a formal contractual classification.
 */
final class VoipOperational
{
    public static function summarize(array $stockRows, array $comparison): array
    {
        $stock = ['ocupado' => 0, 'disponivel' => 0, 'a_conferir' => 0];
        $phones = [];
        foreach ($stockRows as $row) {
            $number = (string)($row['number'] ?? '');
            $status = (string)($row['status'] ?? '');
            if (!array_key_exists($status, $stock) || !preg_match('/^[1-9][0-9]{9}$/D', $number) || isset($phones[$number])) {
                throw new RuntimeException('Estoque não íntegro para análise.');
            }
            $phones[$number] = $status;
            $stock[$status]++;
        }
        $uniqueCandidates = [];
        $rawStatus = [];
        $withoutPhoneWithA = [];
        $unmatchedContracts = [];
        $ambiguousContracts = [];
        foreach (($comparison['rows'] ?? []) as $row) {
            if (!is_array($row)) throw new RuntimeException('Comparação IXC inválida.');
            $status = (string)($row['status'] ?? '');
            $contractId = (string)($row['contract_id'] ?? '');
            if (!preg_match('/^[0-9]{1,12}$/D', $contractId) || $status === '' || strlen($status) > 40) {
                throw new RuntimeException('Contrato IXC inválido.');
            }
            $rawStatus[$status] = ($rawStatus[$status] ?? 0) + 1;
            $classification = $row['classification'] ?? '';
            if ($classification === 'unica' && count($row['candidates'] ?? []) === 1) {
                $number = (string)$row['candidates'][0];
                if (!isset($phones[$number]) || isset($uniqueCandidates[$number])) {
                    throw new RuntimeException('Candidato telefônico inválido.');
                }
                $uniqueCandidates[$number] = $contractId;
            } elseif (in_array($classification, ['sem_correspondencia', 'sem_nome'], true)) {
                $unmatchedContracts[] = $contractId;
                if ($status === 'A') $withoutPhoneWithA[] = $contractId;
            } elseif ($classification === 'ambigua') {
                $ambiguousContracts[] = $contractId;
            } else {
                throw new RuntimeException('Classificação de conciliação inválida.');
            }
        }
        $occupiedWithoutSuggestion = 0;
        foreach ($phones as $number => $status) {
            if ($status === 'ocupado' && !isset($uniqueCandidates[$number])) $occupiedWithoutSuggestion++;
        }
        sort($withoutPhoneWithA, SORT_NUMERIC);
        sort($unmatchedContracts, SORT_NUMERIC);
        sort($ambiguousContracts, SORT_NUMERIC);
        ksort($rawStatus);
        return [
            'stock' => $stock,
            'suggested_unique' => count($uniqueCandidates),
            'occupied_without_suggestion' => $occupiedWithoutSuggestion,
            'raw_status' => $rawStatus,
            'contracts_without_phone' => $unmatchedContracts,
            'contracts_status_A_without_phone' => $withoutPhoneWithA,
            'ambiguous_contracts' => $ambiguousContracts,
            'confirmed_links' => 0,
            'availability_from_ixc' => null,
            'monitoring_mode' => 'snapshot_manual',
        ];
    }
}
