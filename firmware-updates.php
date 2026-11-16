<?php
require_once __DIR__ . '/config/config.php';
requireLogin();
require_once __DIR__ . '/lib/FirmwareUpdates.php';
use App\FirmwareUpdates;
header('Cache-Control: no-store');
function fwuEsc($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$pageTitle = 'Atualização de Firmware'; $currentPage = 'firmware';
$error = ''; $message = ''; $files = []; $jobs = []; $preview = null; $selected = null;
if (empty($_SESSION['jrc_fwb_csrf'])) $_SESSION['jrc_fwb_csrf'] = bin2hex(random_bytes(32));
try {
    FirmwareUpdates::authorize($_SESSION);
    $service = new FirmwareUpdates('/var/lib/jrc-acs-firmware');
    $actor = (string)$_SESSION['user_id'] . ':' . (string)($_SESSION['username'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['jrc_fwb_csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Recarregue a página.');
        $action = $_POST['action'] ?? '';
        if ($action === 'validate') {
            if (($_POST['mode'] ?? '') !== 'bench' && ($_POST['mode'] ?? '') !== 'individual') throw new RuntimeException('Selecione o modo de atualização.');
            $preview = $service->preflight((string)($_POST['firmware_id'] ?? ''), (string)($_POST['device_id'] ?? ''), $_POST['mode'] === 'bench');
            $d = $preview['device']; $f = $preview['firmware'];
            $_SESSION['firmware_ticket'] = ['id'=>bin2hex(random_bytes(16)), 'device_id'=>$d['id'], 'firmware_id'=>$f['id'],
                'serial'=>$d['serial'], 'previous_version'=>$d['version'], 'sha256'=>$f['sha256'], 'bench'=>$preview['bench'], 'expires'=>time()+300];
        } elseif ($action === 'execute') {
            $ticket = $_SESSION['firmware_ticket'] ?? null;
            if (!$ticket || !hash_equals($ticket['id'], (string)($_POST['ticket'] ?? ''))) throw new RuntimeException('Confirmação inválida. Valide novamente.');
            if (trim((string)($_POST['confirm_serial'] ?? '')) !== $ticket['serial'] || empty($_POST['confirm_update'])) throw new RuntimeException('Confirme o serial e a atualização.');
            $job = $service->execute($ticket, $actor);
            $_SESSION['firmware_flash'] = 'Solicitação registrada. Situação: ' . $job['state'] . '. Consulte o histórico abaixo.';
            header('Location: /firmware-updates.php#history'); exit;
        } elseif ($action === 'refresh') {
            $service->refresh((string)($_POST['job_id'] ?? ''));
            $_SESSION['firmware_flash'] = 'Consulta atualizada. A conclusão depende da nova versão informada pela ONU.';
            header('Location: /firmware-updates.php#history'); exit;
        } elseif ($action === 'approve') {
            if (($_SESSION['role'] ?? '') !== 'admin' || empty($_POST['confirm_tests'])) throw new RuntimeException('Administrador deve confirmar os testes funcionais.');
            $service->approve((string)($_POST['job_id'] ?? ''), $actor);
            $_SESSION['firmware_flash'] = 'Firmware homologado para atualização individual do mesmo fabricante, modelo e hardware.';
            header('Location: /firmware-updates.php#history'); exit;
        } else { throw new RuntimeException('Operação inválida.'); }
    }
    if (!empty($_GET['device_id'])) $selected = $service->device((string)$_GET['device_id']);
} catch (Throwable $e) { $error = $e->getMessage(); }
if (isset($service)) {
    try { $files = $service->read('catalog'); $jobs = array_reverse($service->read('updates')); }
    catch (Throwable $e) { $error = $e->getMessage(); }
}
$message = $_SESSION['firmware_flash'] ?? ''; unset($_SESSION['firmware_flash']);
$labels = ['preparando'=>'Preparando arquivo', 'envio_incerto'=>'Envio inconclusivo — verificar no ACS', 'falha_publicacao'=>'Falha ao publicar arquivo',
    'aguardando_confirmacao'=>'Aguardando confirmação da versão', 'falha_dispositivo'=>'Falha informada pelo equipamento', 'concluido'=>'Concluído'];
include __DIR__ . '/views/layouts/header.php';
?>
<link rel="stylesheet" href="/assets/css/firmware-shared-theme.css?v=1">
<style>
.jrc-fwu{max-width:1400px;margin:auto;color:#e5edf8}.jrc-fwu .fw-card{background:#0c2136;border:1px solid #1c4b70;border-radius:12px;padding:22px;margin:16px 0}.jrc-fwu label{display:block;margin-top:12px}.jrc-fwu input:not([type=checkbox]),.jrc-fwu select{background:#071827;border:1px solid #31516d;color:#e5edf8;border-radius:7px;padding:10px;width:100%}.jrc-fwu button,.jrc-fwu .fw-link{display:inline-block;padding:10px 16px;margin-top:12px;border:0;border-radius:7px;background:#1c6cc2;color:white}.jrc-fwu button:disabled{opacity:.5}.jrc-fwu .muted{color:#a0b8ce}.jrc-fwu .notice{padding:14px;border-left:3px solid #4fb5ee;background:#12334a}.jrc-fwu .error{border-color:#fb7185}.jrc-fwu .grid{display:grid;grid-template-columns:1fr 2fr;gap:14px}.jrc-fwu table{width:100%;font-size:14px}.jrc-fwu td,.jrc-fwu th{padding:12px;border-bottom:1px solid #254057;vertical-align:top}.jrc-fwu a{color:#86caff}.jrc-fwu code{color:#c5dcf0;overflow-wrap:anywhere}.jrc-fwu [hidden]{display:none!important}@media(max-width:700px){.jrc-fwu .grid{grid-template-columns:1fr}}
</style>
<section class="jrc-fwu">
<h2>Atualização individual de firmware</h2>
<p class="muted">Selecione a ONU e uma imagem compatível. Confira os dados antes de enviar.</p>
<p><a href="/firmware-library.php">Repositório de firmwares</a> · <a href="/firmware-homologation.php">Preparações de bancada</a> · <a href="#history">Histórico</a></p>
<?php if ($error): ?><div role="alert" class="notice error"><?=fwuEsc($error)?></div><?php endif; ?>
<?php if ($message): ?><div role="status" class="notice"><?=fwuEsc($message)?></div><?php endif; ?>
<?php if (isset($service)): ?>
<?php if ($preview): $ticket = $_SESSION['firmware_ticket']; $d = $preview['device']; $f = $preview['firmware']; ?>
<div class="fw-card"><h3>Confira antes de atualizar</h3>
<p><strong><?=fwuEsc($d['manufacturer'].' '.$d['model'])?></strong> · Serial <?=fwuEsc($d['serial'])?> · Hardware <?=fwuEsc($d['hardware'])?></p>
<p>Versão atual: <strong><?=fwuEsc($d['version'])?></strong> → Versão selecionada: <strong><?=fwuEsc($f['version'])?></strong></p>
<p>Arquivo: <?=fwuEsc($f['original_name'])?><br>SHA-256: <code><?=fwuEsc($f['sha256'])?></code></p>
<p class="notice">A ONU pode reiniciar e interromper a conexão. <?= $preview['bench'] ? 'Este envio é um teste em equipamento isolado de bancada.' : 'Esta imagem foi homologada para este fabricante, modelo e hardware.' ?> Após a confirmação, a tarefa aguardará a próxima comunicação da ONU com o ACS.</p>
<form method="post">
<input type="hidden" name="csrf" value="<?=fwuEsc($_SESSION['jrc_fwb_csrf'])?>"><input type="hidden" name="action" value="execute"><input type="hidden" name="ticket" value="<?=fwuEsc($ticket['id'])?>">
<label>Digite o serial da ONU <input name="confirm_serial" autocomplete="off" required></label>
<label><input type="checkbox" name="confirm_update" value="1" required> Confirmei a imagem oficial e o procedimento do fabricante, o acesso da ONU ao servidor de arquivos e a possibilidade de interrupção. <?= $preview['bench'] ? 'Tenho acesso físico à ONU de bancada.' : '' ?></label>
<button type="submit">Confirmar atualização desta ONU</button> <a href="/firmware-updates.php">Cancelar</a>
</form></div>
<?php else: ?>
<div class="fw-card"><h3>1. Localize o equipamento</h3>
<div class="grid"><select id="fw-mode" aria-label="Tipo de busca"><option value="device">MAC ou serial</option><option value="model">Modelo</option></select><input id="fw-query" type="search" maxlength="64" placeholder="Digite ao menos 3 caracteres" aria-label="Buscar equipamento"></div>
<button id="fw-search" type="button">Buscar ONU</button><p id="fw-status" class="muted" role="status" aria-live="polite"></p><div id="fw-results"></div>
</div>
<form method="post" class="fw-card" id="fw-form" hidden>
<h3>2. Selecione o firmware</h3><p id="fw-device"></p>
<input type="hidden" name="csrf" value="<?=fwuEsc($_SESSION['jrc_fwb_csrf'])?>"><input type="hidden" name="action" value="validate"><input type="hidden" name="device_id" id="fw-device-id">
<label>Tipo de atualização <select name="mode" id="fw-operation"><option value="individual">Individual — somente imagens homologadas</option><option value="bench">Teste em ONU isolada de bancada</option></select></label>
<label>Imagem compatível <select name="firmware_id" id="fw-file" required></select></label>
<p class="muted" id="fw-match"></p><button type="submit" id="fw-validate">Validar e revisar atualização</button>
</form>
<?php endif; ?>
<div class="fw-card" id="history"><h3>Histórico de atualizações</h3>
<p class="muted">“Consultar resultado” lê o ACS e não reenvia firmware. Uma tarefa aceita só será concluída após a versão alvo ser informada novamente pela ONU. Horários em UTC.</p>
<div style="overflow-x:auto"><table><thead><tr><th>ONU / Data</th><th>Versões</th><th>Situação</th><th>Ações</th></tr></thead><tbody>
<?php if (!$jobs): ?><tr><td colspan="4">Nenhuma atualização solicitada.</td></tr><?php endif; ?>
<?php foreach (array_slice($jobs, 0, 100) as $job): ?>
<tr><td><?=fwuEsc($job['serial'])?><br><small><?=fwuEsc($job['created_at'])?></small><br><small><?=fwuEsc($job['actor'])?></small></td>
<td><?=fwuEsc($job['previous_version'])?> → <?=fwuEsc($job['target_version'])?><br><small>Observada: <?=fwuEsc($job['observed_version'] ?? 'aguardando')?></small></td>
<td><?=fwuEsc($labels[$job['state']] ?? $job['state'])?><?php if (!empty($job['error'])): ?><p><?=fwuEsc($job['error'])?></p><?php endif; ?><small>Tarefa: <?=fwuEsc($job['task_id'] ?? 'não confirmada')?></small></td>
<td><form method="post"><input type="hidden" name="csrf" value="<?=fwuEsc($_SESSION['jrc_fwb_csrf'])?>"><input type="hidden" name="job_id" value="<?=fwuEsc($job['id'])?>"><button name="action" value="refresh">Consultar resultado</button></form>
<?php if ($job['state'] === 'concluido' && $job['bench'] && ($_SESSION['role'] ?? '') === 'admin'): ?>
<form method="post"><input type="hidden" name="csrf" value="<?=fwuEsc($_SESSION['jrc_fwb_csrf'])?>"><input type="hidden" name="job_id" value="<?=fwuEsc($job['id'])?>"><label><input type="checkbox" name="confirm_tests" value="1" required> Testei internet, Wi-Fi, configuração e retorno ao ACS.</label><button name="action" value="approve">Homologar imagem</button></form>
<?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php if (count($jobs)>100): ?><p>Exibindo as 100 solicitações mais recentes; histórico completo preservado no servidor.</p><?php endif; ?>
</div>
<script type="application/json" id="fw-data"><?=json_encode(['csrf'=>$_SESSION['jrc_fwb_csrf'], 'selected'=>$selected, 'files'=>array_map(static fn($f)=>array_intersect_key($f, array_flip(['id','manufacturer','model','hw_revision','version','status'])), $files)], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR)?></script>
<script src="/assets/js/firmware-updates.js?v=1"></script>
<?php endif; ?>
</section>
<?php include __DIR__ . '/views/layouts/footer.php'; ?>
