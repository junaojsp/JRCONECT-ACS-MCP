<?php
/* JR Conect ACS - Firmware repository (catalog only).
 * No GenieACS uploads, CWMP tasks or mass operations occur here.
 */
require_once __DIR__ . '/config/config.php';
requireLogin();
$pageTitle = 'Repositorio de Firmware';
$currentPage = 'firmware';

const FW_DIR = '/var/lib/jrc-acs-firmware';
const FW_MAX_BYTES = 268435456; // 256 MiB; PHP upload_max_filesize/post_max_size may be lower
$allowedExt = ['bin', 'img', 'fw', 'trx', 'zip', 'tar', 'npk'];
function fwEsc($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function fwClean($s, $max = 120) {
    $s = trim((string)$s);
    if ($s === '' || strlen($s) > $max || preg_match('/[\x00-\x1f\x7f]/', $s)) {
        throw new RuntimeException('Campo obrigatorio, longo demais ou com caracteres invalidos.');
    }
    return $s;
}
function fwCatalog() {
    $path = FW_DIR . '/catalog.json';
    if (!is_file($path)) return [];
    $text = file_get_contents($path);
    if ($text === false) throw new RuntimeException('Falha ao ler catalogo.');
    $records = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($records)) throw new RuntimeException('Catalogo invalido.');
    return $records;
}
function fwSave(array $item, string $tempFile) {
    $lock = fopen(FW_DIR . '/catalog.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Falha no bloqueio do catalogo.');
    try {
        $data = fwCatalog();
        foreach ($data as $existing) {
            if (($existing['sha256'] ?? '') === $item['sha256']
                && ($existing['model'] ?? '') === $item['model']
                && ($existing['hw_revision'] ?? '') === $item['hw_revision']) {
                throw new RuntimeException('Arquivo ja registrado para o mesmo modelo e revisao.');
            }
        }
        $name = $item['id'] . '.blob';
        $dest = FW_DIR . '/' . $name;
        if (!move_uploaded_file($tempFile, $dest)) throw new RuntimeException('Falha ao guardar arquivo.');
        chmod($dest, 0640);
        $item['storage_name'] = $name;
        $data[] = $item;
        $tempMeta = FW_DIR . '/.catalog-' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (file_put_contents($tempMeta, $json, LOCK_EX) === false) throw new RuntimeException('Falha ao salvar metadados.');
            chmod($tempMeta, 0640);
            if (!rename($tempMeta, FW_DIR . '/catalog.json')) throw new RuntimeException('Falha ao publicar catalogo.');
        } catch (Throwable $e) {
            @unlink($dest);
            @unlink($tempMeta);
            throw $e;
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['jrc_fw_csrf'])) $_SESSION['jrc_fw_csrf'] = bin2hex(random_bytes(32));
$message = '';
$error = '';
$items = [];
try {
    if (!is_dir(FW_DIR) || !is_writable(FW_DIR) || is_link(FW_DIR)) {
        throw new RuntimeException('Armazenamento indisponivel. Verifique a instalacao e as permissoes em /var/lib/jrc-acs-firmware.');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['jrc_fw_csrf'], (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Sessao expirada. Atualize a pagina.');
        }
        if (($_POST['action'] ?? '') !== 'upload') throw new RuntimeException('Operacao nao permitida.');
        $file = $_FILES['firmware'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload nao recebido. Verifique os limites do PHP e o tamanho do arquivo.');
        }
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > FW_MAX_BYTES || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Arquivo invalido ou maior que 256 MiB.');
        }
        $originalName = basename((string)$file['name']);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExt, true)) throw new RuntimeException('Extensao nao aceita.');
        $manufacturer = fwClean($_POST['manufacturer'] ?? '', 80);
        $model = fwClean($_POST['model'] ?? '', 80);
        $hw = fwClean($_POST['hw_revision'] ?? '', 80);
        $version = fwClean($_POST['version'] ?? '', 100);
        $origin = fwClean($_POST['origin'] ?? '', 160);
        $sha = hash_file('sha256', $file['tmp_name']);
        if ($sha === false) throw new RuntimeException('Falha na verificacao SHA-256.');
        $expected = strtolower(trim((string)($_POST['expected_sha256'] ?? '')));
        if ($expected !== '' && (!preg_match('/^[a-f0-9]{64}$/', $expected) || !hash_equals($expected, $sha))) {
            throw new RuntimeException('SHA-256 diferente do informado. Arquivo nao cadastrado.');
        }
        $item = [
            'id' => bin2hex(random_bytes(16)), 'manufacturer' => $manufacturer,
            'model' => $model, 'hw_revision' => $hw, 'version' => $version,
            'origin' => $origin, 'original_name' => $originalName,
            'bytes' => $size, 'sha256' => $sha,
            'status' => 'nao_homologado',
            'created_at' => gmdate('c')
        ];
        fwSave($item, $file['tmp_name']);
        $message = 'Firmware adicionado ao repositorio. Status: NAO HOMOLOGADO. Nenhum dispositivo foi atualizado.';
    }
    $items = fwCatalog();
} catch (Throwable $e) {
    $error = $e->getMessage();
}
include __DIR__ . '/views/layouts/header.php';
?>
<style>
.jrc-fw-lib{--fw-card:#0c2136;--fw-border:#1c4b70;--fw-accent:#1c6cc2;color:#e5edf8;max-width:1520px;margin:auto}
.jrc-fw-lib .fw-card{background:var(--fw-card);border:1px solid var(--fw-border);border-radius:12px;padding:18px;margin:14px 0}
.jrc-fw-lib .fw-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.jrc-fw-lib .fw-muted{color:#9db5c9;font-size:13px}
.jrc-fw-lib .fw-num{font-size:26px;font-weight:700}
.jrc-fw-lib input{background:#071827;color:#eef8ff;border:1px solid #31516d;border-radius:6px;padding:9px;width:100%}
.jrc-fw-lib label{display:block;font-size:12px;color:#a0c1d8;margin-top:10px;margin-bottom:4px}
.jrc-fw-lib button{background:var(--fw-accent);color:white;border:0;border-radius:7px;padding:11px 18px;cursor:pointer;margin-top:12px}
.jrc-fw-lib table{width:100%;border-collapse:collapse;font-size:13px}
.jrc-fw-lib th,.jrc-fw-lib td{padding:11px;border-bottom:1px solid #254057;text-align:left}
.jrc-fw-lib th{color:#a0c1d8}
.jrc-fw-lib .fw-alert{padding:12px;background:#143449;border-left:3px solid #47b8ed;margin-top:12px}
.jrc-fw-lib .fw-error{border-left-color:#fb7185}
.jrc-fw-lib .fw-tag{color:#ffdb9c}
@media(max-width:800px){.jrc-fw-lib .fw-grid{grid-template-columns:1fr}}
</style>
<section class="jrc-fw-lib">
<h2>Repositorio Central de Firmwares</h2>
<p class="fw-muted">Biblioteca local de imagens oficiais para futura homologacao e distribuicao via GenieACS. Nenhum envio automatico as ONUs.</p>
<div class="fw-grid">
<div class="fw-card"><div class="fw-muted">Arquivos no catalogo</div><div class="fw-num"><?= count($items) ?></div></div>
<div class="fw-card"><div class="fw-muted">Nao homologados</div><div class="fw-num"><?= count(array_filter($items, fn($x) => ($x['status'] ?? '') === 'nao_homologado')) ?></div></div>
<div class="fw-card"><div class="fw-muted">Atualizacoes em execucao</div><div class="fw-num">0</div><div class="fw-muted">Modulo de envio nao habilitado</div></div>
</div>
<?php if ($message): ?><div class="fw-alert" role="status"><?= fwEsc($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="fw-alert fw-error" role="alert"><?= fwEsc($error) ?></div><?php endif; ?>
<div class="fw-card">
<h3>Cadastrar arquivo de firmware</h3>
<form action="/firmware-library.php" method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= fwEsc($_SESSION['jrc_fw_csrf']) ?>">
<input type="hidden" name="action" value="upload">
<div class="fw-grid">
<div><label for="fw-man">Fabricante</label><input id="fw-man" name="manufacturer" maxlength="80" required></div>
<div><label for="fw-model">Modelo</label><input id="fw-model" name="model" maxlength="80" required></div>
<div><label for="fw-hw">Revisao de hardware</label><input id="fw-hw" name="hw_revision" maxlength="80" required></div>
<div><label for="fw-ver">Versao</label><input id="fw-ver" name="version" maxlength="100" required></div>
<div><label for="fw-origin">Origem oficial / fornecedor</label><input id="fw-origin" name="origin" maxlength="160" required></div>
<div><label for="fw-file">Imagem (ate 256 MiB)</label><input id="fw-file" type="file" name="firmware" accept=".bin,.img,.fw,.trx,.zip,.tar,.npk" required></div>
</div>
<label for="fw-hash">SHA-256 esperado (opcional, recomendado quando fornecido pelo fabricante)</label>
<input id="fw-hash" name="expected_sha256" maxlength="64" pattern="[a-fA-F0-9]{64}" placeholder="64 caracteres hexadecimais">
<button type="submit">Adicionar ao repositorio</button>
<p class="fw-muted">O cadastro nao homologa a imagem e nao inicia atualizacao. Valide modelo, revisao e procedencia antes de utilizar.</p>
</form>
</div>
<div class="fw-card"><h3>Arquivos cadastrados</h3><div style="overflow-x:auto"><table>
<thead><tr><th>Fabricante / Modelo</th><th>Hardware</th><th>Versao</th><th>Arquivo</th><th>SHA-256</th><th>Situacao</th></tr></thead>
<tbody>
<?php if (!$items): ?><tr><td colspan="6">Nenhum firmware cadastrado.</td></tr><?php endif; ?>
<?php foreach (array_reverse($items) as $item): ?><tr>
<td><?= fwEsc(($item['manufacturer'] ?? '') . ' / ' . ($item['model'] ?? '')) ?></td>
<td><?= fwEsc($item['hw_revision'] ?? '') ?></td>
<td><?= fwEsc($item['version'] ?? '') ?></td>
<td><?= fwEsc($item['original_name'] ?? '') ?><div class="fw-muted"><?= number_format(((int)($item['bytes'] ?? 0))/1048576, 2, ',', '.') ?> MiB</div></td>
<td title="<?= fwEsc($item['sha256'] ?? '') ?>"><code><?= fwEsc(substr($item['sha256'] ?? '', 0, 16)) ?>...</code></td>
<td class="fw-tag">Nao homologado</td>
</tr><?php endforeach; ?></tbody></table></div></div>
<div class="fw-card"><h3>Proximas etapas</h3>
<p class="fw-muted">Homologacao em ONU de bancada, registro do resultado e liberacao de atualizacao individual. Atualizacoes em massa permanecem desabilitadas.</p>
<a href="/firmware-manager.php" style="color:#86caff">Consultar interface anterior</a>
</div></section>
<?php include __DIR__ . '/views/layouts/footer.php'; ?>
