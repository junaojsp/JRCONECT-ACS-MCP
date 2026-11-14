<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
requireLogin();
$pageTitle = 'Provisionamento de ONUs';
$currentPage = 'provisioning';
include __DIR__ . '/views/layouts/header.php';
?>
<link rel="stylesheet" href="/assets/css/provisionamento-onu.css?v=1">
<section class="onu-page">
  <link rel="stylesheet" href="/assets/css/provisionamento-standard-v2.css?v=20261030-4">
<header class="onu-title"><div><h1>Provisionamento de ONUs</h1><p>Inventário IXC × GenieACS. Autorização na OLT ainda não integrada.</p></div><button id="onu-refresh" class="onu-primary" type="button"><i class="bi bi-arrow-clockwise"></i> Atualizar</button></header>
  <div class="onu-stats">
    <article><i class="bi bi-clock-history"></i><strong id="onu-pending">—</strong><span>Sem registro no ACS</span></article>
    <article><i class="bi bi-check-circle"></i><strong id="onu-active">—</strong><span>TR-069 ativo</span></article>
    <article><i class="bi bi-arrow-repeat"></i><strong id="onu-stale">—</strong><span>Sem comunicação recente</span></article>
    <article><i class="bi bi-exclamation-triangle"></i><strong id="onu-fail">—</strong><span>Falhas na consulta</span></article>
    <article><i class="bi bi-hdd-network"></i><strong id="onu-total">—</strong><span>Equipamentos consultados</span></article>
  </div>
  <section class="onu-panel"><h2><i class="bi bi-funnel"></i> Filtros de busca</h2>
    <div class="onu-filters">
      <label>Status<select id="onu-status"><option value="ALL">Todos</option><option value="NOT_IN_ACS">Não no ACS</option><option value="TR069_ACTIVE">TR-069 ativo</option><option value="TR069_STALE">Sem Inform</option></select></label>
      <label>Serial Number<input id="onu-serial" placeholder="Buscar serial"></label>
      <label>Modelo<input id="onu-model" placeholder="Modelo da ONU"></label>
      <label>Cliente (IXC)<input id="onu-client" placeholder="Nome do cliente"></label>
      <button class="onu-primary" id="onu-search" type="button"><i class="bi bi-search"></i> Buscar ONUs</button>
      <button class="onu-secondary" id="onu-clear" type="button">Limpar filtros</button>
    </div>
  </section>
  <section class="onu-panel onu-results">
    <nav class="onu-tabs" aria-label="Filtros de inventário">
      <button type="button" data-status="NOT_IN_ACS">Não no ACS</button>
      <button type="button" data-status="TR069_ACTIVE">TR-069 ativo</button>
      <button type="button" data-status="ALL" class="selected">Todas as ONUs</button>
    </nav>
    <p class="onu-note"><i class="bi bi-info-circle"></i> “Não no ACS” não significa necessariamente ONU não autorizada na OLT. Esta tela é somente consulta até existir integração de autorização validada.</p>
    <div class="onu-scroll"><table><thead><tr><th>Status</th><th>Serial Number</th><th>Modelo</th><th>Cliente (IXC)</th><th>PON</th><th>Nº ONU</th><th>Último Inform</th><th>Ações</th></tr></thead><tbody id="onu-rows"><tr><td colspan="8" class="onu-empty">Carregando inventário...</td></tr></tbody></table></div>
    <footer class="onu-footer"><span id="onu-count">—</span><div><button type="button" id="onu-prev" class="onu-secondary">Anterior</button><span id="onu-page-label">Página 1</span><button type="button" id="onu-next" class="onu-secondary">Próxima</button></div></footer>
  </section>
</section>

<style id="jrc-provision-inputs-v5">
/* JRC_PROVISION_INPUTS_V5 */
html body #onu-serial,
html body #onu-model,
html body #onu-client,
html body .onu-filters #onu-serial,
html body .onu-filters #onu-model,
html body .onu-filters #onu-client {
    --bs-body-bg: #0d0f12 !important;
    --bs-secondary-bg: #0d0f12 !important;
    --bs-tertiary-bg: #0d0f12 !important;
    background: #0d0f12 !important;
    background-color: #0d0f12 !important;
    background-image: none !important;
    border: 1px solid #30343c !important;
    color: #e8ebef !important;
    box-shadow: inset 0 0 0 1000px #0d0f12 !important;
    -webkit-box-shadow: inset 0 0 0 1000px #0d0f12 !important;
    -webkit-text-fill-color: #e8ebef !important;
    color-scheme: dark !important;
}

html body #onu-serial:hover,
html body #onu-model:hover,
html body #onu-client:hover,
html body #onu-serial:focus,
html body #onu-model:focus,
html body #onu-client:focus {
    background: #101217 !important;
    background-color: #101217 !important;
    border-color: #555d69 !important;
    box-shadow:
        inset 0 0 0 1000px #101217,
        0 0 0 2px rgba(255,255,255,.035) !important;
    -webkit-box-shadow:
        inset 0 0 0 1000px #101217,
        0 0 0 2px rgba(255,255,255,.035) !important;
    outline: none !important;
}

html body #onu-serial::placeholder,
html body #onu-model::placeholder,
html body #onu-client::placeholder {
    color: #697384 !important;
    opacity: 1 !important;
    -webkit-text-fill-color: #697384 !important;
}

html body #onu-serial:-webkit-autofill,
html body #onu-model:-webkit-autofill,
html body #onu-client:-webkit-autofill,
html body #onu-serial:-webkit-autofill:hover,
html body #onu-model:-webkit-autofill:hover,
html body #onu-client:-webkit-autofill:hover,
html body #onu-serial:-webkit-autofill:focus,
html body #onu-model:-webkit-autofill:focus,
html body #onu-client:-webkit-autofill:focus {
    -webkit-text-fill-color: #e8ebef !important;
    -webkit-box-shadow: inset 0 0 0 1000px #0d0f12 !important;
    box-shadow: inset 0 0 0 1000px #0d0f12 !important;
    transition: background-color 999999s ease-in-out 0s !important;
}
</style>

<script src="/assets/js/provisionamento-onu.js?v=20261030-2"></script>
<?php include __DIR__ . '/views/layouts/footer.php'; ?>
