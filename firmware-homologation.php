<?php
/* JR Conect ACS - Bench firmware preflight v1.
 * Controlled enrollment only. NO GenieACS NBI/FS, CWMP, firmware Download or firmware execution.
 */
require_once __DIR__ . '/config/config.php';
requireLogin();
$pageTitle = 'Homologacao de Firmware';
$currentPage = 'firmware';

const FW_BENCH_DIR = '/var/lib/jrc-acs-firmware';
function fwbh($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function fwbfield($value, $limit=128) {
    $s = trim((string)$value);
    if ($s === '' || strlen($s) > $limit || preg_match('/[\x00-\x1f\x7f]/', $s)) {
        throw new RuntimeException('Campo obrigatorio invalido.');
    }
    return $s;
}
function fwbRead($path) {
    if (!is_file($path)) return [];
    if (is_link($path)) throw new RuntimeException('Arquivo de dados invalido.');
    $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('Dados invalidos.');
    return $data;
}
function fwbWrite($path, $data) {
    $tmp = dirname($path).'/.bench-'.bin2hex(random_bytes(8)).'.tmp';
    try {
        if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Falha ao gravar homologacao.');
        }
        chmod($tmp, 0640);
        if (!rename($tmp, $path)) throw new RuntimeException('Falha ao publicar homologacao.');
    } finally {
        if (file_exists($tmp)) unlink($tmp);
    }
}
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['jrc_fwb_csrf'])) $_SESSION['jrc_fwb_csrf'] = bin2hex(random_bytes(32));
$msg=''; $err=''; $records=[]; $files=[];
try {
    if (!is_dir(FW_BENCH_DIR) || is_link(FW_BENCH_DIR) || !is_writable(FW_BENCH_DIR)) {
        throw new RuntimeException('Repositorio indisponivel ou sem permissao de escrita.');
    }
    $files = fwbRead(FW_BENCH_DIR.'/catalog.json');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!hash_equals($_SESSION['jrc_fwb_csrf'], (string)($_POST['csrf']??''))) {
            throw new RuntimeException('Sessao expirada. Atualize a pagina.');
        }
        if (($_POST['action']??'') !== 'register_bench') throw new RuntimeException('Operacao invalida.');
        $fwid=fwbfield($_POST['firmware_id']??'',64);
        $firmware=null;
        foreach ($files as $f) {
            if (($f['id']??'') === $fwid) { $firmware=$f; break; }
        }
        if (!$firmware || ($firmware['status']??'') !== 'nao_homologado') {
            throw new RuntimeException('Firmware nao cadastrado ou em status inesperado.');
        }
        $deviceId=fwbfield($_POST['device_id']??'',180);
        $serial=fwbfield($_POST['serial']??'',100);
        $manufacturer=fwbfield($_POST['manufacturer']??'',80);
        $model=fwbfield($_POST['model']??'',80);
        $hardware=fwbfield($_POST['hardware']??'',80);
        $current=fwbfield($_POST['installed_version']??'',100);
        if (!hash_equals(strtolower(trim((string)$firmware['manufacturer'])), strtolower($manufacturer)) ||
            !hash_equals(strtolower(trim((string)$firmware['model'])), strtolower($model)) ||
            !hash_equals(strtolower(trim((string)$firmware['hw_revision'])), strtolower($hardware))) {
            throw new RuntimeException('Incompatibilidade entre os dados informados da ONU e o cadastro do firmware.');
        }
        if (empty($_POST['bench_confirm'])) throw new RuntimeException('Confirme que se trata de uma ONU isolada de bancada.');
        $record=[
            'id'=>bin2hex(random_bytes(16)), 'firmware_id'=>$fwid,
            'firmware_sha256'=>(string)($firmware['sha256']??''),
            'device_id'=>$deviceId,'serial'=>$serial,
            'manufacturer'=>$manufacturer,'model'=>$model,'hardware'=>$hardware,
            'installed_version'=>$current,'target_version'=>(string)$firmware['version'],
            'created_at'=>gmdate('c'),
            'state'=>'aguardando_verificacao_acs',
            'notes'=>'Dados de ONU declarados pelo operador. Nenhuma leitura NBI ou tarefa CWMP foi realizada.'
        ];
        $lock=fopen(FW_BENCH_DIR.'/bench.lock','c');
        if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Falha de bloqueio.');
        try {
            $records=fwbRead(FW_BENCH_DIR.'/bench-register.json');
            $records[]=$record;
            fwbWrite(FW_BENCH_DIR.'/bench-register.json',$records);
        } finally {
            flock($lock,LOCK_UN); fclose($lock);
        }
        $msg='ONU de bancada registrada para verificacao. Nenhuma atualizacao enviada.';
    }
    $records=fwbRead(FW_BENCH_DIR.'/bench-register.json');
} catch (Throwable $e) { $err=$e->getMessage(); }
include __DIR__.'/views/layouts/header.php';
?>
<style>
.jrc-fwb{color:#e5edf8;max-width:1520px;margin:auto}
.jrc-fwb .card{background:#0c2136;border:1px solid #1c4b70;border-radius:12px;padding:18px;margin:14px 0}
.jrc-fwb .muted{color:#9db5c9;font-size:13px}
.jrc-fwb .grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.jrc-fwb input,.jrc-fwb select{width:100%;padding:9px;background:#071827;color:#eef8ff;border:1px solid #31516d;border-radius:6px}
.jrc-fwb label{display:block;margin:10px 0 5px;color:#b5d0e6;font-size:12px}
.jrc-fwb button{background:#1c6cc2;color:white;padding:10px 16px;border:0;border-radius:7px;margin-top:12px;cursor:pointer}
.jrc-fwb .notice{background:#12334a;border-left:3px solid #47b8ed;padding:12px;margin:14px 0}
.jrc-fwb .error{border-left-color:#fb7185}
.jrc-fwb table{width:100%;border-collapse:collapse;font-size:13px}
.jrc-fwb th,.jrc-fwb td{padding:10px;text-align:left;border-bottom:1px solid #254057}
.jrc-fwb a{color:#86caff}
@media(max-width:860px){.jrc-fwb .grid{grid-template-columns:1fr}}
</style>
<section class="jrc-fwb">
<h2>Homologacao de Firmware em Bancada</h2>
<p class="muted">Etapa de cadastro e conferencia. A verificacao ao vivo do dispositivo e o envio do firmware estao bloqueados ate integrarmos as rotinas existentes do ACS.</p>
<p><a href="/firmware-library.php">Voltar ao repositorio</a></p>
<?php if($msg):?><div class="notice" role="status"><?=fwbh($msg)?></div><?php endif;?>
<?php if($err):?><div class="notice error" role="alert"><?=fwbh($err)?></div><?php endif;?>
<div class="card"><h3>Vincular ONU de bancada a imagem cadastrada</h3>
<form action="/firmware-homologation.php" method="post">
<input type="hidden" name="csrf" value="<?=fwbh($_SESSION['jrc_fwb_csrf'])?>">
<input type="hidden" name="action" value="register_bench">
<label for="fw-select">Firmware cadastrado</label>
<select id="fw-select" name="firmware_id" required>
<option value="">Selecione um arquivo do repositorio</option>
<?php foreach($files as $f): if(($f['status']??'')!=='nao_homologado') continue;?>
<option value="<?=fwbh($f['id']??'')?>"><?=fwbh(($f['manufacturer']??'').' / '.($f['model']??'').' / HW '.($f['hw_revision']??'').' / '.($f['version']??'').' / '.substr($f['sha256']??'',0,12))?></option>
<?php endforeach;?>
</select>
<div class="grid">
<div><label for="device-id">ID exato no GenieACS</label><input id="device-id" name="device_id" maxlength="180" required placeholder="ID do dispositivo no ACS"></div>
<div><label for="serial">Serial</label><input id="serial" name="serial" maxlength="100" required></div>
<div><label for="manufacturer">Fabricante informado</label><input id="manufacturer" name="manufacturer" maxlength="80" required></div>
<div><label for="model">Modelo informado</label><input id="model" name="model" maxlength="80" required></div>
<div><label for="hardware">Revisao de hardware informada</label><input id="hardware" name="hardware" maxlength="80" required></div>
<div><label for="installed-version">Versao instalada informada</label><input id="installed-version" name="installed_version" maxlength="100" required></div>
</div>
<label><input type="checkbox" name="bench_confirm" value="1" style="width:auto"> Confirmo que esta ONU e equipamento de bancada e tenho acesso fisico a ela.</label>
<button type="submit">Registrar bancada (sem atualizar)</button>
<p class="muted">A conferencia compara apenas os dados digitados com o cadastro do arquivo. Isso nao comprova a compatibilidade da imagem. Nenhuma tarefa e enviada ao GenieACS.</p>
</form></div>
<div class="card"><h3>Registros de pre-homologacao</h3><div style="overflow-x:auto"><table>
<thead><tr><th>ONU / Serial</th><th>Firmware</th><th>Versao instalada</th><th>Situacao</th><th>Data UTC</th></tr></thead><tbody>
<?php if(!$records):?><tr><td colspan="5">Nenhuma ONU vinculada.</td></tr><?php endif;?>
<?php foreach(array_reverse($records) as $r):?>
<tr><td><?=fwbh(($r['model']??'').' / '.($r['serial']??''))?></td>
<td><?=fwbh(substr($r['firmware_sha256']??'',0,12))?>...</td>
<td><?=fwbh($r['installed_version']??'')?></td><td>Aguardando verificacao real no ACS</td><td><?=fwbh($r['created_at']??'')?></td></tr>
<?php endforeach;?></tbody></table></div></div>
<div class="card"><h3>Execucao de firmware</h3><div class="notice">Desabilitada nesta fase. Somente apos validar API, identificacao real da ONU, compatibilidade de hardware, permissao e entrega GenieACS FS/NBI.</div><button type="button" disabled style="opacity:.5;cursor:not-allowed">Atualizar ONU (indisponivel)</button></div>
</section>
<?php include __DIR__.'/views/layouts/footer.php'; ?>
