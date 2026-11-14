<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$pageTitle = 'Dispositivos';
$currentPage = 'devices';

$genieacsConfigured = isGenieACSConfigured();

include __DIR__ . '/views/layouts/header.php';
?>

<style>
html, body, .main-content, #mainContent, .content-wrapper, .page-content, .container-fluid, .container, main {
    background: #0c0d10 !important;
    background-image: none !important;
}
.topbar, .navbar, header.topbar, body .topbar {
    background: #0c0d10 !important;
    border-bottom: 1px solid #1c1e24 !important;
    color: #f3f5f7 !important;
}
.footer { display: none !important; }
.fb-devices { color: #f3f5f7; }
.fb-dev-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; margin-bottom: 16px; flex-wrap: wrap; }
.fb-dev-head h1 { margin: 0; color: #f7f8fa; font-size: 28px; font-weight: 650; letter-spacing: -0.03em; }
.fb-dev-head p { margin: 6px 0 0; color: #8d939c; font-size: 14px; }
#device-stats-badges.fb-head-metrics { display: flex !important; gap: 22px !important; align-items: flex-end !important; }
.fb-metric { text-align: right; min-width: 72px; }
.fb-metric strong { display: block; color: #f7f8fa; font-size: 22px; font-weight: 650; line-height: 1; }
.fb-metric span { display: block; margin-top: 4px; color: #8d939c; font-size: 12px; }
.fb-metric.is-warn strong { color: #f5b942; }
.fb-metric.is-bad strong { color: #ff6b6b; }
.fb-devices .card-header,
.fb-devices #deviceTypeTabs,
.fb-devices .fb-legacy-tools { display: none !important; }
.fb-devices .card-body { padding: 4px 8px 12px !important; }
#devices-table { white-space: normal !important; }
#devices-table thead th { text-transform: uppercase; letter-spacing: .04em; font-size: 11px !important; font-weight: 600 !important; cursor: pointer; }
#devices-table td { vertical-align: middle; padding: 12px 10px !important; }
.fb-equip { display: flex !important; align-items: center; gap: 10px; color: #f3f5f7 !important; }
.fb-equip strong { display: block; color: #f7f8fa !important; font-size: 13px; font-weight: 650; }
.fb-equip small, #devices-table .fb-muted { display: block; color: #8d939c !important; font-size: 12px; }
.fb-equip-icon { width: 32px; height: 32px; border-radius: 10px; display: grid; place-items: center; background: #1c1f27; color: #c5cad3; flex: 0 0 auto; }
.fb-signal { display: inline-flex; align-items: center; gap: 6px; border-radius: 999px; padding: 3px 8px; font-size: 12px; font-weight: 650; background: #1c1f27; color: #c5cad3; }
.fb-signal i { font-size: 8px; }
.fb-signal.is-good { background: #143222; color: #3ddc84; }
.fb-signal.is-warn { background: #3a2e14; color: #f5b942; }
.fb-signal.is-bad { background: #3a1518; color: #ff6b6b; }
.fb-top-search-wrap { position: relative; flex: 1; max-width: 460px; margin: 0 16px; }
.fb-top-search-wrap i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #8d939c; }
.fb-top-search { width: 100%; height: 38px; border-radius: 12px; border: 1px solid #2a2d34; background: #14161b; color: #f3f5f7; padding: 0 12px 0 34px; outline: none; }
.topbar { gap: 12px; flex-wrap: wrap; }
.fb-devices .card { background: #14161b !important; border: 1px solid #2a2d34 !important; border-radius: 16px !important; color: #f3f5f7 !important; box-shadow: none !important; }
.fb-devices .card-header, .fb-devices .card-body { background: transparent !important; color: #f3f5f7 !important; border-color: #2a2d34 !important; }
.fb-devices .text-muted, .fb-devices label { color: #8d939c !important; }
#devices-table, #devices-table thead, #devices-table thead tr, #devices-table thead th,
.table thead, .table thead tr, .table thead th {
    background: #14161b !important; color: #8d939c !important; border-color: #2a2d34 !important; box-shadow: none !important;
}
#devices-table tbody td, .fb-devices .table tbody td { background: #14161b !important; color: #d7dbe2 !important; border-color: #26282f !important; }
#devices-table tbody tr:hover td { background: #1b1e25 !important; }
#devices-table a, #devices-table a:link, #devices-table a:visited,
.fb-devices .table a, .fb-devices a:link, .fb-devices a:visited { color: #e8eaee !important; text-decoration: none !important; }
#devices-table a:hover, .fb-devices a:hover { color: #fff !important; }
#devices-table .btn, #devices-table .btn-primary, #devices-table .btn-success {
    background: #1c1f26 !important; border: 1px solid #32353d !important; color: #e8eaee !important; box-shadow: none !important;
}
#devices-table .badge.bg-primary, #devices-table .badge.bg-secondary, #devices-table .badge.bg-info {
    background: #1c1f26 !important; color: #d7dbe2 !important; border: 1px solid #32353d !important;
}
.fb-devices .form-control, .fb-devices .form-select, .fb-devices .input-group-text {
    background: #1a1d24 !important; color: #f3f5f7 !important; border-color: #2a2d34 !important; box-shadow: none !important;
}
.fb-devices .btn { border-radius: 8px !important; box-shadow: none !important; }
.fb-devices .btn-primary, .fb-devices .btn-secondary, .fb-devices .btn-outline-secondary {
    background: #1c1f26 !important; border-color: #32353d !important; color: #e8eaee !important;
}
.fb-devices .btn-primary:hover, .fb-devices .btn-secondary:hover, .fb-devices .btn-outline-secondary:hover {
    background: #2a2d34 !important; color: #fff !important;
}
.fb-devices .nav-tabs { border-color: #2a2d34 !important; }
.fb-devices .nav-tabs .nav-link { color: #c5cad3 !important; background: transparent !important; border: 0 !important; }
.fb-devices .nav-tabs .nav-link.active { color: #fff !important; background: transparent !important; border-bottom: 2px solid #e10600 !important; }
.fb-devices .nav-tabs .badge { background: #1c1f26 !important; color: #d7dbe2 !important; }
.fb-devices .page-link { background: #1a1d24 !important; color: #e8eaee !important; border-color: #2a2d34 !important; }
.fb-devices .page-item.active .page-link { background: #3a1216 !important; border-color: #7a1d24 !important; color: #fff !important; }
.fb-devices .page-item.disabled .page-link { background: #14161b !important; color: #5c616a !important; }
.fb-devices .alert { background: #1c1f26 !important; border-color: #32353d !important; color: #f3f5f7 !important; }
.modal-content { background: #14161b !important; color: #f3f5f7 !important; border: 1px solid #2a2d34 !important; }
.modal-header, .modal-footer { background: transparent !important; border-color: #2a2d34 !important; color: #f3f5f7 !important; }
.modal-content .btn-close { filter: invert(1) grayscale(1); }
@media (max-width: 768px) {
    .fb-dev-head { align-items: flex-start; }
    #device-stats-badges.fb-head-metrics { width: 100%; justify-content: flex-start !important; }
    .fb-metric { text-align: left; }
}
</style>

<div class="fb-devices">
<div class="fb-dev-head">
    <div>
        <h1>Dispositivos</h1>
        <p>Inventário de CPEs da frota.</p>
    </div>
    <div class="fb-head-metrics" id="device-stats-badges"></div>
</div>

<?php if (!$genieacsConfigured): ?>

    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle"></i>
        O GenieACS ainda não foi configurado.
        Configure primeiro em
        <a href="/configuration.php">Configurações</a>.
    </div>

<?php else: ?>

    <div class="card">

        <div class="card-header">

            <div class="d-flex justify-content-between align-items-center">

                <div>

                    <span class="badge bg-secondary" title="IP, PPPoE e óptico pelo IXC; equipamento e Wi-Fi pelo TR-069">IXC + TR-069</span>

                </div>


                <div>

                    <!-- Botões de ações em massa -->
                    <div
                        id="bulk-action-buttons"
                        style="display: none; margin-right: 10px;"
                    >

                        <button
                            class="btn btn-sm btn-success"
                            onclick="showBulkAddTagModal()"
                            title="Adicionar etiqueta aos equipamentos selecionados"
                        >
                            <i class="bi bi-tag-fill"></i>
                            Adicionar etiqueta
                        </button>


                        <button
                            class="btn btn-sm btn-warning"
                            onclick="showBulkUntagModal()"
                            title="Remover etiqueta dos equipamentos selecionados"
                            style="margin-left: 5px;"
                        >
                            <i class="bi bi-tags"></i>
                            Remover etiqueta
                        </button>


                        <button
                            class="btn btn-sm btn-danger"
                            onclick="showBulkDeleteModal()"
                            title="Excluir equipamentos selecionados"
                            style="margin-left: 5px;"
                        >
                            <i class="bi bi-trash"></i>
                            Excluir
                        </button>


                        <span
                            id="selected-count"
                            class="badge bg-primary"
                            style="margin-left: 10px;"
                        >
                            0 selecionados
                        </span>

                    </div>


                    <button
                        class="btn btn-sm btn-secondary"
                        id="toggle-tags-btn"
                        onclick="toggleTagsColumn()"
                        style="margin-right: 10px;"
                    >
                        <i class="bi bi-tags"></i>
                        Mostrar etiquetas
                    </button>


                    <button
                        class="btn btn-sm btn-primary"
                        onclick="loadDevices()"
                    >
                        <i class="bi bi-arrow-clockwise"></i>
                        Atualizar
                    </button>

                </div>

            </div>

        </div>


        <div class="card-body">


            <!-- Navegação por tipo de equipamento -->
            <ul
                class="nav nav-tabs mb-3"
                id="deviceTypeTabs"
                role="tablist"
            >

                <li class="nav-item" role="presentation">

                    <button
                        class="nav-link active"
                        id="onu-tab"
                        data-bs-toggle="tab"
                        data-bs-target="#onu"
                        type="button"
                        role="tab"
                        onclick="filterByType('onu')"
                    >
                        <i class="bi bi-wifi"></i>
                        ONU

                        <span
                            class="badge bg-primary ms-1"
                            id="count-onu"
                        >
                            0
                        </span>

                    </button>

                </li>


                


                


                


                

            </ul>


            <!-- Busca e paginação -->
            <div class="row mb-3 fb-legacy-tools">


                <div class="col-md-4">

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="bi bi-search"></i>
                        </span>


                        <input
                            type="text"
                            class="form-control"
                            id="search-input"
                            placeholder="Pesquisar por serial, MAC, login, CPF ou nome do cliente..."
                            onkeyup="filterDevices()"
                        >


                        <button
                            class="btn btn-outline-secondary"
                            type="button"
                            onclick="clearSearch()"
                        >
                            <i class="bi bi-x-lg"></i>
                            Limpar
                        </button>

                    </div>

                </div>


                <div class="col-md-4 text-center">

                    <div class="d-flex align-items-center justify-content-center">

                        <label
                            class="me-2 mb-0"
                            style="white-space: nowrap;"
                        >
                            Exibir:
                        </label>


                        <select
                            class="form-select form-select-sm"
                            id="items-per-page"
                            onchange="changeItemsPerPage()"
                            style="width: auto;"
                        >

                            <option value="20" selected>20</option>
                            <option value="50">50</option>
                            <option value="100">100</option>

                        </select>


                        <span
                            class="ms-2 text-muted"
                            style="white-space: nowrap;"
                        >
                            por página
                        </span>

                    </div>

                </div>


                <div class="col-md-4 text-end">

                    <span
                        class="text-muted"
                        id="device-count"
                    >
                        Carregando...
                    </span>

                </div>

            </div>


            <!-- Tabela -->
            <div
                class="table-responsive"
                style="overflow-x: auto;"
            >

                <table
                    class="table table-hover"
                    id="devices-table"
                    style="white-space: nowrap;"
                >

                    <thead id="table-header">

                        <!--
                        O cabeçalho da tabela será gerado
                        dinamicamente conforme a aba selecionada
                        -->

                    </thead>


                    <tbody id="devices-tbody">

                        <tr>

                            <td
                                colspan="12"
                                class="text-center"
                            >
                                <div class="spinner"></div>
                            </td>

                        </tr>

                    </tbody>

                </table>

            </div>


            <!-- Navegação da paginação -->
            <div
                class="row mt-3"
                id="pagination-container"
                style="display: none;"
            >

                <div class="col-12">

                    <nav aria-label="Paginação de equipamentos">

                        <ul class="pagination justify-content-center mb-0">


                            <li
                                class="page-item"
                                id="pagination-first"
                            >

                                <button
                                    class="page-link"
                                    onclick="goToPage(1)"
                                >
                                    <i class="bi bi-chevron-double-left"></i>
                                    Primeira
                                </button>

                            </li>


                            <li
                                class="page-item"
                                id="pagination-prev"
                            >

                                <button
                                    class="page-link"
                                    onclick="goToPage(currentPage - 1)"
                                >
                                    <i class="bi bi-chevron-left"></i>
                                    Anterior
                                </button>

                            </li>


                            <li class="page-item active">

                                <span
                                    class="page-link"
                                    id="pagination-info"
                                >
                                    Página 1 de 1
                                </span>

                            </li>


                            <li
                                class="page-item"
                                id="pagination-next"
                            >

                                <button
                                    class="page-link"
                                    onclick="goToPage(currentPage + 1)"
                                >
                                    Próxima
                                    <i class="bi bi-chevron-right"></i>
                                </button>

                            </li>


                            <li
                                class="page-item"
                                id="pagination-last"
                            >

                                <button
                                    class="page-link"
                                    onclick="goToPage(Math.ceil(totalDevices / itemsPerPage))"
                                >
                                    Última
                                    <i class="bi bi-chevron-double-right"></i>
                                </button>

                            </li>

                        </ul>

                    </nav>

                </div>

            </div>

        </div>

    </div>


    <?php
    include __DIR__ . '/views/devices/modals.php';
    ?>

<?php endif; ?>

</div>

<script>

// Configuração global
window.GENIEACS_CONFIGURED =
    <?php echo $genieacsConfigured ? 'true' : 'false'; ?>;

</script>


<script src="/assets/js/devices/devices-state.js"></script>
<script src="/assets/js/devices.js?v=20261022-fleet"></script>
<script src="/assets/js/devices-server-pagination.js?v=20261030-1"></script>


<?php
/* JRC_INVENTORY_SAFE_V3_START */
echo '<link rel="stylesheet" href="/assets/css/jrc-inventory-safe-v3.css?v=20261022-3">';
echo '<script src="/assets/js/jrc-inventory-safe-v3.js?v=20261022-3"></script>';
/* JRC_INVENTORY_SAFE_V3_END */
include __DIR__ . '/views/layouts/footer.php';
?>

<link rel="stylesheet" href="/assets/css/jrc-inventory.css?v=20261022">
