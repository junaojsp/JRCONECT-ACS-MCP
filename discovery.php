<?php

declare(strict_types=1);

/*
 * =========================================================
 * JR CONECT ACS
 * DISCOVERY TR-069
 * =========================================================
 *
 * Arquivo:
 * /var/www/gacs/discovery.php
 *
 * Inventário:
 * IXC
 *
 * Estado TR-069:
 * GenieACS
 *
 * API:
 * /api/discovery-ixc-tr069.php
 *
 * =========================================================
 */

$pageTitle = 'Discovery TR-069';

require_once __DIR__ . '/config/config.php';

if (function_exists('requireLogin')) {
    requireLogin();
}

?>
<!DOCTYPE html>

<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        Discovery TR-069 | JR CONECT ACS
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <style>

        :root {
            --jr-bg: #071b2a;
            --jr-sidebar: #0a1f2f;
            --jr-panel: #102c3e;
            --jr-panel-2: #0d2637;
            --jr-border: #315267;
            --jr-primary: #11b8e5;
            --jr-text: #f2f7fa;
            --jr-muted: #9bb2c1;
            --jr-green: #28b77a;
            --jr-yellow: #ffc107;
            --jr-red: #dc3545;
            --jr-blue: #0dcaf0;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--jr-bg);
            color: var(--jr-text);
            font-family: Arial, Helvetica, sans-serif;
            font-size: 14px;
        }

        a {
            text-decoration: none;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 190px;
            background: var(--jr-sidebar);
            border-right: 1px solid var(--jr-border);
            z-index: 1000;
        }

        .sidebar-logo {
            height: 98px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            border-bottom: 1px solid var(--jr-border);
            font-weight: 700;
            font-size: 14px;
        }

        .sidebar-logo i {
            color: var(--jr-primary);
            font-size: 34px;
            margin-bottom: 6px;
        }

        .sidebar-menu {
            padding: 8px 0;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #d7e4eb;
            padding: 14px 16px;
            border-left: 3px solid transparent;
            transition: .15s;
        }

        .sidebar-menu a:hover {
            background: #12364b;
            color: #ffffff;
        }

        .sidebar-menu a.active {
            background: #118caf;
            color: #ffffff;
            border-left-color: #2bd5ff;
        }

        .main {
            margin-left: 190px;
            min-height: 100vh;
        }

        .topbar {
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 18px;
            border-bottom: 1px solid var(--jr-border);
            background: #071927;
        }

        .topbar h1 {
            margin: 0;
            font-size: 19px;
            font-weight: 700;
        }

        .content {
            padding: 20px 24px 40px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 18px;
        }

        .page-title {
            margin: 0;
            font-size: 21px;
            font-weight: 700;
        }

        .page-subtitle {
            margin-top: 5px;
            color: var(--jr-muted);
        }

        .summary-grid {
            display: grid;

            grid-template-columns:
                repeat(
                    7,
                    minmax(145px, 1fr)
                );

            gap: 12px;
            margin-bottom: 18px;
        }

        .summary-card {
            background: var(--jr-panel);
            border: 1px solid var(--jr-border);
            border-radius: 8px;
            padding: 16px;
        }

        .summary-label {
            font-size: 12px;
            text-transform: uppercase;
            color: var(--jr-muted);
            font-weight: 700;
            margin-bottom: 8px;
        }

        .summary-value {
            font-size: 28px;
            font-weight: 700;
        }

        .summary-value.blue {
            color: var(--jr-blue);
        }

        .summary-value.green {
            color: #56d7a0;
        }

        .summary-value.yellow {
            color: #ffcc43;
        }

        .summary-value.gray {
            color: #b4c4cd;
        }

        .summary-value.red {
            color: #ff6767;
        }

        .panel {
            background: var(--jr-panel);
            border: 1px solid var(--jr-border);
            border-radius: 8px;
            overflow: hidden;
        }

        .panel-header {
            padding: 14px 16px;
            border-bottom: 1px solid var(--jr-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .panel-title {
            font-weight: 700;
            font-size: 15px;
        }

        .filters {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            padding: 14px 16px;
            border-bottom: 1px solid var(--jr-border);
            background: var(--jr-panel-2);
        }

        .filter-button {
            border: 1px solid var(--jr-border);
            background: #173749;
            color: #e7f1f6;
            border-radius: 5px;
            padding: 7px 11px;
            font-size: 12px;
            cursor: pointer;
        }

        .filter-button:hover {
            background: #1e4a62;
        }

        .filter-button.active {
            background: var(--jr-primary);
            border-color: var(--jr-primary);
            color: #00131b;
            font-weight: 700;
        }

        .filter-button.signal-critical.active {
            background: #dc3545;
            border-color: #dc3545;
            color: #ffffff;
        }

        .filter-button.no-signal.active {
            background: #6c757d;
            border-color: #6c757d;
            color: #ffffff;
        }

        .sort-box {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-left: 4px;
        }

        .sort-box label {
            color: var(--jr-muted);
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .sort-box select {
            min-width: 210px;
            background: #071b2a;
            color: #ffffff;
            border: 1px solid var(--jr-border);
            border-radius: 5px;
            padding: 7px 9px;
            font-size: 12px;
        }

        .sort-box select:focus {
            outline: none;
            border-color: var(--jr-primary);
        }

        .search-box {
            margin-left: auto;
            display: flex;
            min-width: 330px;
        }

        .search-box input {
            background: #071b2a;
            border: 1px solid var(--jr-border);
            color: #ffffff;
            border-radius: 5px 0 0 5px;
            padding: 7px 10px;
            width: 100%;
        }

        .search-box input::placeholder {
            color: #718b9a;
        }

        .search-box button {
            border: 0;
            background: var(--jr-primary);
            padding: 0 13px;
            border-radius: 0 5px 5px 0;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        th {
            background: #0b2536;
            color: #67ddff;
            padding: 10px;
            border-bottom: 1px solid var(--jr-border);
            white-space: nowrap;
            font-size: 12px;
            text-transform: uppercase;
        }

        td {
            padding: 9px 10px;
            border-bottom: 1px solid #294759;
            vertical-align: middle;
            white-space: nowrap;
        }

        tbody tr:hover {
            background: #16384a;
        }

        .serial {
            font-family: monospace;
            font-weight: 700;
        }

        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 8px;
            border-radius: 5px;
            font-size: 11px;
            font-weight: 700;
        }

        .status-active {
            background: #16865d;
            color: #ffffff;
        }

        .status-stale {
            background: #ac7b04;
            color: #ffffff;
        }

        .status-notacs {
            background: #54656f;
            color: #ffffff;
        }

        .rx-good {
            color: #63e0a7;
            font-weight: 700;
        }

        .rx-warning {
            color: #ffd34d;
            font-weight: 700;
        }

        .rx-critical {
            color: #ff6767;
            font-weight: 700;
        }

        .rx-unavailable {
            color: #8198a5;
            font-style: italic;
        }

        .btn-open {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: #00141c;
            background: var(--jr-primary);
            border-radius: 4px;
            padding: 5px 8px;
            font-size: 11px;
            font-weight: 700;
        }

        .btn-open:hover {
            background: #59dbff;
            color: #00141c;
        }

        .pagination-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            background: var(--jr-panel-2);
            border-top: 1px solid var(--jr-border);
        }

        .pagination-buttons {
            display: flex;
            gap: 5px;
        }

        .pagination-buttons button {
            border: 1px solid var(--jr-border);
            background: #173749;
            color: #ffffff;
            border-radius: 4px;
            padding: 6px 10px;
        }

        .pagination-buttons button:disabled {
            opacity: .4;
        }

        .loading {
            padding: 45px;
            text-align: center;
            color: var(--jr-muted);
        }

        @media (max-width: 1600px) {

            .summary-grid {
                grid-template-columns:
                    repeat(
                        4,
                        1fr
                    );
            }
        }

        @media (max-width: 1200px) {

            .summary-grid {
                grid-template-columns:
                    repeat(
                        2,
                        1fr
                    );
            }

            .search-box {
                margin-left: 0;
            }
        }

        @media (max-width: 800px) {

            .sidebar {
                display: none;
            }

            .main {
                margin-left: 0;
            }

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .search-box {
                min-width: 100%;
            }

            .sort-box {
                width: 100%;
            }

            .sort-box select {
                flex: 1;
            }
        }

    </style>

<!-- JRC NOC SHARED THEME -->
<link rel="stylesheet" href="/assets/css/jrc-theme.css?v=1">
<script src="/assets/js/jrc-theme.js?v=1" defer></script>
<!-- /JRC NOC SHARED THEME -->
</head>


<body>


<div class="sidebar">

    <div class="sidebar-logo">

        <i class="bi bi-hdd-network"></i>

        JR CONECT ACS

    </div>


    <div class="sidebar-menu">

        <a href="/">

            <i class="bi bi-speedometer2"></i>

            Visão Geral

        </a>


        <a href="/devices.php">

            <i class="bi bi-router"></i>

            Equipamentos

        </a>


        <a href="/map.php">

            <i class="bi bi-diagram-3"></i>

            Mapa da Rede

        </a>


        <a
            href="/discovery.php"
            class="active"
        >

            <i class="bi bi-search"></i>

            Discovery TR-069

        </a>


        <a href="/configuration.php">

            <i class="bi bi-gear"></i>

            Configurações

        </a>


        <a href="/logout.php">

            <i class="bi bi-box-arrow-right"></i>

            Sair

        </a>

    </div>

</div>


<div class="main">


    <div class="topbar">

        <h1>
            Discovery TR-069
        </h1>


        <div>

            <i class="bi bi-person-circle"></i>

            admin

        </div>

    </div>


    <div class="content">


        <div class="page-header">

            <div>

                <h2 class="page-title">

                    <i class="bi bi-search"></i>

                    Discovery IXC × GenieACS

                </h2>


                <div class="page-subtitle">

                    IXC como inventário oficial e GenieACS como estado real de comunicação TR-069.

                </div>

            </div>


            <button
                type="button"
                class="btn btn-info"
                id="refreshButton"
                onclick="loadDiscovery()"
            >

                <i class="bi bi-arrow-clockwise"></i>

                Atualizar Discovery

            </button>

        </div>


        <div class="summary-grid">


            <div class="summary-card">

                <div class="summary-label">
                    ONUs no IXC
                </div>

                <div
                    class="summary-value blue"
                    id="summaryTotalIxc"
                >
                    -
                </div>

            </div>


            <div class="summary-card">

                <div class="summary-label">
                    No GenieACS
                </div>

                <div
                    class="summary-value blue"
                    id="summaryGenie"
                >
                    -
                </div>

            </div>


            <div class="summary-card">

                <div class="summary-label">
                    TR-069 Ativo
                </div>

                <div
                    class="summary-value green"
                    id="summaryActive"
                >
                    -
                </div>

            </div>


            <div class="summary-card">

                <div class="summary-label">
                    Sem comunicação recente
                </div>

                <div
                    class="summary-value yellow"
                    id="summaryStale"
                >
                    -
                </div>

            </div>


            <div class="summary-card">

                <div class="summary-label">
                    Não descobertos
                </div>

                <div
                    class="summary-value gray"
                    id="summaryNotAcs"
                >
                    -
                </div>

            </div>


            <div class="summary-card">

                <div class="summary-label">
                    Sinal crítico
                </div>

                <div
                    class="summary-value red"
                    id="summaryCritical"
                >
                    -
                </div>

            </div>


            <div class="summary-card">

                <div class="summary-label">
                    Sem leitura
                </div>

                <div
                    class="summary-value gray"
                    id="summaryNoSignal"
                >
                    -
                </div>

            </div>


        </div>


        <div class="panel">


            <div class="panel-header">

                <div class="panel-title">

                    <i class="bi bi-router"></i>

                    Equipamentos encontrados

                </div>


                <div
                    id="generatedAt"
                    class="text-secondary"
                >
                </div>

            </div>


            <div class="filters">


                <button
                    type="button"
                    class="filter-button active"
                    onclick="setMainFilter('ALL', this)"
                >

                    Todos

                </button>


                <button
                    type="button"
                    class="filter-button"
                    onclick="setMainFilter('TR069_ACTIVE', this)"
                >

                    🟢 TR-069 Ativos

                </button>


                <button
                    type="button"
                    class="filter-button"
                    onclick="setMainFilter('TR069_STALE', this)"
                >

                    🟡 Sem Inform recente

                </button>


                <button
                    type="button"
                    class="filter-button"
                    onclick="setMainFilter('NOT_IN_ACS', this)"
                >

                    ⚪ Não descobertos

                </button>


                <button
                    type="button"
                    class="filter-button signal-critical"
                    onclick="setMainFilter('SIGNAL_CRITICAL', this)"
                >

                    🔴 Sinal crítico

                </button>


                <button
                    type="button"
                    class="filter-button no-signal"
                    onclick="setMainFilter('NO_SIGNAL', this)"
                >

                    ⚪ Sem leitura

                </button>


                <div class="sort-box">

                    <label for="sortSelect">

                        <i class="bi bi-sort-down"></i>

                        Ordenar:

                    </label>


                    <select
                        id="sortSelect"
                        onchange="changeSort()"
                    >

                        <option value="DEFAULT">
                            Padrão
                        </option>


                        <option value="RX_BEST">
                            Melhor sinal → pior
                        </option>


                        <option value="RX_WORST">
                            Pior sinal → melhor
                        </option>


                        <option value="CLIENT_ASC">
                            Cliente A → Z
                        </option>


                        <option value="CLIENT_DESC">
                            Cliente Z → A
                        </option>


                        <option value="PON_ASC">
                            PON crescente
                        </option>


                        <option value="PON_DESC">
                            PON decrescente
                        </option>


                        <option value="SIGNAL_NEWEST">
                            Último sinal mais recente
                        </option>


                        <option value="SIGNAL_OLDEST">
                            Último sinal mais antigo
                        </option>


                        <option value="TR069_STATUS">
                            TR-069 ativos primeiro
                        </option>

                    </select>

                </div>


                <div class="search-box">

                    <input
                        type="text"
                        id="searchInput"
                        placeholder="Serial, cliente, login, contrato, PON, modelo..."
                        autocomplete="off"
                    >


                    <button
                        type="button"
                        onclick="applyLocalFilter()"
                    >

                        <i class="bi bi-search"></i>

                    </button>

                </div>

            </div>


            <div
                id="loadingContainer"
                class="loading"
            >

                <div class="spinner-border text-info"></div>

                <div class="mt-3">

                    Consultando IXC e GenieACS...

                </div>

            </div>


            <div
                class="table-wrap"
                id="tableContainer"
                style="display:none;"
            >

                <table>

                    <thead>

                        <tr>

                            <th>Status</th>
                            <th>Serial / MAC</th>
                            <th>Cliente</th>
                            <th>Modelo</th>
                            <th>PON</th>
                            <th>ONU</th>
                            <th>RX</th>
                            <th>TX</th>
                            <th>Último sinal</th>
                            <th>Último Inform</th>
                            <th>Ação</th>

                        </tr>

                    </thead>


                    <tbody id="discoveryTableBody">
                    </tbody>

                </table>

            </div>


            <div
                class="pagination-bar"
                id="paginationBar"
                style="display:none;"
            >

                <div id="paginationInfo">
                </div>


                <div class="pagination-buttons">

                    <button
                        id="previousButton"
                        onclick="previousPage()"
                    >

                        <i class="bi bi-chevron-left"></i>

                        Anterior

                    </button>


                    <button
                        id="nextButton"
                        onclick="nextPage()"
                    >

                        Próxima

                        <i class="bi bi-chevron-right"></i>

                    </button>

                </div>

            </div>


        </div>

    </div>

</div>


<script>


const API_URL =
    '/api/discovery-ixc-tr069.php';


let allDevices =
    [];


let filteredDevices =
    [];


let currentMainFilter =
    'ALL';


let currentSort =
    'DEFAULT';


let currentPage =
    1;


const pageSize =
    50;


const RX_WARNING =
    -25;


const RX_CRITICAL =
    -28;


/* =========================================================
   LOAD
   ========================================================= */

async function loadDiscovery() {

    const button =
        document.getElementById(
            'refreshButton'
        );


    const loading =
        document.getElementById(
            'loadingContainer'
        );


    button.disabled =
        true;


    button.innerHTML =
        '<span class="spinner-border spinner-border-sm"></span> Atualizando...';


    loading.style.display =
        'block';


    loading.innerHTML =
        `

            <div class="spinner-border text-info"></div>

            <div class="mt-3">

                Consultando IXC e GenieACS...

            </div>
        `;


    document
        .getElementById(
            'tableContainer'
        )
        .style.display =
        'none';


    document
        .getElementById(
            'paginationBar'
        )
        .style.display =
        'none';


    try {

        const response =
            await fetch(
                API_URL,
                {
                    cache:
                        'no-store'
                }
            );


        const data =
            await response.json();


        if (
            !response.ok ||
            !data.success
        ) {

            throw new Error(
                data.message ||
                'Falha ao executar Discovery.'
            );
        }


        setText(
            'summaryTotalIxc',
            formatNumber(
                data.summary?.total_ixc_fiber
            )
        );


        setText(
            'summaryGenie',
            formatNumber(
                data.summary?.total_genieacs
            )
        );


        setText(
            'summaryActive',
            formatNumber(
                data.summary?.tr069_active
            )
        );


        setText(
            'summaryStale',
            formatNumber(
                data.summary?.tr069_stale
            )
        );


        setText(
            'summaryNotAcs',
            formatNumber(
                data.summary?.not_in_acs
            )
        );


        setText(
            'generatedAt',
            'Atualizado: ' +
            formatDateTime(
                data.generated_at
            )
        );


        allDevices =
            Array.isArray(
                data.devices
            )
                ? data.devices
                : [];


        updateSignalSummary();


        currentPage =
            1;


        applyLocalFilter();


    } catch (error) {

        console.error(
            '[DISCOVERY]',
            error
        );


        loading.innerHTML =
            `

                <div class="alert alert-danger m-3">

                    <i class="bi bi-exclamation-triangle"></i>

                    ${escapeHtml(error.message)}

                </div>
            `;


    } finally {

        button.disabled =
            false;


        button.innerHTML =
            '<i class="bi bi-arrow-clockwise"></i> Atualizar Discovery';
    }
}


/* =========================================================
   SAFE TEXT
   ========================================================= */

function setText(
    id,
    value
) {

    const element =
        document.getElementById(
            id
        );


    if (!element) {

        console.warn(
            '[DISCOVERY] Elemento não encontrado:',
            id
        );

        return;
    }


    element.textContent =
        value;
}


/* =========================================================
   SIGNAL SUMMARY
   ========================================================= */

function updateSignalSummary() {

    let critical =
        0;


    let noSignal =
        0;


    allDevices.forEach(
        device => {

            const rx =
                getSignalNumber(
                    device?.ixc?.rx_power
                );


            if (
                rx === null
            ) {

                noSignal++;

                return;
            }


            if (
                rx <=
                RX_CRITICAL
            ) {

                critical++;
            }
        }
    );


    setText(
        'summaryCritical',
        formatNumber(
            critical
        )
    );


    setText(
        'summaryNoSignal',
        formatNumber(
            noSignal
        )
    );
}


/* =========================================================
   FILTER BUTTON
   ========================================================= */

function setMainFilter(
    filter,
    button
) {

    currentMainFilter =
        filter;


    document
        .querySelectorAll(
            '.filter-button'
        )
        .forEach(
            element => {

                element
                    .classList
                    .remove(
                        'active'
                    );
            }
        );


    if (button) {

        button
            .classList
            .add(
                'active'
            );
    }


    currentPage =
        1;


    applyLocalFilter();
}


/* =========================================================
   SORT
   ========================================================= */

function changeSort() {

    const select =
        document.getElementById(
            'sortSelect'
        );


    currentSort =
        select
            ? select.value
            : 'DEFAULT';


    currentPage =
        1;


    applyLocalFilter();
}


/* =========================================================
   SIGNAL
   ========================================================= */

function getSignalNumber(
    value
) {

    if (
        value === null ||
        value === undefined ||
        value === ''
    ) {

        return null;
    }


    const numeric =
        Number(
            String(
                value
            )
            .replace(
                ',',
                '.'
            )
        );


    if (
        !Number.isFinite(
            numeric
        )
    ) {

        return null;
    }


    /*
     * 0,00 = sem leitura
     */

    if (
        Math.abs(
            numeric
        ) < 0.001
    ) {

        return null;
    }


    return numeric;
}


function isCriticalSignal(
    device
) {

    const rx =
        getSignalNumber(
            device?.ixc?.rx_power
        );


    if (
        rx === null
    ) {

        return false;
    }


    return rx <=
        RX_CRITICAL;
}


function isNoSignal(
    device
) {

    const rx =
        getSignalNumber(
            device?.ixc?.rx_power
        );


    return rx === null;
}


/* =========================================================
   APPLY FILTER
   ========================================================= */

function applyLocalFilter() {

    const searchInput =
        document.getElementById(
            'searchInput'
        );


    const search =
        searchInput
            ? searchInput.value
                .trim()
                .toUpperCase()
            : '';


    filteredDevices =
        allDevices.filter(
            device => {

                /* =====================================
                   SINAL CRÍTICO
                   ===================================== */

                if (
                    currentMainFilter ===
                    'SIGNAL_CRITICAL'
                ) {

                    if (
                        !isCriticalSignal(
                            device
                        )
                    ) {

                        return false;
                    }


                /* =====================================
                   SEM LEITURA
                   ===================================== */

                } else if (
                    currentMainFilter ===
                    'NO_SIGNAL'
                ) {

                    if (
                        !isNoSignal(
                            device
                        )
                    ) {

                        return false;
                    }


                /* =====================================
                   STATUS ACS
                   ===================================== */

                } else if (
                    currentMainFilter !==
                    'ALL'
                ) {

                    if (
                        device.status !==
                        currentMainFilter
                    ) {

                        return false;
                    }
                }


                /* =====================================
                   SEARCH
                   ===================================== */

                if (!search) {

                    return true;
                }


                const ixc =
                    device.ixc ||
                    {};


                const acs =
                    device.acs ||
                    {};


                const text =
                    [

                        device.serial,

                        ixc.nome,

                        ixc.id_login,

                        ixc.id_contrato,

                        ixc.pon_id,

                        ixc.onu_numero,

                        acs.manufacturer,

                        acs.product_class

                    ]

                    .map(
                        value =>
                            value == null
                                ? ''
                                : String(value)
                    )

                    .join(' ')

                    .toUpperCase();


                return text.includes(
                    search
                );
            }
        );


    sortDevices();


    renderCurrentPage();
}


/* =========================================================
   SORT DEVICES
   ========================================================= */

function sortDevices() {

    switch (
        currentSort
    ) {

        case 'RX_BEST':

            filteredDevices.sort(
                compareRxBest
            );

            break;


        case 'RX_WORST':

            filteredDevices.sort(
                compareRxWorst
            );

            break;


        case 'CLIENT_ASC':

            filteredDevices.sort(
                (
                    a,
                    b
                ) =>
                    getClientName(a)
                        .localeCompare(
                            getClientName(b),
                            'pt-BR',
                            {
                                sensitivity:
                                    'base'
                            }
                        )
            );

            break;


        case 'CLIENT_DESC':

            filteredDevices.sort(
                (
                    a,
                    b
                ) =>
                    getClientName(b)
                        .localeCompare(
                            getClientName(a),
                            'pt-BR',
                            {
                                sensitivity:
                                    'base'
                            }
                        )
            );

            break;


        case 'PON_ASC':

            filteredDevices.sort(
                (
                    a,
                    b
                ) =>
                    comparePon(
                        a?.ixc?.pon_id,
                        b?.ixc?.pon_id
                    )
            );

            break;


        case 'PON_DESC':

            filteredDevices.sort(
                (
                    a,
                    b
                ) =>
                    comparePon(
                        b?.ixc?.pon_id,
                        a?.ixc?.pon_id
                    )
            );

            break;


        case 'SIGNAL_NEWEST':

            filteredDevices.sort(
                compareSignalNewest
            );

            break;


        case 'SIGNAL_OLDEST':

            filteredDevices.sort(
                compareSignalOldest
            );

            break;


        case 'TR069_STATUS':

            filteredDevices.sort(
                compareTr069Status
            );

            break;


        default:

            filteredDevices.sort(
                compareDefault
            );

            break;
    }
}


/* =========================================================
   RX SORT
   ========================================================= */

function compareRxBest(
    a,
    b
) {

    const rxA =
        getSignalNumber(
            a?.ixc?.rx_power
        );


    const rxB =
        getSignalNumber(
            b?.ixc?.rx_power
        );


    /*
     * Sem leitura sempre no final.
     */

    if (
        rxA === null &&
        rxB === null
    ) {

        return compareSerial(
            a,
            b
        );
    }


    if (
        rxA === null
    ) {

        return 1;
    }


    if (
        rxB === null
    ) {

        return -1;
    }


    if (
        rxA !== rxB
    ) {

        return rxB -
            rxA;
    }


    return compareSerial(
        a,
        b
    );
}


function compareRxWorst(
    a,
    b
) {

    const rxA =
        getSignalNumber(
            a?.ixc?.rx_power
        );


    const rxB =
        getSignalNumber(
            b?.ixc?.rx_power
        );


    /*
     * Sem leitura sempre no final.
     */

    if (
        rxA === null &&
        rxB === null
    ) {

        return compareSerial(
            a,
            b
        );
    }


    if (
        rxA === null
    ) {

        return 1;
    }


    if (
        rxB === null
    ) {

        return -1;
    }


    if (
        rxA !== rxB
    ) {

        return rxA -
            rxB;
    }


    return compareSerial(
        a,
        b
    );
}


/* =========================================================
   CLIENT
   ========================================================= */

function getClientName(
    device
) {

    return String(
        device?.ixc?.nome ||
        device?.serial ||
        ''
    );
}


/* =========================================================
   SERIAL
   ========================================================= */

function compareSerial(
    a,
    b
) {

    return String(
        a?.serial ||
        ''
    ).localeCompare(
        String(
            b?.serial ||
            ''
        ),
        'pt-BR',
        {
            numeric: true,
            sensitivity: 'base'
        }
    );
}


/* =========================================================
   PON
   ========================================================= */

function parsePon(
    value
) {

    if (
        value === null ||
        value === undefined ||
        value === ''
    ) {

        return null;
    }


    const numbers =
        String(
            value
        )
        .match(
            /\d+/g
        );


    if (
        !numbers ||
        numbers.length === 0
    ) {

        return null;
    }


    return numbers.map(
        number =>
            parseInt(
                number,
                10
            )
    );
}


function comparePon(
    valueA,
    valueB
) {

    const a =
        parsePon(
            valueA
        );


    const b =
        parsePon(
            valueB
        );


    if (
        a === null &&
        b === null
    ) {

        return 0;
    }


    if (
        a === null
    ) {

        return 1;
    }


    if (
        b === null
    ) {

        return -1;
    }


    const maxLength =
        Math.max(
            a.length,
            b.length
        );


    for (
        let i = 0;
        i < maxLength;
        i++
    ) {

        const value1 =
            a[i] ??
            -1;


        const value2 =
            b[i] ??
            -1;


        if (
            value1 !==
            value2
        ) {

            return value1 -
                value2;
        }
    }


    return 0;
}


/* =========================================================
   SIGNAL DATE
   ========================================================= */

function getSignalTimestamp(
    device
) {

    const value =
        device?.ixc?.signal_updated_at;


    if (!value) {

        return null;
    }


    const match =
        String(
            value
        )
        .trim()
        .match(
            /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/
        );


    if (!match) {

        return null;
    }


    const timestamp =
        new Date(
            Number(match[1]),
            Number(match[2]) - 1,
            Number(match[3]),
            Number(match[4]),
            Number(match[5]),
            Number(match[6])
        )
        .getTime();


    return Number.isFinite(
        timestamp
    )
        ? timestamp
        : null;
}


function compareSignalNewest(
    a,
    b
) {

    const dateA =
        getSignalTimestamp(
            a
        );


    const dateB =
        getSignalTimestamp(
            b
        );


    if (
        dateA === null &&
        dateB === null
    ) {

        return compareSerial(
            a,
            b
        );
    }


    if (
        dateA === null
    ) {

        return 1;
    }


    if (
        dateB === null
    ) {

        return -1;
    }


    return dateB -
        dateA;
}


function compareSignalOldest(
    a,
    b
) {

    const dateA =
        getSignalTimestamp(
            a
        );


    const dateB =
        getSignalTimestamp(
            b
        );


    if (
        dateA === null &&
        dateB === null
    ) {

        return compareSerial(
            a,
            b
        );
    }


    if (
        dateA === null
    ) {

        return 1;
    }


    if (
        dateB === null
    ) {

        return -1;
    }


    return dateA -
        dateB;
}


/* =========================================================
   STATUS ORDER
   ========================================================= */

function getStatusOrder(
    status
) {

    const order =
        {

            TR069_ACTIVE:
                1,

            TR069_STALE:
                2,

            NOT_IN_ACS:
                3
        };


    return order[
        status
    ] ??
    99;
}


function compareTr069Status(
    a,
    b
) {

    const valueA =
        getStatusOrder(
            a?.status
        );


    const valueB =
        getStatusOrder(
            b?.status
        );


    if (
        valueA !==
        valueB
    ) {

        return valueA -
            valueB;
    }


    return compareSerial(
        a,
        b
    );
}


function compareDefault(
    a,
    b
) {

    return compareTr069Status(
        a,
        b
    );
}


/* =========================================================
   RENDER
   ========================================================= */

function renderCurrentPage() {

    const body =
        document.getElementById(
            'discoveryTableBody'
        );


    if (!body) {

        return;
    }


    body.innerHTML =
        '';


    const total =
        filteredDevices.length;


    const totalPages =
        Math.max(
            1,
            Math.ceil(
                total /
                pageSize
            )
        );


    if (
        currentPage >
        totalPages
    ) {

        currentPage =
            totalPages;
    }


    const start =
        (
            currentPage -
            1
        )
        *
        pageSize;


    const end =
        Math.min(
            start +
            pageSize,
            total
        );


    const pageDevices =
        filteredDevices.slice(
            start,
            end
        );


    if (
        pageDevices.length ===
        0
    ) {

        body.innerHTML =
            `

                <tr>

                    <td
                        colspan="11"
                        class="text-center p-4 text-secondary"
                    >

                        Nenhum equipamento encontrado.

                    </td>

                </tr>
            `;

    } else {

        pageDevices.forEach(
            device => {

                body.insertAdjacentHTML(
                    'beforeend',
                    renderDeviceRow(
                        device
                    )
                );
            }
        );
    }


    const loading =
        document.getElementById(
            'loadingContainer'
        );


    const table =
        document.getElementById(
            'tableContainer'
        );


    const pagination =
        document.getElementById(
            'paginationBar'
        );


    if (loading) {
        loading.style.display =
            'none';
    }


    if (table) {
        table.style.display =
            'block';
    }


    if (pagination) {
        pagination.style.display =
            'flex';
    }


    setText(
        'paginationInfo',

        total > 0

            ? `Mostrando ${start + 1}–${end} de ${total}`

            : '0 equipamentos'
    );


    const previous =
        document.getElementById(
            'previousButton'
        );


    const next =
        document.getElementById(
            'nextButton'
        );


    if (previous) {

        previous.disabled =
            currentPage <= 1;
    }


    if (next) {

        next.disabled =
            currentPage >=
            totalPages;
    }
}


/* =========================================================
   ROW
   ========================================================= */

function renderDeviceRow(
    device
) {

    const ixc =
        device.ixc ||
        {};


    const acs =
        device.acs ||
        {};


    const model =
        acs.product_class ||
        ixc.onu_tipo ||
        '-';


    const manufacturer =
        acs.manufacturer ||
        '';


    const modelDisplay =
        manufacturer

            ? manufacturer +
                ' ' +
                model

            : model;


    const action =

        acs.registered &&
        acs.device_id

            ? `

                <a
                    class="btn-open"
                    href="/device-detail.php?id=${encodeURIComponent(acs.device_id)}"
                >

                    <i class="bi bi-box-arrow-up-right"></i>

                    Abrir

                </a>
            `

            : `

                <span class="text-secondary">
                    -
                </span>
            `;


    return `

        <tr>

            <td>
                ${renderStatus(device.status)}
            </td>


            <td>

                <span class="serial">

                    ${escapeHtml(device.serial)}

                </span>

            </td>


            <td>

                ${escapeHtml(ixc.nome || '-')}

                ${
                    ixc.id_login

                        ? `

                            <div class="small text-secondary">

                                Login:
                                ${escapeHtml(ixc.id_login)}

                            </div>
                        `

                        : ''
                }

            </td>


            <td>
                ${escapeHtml(modelDisplay)}
            </td>


            <td>
                ${escapeHtml(ixc.pon_id || '-')}
            </td>


            <td>
                ${escapeHtml(ixc.onu_numero || '-')}
            </td>


            <td>
                ${renderRx(ixc.rx_power)}
            </td>


            <td>
                ${renderTx(ixc.tx_power)}
            </td>


            <td>

                ${formatIxcDate(
                    ixc.signal_updated_at
                )}

            </td>


            <td>

                ${
                    escapeHtml(
                        acs.last_inform ||
                        '-'
                    )
                }

                ${
                    acs.minutes_since_inform != null

                        ? `

                            <div class="small text-secondary">

                                ${formatMinutesAgo(
                                    acs.minutes_since_inform
                                )}

                            </div>
                        `

                        : ''
                }

            </td>


            <td>
                ${action}
            </td>

        </tr>
    `;
}


/* =========================================================
   STATUS
   ========================================================= */

function renderStatus(
    status
) {

    switch (status) {

        case 'TR069_ACTIVE':

            return `

                <span class="badge-status status-active">

                    <i class="bi bi-check-circle-fill"></i>

                    TR-069 Ativo

                </span>
            `;


        case 'TR069_STALE':

            return `

                <span class="badge-status status-stale">

                    <i class="bi bi-exclamation-triangle-fill"></i>

                    Sem Inform

                </span>
            `;


        default:

            return `

                <span class="badge-status status-notacs">

                    <i class="bi bi-circle"></i>

                    Não no ACS

                </span>
            `;
    }
}


/* =========================================================
   RX
   ========================================================= */

function renderRx(
    value
) {

    const numeric =
        getSignalNumber(
            value
        );


    if (
        numeric === null
    ) {

        return `

            <span class="rx-unavailable">

                Sem leitura

            </span>
        `;
    }


    let css =
        'rx-good';


    let icon =
        '';


    if (
        numeric <=
        RX_CRITICAL
    ) {

        css =
            'rx-critical';


        icon =
            '<i class="bi bi-exclamation-triangle-fill me-1"></i>';


    } else if (
        numeric <=
        RX_WARNING
    ) {

        css =
            'rx-warning';


        icon =
            '<i class="bi bi-exclamation-circle-fill me-1"></i>';
    }


    return `

        <span class="${css}">

            ${icon}

            ${
                numeric
                    .toFixed(2)
                    .replace(
                        '.',
                        ','
                    )
            }

            dBm

        </span>
    `;
}


/* =========================================================
   TX
   ========================================================= */

function renderTx(
    value
) {

    const numeric =
        getSignalNumber(
            value
        );


    if (
        numeric === null
    ) {

        return `

            <span class="rx-unavailable">

                Sem leitura

            </span>
        `;
    }


    return (

        numeric
            .toFixed(2)
            .replace(
                '.',
                ','
            )

        +

        ' dBm'
    );
}


/* =========================================================
   DATE
   ========================================================= */

function formatIxcDate(
    value
) {

    if (!value) {

        return '-';
    }


    const text =
        String(
            value
        );


    const match =
        text.match(
            /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/
        );


    if (!match) {

        return escapeHtml(
            text
        );
    }


    return (

        match[3] +

        '/' +

        match[2] +

        '/' +

        match[1] +

        ' ' +

        match[4] +

        ':' +

        match[5] +

        ':' +

        match[6]
    );
}


function formatDateTime(
    value
) {

    return formatIxcDate(
        value
    );
}


/* =========================================================
   TIME AGO
   ========================================================= */

function formatMinutesAgo(
    minutes
) {

    minutes =
        Number(
            minutes
        );


    if (
        !Number.isFinite(
            minutes
        )
    ) {

        return '';
    }


    if (
        minutes <
        1
    ) {

        return 'agora';
    }


    if (
        minutes <
        60
    ) {

        return (
            minutes +
            ' min atrás'
        );
    }


    const hours =
        Math.floor(
            minutes /
            60
        );


    if (
        hours <
        24
    ) {

        return (
            hours +
            ' h atrás'
        );
    }


    const days =
        Math.floor(
            hours /
            24
        );


    return (
        days +
        ' dias atrás'
    );
}


/* =========================================================
   PAGINATION
   ========================================================= */

function previousPage() {

    if (
        currentPage >
        1
    ) {

        currentPage--;

        renderCurrentPage();
    }
}


function nextPage() {

    const totalPages =
        Math.ceil(
            filteredDevices.length /
            pageSize
        );


    if (
        currentPage <
        totalPages
    ) {

        currentPage++;

        renderCurrentPage();
    }
}


/* =========================================================
   NUMBER
   ========================================================= */

function formatNumber(
    value
) {

    const number =
        Number(
            value
        );


    if (
        !Number.isFinite(
            number
        )
    ) {

        return '0';
    }


    return number.toLocaleString(
        'pt-BR'
    );
}


/* =========================================================
   ESCAPE
   ========================================================= */

function escapeHtml(
    value
) {

    return String(
        value ??
        ''
    )

        .replaceAll(
            '&',
            '&amp;'
        )

        .replaceAll(
            '<',
            '&lt;'
        )

        .replaceAll(
            '>',
            '&gt;'
        )

        .replaceAll(
            '"',
            '&quot;'
        )

        .replaceAll(
            "'",
            '&#039;'
        );
}


/* =========================================================
   SEARCH
   ========================================================= */

const searchInput =
    document.getElementById(
        'searchInput'
    );


if (searchInput) {

    searchInput.addEventListener(
        'input',
        function() {

            currentPage =
                1;


            applyLocalFilter();
        }
    );
}


/* =========================================================
   START
   ========================================================= */

document.addEventListener(
    'DOMContentLoaded',
    function() {

        loadDiscovery();
    }
);

</script>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>


</body>

</html>