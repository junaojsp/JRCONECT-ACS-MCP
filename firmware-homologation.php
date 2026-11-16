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

.jrc-fwb .fw-search-modes{display:flex;gap:18px;flex-wrap:wrap}
.jrc-fwb .fw-search-modes input{width:auto}
.jrc-fwb .fw-search-row{display:flex;gap:8px;align-items:center}
.jrc-fwb .fw-search-row button{margin-top:0;white-space:nowrap}
.jrc-fwb .fw-search-results{margin-top:12px;max-height:380px;overflow-y:auto}
.jrc-fwb .fw-result{background:#071827;border:1px solid #31516d;border-radius:8px;margin:8px 0;padding:12px;display:flex;gap:12px;justify-content:space-between;align-items:center;flex-wrap:wrap}
.jrc-fwb .fw-result button{margin-top:0}

/* JRC_FW_SIMPLE_FLOW_V1 */
.jrc-fwb .fw-simple-search{display:grid;grid-template-columns:220px minmax(0,1fr) auto;gap:9px;align-items:center}
.jrc-fwb .fw-simple-search button{margin-top:0}
.jrc-fwb .fw-simple-item{background:#071827;border:1px solid #31516d;padding:12px;border-radius:8px;display:flex;justify-content:space-between;align-items:center;gap:15px;margin:8px 0}
.jrc-fwb .fw-simple-item button{margin:0;white-space:nowrap}
.jrc-fwb .fw-simple-results{max-height:350px;overflow:auto}
.jrc-fwb [hidden]{display:none!important}
.jrc-fwb .fw-simple-check{font-size:13px;margin:16px 0}
.jrc-fwb .fw-simple-check input{width:auto}
.jrc-fwb button:disabled{opacity:.5;cursor:not-allowed}
.jrc-fwb summary{cursor:pointer}
.jrc-fwb .fw-simple-table{overflow:auto;margin-top:10px}
@media(max-width:850px){.jrc-fwb .fw-simple-search{grid-template-columns:1fr}.jrc-fwb .fw-simple-item{align-items:stretch;flex-direction:column}}
</style>
<section class="jrc-fwb" aria-label="Homologacao simplificada de firmware">
<header><h2>Firmware de ONU — Bancada</h2><p class="muted">Localize a ONU, escolha uma imagem compatível do repositório e prepare o teste. Sem atualização automática.</p><p><a href="/firmware-library.php">← Voltar ao repositório</a></p></header>
<?php if ($msg): ?><div class="notice" role="status"><?=fwbh($msg)?></div><?php endif; ?>
<?php if ($err): ?><div class="notice error" role="alert"><?=fwbh($err)?></div><?php endif; ?>
<div class="card">
<h3>1. Encontre sua ONU</h3>
<div class="fw-simple-search">
<select id="fw-simple-mode" aria-label="Tipo de busca"><option value="device">Por dispositivo (MAC ou serial)</option><option value="model">Por modelo</option></select>
<input id="fw-simple-term" type="search" autocomplete="off" maxlength="64" placeholder="MAC, serial ou ID da ONU" aria-label="Pesquisar ONU">
<button type="button" id="fw-simple-find">Buscar</button>
</div>
<p class="muted" id="fw-simple-status" role="status" aria-live="polite">Nenhum equipamento selecionado.</p>
<div id="fw-simple-results" class="fw-simple-results"></div>
</div>
<div class="card" id="fw-simple-device-card" hidden>
<h3>2. ONU selecionada</h3>
<div id="fw-simple-summary"></div>
<div id="fw-simple-compat-note" class="muted"></div>
</div>
<form action="/firmware-homologation.php" method="post" id="fw-simple-form">
<input type="hidden" name="csrf" value="<?=fwbh($_SESSION['jrc_fwb_csrf'])?>">
<input type="hidden" name="action" value="register_bench">
<input type="hidden" name="device_id" id="device-id">
<input type="hidden" name="serial" id="serial">
<input type="hidden" name="manufacturer" id="manufacturer">
<input type="hidden" name="model" id="model">
<input type="hidden" name="hardware" id="hardware">
<input type="hidden" name="installed_version" id="installed-version">
<div class="card" id="fw-simple-firmware-card" hidden>
<h3>3. Firmware compatível</h3>
<label for="fw-select">Arquivo disponível no repositório</label>
<select id="fw-select" name="firmware_id" required>
<option value="">Selecione uma imagem</option>
<?php foreach(fwbRead(FW_BENCH_DIR.'/catalog.json') as $f): if(($f['status']??'')!=='nao_homologado') continue; ?>
<option value="<?=fwbh($f['id']??'')?>"
data-manufacturer="<?=fwbh($f['manufacturer']??'')?>"
data-model="<?=fwbh($f['model']??'')?>"
data-hardware="<?=fwbh($f['hw_revision']??'')?>"
data-version="<?=fwbh($f['version']??'')?>"><?=fwbh(($f['manufacturer']??'').' / '.($f['model']??'').' / '.($f['version']??''))?></option>
<?php endforeach; ?>
</select>
<p class="muted" id="fw-simple-firmware-info">As imagens cadastradas serão filtradas por fabricante, modelo e revisão de hardware.</p>
<label class="fw-simple-check"><input type="checkbox" name="bench_confirm" value="1" id="fw-simple-confirm"> Esta é minha ONU de bancada e tenho acesso físico a ela.</label>
<button type="submit" id="fw-simple-register" disabled>Preparar teste (sem atualizar)</button>
<p class="muted">Preparar apenas registra o vínculo. Não envia firmware à ONU.</p>
</div>
</form>
<div class="card"><h3>Atualização</h3><div class="notice">Envio bloqueado enquanto não validarmos a integração do GenieACS e o procedimento oficial desta imagem.</div><button type="button" disabled aria-disabled="true" class="fw-simple-disabled">Atualizar ONU (ainda indisponível)</button></div>
<details class="card"><summary>Histórico de preparação</summary><div class="fw-simple-table"><table>
<thead><tr><th>ONU / Serial</th><th>Arquivo (SHA)</th><th>Versão instalada</th><th>Status</th><th>Data UTC</th></tr></thead><tbody>
<?php if (!$records): ?><tr><td colspan="5">Nenhuma ONU vinculada.</td></tr><?php endif; ?>
<?php foreach(array_reverse($records) as $rec): ?>
<tr><td><?=fwbh(($rec['model']??'').' / '.($rec['serial']??''))?></td><td><?=fwbh(substr($rec['firmware_sha256']??'',0,12))?>...</td><td><?=fwbh($rec['installed_version']??'')?></td><td>Aguardando validação</td><td><?=fwbh($rec['created_at']??'')?></td></tr>
<?php endforeach; ?>
</tbody></table></div></details>
</section>
<script>
(function(){
  'use strict';
  const root=document.querySelector('.jrc-fwb');
  if(!root)return;
  const get=id=>root.querySelector('#'+id);
  const mode=get('fw-simple-mode'),term=get('fw-simple-term'),find=get('fw-simple-find');
  const status=get('fw-simple-status'),results=get('fw-simple-results');
  const deviceCard=get('fw-simple-device-card'),summary=get('fw-simple-summary');
  const firmwareCard=get('fw-simple-firmware-card'),firmware=get('fw-select');
  const note=get('fw-simple-firmware-info'),compat=get('fw-simple-compat-note');
  // Keep an immutable local copy of the catalogue options. Native <option hidden>
  // behaves inconsistently across browsers; rebuild only eligible options.
  const catalogueOptions=Array.from(firmware.options).filter(opt=>opt.value).map(opt=>({
    value:opt.value,label:opt.textContent||'',
    manufacturer:opt.dataset.manufacturer||'',
    model:opt.dataset.model||'',
    hardware:opt.dataset.hardware||''
  }));
  const confirm=get('fw-simple-confirm'),register=get('fw-simple-register');
  const csrf=<?=json_encode($_SESSION['jrc_fwb_csrf'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
  let current=null;
  const norm=s=>String(s||'').trim().toLowerCase();
  const cell=(tag,text,cls)=>{
    const el=document.createElement(tag);
    if(cls)el.className=cls;
    el.textContent=text;
    return el;
  };
  const clearSelection=()=>{
    current=null;deviceCard.hidden=true;firmwareCard.hidden=true;
    firmware.value='';confirm.checked=false;register.disabled=true;
    ['device-id','serial','manufacturer','model','hardware','installed-version'].forEach(id=>get(id).value='');
  };
  function syncFirmware(){
    if(!current)return;
    const previous=firmware.value;
    const matches=catalogueOptions.filter(item=>
      norm(item.manufacturer)===norm(current.manufacturer) &&
      norm(item.model)===norm(current.model) &&
      norm(item.hardware)===norm(current.hardware) &&
      !!norm(current.hardware));
    const choices=[new Option('Selecione uma imagem','')];
    matches.forEach(item=>choices.push(new Option(item.label,item.value)));
    firmware.replaceChildren(...choices);
    firmware.value=matches.some(item=>item.value===previous)?previous:(matches.length===1?matches[0].value:'');
    if(matches.length){
      note.textContent=matches.length+' imagem(ns) correspondente(s) ao fabricante, modelo e hardware cadastrados. Essa correspondência ainda não comprova compatibilidade técnica ou homologação.';
    }else if(!catalogueOptions.length){
      note.textContent='O catálogo não retornou nenhuma imagem cadastrada para seleção. Confira o repositório.';
    }else{
      const revisions=catalogueOptions.filter(item=>norm(item.manufacturer)===norm(current.manufacturer)&&norm(item.model)===norm(current.model)).map(item=>item.hardware);
      note.textContent=revisions.length
        ? 'Modelo localizado, mas revisão de hardware diferente do catálogo. ONU: '+(current.hardware||'não informada')+'; catálogo: '+[...new Set(revisions)].join(', ')+'.'
        : 'Nenhuma imagem cadastrada para este fabricante e modelo. Consulte o repositório.';
    }
    firmwareCard.hidden=false;
    register.disabled=!(firmware.value && confirm.checked && current.id && current.serial && current.manufacturer && current.model && current.hardware && current.version);
  }
  function selectDevice(d){
    clearSelection();current=d;
    const fields={'device-id':'id','serial':'serial','manufacturer':'manufacturer','model':'model','hardware':'hardware','installed-version':'version'};
    Object.entries(fields).forEach(([id,key])=>get(id).value=d[key]||'');
    summary.replaceChildren();
    const h=cell('strong',(d.manufacturer||'Fabricante indisponível')+' '+(d.model||'Modelo indisponível'));
    const details=cell('p','Serial: '+(d.serial||'não informado')+' | Hardware: '+(d.hardware||'não informado')+' | Firmware atual: '+(d.version||'não informado'),'muted');
    summary.append(h,details);
    compat.textContent=(!d.hardware||!d.version)
      ? 'O ACS não retornou todos os parâmetros obrigatórios. Não é possível preparar o teste até que estejam disponíveis.'
      : 'Dados preenchidos automaticamente pelo inventário GenieACS; confirme que selecionou sua ONU de bancada.';
    deviceCard.hidden=false;syncFirmware();
    status.textContent='ONU selecionada. Confira o firmware compatível.';
  }
  async function search(){
    clearSelection();results.replaceChildren();
    const q=term.value.trim();
    if(q.length<3){status.textContent='Digite ao menos 3 caracteres.';return;}
    find.disabled=true;status.textContent='Buscando no ACS...';
    try{
      const url='/api/firmware-device-search.php?'+new URLSearchParams({type:mode.value,term:q,csrf});
      const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}});
      const raw=await response.text();let data;
      try{data=JSON.parse(raw);}catch(_){throw Error('Resposta inválida da API do ACS. Verifique os logs PHP.');}
      if(!response.ok)throw Error(data.error||'Falha ao consultar inventário.');
      const items=Array.isArray(data.items)?data.items:[];
      status.textContent=items.length+' ONU(s) encontrada(s).'+(data.truncated?' Refine a busca para ver outros resultados.':'');
      items.forEach(d=>{
        const row=cell('div','','fw-simple-item');
        const info=cell('div',(d.manufacturer||'Fabricante não informado')+' '+(d.model||'Modelo não informado')
          +' | '+(d.serial||'Serial indisponível')+' | HW '+(d.hardware||'?')+' | '+(d.version||'Versão não informada'));
        const choose=cell('button','Selecionar ONU');
        choose.type='button';choose.addEventListener('click',()=>selectDevice(d));
        row.append(info,choose);results.append(row);
      });
    }catch(err){status.textContent=err.message||'Falha ao consultar o ACS.';}
    finally{find.disabled=false;}
  }
  mode.addEventListener('change',()=>{
    clearSelection();results.replaceChildren();term.value='';
    term.placeholder=mode.value==='model'?'Modelo, ex.: HG6143D3':'MAC, serial ou ID da ONU';
    status.textContent='Nenhum equipamento selecionado.';
  });
  find.addEventListener('click',search);
  term.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();search();}});
  firmware.addEventListener('change',syncFirmware);
  confirm.addEventListener('change',syncFirmware);
  get('fw-simple-form').addEventListener('submit',e=>{
    if(!current||register.disabled||!confirm.checked){e.preventDefault();status.textContent='Selecione uma ONU e imagem compatível antes de registrar.';}
  });
})();
</script>

<?php include __DIR__.'/views/layouts/footer.php'; ?>
