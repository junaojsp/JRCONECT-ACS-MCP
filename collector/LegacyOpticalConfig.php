<?php
declare(strict_types=1);

namespace JRC\Collector;

/**
 * Safe migration adapter for the installed optical IXC integration.
 *
 * Reads the optical endpoint as TOKENIZED SOURCE, never includes or evals it.
 * Accepts only a PHP literal OR a direct $ixcConfig['key'] assignment.
 * The config array is provided by the trusted app's getIxcConfig().
 *
 * Tokens and subscriber data must never be printed/logged.
 */
final class LegacyOpticalConfig
{
    /**
     * @param ?callable $provider Test-only alternative to trusted getIxcConfig().
     * @return array{url:string,token:string}
     */
    public static function read(string $file, ?callable $provider = null): array
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new \RuntimeException('Arquivo optico IXC indisponivel');
        }
        $source = file_get_contents($file);
        if (!is_string($source)) throw new \RuntimeException('Falha lendo configuracao optica');

        $tokens = token_get_all($source);
        $variables = ['$ixcBaseUrl' => 'url', '$ixcToken' => 'token'];
        $found = [];
        $references = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_VARIABLE ||
                !isset($variables[$token[1]])) continue;
            $name = $variables[$token[1]];
            $eq = self::significant($tokens, $i + 1);
            if ($eq === null || $tokens[$eq] !== '=') continue;
            if (isset($found[$name]) || isset($references[$name])) {
                throw new \RuntimeException('Multipla configuracao IXC para ' . $name);
            }
            $start = self::significant($tokens, $eq + 1);
            $right = $start === null ? null : $tokens[$start];
            if (is_array($right) && $right[0] === T_CONSTANT_ENCAPSED_STRING) {
                $found[$name] = self::decodeLiteral($right[1]);
                $semi = self::significant($tokens, $start + 1);
                if ($semi === null || $tokens[$semi] !== ';') {
                    throw new \RuntimeException('Literal IXC com expressao nao suportada');
                }
                continue;
            }
            // On the installed ACS, both values are read from $ixcConfig,
            // which itself comes from getIxcConfig() in the installed lib/IXCConfig.php.
            if (is_array($right) && $right[0] === T_VARIABLE &&
                $right[1] === '$ixcConfig') {
                $bracket = self::significant($tokens, $start + 1);
                if ($bracket === null || $tokens[$bracket] !== '[') {
                    throw new \RuntimeException('Referencia IXC nao suportada');
                }
                $keyIndex = self::significant($tokens, $bracket + 1);
                $keyToken = $keyIndex === null ? null : $tokens[$keyIndex];
                if (!is_array($keyToken) ||
                    $keyToken[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    throw new \RuntimeException('Chave IXC dinamica nao permitida');
                }
                $key = self::decodeLiteral($keyToken[1]);
                if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,60}$/', $key)) {
                    throw new \RuntimeException('Nome de chave IXC invalido');
                }
                $close = self::significant($tokens, $keyIndex + 1);
                if ($close === null || $tokens[$close] !== ']') {
                    throw new \RuntimeException('Indice IXC nao suportado');
                }
                $references[$name] = $key;
                continue;
            }
            throw new \RuntimeException('Expressao IXC nao suportada; nao executar endpoint');
        }

        if (count($found) + count($references) !== 2) {
            throw new \RuntimeException('URL/token IXC nao identificados na API optica');
        }
        if ($references !== []) {
            // Verify the expected trusted provider is used by the original API.
            if (!preg_match('/\$ixcConfig\s*=\s*getIxcConfig\s*\(\s*\)\s*;/', $source)) {
                throw new \RuntimeException('Origem da configuracao IXC nao comprovada');
            }
            if ($provider === null) {
                if (!function_exists('getIxcConfig')) {
                    throw new \RuntimeException('Carregue config/config.php antes da auditoria IXC');
                }
                $provider = 'getIxcConfig';
            }
            $config = $provider();
            if (!is_array($config)) {
                throw new \RuntimeException('getIxcConfig nao retornou array');
            }
            foreach ($references as $name => $key) {
                // Resolve the exact key observed in the production PHP.
                $value = $config[$key] ?? null;
                if (!is_string($value) || trim($value) === '') {
                    throw new \RuntimeException('Configuracao IXC ausente para ' . $name);
                }
                $found[$name] = trim($value);
            }
        }
        if (!isset($found['url'], $found['token'])) {
            throw new \RuntimeException('Configuracao IXC incompleta');
        }
        $url = Config::requireSafeUrl($found['url']);
        if ($found['token'] === '') throw new \RuntimeException('Token IXC vazio');
        return ['url' => $url, 'token' => $found['token']];
    }

    private static function decodeLiteral(string $literal): string
    {
        $quote = $literal[0] ?? '';
        if (($quote !== "'" && $quote !== '"') || substr($literal, -1) !== $quote) {
            throw new \RuntimeException('Formato de literal IXC invalido');
        }
        $value = substr($literal, 1, -1);
        // No interpolation or escape processing: reject complex expressions.
        if ($value === '' || str_contains($value, '\\') ||
            ($quote === '"' && str_contains($value, '$'))) {
            throw new \RuntimeException('Literal IXC nao suportado');
        }
        return $value;
    }

    private static function significant(array $tokens, int $start): ?int
    {
        for ($i = $start, $count = count($tokens); $i < $count; ++$i) {
            $item = $tokens[$i];
            if (is_array($item) && in_array(
                $item[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true
            )) continue;
            return $i;
        }
        return null;
    }
}
