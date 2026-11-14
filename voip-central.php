<?php
/**
 * Central VoIP - primeira etapa visual.
 * Estoque isolado; importacao manual autenticada. Sem escritas IXC.
 */
require_once __DIR__ . '/config/config.php';
requireLogin();

require_once __DIR__ . '/lib/voip-stock.php';
require_once __DIR__ . '/lib/voip-reconcile.php';
require_once __DIR__ . '/lib/voip-historical-review.php';
require_once __DIR__ . '/lib/voip-operational.php';
require_once __DIR__ . '/lib/voip-link-prefill.php';
require_once __DIR__ . '/lib/voip-ixc-monitor.php';
header('Cache-Control: no-store, private');
if (empty($_SESSION['voip_csrf'])) $_SESSION['voip_csrf'] = bin2hex(random_bytes(32));
$voipError = '';
$voipData = null;
$voipStore = new VoipStock();
$voipSnapshotInput = null;
$voipComparison = null;
$voipOperational = null;
$voipHistorical = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $received = $_POST['csrf'] ?? null;
        if (!is_string($received) || !hash_equals($_SESSION['voip_csrf'], $received)) {
            throw new RuntimeException('Sessão expirada. Atualize a página.');
        }
        if (($_POST['voip_action'] ?? 'import') === 'compare_ixc') {
            $input = $_POST['ixc_snapshot'] ?? null;
            if (!is_string($input)) throw new RuntimeException('Snapshot IXC inválido.');
            $voipSnapshotInput = $input;
        } elseif (($_POST['voip_action'] ?? '') === 'link_manual') {
            if (($_POST['checked_ixc_voicecopy'] ?? null) !== 'YES') {
                throw new RuntimeException('Confirme a verificação do contrato no IXC e do número na VoiceCopy.');
            }
            $number = $_POST['number'] ?? null;
            $contract = $_POST['contract_id'] ?? null;
            $product = $_POST['product_record_id'] ?? null;
            if (!is_string($number) || !is_string($contract) || !is_string($product)) {
                throw new RuntimeException('Informe os três identificadores do vínculo.');
            }
            $voipStore->linkManually($number, $contract, $product, (string)$_SESSION['user_id']);
            $_SESSION['voip_flash'] = 'Vínculo registrado manualmente com auditoria. IXC não foi alterado.';
            header('Location: /voip-central.php#voip-vinculos', true, 303);
            exit;
        } elseif (($_POST['voip_action'] ?? '') === 'confirm_legacy_free') {
            if (($_POST['checked_voicecopy_legacy'] ?? null) !== 'YES') {
                throw new RuntimeException('Confirme na VoiceCopy que o DID histórico está efetivamente livre.');
            }
            $number = $_POST['number'] ?? null;
            if (!is_string($number)) throw new RuntimeException('Número histórico inválido.');
            $voipStore->confirmLegacyFree($number, (string)$_SESSION['user_id']);
            $_SESSION['voip_flash'] = 'DID histórico liberado após confirmação da VoiceCopy. Auditoria registrada.';
            header('Location: /voip-central.php#voip-vinculos', true, 303);
            exit;
        } elseif (($_POST['voip_action'] ?? '') === 'confirm_new_batch_available') {
            if (($_POST['checked_new_batch_voicecopy'] ?? null) !== 'YES') {
                throw new RuntimeException('Confirme que todos os DIDs selecionados são novos e foram entregues livres pela VoiceCopy.');
            }
            $numbers = $_POST['new_batch_numbers'] ?? null;
            $revision = $_POST['stock_revision'] ?? null;
            if (!is_array($numbers) || !is_string($revision) ||
                !preg_match('/^(0|[1-9][0-9]{0,12})$/D', $revision)) {
                throw new RuntimeException('Seleção de números ou revisão do estoque inválida.');
            }
            $voipStore->confirmNewBatchAvailable($numbers, (int)$revision, (string)$_SESSION['user_id']);
            $_SESSION['voip_flash'] = count($numbers) . ' DIDs novos confirmados como disponíveis. Nenhum contrato IXC foi alterado.';
            header('Location: /voip-central.php#voip-vinculos', true, 303);
            exit;
        } elseif (($_POST['voip_action'] ?? '') === 'confirm_available') {
            if (($_POST['checked_voicecopy'] ?? null) !== 'YES') {
                throw new RuntimeException('Confirme a disponibilidade verificada na VoiceCopy.');
            }
            $number = $_POST['number'] ?? null;
            if (!is_string($number)) throw new RuntimeException('Número inválido.');
            $voipStore->confirmAvailable($number, (string)$_SESSION['user_id']);
            $_SESSION['voip_flash'] = 'Disponibilidade confirmada manualmente. IXC não foi alterado.';
            header('Location: /voip-central.php#voip-vinculos', true, 303);
            exit;
        } elseif (($_POST['voip_action'] ?? '') === 'set_minimum') {
            $minimum = $_POST['minimum_available'] ?? null;
            if (!is_string($minimum) || !preg_match('/^(?:[1-9]|[1-9][0-9]|100)$/D', $minimum)) {
                throw new RuntimeException('Informe estoque mínimo de 1 a 100.');
            }
            $voipStore->setMinimum((int)$minimum, (string)$_SESSION['user_id']);
            $_SESSION['voip_flash'] = 'Limite mínimo atualizado.';
            header('Location: /voip-central.php#voip-vinculos', true, 303);
            exit;
        } elseif (($_POST['voip_action'] ?? 'import') === 'import') {
            $input = $_POST['inventory'] ?? null;
            if (!is_string($input)) throw new RuntimeException('Importação inválida.');
            $voipStore->import($input, (string)$_SESSION['user_id']);
            $_SESSION['voip_flash'] = 'Estoque importado. Os vínculos com o IXC continuam pendentes.';
            header('Location: /voip-central.php', true, 303);
            exit;
        } else {
            throw new RuntimeException('Operação VoIP desconhecida.');
        }
    } catch (Throwable $e) { $voipError = $e instanceof JsonException ? 'Arquivo de estoque inválido; importação interrompida.' : $e->getMessage(); }
}
try { $voipData = $voipStore->read(); } catch (Throwable $e) { $voipError = 'Estoque indisponível. Verifique a instalação antes de importar.'; }
$voipRows = array_values($voipData['numbers'] ?? []);
$voipStockByNumber = $voipData['numbers'] ?? [];
if ($voipSnapshotInput !== null && $voipData !== null) {
    try {
        $voipComparison = VoipReconcile::compare(
            $voipRows,
            VoipReconcile::parseSnapshot($voipSnapshotInput)
        );
        $voipOperational = VoipOperational::summarize($voipRows, $voipComparison);
        $voipHistorical = VoipHistoricalReview::analyze($voipRows, VoipReconcile::parseSnapshot($voipSnapshotInput));
    } catch (Throwable $e) {
        $voipError = $e->getMessage();
    }
}
unset($voipSnapshotInput);

$voipOccupied = count(array_filter($voipRows, static fn($r) => $r['status'] === 'ocupado'));
$voipReview = count(array_filter($voipRows, static fn($r) => $r['status'] === 'a_conferir'));
// Reservado para uma etapa futura: somente liberações confirmadas podem ser disponíveis.
$voipAvailable = count(array_filter($voipRows, static fn($r) => $r['status'] === 'disponivel'));
$voipOther = count($voipRows) - $voipOccupied - $voipReview - $voipAvailable;
$voipLinked = count(array_filter($voipRows, static fn($r) => !empty($r['contract_id']) && !empty($r['product_record_id'])));
$voipMin = (int)($voipData['minimum_available'] ?? 5);
$voipLowStock = $voipData !== null && $voipAvailable <= $voipMin;
$voipMonitor = null;
$voipMonitorError = null;
try {
    $voipMonitor = VoipIxcMonitor::overview(
        VoipIxcMonitor::read(),
        $voipRows
    );
} catch (Throwable $e) {
    $voipMonitorError = 'Monitor IXC indisponível; verifique a última execução no ACS.';
}

$voipFlash = $_SESSION['voip_flash'] ?? '';
unset($_SESSION['voip_flash']);
function voipEscape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$pageTitle = 'Central VoIP';
$currentPage = 'voip-central';
include __DIR__ . '/views/layouts/header.php';
?>
<style>
/* Estilos restritos a esta pagina; o layout global do ACS permanece intacto. */
.voip-page{display:block;width:100%;max-width:none;margin:0;padding:8px 0 24px;background:transparent;color:#f1f3f5;font-size:16px;line-height:1.6}
.voip-page *{box-sizing:border-box}
.voip-page .voip-heading{display:flex;align-items:center;justify-content:space-between;gap:24px;margin:0 0 28px;flex-wrap:wrap}
.voip-page .voip-heading h2{color:#f5f6f7;font-size:28px;font-weight:700;line-height:1.25;margin:0 0 8px}
.voip-page .voip-muted{color:#a6adb8;font-size:16px;margin:0}
.voip-page .voip-tag{display:inline-flex;align-items:center;gap:8px;border:1px solid #4a3923;background:#251f17;color:#e9c080;border-radius:8px;padding:8px 14px;font-size:14px;font-weight:600;white-space:nowrap}
.voip-page .voip-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:20px;margin:0 0 28px}
.voip-page .voip-card{min-width:0;background:#14171c;border:1px solid #2a2f37;border-radius:12px;padding:26px;box-shadow:0 4px 16px #00000012}
.voip-page .voip-grid .voip-card{display:flex;flex-direction:column;border-top:3px solid #d72a2a}
.voip-page .voip-card>i{display:grid;place-items:center;width:44px;height:44px;border-radius:10px;background:#2c1a1e;color:#ff9393;font-size:22px}
.voip-page .voip-card h3{color:#f1f3f5;font-size:18px;font-weight:650;line-height:1.4;margin:18px 0 8px}
.voip-page .voip-card p{font-size:15px;color:#a6adb8;line-height:1.65;margin:0}
.voip-page .voip-number{font-size:36px;font-weight:650;line-height:1;margin:24px 0 12px;color:#d8dde5}
.voip-page .voip-grid .voip-card p:last-child{margin-top:auto;padding-top:16px;border-top:1px solid #2a2f37;font-size:14px;color:#969faa}
.voip-page .voip-card .voip-section-title{font-size:20px;margin:0 0 24px}
.voip-page .voip-workflow{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:28px}
.voip-page .voip-step{display:flex;align-items:flex-start;gap:14px;min-width:0}
.voip-page .voip-step strong{display:block;color:#e9edf1;font-size:16px;margin-bottom:6px}
.voip-page .voip-step span{font-size:15px;color:#a6adb8;line-height:1.65}
.voip-page .voip-step-icon{display:grid;place-items:center;flex:none;background:#22262e;border:1px solid #343b46;border-radius:10px;width:44px;height:44px}
.voip-page .voip-step-icon i{color:#c3cbd6;font-size:20px}
.voip-page .voip-note{display:flex;align-items:center;gap:10px;border:1px solid #2a2f37;background:#111419;border-radius:10px;padding:16px 20px;color:#a6adb8;font-size:14px;line-height:1.65;margin-top:24px}
.voip-page .voip-note i{color:#b4bdca;flex:none}
@media(max-width:1200px){.voip-page .voip-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.voip-page .voip-workflow{grid-template-columns:1fr;gap:22px}}
@media(max-width:600px){.voip-page .voip-grid{grid-template-columns:1fr;gap:16px}.voip-page .voip-card{padding:22px}.voip-page .voip-heading h2{font-size:24px}.voip-page .voip-heading{gap:16px}.voip-page .voip-note{align-items:flex-start}}

.voip-page .voip-table-wrap{overflow-x:auto;margin-top:20px}
.voip-page .voip-table{width:100%;border-collapse:collapse}
.voip-page .voip-table th{background:#1b1f26;color:#bbc4d0;text-align:left;font-size:14px;padding:14px;text-transform:none;letter-spacing:normal}
.voip-page .voip-table td{color:#d8dde5;border-bottom:1px solid #2a2f37;padding:14px;font-size:15px}
.voip-page .voip-phone{white-space:nowrap;font-variant-numeric:tabular-nums}
.voip-page .voip-status{display:inline-block;border:1px solid #4a3923;border-radius:6px;padding:3px 9px;color:#e9c080;white-space:nowrap}
.voip-page .voip-import{margin-top:20px}
.voip-page summary{font-size:18px;font-weight:600;cursor:pointer;color:#f1f3f5}
.voip-page .voip-import p{margin:16px 0}
.voip-page textarea{display:block;width:100%;margin:10px 0 16px;padding:14px;border:1px solid #414955;border-radius:8px;background:#0b0d10;color:#eef2f6;font:15px/1.6 monospace;resize:vertical}
.voip-page button{background:#ca2222;color:white;border:0;border-radius:8px;padding:12px 18px;font-size:16px;cursor:pointer}
.voip-page button:disabled{opacity:.5;cursor:not-allowed}
.voip-page :is(button,textarea,summary):focus-visible{outline:2px solid #efadad;outline-offset:3px}
.voip-page .voip-note{margin:0 0 20px}

/* Navegação local, cards e blocos do layout operacional aprovado. */
.voip-page{max-width:1540px}
.voip-page .voip-heading{margin-bottom:19px}
.voip-page .voip-heading h2{font-size:25px}
.voip-page .voip-muted{font-size:13px}
.voip-page .voip-tag{font-size:12px}
.voip-page .voip-tag i{font-size:7px}
.voip-page .voip-nav{display:flex;gap:7px;flex-wrap:wrap;margin:0 0 22px;padding:8px;background:#111419;border:1px solid #2a2f37;border-radius:10px}
.voip-page .voip-nav a{display:inline-flex;align-items:center;gap:7px;padding:9px 12px;border-radius:7px;color:#b4bfcd;text-decoration:none;font-size:12px;font-weight:600}
.voip-page .voip-nav a:hover,.voip-page .voip-nav a:focus-visible{background:#242a33;color:#fff}
.voip-page .voip-grid{gap:14px;margin-bottom:18px}
.voip-page .voip-grid .voip-kpi{padding:20px;border-top:2px solid #cb2727}
.voip-page .voip-kpi-heading{display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:13px;font-weight:700;color:#f1f3f5}
.voip-page .voip-kpi-heading i{color:#e86767;font-size:18px}
.voip-page .voip-kpi .voip-number{font-size:30px;margin:21px 0 9px}
.voip-page .voip-kpi p{font-size:12px;line-height:1.5;min-height:36px}
.voip-page .voip-kpi-foot{display:block;margin-top:16px;padding-top:12px;border-top:1px solid #2a2f37;font-size:11px;color:#8998a9}
.voip-page .voip-section{margin-bottom:16px;padding:21px}
.voip-page .voip-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px}
.voip-page .voip-section-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.voip-page .voip-refresh{display:inline-flex;align-items:center;gap:6px;border:1px solid #374454;background:#18202a;color:#c7d4e2;border-radius:7px;padding:5px 9px;font-size:11px;font-weight:600;text-decoration:none}.voip-page .voip-refresh:hover,.voip-page .voip-refresh:focus-visible{background:#253342;color:#fff}
.voip-page .voip-section-head .voip-section-title{margin:0 0 6px;font-size:17px}
.voip-page .voip-section-head p{font-size:12px;margin:0;color:#a6adb8}
.voip-page .voip-state{display:inline-flex;border:1px solid #594328;background:#251f17;color:#e9c080;border-radius:7px;padding:5px 9px;font-size:11px;white-space:nowrap}
.voip-page .voip-empty-state{display:flex;align-items:center;gap:14px;padding:17px;border:1px dashed #39414c;border-radius:9px;background:#111419}
.voip-page .voip-empty-state>i{font-size:23px;color:#8999aa}
.voip-page .voip-empty-state strong{display:block;color:#dce5ed;font-size:13px;margin-bottom:4px}
.voip-page .voip-empty-state span{display:block;color:#91a1b2;font-size:12px}
.voip-page .voip-subtitle{font-size:13px;font-weight:700;color:#dce5ed;margin:12px 0}
.voip-page .voip-section .voip-table-wrap{margin-top:10px}
.voip-page .voip-table th{font-size:12px;padding:12px}
.voip-page .voip-table td{font-size:12px;padding:12px}
.voip-page .voip-steps .voip-section-title{font-size:17px;margin-bottom:20px}
.voip-page .voip-step strong{font-size:13px}
.voip-page .voip-step span{font-size:12px}
.voip-page :is(.voip-nav a,.voip-card){scroll-margin-top:24px}
@media(max-width:600px){.voip-page .voip-nav a{flex:1 1 45%}.voip-page .voip-grid .voip-kpi{padding:17px}.voip-page .voip-section{padding:17px}}

/* Central VoIP v1.1: alinhamento responsivo sem alterar o layout global. */
.main-content .content-wrapper:has(> .voip-page){width:100%;max-width:none;min-width:0;padding:clamp(12px,1.5vw,24px)}
.main-content .content-wrapper > .voip-page{width:100%;max-width:none;min-width:0;margin:0}
.voip-page .voip-grid{grid-template-columns:repeat(4,minmax(0,1fr))}
.voip-page .voip-card,.voip-page .voip-section,.voip-page .voip-import{min-width:0;max-width:100%}
.voip-page .voip-table-wrap{width:100%;max-width:100%;overflow-x:auto;border:1px solid #30343a;border-radius:9px}
.voip-page table.voip-table{width:100%;min-width:650px;border-collapse:collapse;background:#14171c !important;color:#e8edf2}
.voip-page table.voip-table thead,
.voip-page table.voip-table tbody,
.voip-page table.voip-table tr,
.voip-page table.voip-table th,
.voip-page table.voip-table td{background:#14171c !important;background-color:#14171c !important;box-shadow:none !important}
.voip-page table.voip-table th{color:#aeb8c4 !important;font-size:12px;border-bottom:1px solid #383d46 !important}
.voip-page table.voip-table td{color:#e0e6ee !important;border-bottom:1px solid #262c33 !important;overflow-wrap:anywhere}
.voip-page table.voip-table tbody tr:hover td{background:#1b2027 !important;background-color:#1b2027 !important}
.voip-page textarea{max-width:100%;min-height:170px}
.voip-page .voip-empty-state{min-width:0}
.voip-page .voip-empty-state div{min-width:0}
@media(max-width:1500px){.voip-page .voip-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:900px){.voip-page .voip-heading{align-items:flex-start}.voip-page .voip-workflow{grid-template-columns:1fr}.voip-page .voip-grid{gap:12px}.voip-page .voip-section-head{display:flex;flex-direction:column;align-items:flex-start}}
@media(max-width:640px){.main-content .content-wrapper:has(> .voip-page){padding:10px}.voip-page .voip-grid{grid-template-columns:minmax(0,1fr)}.voip-page .voip-nav{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.voip-page .voip-nav a{min-width:0}.voip-page .voip-heading h2{font-size:22px}.voip-page .voip-card{padding:15px}.voip-page table.voip-table{min-width:600px}.voip-page .voip-empty-state{align-items:flex-start}.voip-page .voip-tag{max-width:100%;white-space:normal}}

/* Sinalização de ocupação: independente dos estilos de tabela do ACS. */
.voip-page .voip-stock-summary{display:flex;flex-wrap:wrap;gap:9px;margin:10px 0 18px}
.voip-page .voip-stock-chip{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:8px;border:1px solid #3a414a;color:#cbd5df;background:#1a1f25;font-size:12px}
.voip-page .voip-stock-chip strong{font-variant-numeric:tabular-nums;font-size:14px}
.voip-page .voip-stock-chip i{font-size:8px}
.voip-page .voip-stock-chip--occupied{color:#ff9c9c;border-color:#673036;background:#281a1d}
.voip-page .voip-stock-chip--available{color:#80dbae;border-color:#285840;background:#13271f}
.voip-page .voip-stock-chip--review{color:#f2ca7b;border-color:#68502d;background:#282219}
.voip-page .voip-status{display:inline-flex;align-items:center;gap:8px;padding:5px 9px;border-radius:6px;font-size:11px;font-weight:700}
.voip-page .voip-status i{font-size:7px}
.voip-page .voip-status--occupied{color:#ffa0a0;border:1px solid #81363d;background:#331c21}
.voip-page .voip-status--available{color:#86e0b1;border:1px solid #246845;background:#173726}
.voip-page .voip-status--review{color:#f4cb83;border:1px solid #64502d;background:#2b2419}
@media(max-width:640px){.voip-page .voip-stock-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.voip-page .voip-stock-chip{justify-content:space-between}}
.voip-page #voip-vinculos form{display:grid;grid-template-columns:minmax(0,1fr);gap:9px;margin:18px 0 24px;max-width:720px}
.voip-page #voip-vinculos input:not([type=checkbox]),.voip-page #voip-vinculos select{background:#0b0d10;color:#eef2f6;border:1px solid #414955;border-radius:8px;padding:10px;max-width:100%}
.voip-page #voip-vinculos label{color:#d8dde5;font-size:14px}
.voip-page #voip-vinculos label:has(input[type=checkbox]){display:flex;gap:10px;align-items:flex-start}
.voip-page #voip-vinculos button{justify-self:start}
.voip-page .voip-use-suggestion{font-size:12px;padding:8px 10px;white-space:nowrap;background:#27533c;border:1px solid #39845d}
.voip-page .voip-use-suggestion:hover{background:#316b4d}
.voip-page #voip-prefill-notice[hidden]{display:none}
</style>
<section class="voip-page" aria-labelledby="voip-title">
    <div class="voip-heading">
        <div>
            <h2 id="voip-title"><i class="bi bi-telephone me-2" aria-hidden="true"></i>Central VoIP</h2>
            <p class="voip-muted mb-0">Estoque VoiceCopy, solicitações de telefonia e vínculos com contratos IXC.</p>
        </div>
        <span class="voip-tag"><i class="bi bi-circle-fill" aria-hidden="true"></i> Central em implantação</span>
    </div>

    <?php if ($voipError !== ''): ?><div class="voip-note" role="alert"><?= voipEscape($voipError) ?></div><?php endif; ?>
    <?php if ($voipFlash !== ''): ?><div class="voip-note" role="status"><?= voipEscape($voipFlash) ?></div><?php endif; ?>
    <nav class="voip-nav" aria-label="Áreas da Central VoIP">
        <a href="#voip-solicitacoes"><i class="bi bi-bell" aria-hidden="true"></i> Solicitações</a>
        <a href="#voip-estoque"><i class="bi bi-sim" aria-hidden="true"></i> Estoque</a>
        <a href="#voip-conciliacao"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Conciliação IXC</a>
        <a href="#voip-vinculos"><i class="bi bi-link-45deg" aria-hidden="true"></i> Vínculos</a>
        <a href="#voip-baixas"><i class="bi bi-shield-exclamation" aria-hidden="true"></i> Cancelamentos</a>
        <a href="#voip-historico"><i class="bi bi-clock-history" aria-hidden="true"></i> Histórico</a>
    </nav>

    <div class="voip-grid" aria-label="Visão geral da Central VoIP">
        <section class="voip-card voip-kpi">
            <div class="voip-kpi-heading"><span>Pendências atuais</span><i class="bi bi-bell" aria-hidden="true"></i></div>
            <div class="voip-number"><?= $voipMonitor !== null && !$voipMonitor['stale'] ? count($voipMonitor['unlinked']) : '—' ?></div>
            <p>Contratos com produto 74 sem vínculo confirmado no ACS</p>
            <small class="voip-kpi-foot"><?= $voipMonitor !== null && !$voipMonitor['stale'] ? 'Consulta automática: status IXC bruto, revisar antes de atribuir' : 'Aguardando monitor IXC válido e recente' ?></small>
        </section>
        <section class="voip-card voip-kpi">
            <div class="voip-kpi-heading"><span>Estoque VoiceCopy</span><i class="bi bi-sim" aria-hidden="true"></i></div>
            <div class="voip-number"><?= $voipData === null ? '—' : count($voipRows) ?></div>
            <p>Números cadastrados no estoque</p>
            <small class="voip-kpi-foot"><?= $voipData === null ? 'Armazenamento indisponível' : $voipOccupied . ' ocupados · ' . $voipReview . ' a conferir · ' . $voipAvailable . ' disponíveis' ?></small>
        </section>
        <section class="voip-card voip-kpi">
            <div class="voip-kpi-heading"><span>Números vinculados</span><i class="bi bi-link-45deg" aria-hidden="true"></i></div>
            <div class="voip-number"><?= $voipData === null ? '—' : $voipLinked ?></div>
            <p>Números associados manualmente a contratos do IXC</p>
            <small class="voip-kpi-foot">Vínculos registrados com auditoria no ACS</small>
        </section>
        <section class="voip-card voip-kpi">
            <div class="voip-kpi-heading"><span>Cancelamentos e baixas</span><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></div>
            <div class="voip-number" aria-label="Ainda não habilitado">—</div>
            <p>Linhas aguardando revisão e liberação</p>
            <small class="voip-kpi-foot">Nenhuma baixa automática</small>
        </section>
    </div>

    <section class="voip-card voip-section" id="voip-solicitacoes">
        <div class="voip-section-head"><div>
            <h3 class="voip-section-title"><i class="bi bi-bell me-2" aria-hidden="true"></i>Monitoramento IXC — produto 74</h3>
            <p>Consulta periódica somente leitura no MCP. Mostra contratações novas e alterações, sem liberar ou atribuir DID automaticamente.</p>
        </div><div class="voip-section-actions"><span class="voip-state"><?= $voipMonitor !== null && !$voipMonitor['stale'] ? 'Dados recentes' : 'Monitor pendente' ?></span><a class="voip-refresh" href="/voip-central.php#voip-solicitacoes" title="Recarregar dados do painel"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Atualizar painel</a></div></div>
        <?php if ($voipMonitorError !== null): ?>
            <div class="voip-note" role="alert"><?= voipEscape($voipMonitorError) ?></div>
        <?php elseif ($voipMonitor === null || $voipMonitor['status'] === 'not_configured'): ?>
            <div class="voip-empty-state"><i class="bi bi-shield-lock" aria-hidden="true"></i>
                <div><strong>Monitor automático ainda não ativado</strong>
                    <span>A instalação do código não ativa um agendamento. A primeira consulta define a referência inicial de contratos.</span>
                </div></div>
        <?php else: ?>
            <p>Última consulta: <strong><?= voipEscape($voipMonitor['last_checked']) ?></strong>
                · Contratos com produto 74: <strong><?= (int)$voipMonitor['total_contracts'] ?></strong>
                · Sem vínculo confirmado: <strong><?= count($voipMonitor['unlinked']) ?></strong>.
                O status contratual exige validação; não classificar automaticamente como solicitação.</p>
            <?php if ($voipMonitor['stale']): ?>
                <div class="voip-note" role="alert"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                    Monitor desatualizado. Dados podem não refletir o IXC. Verifique o serviço agendado no ACS.</div>
            <?php endif; ?>
            <div class="voip-stock-summary">
                <span class="voip-stock-chip voip-stock-chip--review">Contratos sem vínculo <strong><?= count($voipMonitor['unlinked']) ?></strong></span>
                <span class="voip-stock-chip voip-stock-chip--review">Histórico de alterações <strong><?= count($voipMonitor['pending']) ?></strong></span>
            </div>
            <?php if ($voipMonitor['pending']): ?>
                <div class="voip-table-wrap"><table class="voip-table">
                    <thead><tr><th>Data UTC</th><th>Contrato</th><th>Alteração detectada</th></tr></thead><tbody>
                    <?php foreach ($voipMonitor['pending'] as $event): ?>
                        <tr><td><?= voipEscape($event['at'] ?? '—') ?></td>
                            <td><?= voipEscape($event['contract_id'] ?? '—') ?></td>
                            <td><?= voipEscape([
                                'new_product_74'=>'Produto 74 identificado em novo contrato',
                                'status_changed'=>'Status IXC alterado: ' . ($event['previous_status'] ?? '?') . ' → ' . ($event['status'] ?? '?'),
                                'product_74_absent'=>'Produto 74 não consta mais na consulta'
                            ][$event['type'] ?? ''] ?? 'Verificar alteração') ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
            <?php else: ?>
                <p>Nenhuma mudança detectada desde a referência inicial.</p>
            <?php endif; ?>
            <div class="voip-note" role="status"><i class="bi bi-list-check" aria-hidden="true"></i><div><strong>Pendências atuais no monitor</strong><span>Estes contratos continuam sem vínculo local confirmado e permanecem separados do histórico de eventos:</span><br><strong><?= voipEscape($voipMonitor['unlinked'] ? implode(', ', $voipMonitor['unlinked']) : 'Nenhum') ?></strong></div></div>
            <div class="voip-subtitle">Resumo da consulta atual</div>
            <p>Contratos com produto 74 ainda sem vínculo confirmado:
                <strong><?= voipEscape($voipMonitor['unlinked'] ? implode(', ', $voipMonitor['unlinked']) : 'Nenhum') ?></strong>.
                Confirme a necessidade de telefonia antes de atribuir um número. Remoção do produto ou mudança de status não libera o DID automaticamente.</p>
        <?php endif; ?>
    </section>

    <section class="voip-card voip-section" id="voip-estoque">
        <div class="voip-section-head"><div><h3 class="voip-section-title"><i class="bi bi-sim me-2" aria-hidden="true"></i>Estoque VoiceCopy</h3>
        <p>Números informados pela equipe, ainda sem validação dos contratos pelo IXC.</p></div><span class="voip-state">Importação manual</span></div>

        <div class="voip-stock-summary" aria-label="Situação dos números">
            <span class="voip-stock-chip voip-stock-chip--occupied"><i class="bi bi-circle-fill" aria-hidden="true"></i> Ocupados <strong><?= $voipData === null ? '—' : $voipOccupied ?></strong></span>
            <span class="voip-stock-chip voip-stock-chip--available"><i class="bi bi-circle-fill" aria-hidden="true"></i> Disponíveis <strong><?= $voipData === null ? '—' : $voipAvailable ?></strong></span>
            <span class="voip-stock-chip voip-stock-chip--review"><i class="bi bi-circle-fill" aria-hidden="true"></i> A conferir <strong><?= $voipData === null ? '—' : $voipReview ?></strong></span>
        </div>
        <div class="voip-subtitle">Números e titulares</div>
        <p>Ocupação informada pela equipe. A validação dos contratos e do produto 74 será feita na próxima etapa.</p>
        <div class="voip-table-wrap">
        <table class="voip-table">
            <thead><tr><th>Número</th><th>Titular informado</th><th>Situação</th><th>Contrato IXC</th></tr></thead>
            <tbody>
            <?php foreach ($voipRows as $row): ?>
            <tr>
                <td class="voip-phone"><?= voipEscape('('.substr($row['number'],0,2).') '.substr($row['number'],2,4).'-'.substr($row['number'],6)) ?></td>
                <td><?= voipEscape($row['holder'] ?: 'Não informado') ?></td>
                <td><?php
                    $status = $row['status'] ?? 'a_conferir';
                    $stateClass = $status === 'ocupado' ? 'occupied' : ($status === 'disponivel' ? 'available' : 'review');
                    $stateLabel = $status === 'ocupado' ? 'Ocupado' : ($status === 'disponivel' ? 'Disponível' : 'A conferir');
                ?><span class="voip-status voip-status--<?= $stateClass ?>"><i class="bi bi-circle-fill" aria-hidden="true"></i> <?= voipEscape($stateLabel) ?></span></td>
                <td><?= !empty($row['contract_id']) ? voipEscape($row['contract_id']) : 'Pendente de vinculação' ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if ($voipData !== null && !$voipRows): ?><tr><td colspan="4">Nenhum número importado. Use a importação abaixo.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </section>
    <details class="voip-card voip-import" id="voip-importar">
        <summary>Importar números</summary>
        <p>Uma linha por número: <code>número;ocupado;nome</code> ou <code>número;a_conferir;</code>. Uma nova importação não substitui cadastros existentes.</p>
        <form method="post" action="/voip-central.php">
            <input type="hidden" name="csrf" value="<?= voipEscape($_SESSION['voip_csrf']) ?>">
            <label for="voip-inventory">Lista de números e titulares</label>
            <textarea id="voip-inventory" name="inventory" rows="10" maxlength="100000" required spellcheck="false"></textarea>
            <button type="submit" <?= $voipData === null ? 'disabled' : '' ?>>Importar estoque</button>
        </form>
    </details>


    <section class="voip-card voip-section" id="voip-conciliacao">
        <div class="voip-section-head"><div>
            <h3 class="voip-section-title"><i class="bi bi-arrow-left-right me-2" aria-hidden="true"></i>Conciliação IXC × VoiceCopy</h3>
            <p>Compare o estoque importado com a consulta autorizada do MCP. Somente sugestão, sem gravar vínculos ou liberar números.</p>
        </div><span class="voip-state">Consulta supervisionada</span></div>
        <p>Obtenha o JSON completo usando <code>ixc_voip_listar_contratos_produto74</code> no ChatGPT autorizado e cole abaixo.
        O ACS ainda não possui um canal de autenticação servidor-servidor homologado para sincronização direta. Nenhum dado desta comparação será persistido.</p>
        <form method="post" action="/voip-central.php#voip-conciliacao" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= voipEscape($_SESSION['voip_csrf']) ?>">
            <input type="hidden" name="voip_action" value="compare_ixc">
            <label for="voip-ixc-snapshot">Resposta JSON da ferramenta MCP</label>
            <textarea id="voip-ixc-snapshot" name="ixc_snapshot" rows="5" maxlength="100000" required placeholder='{"produto_id":"74","total_contratos":...}'></textarea>
            <button type="submit" <?= $voipData === null ? 'disabled' : '' ?>>Comparar com estoque</button>
        </form>
        <?php if ($voipComparison !== null): ?>
            <?php if ($voipOperational !== null): ?>
                <div class="voip-stock-summary" aria-label="Análise operacional com dados IXC">
                    <span class="voip-stock-chip voip-stock-chip--occupied">Ocupados no estoque <strong><?= $voipOperational['stock']['ocupado'] ?></strong></span>
                    <span class="voip-stock-chip voip-stock-chip--review">Sugestões únicas <strong><?= $voipOperational['suggested_unique'] ?></strong></span>
                    <span class="voip-stock-chip voip-stock-chip--review">Ocupados sem sugestão <strong><?= $voipOperational['occupied_without_suggestion'] ?></strong></span>
                    <span class="voip-stock-chip voip-stock-chip--review">A conferir <strong><?= $voipOperational['stock']['a_conferir'] ?></strong></span>
                    <span class="voip-stock-chip voip-stock-chip--available">Disponíveis confirmados no estoque <strong><?= $voipOperational['stock']['disponivel'] ?></strong></span>
                </div>
                <div class="voip-note" role="status">
                    <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                    <div>
                        <strong>Alertas de conferência — consulta atual (não são notificações automáticas)</strong>
                        <div class="voip-subtitle">Pendências atuais</div>
                <p>Contratos com produto 74 e status bruto A sem número sugerido:
                        <strong><?= voipEscape($voipOperational['contracts_status_A_without_phone'] ? implode(', ', $voipOperational['contracts_status_A_without_phone']) : 'Nenhum') ?></strong>.
                        Verificar e atribuir um DID apenas após confirmação da equipe.</p>
                        <p>Contratos sem número sugerido (qualquer status):
                        <strong><?= voipEscape($voipOperational['contracts_without_phone'] ? implode(', ', $voipOperational['contracts_without_phone']) : 'Nenhum') ?></strong>.
                        Divergências e nomes duplicados requerem revisão.</p>
                        <p>Distribuição IXC por códigos brutos:
                        <?php foreach ($voipOperational['raw_status'] as $code => $count): ?>
                            <span><?= voipEscape($code) ?>: <?= (int)$count ?></span>
                        <?php endforeach; ?>
                        </p>
                        <p>Um novo contrato com produto 74 só poderá gerar alerta de inclusão automaticamente após existir monitoramento autenticado e referência persistente entre consultas. Nenhuma disponibilidade é inferida pela ausência de correspondência.</p>
                    </div>
                </div>
            <?php endif; ?>
            <div class="voip-stock-summary" aria-label="Resumo da comparação">
                <span class="voip-stock-chip voip-stock-chip--occupied">Correspondência única: <strong><?= $voipComparison['stats']['unica'] ?></strong></span>
                <span class="voip-stock-chip voip-stock-chip--review">Ambíguos: <strong><?= $voipComparison['stats']['ambigua'] ?></strong></span>
                <span class="voip-stock-chip voip-stock-chip--review">Sem correspondência: <strong><?= $voipComparison['stats']['sem_correspondencia'] ?></strong></span>
                <span class="voip-stock-chip voip-stock-chip--review">Sem titular: <strong><?= $voipComparison['stats']['sem_nome'] ?></strong></span>
            </div>
            <p><strong>Resultado somente para conferência.</strong> Mesmo uma correspondência única não confirma a atribuição do número.
            Códigos de status IXC não foram classificados; nenhuma baixa VoiceCopy foi confirmada.</p>
            <div class="voip-table-wrap">
                <table class="voip-table">
                    <thead><tr><th>Contrato</th><th>Titular IXC</th><th>Status IXC bruto</th><th>Número(s) sugerido(s)</th><th>Conferência</th><th>Vinculação</th></tr></thead>
                    <tbody>
                    <?php foreach ($voipComparison['rows'] as $match): ?>
                        <tr>
                            <td><?= voipEscape($match['contract_id']) ?></td>
                            <td><?= voipEscape($match['customer_name'] ?? 'Não informado') ?></td>
                            <td><?= voipEscape($match['status']) ?></td>
                            <td><?= voipEscape($match['candidates'] ? implode(', ', $match['candidates']) : 'Nenhum') ?></td>
                            <td><?php
                                $labels = ['unica' => 'Sugestão única — confirmar', 'ambigua' => 'Ambígua — revisar',
                                    'sem_correspondencia' => 'Não localizado no estoque', 'sem_nome' => 'Titular ausente'];
                                echo voipEscape($labels[$match['classification']] ?? 'Revisar');
                            ?></td>
                            <td>
                                <?php $voipPrefill = VoipLinkPrefill::suggest($match, $voipStockByNumber); ?>
                                <?php if ($voipPrefill !== null): ?>
                                    <button type="button" class="voip-use-suggestion"
                                        data-number="<?= voipEscape($voipPrefill['number']) ?>"
                                        data-contract="<?= voipEscape($voipPrefill['contract_id']) ?>"
                                        data-product="<?= voipEscape($voipPrefill['product_record_id']) ?>">
                                        Preparar vínculo
                                    </button>
                                <?php else: ?>
                                    <span class="voip-muted">Conferir manualmente</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$voipComparison['rows']): ?><tr><td colspan="6">Nenhum contrato do produto 74 retornado.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php if ($voipHistorical !== null): ?>
            <div class="voip-subtitle">Conciliação do estoque histórico — por número</div>
            <p>Esta classificação usa a lista original dos DIDs ocupados e os contratos do produto 74. Status A/I são códigos brutos; ausência de contrato na lista não comprova que o número esteja livre. Nenhuma baixa é feita pela comparação.</p>
            <div class="voip-stock-summary">
                <span class="voip-stock-chip voip-stock-chip--occupied">Históricos ocupados <strong><?= (int)$voipHistorical['summary']['historical_occupied'] ?></strong></span>
                <span class="voip-stock-chip voip-stock-chip--review">Possível vínculo ativo <strong><?= (int)$voipHistorical['summary']['active_candidate'] ?></strong></span>
                <span class="voip-stock-chip voip-stock-chip--review">Possível liberação (I) <strong><?= (int)$voipHistorical['summary']['inactive_candidate'] ?></strong></span>
                <span class="voip-stock-chip voip-stock-chip--review">Sem produto 74 localizado <strong><?= (int)$voipHistorical['summary']['missing_product74'] ?></strong></span>
                <span class="voip-stock-chip voip-stock-chip--available">Vínculos preservados <strong><?= (int)$voipHistorical['summary']['already_linked'] ?></strong></span>
            </div>
            <div class="voip-table-wrap">
                <table class="voip-table" aria-label="Revisão do estoque histórico">
                    <thead><tr><th>DID</th><th>Titular histórico</th><th>Contrato sugerido</th><th>Status IXC</th><th>Resultado</th><th>Próxima ação</th></tr></thead>
                    <tbody>
                        <?php
                        $voipHistoricalLabels = [
                            'active_candidate'=>'Possível vínculo ativo',
                            'inactive_candidate'=>'Candidato à liberação',
                            'missing_product74'=>'Sem produto 74 encontrado',
                            'ambiguous'=>'Vários contratos possíveis',
                            'already_linked'=>'Vínculo existente protegido',
                            'unknown_status'=>'Status não classificado',
                            'other_review'=>'Conferir',
                        ];
                        foreach ($voipHistorical['rows'] as $historic): ?>
                            <tr>
                                <td class="voip-phone"><?= voipEscape($historic['number']) ?></td>
                                <td><?= voipEscape($historic['holder'] ?: 'Não informado') ?></td>
                                <td><?= voipEscape($historic['contract_id'] ?? '—') ?></td>
                                <td><?= voipEscape($historic['status_raw'] ?? '—') ?></td>
                                <td><?= voipEscape($voipHistoricalLabels[$historic['category']] ?? 'Conferir') ?></td>
                                <td><?= $historic['category'] === 'already_linked'
                                    ? 'Preservar vínculo existente'
                                    : ($historic['category'] === 'active_candidate'
                                        ? 'Conferir atribuição e usar Preparar vínculo'
                                        : 'Confirmar situação na VoiceCopy antes de liberar') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="voip-card voip-section" id="voip-vinculos">
        <div class="voip-section-head"><div>
            <h3 class="voip-section-title"><i class="bi bi-link-45deg me-2" aria-hidden="true"></i>Vínculos manuais e estoque mínimo</h3>
            <p>Vínculos são decisões do operador após conferir o contrato e o produto 74 no IXC e a atribuição telefônica na VoiceCopy.</p>
        </div><span class="voip-state">Confirmação supervisionada</span></div>
        <div class="voip-stock-summary">
            <span class="voip-stock-chip voip-stock-chip--occupied">Vinculados <strong><?= $voipLinked ?></strong></span>
            <span class="voip-stock-chip voip-stock-chip--available">Disponíveis confirmados <strong><?= $voipAvailable ?></strong></span>
            <span class="voip-stock-chip voip-stock-chip--review">Mínimo configurado <strong><?= $voipMin ?></strong></span>
        </div>
        <?php if ($voipLowStock): ?>
            <div class="voip-note" role="alert">
                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                <div><strong>Estoque disponível no limite: <?= $voipAvailable ?> de <?= $voipMin ?>.</strong>
                    Revisar os números a conferir e avaliar solicitação à VoiceCopy. Nenhum pedido é enviado automaticamente.</div>
            </div>
        <?php endif; ?>
        <form method="post" action="/voip-central.php#voip-vinculos">
            <input type="hidden" name="csrf" value="<?= voipEscape($_SESSION['voip_csrf']) ?>">
            <input type="hidden" name="voip_action" value="set_minimum">
            <label for="voip-minimum">Alerta de estoque mínimo (1 a 100)</label>
            <input id="voip-minimum" type="number" min="1" max="100" step="1" name="minimum_available" value="<?= $voipMin ?>" required>
            <button type="submit">Salvar limite</button>
        </form>
        <div class="voip-subtitle">Confirmar vínculo manual</div>
        <p>Preencha somente após verificar o ID exato do contrato, o registro do produto 74 e o número telefônico. Não confirma status ou propriedade junto ao IXC automaticamente.</p>
        <div id="voip-prefill-notice" class="voip-note" role="status" hidden>Campos preparados a partir da conciliação. Confira os dados na VoiceCopy e no IXC antes de confirmar o vínculo.</div>
        <form method="post" action="/voip-central.php#voip-vinculos" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= voipEscape($_SESSION['voip_csrf']) ?>">
            <input type="hidden" name="voip_action" value="link_manual">
            <label for="voip-link-number">Número VoiceCopy (somente dígitos)</label>
            <input id="voip-link-number" name="number" inputmode="numeric" pattern="[0-9]{10}" maxlength="10" required placeholder="1151961252">
            <label for="voip-link-contract">Contrato IXC</label>
            <input id="voip-link-contract" name="contract_id" inputmode="numeric" pattern="[0-9]{1,12}" maxlength="12" required>
            <label for="voip-link-product">ID do registro do produto 74</label>
            <input id="voip-link-product" name="product_record_id" inputmode="numeric" pattern="[0-9]{1,12}" maxlength="12" required>
            <label><input type="checkbox" name="checked_ixc_voicecopy" value="YES" required>
                Conferi o contrato e o produto 74 no IXC e confirmei a atribuição deste número na VoiceCopy.</label>
            <button type="submit">Confirmar vínculo</button>
        </form>
        <div class="voip-subtitle">Liberar DID histórico após conferência na VoiceCopy</div>
        <p>Somente DIDs antigos com status <strong>ocupado</strong>, ainda sem vínculo de contrato e sem reserva. A ausência de produto 74 no IXC não comprova liberação. Confira diretamente na VoiceCopy antes de confirmar.</p>
        <form method="post" action="/voip-central.php#voip-vinculos" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= voipEscape($_SESSION['voip_csrf']) ?>">
            <input type="hidden" name="voip_action" value="confirm_legacy_free">
            <label for="voip-legacy-free-number">DID histórico confirmado livre</label>
            <select id="voip-legacy-free-number" name="number" required>
                <option value="">Selecione um número</option>
                <?php foreach ($voipRows as $candidate): ?>
                    <?php if ($candidate['status'] === 'ocupado' && empty($candidate['contract_id']) && empty($candidate['product_record_id']) && empty($candidate['reservation_id'])): ?>
                        <option value="<?= voipEscape($candidate['number']) ?>"><?= voipEscape($candidate['number']) ?></option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <label><input type="checkbox" name="checked_voicecopy_legacy" value="YES" required>
                Confirmei na VoiceCopy que este DID não está atribuído a ninguém e pode voltar ao estoque disponível.</label>
            <button type="submit">Confirmar liberação histórica</button>
        </form>
        <div class="voip-subtitle">Confirmar em lote os DIDs novos entregues pela VoiceCopy</div>
        <p>Selecione apenas os números novos que a VoiceCopy entregou livres para uso. Não inclui números ocupados, vinculados ou reservados. A operação será feita de uma só vez, com histórico por DID.</p>
        <form method="post" action="/voip-central.php#voip-vinculos" autocomplete="off" id="voip-new-batch-form">
            <input type="hidden" name="csrf" value="<?= voipEscape($_SESSION['voip_csrf']) ?>">
            <input type="hidden" name="voip_action" value="confirm_new_batch_available">
            <input type="hidden" name="stock_revision" value="<?= (int)($voipData['revision'] ?? 0) ?>">
            <div class="voip-subtitle">Números novos pendentes de confirmação</div>
            <label><input type="checkbox" id="voip-new-batch-select-all"> Selecionar todos os novos números elegíveis</label>
            <div class="voip-table-wrap">
                <table class="voip-table">
                    <thead><tr><th>Selecionar</th><th>DID</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php $voipNewBatchCount = 0; foreach ($voipRows as $candidate): ?>
                        <?php if ($candidate['status'] === 'a_conferir' &&
                            empty($candidate['contract_id']) && empty($candidate['product_record_id']) &&
                            empty($candidate['reservation_id']) && trim((string)($candidate['holder'] ?? '')) === ''):
                            $voipNewBatchCount++; ?>
                            <tr>
                                <td><input type="checkbox" class="voip-new-batch-number" name="new_batch_numbers[]"
                                    value="<?= voipEscape($candidate['number']) ?>"
                                    aria-label="Confirmar <?= voipEscape($candidate['number']) ?>"></td>
                                <td class="voip-phone"><?= voipEscape($candidate['number']) ?></td>
                                <td>A conferir</td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($voipNewBatchCount === 0): ?>
                        <tr><td colspan="3">Nenhum DID novo pendente elegível.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p><strong id="voip-new-batch-count">0 selecionados</strong> de <?= $voipNewBatchCount ?> candidatos.</p>
            <label><input type="checkbox" name="checked_new_batch_voicecopy" value="YES" id="voip-new-batch-verified" required>
                Confirmo que todos os DIDs selecionados são novos e foram entregues disponíveis pela VoiceCopy.</label>
            <button type="submit" id="voip-new-batch-submit" disabled>Confirmar disponibilidade em lote</button>
        </form>
        <div class="voip-subtitle">Confirmar disponibilidade na VoiceCopy</div>
        <p>Disponível apenas para números <strong>a conferir</strong> e sem vínculo. Nunca libera números ocupados ou vinculados.</p>
        <form method="post" action="/voip-central.php#voip-vinculos" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= voipEscape($_SESSION['voip_csrf']) ?>">
            <input type="hidden" name="voip_action" value="confirm_available">
            <label for="voip-available-number">Número a conferir</label>
            <select id="voip-available-number" name="number" required>
                <option value="">Selecione um número</option>
                <?php foreach ($voipRows as $candidate): ?>
                    <?php if ($candidate['status'] === 'a_conferir' && empty($candidate['contract_id'])): ?>
                        <option value="<?= voipEscape($candidate['number']) ?>"><?= voipEscape($candidate['number']) ?></option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <label><input type="checkbox" name="checked_voicecopy" value="YES" required>
                Verifiquei na VoiceCopy que o número está livre e apto para nova atribuição.</label>
            <button type="submit">Confirmar disponibilidade</button>
        </form>
    </section>

    <section class="voip-card voip-section" id="voip-baixas">
        <div class="voip-section-head"><div><h3 class="voip-section-title"><i class="bi bi-shield-exclamation me-2" aria-hidden="true"></i>Cancelamentos e baixas</h3>
        <p>O cancelamento só é processado com o marcador explícito <code>#JRC_VOIP_CANCELAR#</code> e a remoção do vínculo na observação.</p></div><span class="voip-state">Monitorado</span></div>
        <div class="voip-empty-state"><i class="bi bi-shield-check" aria-hidden="true"></i><div><strong>Liberação supervisionada</strong><span>O DID só poderá voltar ao estoque disponível após confirmação de baixa na VoiceCopy.</span></div></div>
    </section>

    <section class="voip-card voip-section voip-steps" aria-label="Fluxo operacional">
        <h3 class="voip-section-title">Fluxo operacional</h3>
        <div class="voip-workflow">
            <div class="voip-step"><div class="voip-step-icon"><i class="bi bi-cart-check" aria-hidden="true"></i></div><div><strong>1. Contratação no IXC</strong><span>O suporte N1 adiciona o produto 74 ao contrato.</span></div></div>
            <div class="voip-step"><div class="voip-step-icon"><i class="bi bi-bell" aria-hidden="true"></i></div><div><strong>2. Alerta no ACS</strong><span>O MCP identificará contratos sem telefone vinculado.</span></div></div>
            <div class="voip-step"><div class="voip-step-icon"><i class="bi bi-telephone-plus" aria-hidden="true"></i></div><div><strong>3. Vinculação manual</strong><span>A equipe selecionará um número e confirmará a ativação.</span></div></div>
        </div>
    </section>

    <details class="voip-card voip-import" id="voip-historico">
        <summary>Histórico de importação</summary>
        <div class="voip-table-wrap"><table class="voip-table">
        <thead><tr><th>Data (UTC)</th><th>Operador (ID)</th><th>Número</th><th>Ação</th></tr></thead><tbody>
        <?php foreach (array_reverse($voipData['audit'] ?? []) as $event): ?>
        <tr><td><?= voipEscape($event['at']) ?></td><td><?= voipEscape($event['actor']) ?></td><td><?= voipEscape($event['number'] ?? '—') ?></td><td><?= voipEscape([
            'import'=>'Importação',
            'manual_link'=>'Vínculo manual: contrato ' . ($event['contract_id'] ?? '') . ', produto ' . ($event['product_record_id'] ?? ''),
            'voicecopy_available_confirmed'=>'Disponibilidade confirmada na VoiceCopy',
            'new_did_batch_available_confirmed'=>'DID novo disponibilizado em lote (conferência VoiceCopy)',
            'legacy_free_confirmed_voicecopy'=>'DID histórico liberado após conferência na VoiceCopy',
            'minimum_changed'=>'Novo estoque mínimo: ' . ($event['minimum_available'] ?? '')
        ][$event['action'] ?? ''] ?? 'Operação') ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </details>
</section>
<script>
(() => {
  'use strict';
  const section = document.getElementById('voip-vinculos');
  if (!section) return;
  const batchForm = document.getElementById('voip-new-batch-form');
  if (batchForm) {
    const items = Array.from(batchForm.querySelectorAll('.voip-new-batch-number'));
    const all = batchForm.querySelector('#voip-new-batch-select-all');
    const count = batchForm.querySelector('#voip-new-batch-count');
    const verified = batchForm.querySelector('#voip-new-batch-verified');
    const submit = batchForm.querySelector('#voip-new-batch-submit');
    const refresh = () => {
      const selected = items.filter(item => item.checked).length;
      count.textContent = selected + ' selecionados';
      all.checked = items.length > 0 && selected === items.length;
      all.indeterminate = selected > 0 && selected < items.length;
      submit.disabled = selected === 0 || !verified.checked;
    };
    all.addEventListener('change', () => {
      items.forEach(item => { item.checked = all.checked; });
      verified.checked = false;
      refresh();
    });
    items.forEach(item => item.addEventListener('change', () => {
      verified.checked = false;
      refresh();
    }));
    verified.addEventListener('change', refresh);
    refresh();
  }

  const number = section.querySelector('#voip-link-number');
  const contract = section.querySelector('#voip-link-contract');
  const product = section.querySelector('#voip-link-product');
  const confirm = section.querySelector('input[name="checked_ixc_voicecopy"]');
  const notice = section.querySelector('#voip-prefill-notice');
  document.querySelectorAll('.voip-use-suggestion').forEach(button => {
    button.addEventListener('click', () => {
      if (!number || !contract || !product || !confirm) return;
      number.value = button.dataset.number || '';
      contract.value = button.dataset.contract || '';
      product.value = button.dataset.product || '';
      confirm.checked = false;
      if (notice) notice.hidden = false;
      section.scrollIntoView({ behavior: 'smooth', block: 'start' });
      contract.focus({ preventScroll: true });
    });
  });
})();
</script>
<?php include __DIR__ . '/views/layouts/footer.php'; ?>
