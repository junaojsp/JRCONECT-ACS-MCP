<?php
declare(strict_types=1);
require_once __DIR__ . '/../collector/Config.php';
require_once __DIR__ . '/../collector/LegacyOpticalConfig.php';

use JRC\Collector\LegacyOpticalConfig;

function verify(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
}

$file = tempnam(sys_get_temp_dir(), 'jrc-optical-fixture-');
if ($file === false) throw new RuntimeException('Sem pasta temporaria');

try {
    // Credentials in this fixture are deliberately fictitious.
    file_put_contents($file, <<<'PHP'
<?php
// $ixcToken = 'comentario_invalido';
$ixcBaseUrl =
  'https://example.invalid';
$ixcToken = 'dummy-token-UNIT-TEST';
PHP);
    $cfg = LegacyOpticalConfig::read($file);
    verify($cfg['url'] === 'https://example.invalid', 'Extracao HTTPS');
    verify($cfg['token'] === 'dummy-token-UNIT-TEST', 'Extracao literal');
    unset($cfg);

    file_put_contents($file, <<<'PHP'
<?php
$ixcBaseUrl = 'http://untrusted.external.invalid';
$ixcToken = 'dummy-token-UNIT-TEST';
PHP);
    try {
        LegacyOpticalConfig::read($file);
        throw new RuntimeException('HTTP remoto deveria falhar');
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'HTTP remoto deveria falhar') throw $e;
    }

    file_put_contents($file, <<<'PHP'
<?php
$ixcBaseUrl = 'https://example.invalid';
$ixcToken = getenv('FAKE_SECRET');
PHP);
    try {
        LegacyOpticalConfig::read($file);
        throw new RuntimeException('Expressao dinamica deveria falhar');
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Expressao dinamica deveria falhar') throw $e;
    }

    // Production-shaped source: URL and token derived from getIxcConfig().
    file_put_contents($file, <<<'PHP'
<?php
$ixcConfig = getIxcConfig();
$ixcBaseUrl = $ixcConfig['base_url'] ?? '';
$ixcToken = $ixcConfig['token'] ?? '';
PHP);
    $fixture = ['base_url' => 'https://example.invalid', 'token' => 'dummy-token-UNIT-TEST'];
    $cfg = LegacyOpticalConfig::read($file, static fn() => $fixture);
    verify($cfg['url'] === $fixture['base_url'], 'getIxcConfig URL');
    verify($cfg['token'] === $fixture['token'], 'getIxcConfig token');
    unset($cfg);

    // A missing key must fail closed, never guessing a credential.
    try {
        LegacyOpticalConfig::read($file, static fn() => ['base_url' => 'https://example.invalid']);
        throw new RuntimeException('Provider incompleto deveria falhar');
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Provider incompleto deveria falhar') throw $e;
        verify(!str_contains($e->getMessage(), 'dummy-token-'), 'Sem token em erros');
    }

    // Untrusted alternate call origin is rejected even with a supplied provider.
    file_put_contents($file, <<<'PHP'
<?php
$ixcConfig = getenv('FAKE_CONFIG');
$ixcBaseUrl = $ixcConfig['base_url'];
$ixcToken = $ixcConfig['token'];
PHP);
    try {
        LegacyOpticalConfig::read($file, static fn() => $fixture);
        throw new RuntimeException('Origem desconhecida deveria falhar');
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Origem desconhecida deveria falhar') throw $e;
    }

    echo "JRC_OPTICAL_AUDIT_TESTS_OK\n";
} finally {
    unlink($file);
}
