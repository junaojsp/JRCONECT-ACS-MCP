<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$pageTitle = 'Equipamentos';
$currentPage = 'devices';

$genieacsConfigured = isGenieACSConfigured();

include __DIR__ . '/views/layouts/header.php';
?>

<?php if (!$genieacsConfigured): ?>

    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle"></i>
        O GenieACS ainda não foi configurado.
        Configure primeiro em
        <a href="/configuration.php">Configurações</a>.
    </div>

<?php else: ?>
<div class="jrc-inventory-page jrc-devices-reference">
    <header class="jdn-page-title">
      <div>
        <div class="jdn-eyebrow">JR CONECT TELECOM / INVENTARIO TR-069</div>
        <h1>Dispositivos</h1>
        <p>Gerenciamento de roteadores · consulta e ações sob demanda</p>
      </div>
      <span class="jdn-data-source"><i class="bi bi-database-check"></i> GENIEACS</span>
    </header>
    <section class="jdn-total-panel" aria-label="Resumo do inventario">
      <div class="jdn-total-top"><span><i class="bi bi-router"></i> TOTAL DE DISPOSITIVOS</span><i class="bi bi-hdd-network" aria-hidden="true"></i></div>
      <p>Inventário de equipamentos cadastrados no ACS</p>
      <div class="jdn-total-description">Os totais e estados dependem da consulta atual do GenieACS.</div>
    </section>

    <div class="card">

        <div class="card-header">

            <div class="d-flex justify-content-between align-items-center">

                <div>

                    <i class="bi bi-router"></i>
                    Lista de Equipamentos

                    <span id="device-stats-badges" style="margin-left: 10px;">

                        <span class="badge bg-secondary">
                            Total [0]
                        </span>

                        <span class="badge bg-success">
                            Online [0]
                        </span>

                        <span class="badge bg-danger">
                            Offline [0]
                        </span>

                    </span>

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


                <li class="nav-item" role="presentation">

                    <button
                        class="nav-link"
                        id="odp-tab"
                        data-bs-toggle="tab"
                        data-bs-target="#odp"
                        type="button"
                        role="tab"
                        onclick="filterByType('odp')"
                    >
                        <i class="bi bi-cube"></i>
                        ODP

                        <span
                            class="badge bg-primary ms-1"
                            id="count-odp"
                        >
                            0
                        </span>

                    </button>

                </li>


                <li class="nav-item" role="presentation">

                    <button
                        class="nav-link"
                        id="odc-tab"
                        data-bs-toggle="tab"
                        data-bs-target="#odc"
                        type="button"
                        role="tab"
                        onclick="filterByType('odc')"
                    >
                        <i class="bi bi-box"></i>
                        ODC

                        <span
                            class="badge bg-primary ms-1"
                            id="count-odc"
                        >
                            0
                        </span>

                    </button>

                </li>


                <li class="nav-item" role="presentation">

                    <button
                        class="nav-link"
                        id="olt-tab"
                        data-bs-toggle="tab"
                        data-bs-target="#olt"
                        type="button"
                        role="tab"
                        onclick="filterByType('olt')"
                    >
                        <i class="bi bi-broadcast-pin"></i>
                        OLT

                        <span
                            class="badge bg-primary ms-1"
                            id="count-olt"
                        >
                            0
                        </span>

                    </button>

                </li>


                <li class="nav-item" role="presentation">

                    <button
                        class="nav-link"
                        id="server-tab"
                        data-bs-toggle="tab"
                        data-bs-target="#server"
                        type="button"
                        role="tab"
                        onclick="filterByType('server')"
                    >
                        <i class="bi bi-server"></i>
                        Servidor

                        <span
                            class="badge bg-primary ms-1"
                            id="count-server"
                        >
                            0
                        </span>

                    </button>

                </li>

            </ul>


            <!-- Busca e paginação -->
            <div class="row mb-3">


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
                            <option value="0">Todos</option>

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


            <div class="jdn-list-heading">
                <div class="jdn-list-icon"><i class="bi bi-hdd-stack"></i></div>
                <div><h2>Lista de dispositivos</h2><p>Pesquise equipamentos e abra o gerenciamento individual</p></div>
            </div>
            <!-- Tabela -->
            <div
                id="devices-table-wrap"
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


</div>
    <?php
    include __DIR__ . '/views/devices/modals.php';
    ?>

<?php endif; ?>


<script>

// Configuração global
window.GENIEACS_CONFIGURED =
    <?php echo $genieacsConfigured ? 'true' : 'false'; ?>;

</script>


<link rel="stylesheet" href="/assets/css/jrc-inventory.css?v=1">
<link rel="stylesheet" href="/assets/css/jrc-devices-reference.css?v=1">
<script src="/assets/js/devices/devices-state.js"></script>
<script src="/assets/js/devices.js?v=20261005-ssid24"></script>


<?php
include __DIR__ . '/views/layouts/footer.php';
?>