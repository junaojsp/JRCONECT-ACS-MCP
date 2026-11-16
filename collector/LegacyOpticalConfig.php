<?php
declare(strict_types=1);

namespace JRC\Collector;

/**
 * Read only the two literal IXC settings from the existing optical endpoint.
 * NEVER include/evaluate that endpoint: it has application/session side effects.
 * Never log or print the token.
 * Temporary migration tool, not a replacement for a dedicated secret store.
 */
final class LegacyOpticalConfig
{
    public static function read(string $file): array
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new \RuntimeException('Arquivo de integracao optica nao encontrado');
        }
        $php = file_get_contents($file);
        if (!is_string($php)) {
            throw new \RuntimeException('Falha ao ler configuracao da integracao');
        }

        $found = [];
        $keys = ['$ixcBaseUrl' => 'url', '$ixcToken' => 'token'];
        $tokens = token_get_all($php);
        $total = count($tokens);
        for ($i = 0; $i < $total; ++$i) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_VARIABLE ||
                !isset($keys[$token[1]])) {
                continue;
            }
            $variable = $token[1];
            $next = self::significant($tokens, $i + 1);
            if ($next === null || $tokens[$next] !== '=') continue;

            $valueIndex = self::significant($tokens, $next + 1);
            $value = $valueIndex === null ? null : $tokens[$valueIndex];
            if (!is_array($value) || $value[0] !== T_CONSTANT_ENCAPSED_STRING) {
                throw new \RuntimeException('Configuracao IXC nao e string literal; migrar manualmente');
            }
            $nextAfterValue = self::significant($tokens, $valueIndex + 1);
            if ($nextAfterValue === null || $tokens[$nextAfterValue] !== ';') {
                throw new \RuntimeException('Configuracao IXC nao e literal simples');
            }
            $text = $value[1];
            $quote = $text[0] ?? '';
            if (($quote !== "'" && $quote !== '"') ||
                substr($text, -1) !== $quote) {
                throw new \RuntimeException('Formato de literal IXC invalido');
            }
            $inner = substr($text, 1, -1);
            // Do not interpret escape sequences or interpolate secrets.
            if ($inner === '' || str_contains($inner, '\\') ||
                ($quote === '"' && str_contains($inner, '$'))) {
                throw new \RuntimeException('Literal IXC nao suportado; migrar manualmente');
            }
            $key = $keys[$variable];
            if (isset($found[$key])) {
                throw new \RuntimeException('Configuracao IXC com multiplas atribuicoes');
            }
            $found[$key] = $inner;
        }

        if (!isset($found['url'], $found['token'])) {
            throw new \RuntimeException('Configuracao IXC incompleta; nao adivinhar credenciais');
        }
        $found['url'] = Config::requireSafeUrl($found['url']);
        return $found;
    }

    private static function significant(array $tokens, int $start): ?int
    {
        $count = count($tokens);
        for ($i = $start; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (is_array($token) && in_array(
                $token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true
            )) continue;
            return $i;
        }
        return null;
    }
}
