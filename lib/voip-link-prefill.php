<?php
declare(strict_types=1);

/**
 * Computes safe, non-persistent link-form suggestions from the current
 * authorized IXC comparison and current VoiceCopy inventory.
 * This is UI convenience only, never automatic confirmation.
 */
final class VoipLinkPrefill
{
    public static function suggest(array $match, array $stockNumbers): ?array
    {
        if (($match['classification'] ?? null) !== 'unica' ||
            count($match['candidates'] ?? []) !== 1 ||
            count($match['product_ids'] ?? []) !== 1) return null;

        $number = $match['candidates'][0];
        $contract = $match['contract_id'] ?? null;
        $product = $match['product_ids'][0];
        if (!is_string($number) || preg_match('/^[1-9][0-9]{9}$/D', $number) !== 1 ||
            !is_string($contract) || preg_match('/^[0-9]{1,12}$/D', $contract) !== 1 ||
            !is_string($product) || preg_match('/^[0-9]{1,12}$/D', $product) !== 1) return null;

        $stock = $stockNumbers[$number] ?? null;
        if (!is_array($stock) ||
            !in_array($stock['status'] ?? '', ['ocupado', 'disponivel'], true) ||
            !empty($stock['contract_id']) || !empty($stock['product_record_id'])) return null;

        foreach ($stockNumbers as $record) {
            if ((string)($record['contract_id'] ?? '') === $contract ||
                (string)($record['product_record_id'] ?? '') === $product) return null;
        }

        return ['number' => $number, 'contract_id' => $contract, 'product_record_id' => $product];
    }
}
