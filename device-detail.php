<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$deviceAba = isset($_GET['aba']) ? (string) $_GET['aba'] : '';
$pageTitle = $deviceAba === 'firmware' ? 'Firmware' : 'Dispositivos';
$currentPage = $deviceAba === 'firmware' ? 'firmware' : 'devices';

$genieacsConfigured = isGenieACSConfigured();

$deviceId = isset($_GET['id']) ? $_GET['id'] : null;

if (!$deviceId) {
    header('Location: /devices.php');
    exit;
}

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

    <!-- =====================================================
         CABECALHO OPERACIONAL DO EQUIPAMENTO
         ===================================================== -->

    <link rel="stylesheet" href="/assets/css/device-readability.css?v=20261005-6">
    <script src="/assets/js/device-readability.js?v=20261005-3"></script>
    <div class="acs-device-shell fb-device">

        <a href="/devices.php" class="fb-device-back" title="Voltar para equipamentos">
            <i class="bi bi-arrow-left"></i>
            Dispositivos
        </a>

        <div class="acs-device-toolbar">

            <div class="acs-device-toolbar-left">
                <div class="acs-device-avatar">
                    <i class="bi bi-router"></i>
                </div>

                <div class="acs-device-heading">
                    <div class="acs-device-title-row">
                        <h3 id="device-model-title">Carregando...</h3>
                        <span id="device-status-header" class="acs-header-status">...</span>
                    </div>

                    <div class="acs-device-subtitle">
                        <span id="device-id-badge">Carregando...</span>
                        <span class="acs-separator">•</span>
                        <span id="device-ip-header">IP não disponível</span>
                        <span id="device-tags-badge"></span>
                    </div>
                </div>
            </div>

            <div class="acs-device-toolbar-actions">
                <label class="acs-text-size-control fb-header-extra" for="acs-text-size">
                    <span>Tamanho do texto</span>
                    <select id="acs-text-size">
                        <option value="standard">Padrão</option>
                        <option value="large">Grande</option>
                    </select>
                </label>
                <button class="acs-toolbar-action fb-header-extra" type="button" onclick="runDeviceAISummary()" title="Resumo técnico">
                    <i class="bi bi-clipboard2-pulse"></i>
                    <span>Resumo</span>
                </button>

                <button class="acs-toolbar-action fb-primary fb-header-extra" type="button" onclick="openDeviceAIDrawer()" title="Abrir o assistente">
                    <i class="bi bi-stars"></i>
                    <span>Assistente</span>
                </button>

                <button class="acs-toolbar-action fb-header-extra" type="button" onclick="summonDevice()" title="Solicitar comunicação">
                    <i class="bi bi-lightning-charge"></i>
                    <span>Comunicar</span>
                </button>

                <button class="acs-toolbar-action fb-header-extra" type="button" onclick="startONUSpeedtest()" id="onu-speedtest-btn" title="Teste de velocidade">
                    <i class="bi bi-speedometer2"></i>
                    <span>Speedtest</span>
                </button>

                <button class="acs-toolbar-action fb-header-extra" type="button" onclick="showAddTagModal()" title="Adicionar etiqueta">
                    <i class="bi bi-tag"></i>
                    <span>Etiqueta</span>
                </button>

                <button class="acs-toolbar-action fb-header-extra" type="button" onclick="openWebManagement()" title="Abrir gerenciamento web do equipamento">
                    <i class="bi bi-box-arrow-up-right"></i>
                    <span>Web</span>
                </button>

                <button class="acs-toolbar-action acs-toolbar-icon" type="button" onclick="loadDeviceDetail()" title="Atualizar">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>

                <div class="dropdown fb-header-extra">
                    <button class="acs-toolbar-action acs-toolbar-icon" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Mais ações">
                        <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <button class="dropdown-item" type="button" onclick="showRemoveTagModal()">
                                <i class="bi bi-tag-fill me-2"></i>Remover etiqueta
                            </button>
                        </li>
                    </ul>
                </div>
            </div>

        </div>

        <section id="acs-customer-strip" class="acs-customer-strip" hidden aria-live="polite">
            <div class="acs-customer-identity">
                <div class="acs-customer-avatar"><i class="bi bi-person-vcard"></i></div>
                <div>
                    <span>CLIENTE IXC</span>
                    <strong id="acs-customer-name">Consultando...</strong>
                    <small id="acs-customer-address">Cadastro vinculado ao equipamento</small>
                </div>
            </div>

            <div class="acs-customer-facts">
                <div><span><i class="bi bi-speedometer2"></i> Plano</span><strong id="acs-customer-plan">—</strong></div>
                <div><span><i class="bi bi-file-earmark-text"></i> Contrato</span><strong id="acs-customer-contract">—</strong></div>
                <div><span><i class="bi bi-person-lock"></i> Login PPPoE</span><strong id="acs-customer-login">—</strong></div>
                <div><span><i class="bi bi-telephone"></i> Telefone</span><strong id="acs-customer-phone">—</strong></div>
            </div>

            <div class="acs-customer-statuses">
                <div>
                    <span>Contrato</span>
                    <strong id="acs-customer-contract-status" class="acs-customer-pill neutral">Consultando</strong>
                </div>
                <div>
                    <span>Financeiro</span>
                    <strong id="acs-customer-financial" class="acs-customer-pill neutral">Consultando</strong>
                </div>
                <small id="acs-customer-source">IXC</small>
            </div>
        </section>

        <div class="acs-device-tabs-wrap">

            <div
                id="loading-spinner"
                class="text-center"
            >
                <div class="spinner"></div>
            </div>

            <ul
                class="nav nav-tabs"
                id="deviceTabs"
                role="tablist"
                style="display:none;"
            >
                <li class="nav-item" role="presentation" style="order:1">
                    <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab">Visão geral</button>
                </li>
                <li class="nav-item" role="presentation" style="order:2">
                    <button class="nav-link" id="diagnostics-tab" data-bs-toggle="tab" data-bs-target="#diagnostics" type="button" role="tab" aria-controls="diagnostics" aria-selected="false">Ações</button>
                </li>
                <li class="nav-item" role="presentation" style="order:3">
                    <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab">Histórico</button>
                </li>
                <li class="nav-item" role="presentation" style="order:4">
                    <button class="nav-link" id="optical-tab" data-bs-toggle="tab" data-bs-target="#optical" type="button" role="tab">Óptico</button>
                </li>
                <li class="nav-item" role="presentation" style="order:5">
                    <button class="nav-link" id="wifi-tab" data-bs-toggle="tab" data-bs-target="#wifi" type="button" role="tab">Wi-Fi</button>
                </li>
                <li class="nav-item" role="presentation" style="order:6">
                    <button class="nav-link" id="wan-tab" data-bs-toggle="tab" data-bs-target="#wan" type="button" role="tab">
                        WAN / PPPoE
                        <span id="wan-count-badge" class="badge bg-primary ms-1">0</span>
                    </button>
                </li>
                <li class="nav-item fb-nav-retired" role="presentation">
                    <button class="nav-link" id="firmware-tab" data-bs-toggle="tab" data-bs-target="#firmware" type="button" role="tab">Firmware</button>
                </li>
                <li class="nav-item" role="presentation" style="order:8">
                    <button class="nav-link" id="voice-tab" data-bs-toggle="tab" data-bs-target="#voice" type="button" role="tab">Voz</button>
                </li>
                <li class="nav-item" role="presentation" style="order:9">
                    <button class="nav-link" id="devices-tab" data-bs-toggle="tab" data-bs-target="#devices" type="button" role="tab">
                        Rede / Hosts
                        <span id="devices-count-badge" class="badge bg-primary ms-1">0</span>
                    </button>
                </li>
                <li class="nav-item fb-nav-retired" role="presentation">
                    <button class="nav-link" id="dhcp-tab" data-bs-toggle="tab" data-bs-target="#dhcp" type="button" role="tab">DHCP</button>
                </li>
                <li class="nav-item fb-nav-retired" role="presentation">
                    <button class="nav-link" id="monitoring-tab" data-bs-toggle="tab" data-bs-target="#monitoring" type="button" role="tab">Monitoramento</button>
                </li>
            </ul>

            <div class="fb-source-line" id="fb-source-line">
                <span class="is-on">Coletados</span>
                <span id="fb-discovery-chip">Descoberta completa</span>
            </div>


            <!-- =================================================
                 CONTEUDO DAS ABAS
                 ================================================= -->

            <div
                class="tab-content mt-3"
                id="deviceTabContent"
                style="display:none;"
            >

                <div
                    class="tab-pane fade show active"
                    id="overview"
                    role="tabpanel"
                >
                    <div id="overview-content"></div>
                </div>


                


                <div
                    class="tab-pane fade"
                    id="wan"
                    role="tabpanel"
                >
                    <div id="wan-content"></div>
                </div>


                <div
                    class="tab-pane fade"
                    id="dhcp"
                    role="tabpanel"
                >
                    <div id="dhcp-content"></div>
                </div>


                <div
                    class="tab-pane fade"
                    id="firmware"
                    role="tabpanel"
                >
                    <div id="firmware-content"></div>
                </div>


                <div
                    class="tab-pane fade"
                    id="devices"
                    role="tabpanel"
                >
                    <div id="devices-content"></div>
                </div>

                <div class="tab-pane fade" id="history" role="tabpanel" aria-labelledby="history-tab">
                    <div id="history-content"></div>
                </div>

                <div class="tab-pane fade" id="optical" role="tabpanel" aria-labelledby="optical-tab">
                    <div id="optical-chart-mount"></div>
                    <div class="fb-ind-grid fb-optical-metrics">
                        <article class="fb-ind"><span>Sinal RX</span><strong data-optical="rx_power">Consultando...</strong><em class="fb-pill" data-optical-pill="rx_power">—</em><small>Potência recebida da OLT. Abaixo de −25 dBm o enlace começa a oscilar.</small></article>
                        <article class="fb-ind"><span>Sinal TX</span><strong data-optical="tx_power">Consultando...</strong><em class="fb-pill" data-optical-pill="tx_power">—</em><small>Potência que a ONU envia de volta para a OLT.</small></article>
                        <article class="fb-ind"><span>Temperatura</span><strong data-optical="temperature">Consultando...</strong><em class="fb-pill" data-optical-pill="temperature">—</em><small>Temperatura do módulo óptico.</small></article>
                        <article class="fb-ind"><span>Voltagem</span><strong data-optical="voltage">Consultando...</strong><em class="fb-pill" data-optical-pill="voltage">—</em><small>Tensão de alimentação do módulo óptico.</small></article>
                    </div>
                    <section class="acs-overview-card fb-optical-meta-card">
                        <div class="acs-reference-list">
                            <div><span>OLT / PON</span><strong id="fb-optical-pon">Consultando...</strong></div>
                            <div><span>Última leitura</span><strong id="fb-optical-seen">Consultando...</strong></div>
                        </div>
                    </section>
                </div>

                <div class="tab-pane fade" id="wifi" role="tabpanel" aria-labelledby="wifi-tab">
                    <section class="acs-overview-card acs-card-wifi acs-approved-wifi">
                        <div class="acs-overview-card-header">
                            <div>
                                <span class="acs-kicker">Wi-Fi</span>
                                <small class="d-block text-muted">SSID e senha das redes deste equipamento.</small>
                            </div>
                            <button class="acs-toolbar-action fb-primary" type="button" onclick="fbOpenLegacyWifi()">Alterar SSID e senha</button>
                        </div>
                        <div class="acs-wifi-reference-grid"></div>
                    </section>
                </div>

                <div class="tab-pane fade" id="voice" role="tabpanel" aria-labelledby="voice-tab">
                    <section class="acs-overview-card">
                        <h2 class="fb-section-title">Voz</h2>
                        <p class="fb-alert-empty">Este CPE não informou linhas de voz nesta coleta.</p>
                    </section>
                </div>

                <div class="tab-pane fade" id="diagnostics" role="tabpanel" aria-labelledby="diagnostics-tab">
                    <div id="acs-diagnostics-content" class="fb-actions">
                        <aside class="fb-action-menu">
                            <label class="fb-action-search">
                                <i class="bi bi-search"></i>
                                <input id="fb-action-search" type="search" placeholder="buscar ação..." autocomplete="off">
                            </label>

                            <div class="fb-action-group" data-fb-group="favoritas">
                                <p>Favoritas</p>
                                <div class="fb-fav-row">
        <button type="button" class="fb-fav-chip is-on" data-fb-action="ping">Ping</button>
        <button type="button" class="fb-fav-chip" data-fb-action="speedtest">Velocidade</button>
        <button type="button" class="fb-fav-chip" data-fb-action="traceroute">TraceRoute</button>
        <button type="button" class="fb-fav-chip" data-fb-action="wifi-ssid">Trocar SSID</button>
      </div>
                            </div>

                            <div class="fb-action-group" data-fb-group="diagnostico">
                                <p>Diagnóstico</p>
                                <button type="button" class="fb-action-item is-on" data-fb-action="ping"><i class="bi bi-activity"></i><span>Ping</span></button>
                                <button type="button" class="fb-action-item" data-fb-action="traceroute"><i class="bi bi-signpost-split"></i><span>TraceRoute</span></button>
                                <button type="button" class="fb-action-item" data-fb-action="speedtest"><i class="bi bi-speedometer2"></i><span>Velocidade</span></button>
                                <button type="button" class="fb-action-item" data-fb-action="nearby"><i class="bi bi-broadcast"></i><span>Redes próximas</span></button>
                            </div>

                            <div class="fb-action-group" data-fb-group="wifi">
                                <p>Wi-Fi</p>
                                <button type="button" class="fb-action-item" data-fb-action="wifi-ssid"><i class="bi bi-wifi"></i><span>Trocar SSID</span></button>
                                <button type="button" class="fb-action-item" data-fb-action="wifi-pass"><i class="bi bi-key"></i><span>Trocar senha</span></button>
                            </div>

                            <div class="fb-action-group" data-fb-group="conexao">
                                <p>Conexão</p>
                                <button type="button" class="fb-action-item" data-fb-action="summon"><i class="bi bi-arrow-repeat"></i><span>Comunicar</span></button>
                                <button type="button" class="fb-action-item" data-fb-action="web"><i class="bi bi-box-arrow-up-right"></i><span>Acesso web</span></button>
                            </div>

                            <div class="fb-action-group" data-fb-group="manutencao">
                                <p>Manutenção</p>
                                <button type="button" class="fb-action-item" data-fb-action="tag"><i class="bi bi-tag"></i><span>Etiqueta</span></button>
                                <button type="button" class="fb-action-item" data-fb-action="untag"><i class="bi bi-tag-fill"></i><span>Remover etiqueta</span></button>
                                <button type="button" class="fb-action-item" data-fb-action="refresh"><i class="bi bi-arrow-clockwise"></i><span>Atualizar coleta</span></button>
                            <button type="button" class="fb-action-item" data-fb-action="reboot"><i class="bi bi-power"></i><span>Reiniciar equipamento</span></button>
                                <button type="button" class="fb-action-item" data-fb-action="summary"><i class="bi bi-clipboard2-pulse"></i><span>Resumo</span></button>
                                <button type="button" class="fb-action-item" data-fb-action="assistant"><i class="bi bi-stars"></i><span>Assistente</span></button>
                            </div>
                        </aside>

                        <section class="fb-action-stage" aria-live="polite">
                            <header class="fb-action-head">
                                <div>
                                    <i id="fb-action-icon" class="bi bi-activity"></i>
                                    <h2 id="fb-action-title">Ping</h2>
                                </div>
                                <span id="fb-action-safe" class="fb-action-safe">Seguro</span>
                            </header>
                            <p id="fb-action-desc">Testa conectividade e latência até um host. O teste precisa sair do próprio CPE.</p>
                            <label class="fb-action-host" id="fb-action-host-wrap">
                                <span>Host de destino</span>
                                <input id="fb-action-host" type="text" value="8.8.8.8" autocomplete="off" spellcheck="false">
                            </label>
                            <button type="button" class="fb-action-run" id="fb-action-run"><i class="bi bi-play-fill"></i> Executar</button>
                            <div class="fb-action-results">
                                <span>Resultado</span>
                                <div id="fb-action-result">
                                    <p class="fb-alert-empty">Nenhum resultado nesta sessão. Escolha a ação e execute.</p>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>

                <div class="tab-pane fade" id="monitoring" role="tabpanel">
                    <div id="monitoring-content"></div>
                </div>

            </div>

        </div>

    </div>

<?php endif; ?>


<!-- =========================================================
     ASSISTENTE IA - PAINEL LATERAL PERSISTENTE
     ========================================================= -->
<div
    class="offcanvas offcanvas-end jr-ai-drawer"
    tabindex="-1"
    id="jrDeviceAIDrawer"
    aria-labelledby="jrDeviceAIDrawerLabel"
    data-bs-scroll="true"
>
    <div class="jr-ai-drawer-header">
        <div class="jr-ai-drawer-title">
            <div class="jr-ai-drawer-logo"><i class="bi bi-stars"></i></div>
            <div>
                <span>JR CONECT IA</span>
                <strong id="jrDeviceAIDrawerLabel">Assistente técnico</strong>
                <small id="jr-ai-drawer-provider">IA configurada</small>
            </div>
        </div>

        <div class="jr-ai-drawer-actions">
            <button type="button" onclick="clearDeviceAIConversation()" title="Limpar conversa">
                <i class="bi bi-trash3"></i>
            </button>
            <button type="button" data-bs-dismiss="offcanvas" aria-label="Fechar" title="Fechar">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    </div>

    <div class="jr-ai-drawer-device">
        <div>
            <span>Equipamento</span>
            <strong id="jr-ai-drawer-device-model">Carregando...</strong>
        </div>
        <div>
            <span>Cliente</span>
            <strong id="jr-ai-drawer-client">Carregando...</strong>
        </div>
        <div>
            <span>Status</span>
            <strong id="jr-ai-drawer-device-status">...</strong>
        </div>
    </div>

    <div id="jr-ai-drawer-messages" class="jr-ai-drawer-messages"></div>

    <div class="jr-ai-drawer-composer">
        <textarea
            id="jr-ai-drawer-input"
            rows="2"
            placeholder="Pergunte sobre este equipamento..."
            onkeydown="if(event.key==='Enter' && !event.shiftKey){event.preventDefault();sendDeviceAIDrawerMessage();}"
        ></textarea>
        <button type="button" id="jr-ai-drawer-send" onclick="sendDeviceAIDrawerMessage()" title="Enviar">
            <i class="bi bi-send-fill"></i>
        </button>
    </div>
</div>


<!-- =========================================================
     MODAL RESULTADO DO SPEEDTEST - NOVO LAYOUT
     ========================================================= -->

<style>
    .jr-speed-modal .modal-dialog {
        max-width: 820px;
    }

    .jr-speed-modal .modal-content {
        background: #0b1d2d;
        border: 1px solid #315267;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 25px 70px rgba(0,0,0,.45);
    }

    .jr-speed-modal .modal-header,
    .jr-speed-modal .modal-footer {
        background: #102c3e;
        border-color: #315267;
    }

    .jr-speed-modal .modal-title {
        color: #fff;
        font-weight: 700;
    }

    .jr-speed-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 18px;
    }

    .jr-speed-gauge-card {
        background: #081824;
        border: 1px solid #29485d;
        border-radius: 16px;
        padding: 22px 16px;
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 285px;
    }

    .jr-speed-gauge {
        --value: 0;
        --gauge-color: #5ed9ff;
        width: 220px;
        height: 220px;
        border-radius: 50%;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        background:
            conic-gradient(
                var(--gauge-color) calc(var(--value) * 1%),
                #193343 0
            );
        transition: background .6s ease;
        filter: drop-shadow(0 0 14px rgba(94,217,255,.10));
    }

    .jr-speed-gauge.upload {
        --gauge-color: #47dda1;
        filter: drop-shadow(0 0 14px rgba(71,221,161,.10));
    }


    .jr-speed-gauge.testing {
        animation: jr-speed-pulse 1.15s ease-in-out infinite;
        box-shadow: 0 0 0 1px rgba(94,217,255,.15);
    }

    .jr-speed-gauge.upload.testing {
        box-shadow: 0 0 0 1px rgba(71,221,161,.15);
    }

    .jr-speed-gauge.done {
        animation: none;
    }

    .jr-speed-gauge.waiting {
        opacity: .55;
    }

    @keyframes jr-speed-pulse {
        0%, 100% {
            transform: scale(1);
            filter: brightness(1);
        }
        50% {
            transform: scale(1.025);
            filter: brightness(1.22);
        }
    }

    .jr-speed-stage {
        margin-top: 8px;
        min-height: 18px;
        color: #67ddff;
        font-size: .76rem;
        font-weight: 700;
        letter-spacing: .02em;
    }

    .jr-speed-stage.waiting {
        color: #708a99;
    }

    .jr-speed-stage.done {
        color: #47dda1;
    }

    .jr-speed-live-dot {
        display: inline-block;
        width: 7px;
        height: 7px;
        margin-right: 5px;
        border-radius: 50%;
        background: currentColor;
        animation: jr-speed-dot 1s ease-in-out infinite;
    }

    @keyframes jr-speed-dot {
        0%, 100% { opacity: .3; }
        50% { opacity: 1; }
    }

    .jr-speed-gauge::before {
        content: '';
        position: absolute;
        inset: 13px;
        border-radius: 50%;
        background: #0b1d2d;
        box-shadow: inset 0 0 0 1px #29485d;
    }

    .jr-speed-gauge-content {
        position: relative;
        z-index: 2;
        text-align: center;
        padding: 10px;
    }

    .jr-speed-gauge-icon {
        font-size: 1rem;
        margin-bottom: 4px;
        color: #95afc1;
    }

    .jr-speed-gauge-label {
        font-size: .78rem;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: #95afc1;
        margin-bottom: 5px;
    }

    .jr-speed-gauge-value {
        color: #fff;
        font-size: 2.1rem;
        line-height: 1.05;
        font-weight: 800;
    }

    .jr-speed-gauge-unit {
        margin-top: 6px;
        color: #95afc1;
        font-size: .9rem;
    }

    .jr-speed-info-grid {
        margin-top: 18px;
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
    }

    .jr-speed-info-card {
        background: #102536;
        border: 1px solid #29485d;
        border-radius: 10px;
        padding: 13px;
        min-height: 75px;
    }

    .jr-speed-info-label {
        color: #95afc1;
        font-size: .72rem;
        text-transform: uppercase;
        margin-bottom: 5px;
    }

    .jr-speed-info-value {
        color: #fff;
        font-weight: 700;
        font-size: 1rem;
    }

    .jr-speed-info-value.ok {
        color: #47dda1;
    }

    .jr-speed-server {
        margin-top: 12px;
        display: flex;
        justify-content: space-between;
        gap: 15px;
        align-items: center;
        background: #102536;
        border: 1px solid #29485d;
        border-radius: 10px;
        padding: 14px;
    }

    .jr-speed-server strong {
        display: block;
        color: #fff;
    }

    .jr-speed-server small {
        color: #95afc1;
    }

    .jr-speed-tr143 {
        color: #67ddff;
        font-size: .82rem;
        white-space: nowrap;
    }

    .jr-speed-modal .alert-info {
        background: #0c4058;
        color: #d9f6ff;
        border-color: #14749b;
    }

    @media (max-width: 700px) {
        .jr-speed-grid {
            grid-template-columns: 1fr;
        }

        .jr-speed-info-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .jr-speed-gauge-card {
            min-height: 245px;
        }

        .jr-speed-gauge {
            width: 190px;
            height: 190px;
        }
    }
</style>

<div
    class="modal fade jr-speed-modal"
    id="onuSpeedtestResultModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered modal-lg">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title">
                    <i class="bi bi-speedometer2 me-2"></i>
                    Teste de Velocidade
                </h5>

                <button
                    type="button"
                    class="btn-close btn-close-white"
                    data-bs-dismiss="modal"
                    aria-label="Fechar"
                ></button>

            </div>

            <div class="modal-body p-4">

                <div class="jr-speed-grid">

                    <div class="jr-speed-gauge-card">
                        <div
                            class="jr-speed-gauge"
                            id="onu-speedtest-download-gauge"
                        >
                            <div class="jr-speed-gauge-content">
                                <div class="jr-speed-gauge-icon">
                                    <i class="bi bi-arrow-down-circle"></i>
                                </div>
                                <div class="jr-speed-gauge-label">
                                    Download
                                </div>
                                <div
                                    class="jr-speed-gauge-value"
                                    id="onu-speedtest-download"
                                >
                                    --
                                </div>
                                <div class="jr-speed-gauge-unit">
                                    Mbps
                                </div>
                                <div
                                    class="jr-speed-stage waiting"
                                    id="onu-speedtest-download-stage"
                                >
                                    Aguardando
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="jr-speed-gauge-card">
                        <div
                            class="jr-speed-gauge upload"
                            id="onu-speedtest-upload-gauge"
                        >
                            <div class="jr-speed-gauge-content">
                                <div class="jr-speed-gauge-icon">
                                    <i class="bi bi-arrow-up-circle"></i>
                                </div>
                                <div class="jr-speed-gauge-label">
                                    Upload
                                </div>
                                <div
                                    class="jr-speed-gauge-value"
                                    id="onu-speedtest-upload"
                                >
                                    --
                                </div>
                                <div class="jr-speed-gauge-unit">
                                    Mbps
                                </div>
                                <div
                                    class="jr-speed-stage waiting"
                                    id="onu-speedtest-upload-stage"
                                >
                                    Aguardando
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="jr-speed-info-grid">

                    <div class="jr-speed-info-card">
                        <div class="jr-speed-info-label">
                            Duração total
                        </div>
                        <div
                            class="jr-speed-info-value"
                            id="onu-speedtest-duration"
                        >
                            --
                        </div>
                    </div>

                    <div class="jr-speed-info-card">
                        <div class="jr-speed-info-label">
                            Status
                        </div>
                        <div
                            class="jr-speed-info-value ok"
                            id="onu-speedtest-status"
                        >
                            --
                        </div>
                    </div>

                    <div class="jr-speed-info-card">
                        <div class="jr-speed-info-label">
                            RX óptico
                        </div>
                        <div
                            class="jr-speed-info-value"
                            id="onu-speedtest-rx"
                        >
                            --
                        </div>
                    </div>

                    <div class="jr-speed-info-card">
                        <div class="jr-speed-info-label">
                            PON
                        </div>
                        <div
                            class="jr-speed-info-value"
                            id="onu-speedtest-pon"
                        >
                            --
                        </div>
                    </div>

                </div>

                <div class="jr-speed-server">
                    <div>
                        <div class="jr-speed-info-label">
                            Servidor do teste
                        </div>
                        <strong>JR CONECT</strong>
                        <small>teste.jrconect.com</small>
                        <small>LibreSpeed • 10 conexões de download • 6 de upload • 20 s por etapa.</small>
                        <small>O teste mede a conexão deste navegador até o servidor JR CONECT.</small>
                    </div>

                    <div class="jr-speed-tr143">
                        <i class="bi bi-diagram-3 me-1"></i>
                        LibreSpeed
                    </div>
                </div>

                <div
                    id="onu-speedtest-warning"
                    class="alert alert-info mt-3 mb-0"
                    style="display:none;"
                ></div>

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >
                    Fechar
                </button>

                <button
                    type="button"
                    class="btn btn-primary"
                    id="onu-speedtest-repeat-btn"
                    onclick="repeatONUSpeedtest()"
                >
                    <i class="bi bi-arrow-clockwise"></i>
                    Testar Novamente
                </button>

            </div>

        </div>

    </div>

</div>


<?php
include __DIR__ . '/views/device-detail/modals.php';
?>


<script>

/* =========================================================
   CONFIGURACAO GLOBAL
   ========================================================= */

window.DEVICE_ID =
    <?php echo json_encode((string)$deviceId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;


window.ONU_SPEEDTEST_DOWNLOAD =
    null;


window.ONU_SPEEDTEST_STARTED_AT =
    null;


window.ONU_SPEEDTEST_TIMER =
    null;


window.ONU_SPEEDTEST_RUNNING =
    false;


/* =========================================================
   LER RESPOSTA JSON
   ========================================================= */

async function readJSONResponse(response) {

    const raw =
        await response.text();


    console.log(
        '[ONU SPEEDTEST]',
        response.status,
        raw
    );


    let result;


    try {

        result =
            JSON.parse(raw);

    } catch (error) {

        throw new Error(
            'Resposta inválida da API. HTTP ' +
            response.status
        );

    }


    if (!response.ok) {

        throw new Error(
            result.message ||
            (
                'Erro HTTP ' +
                response.status
            )
        );

    }


    return result;
}


/* =========================================================
   JR CONECT - TESTE DE VELOCIDADE
   LIBRESPEED + SERVIDOR HTTP PROPRIO
   ========================================================= */

const JR_SPEEDTEST_BASE =
    'https://teste.jrconect.com/';

const JR_SPEEDTEST_SCRIPT =
    '/assets/vendor/librespeed/speedtest.js';

window.JR_LIBRESPEED_INSTANCE = null;
window.JR_LIBRESPEED_SCRIPT_PROMISE = null;
window.JR_LIBRESPEED_LAST_DATA = null;
window.JR_SPEEDTEST_LAST_STATE = -1;
window.JR_SPEEDTEST_DOWNLOAD_STARTED_AT = 0;
window.JR_SPEEDTEST_UPLOAD_STARTED_AT = 0;

function jrLoadLibreSpeed() {
    if (typeof Speedtest !== 'undefined') return Promise.resolve();
    if (window.JR_LIBRESPEED_SCRIPT_PROMISE) return window.JR_LIBRESPEED_SCRIPT_PROMISE;

    window.JR_LIBRESPEED_SCRIPT_PROMISE = new Promise(function(resolve, reject) {
        const script = document.createElement('script');
        script.src = JR_SPEEDTEST_SCRIPT + '?v=' + Date.now();
        script.async = true;
        script.onload = function() {
            if (typeof Speedtest === 'undefined') {
                reject(new Error('O motor LibreSpeed não foi carregado corretamente.'));
                return;
            }
            resolve();
        };
        script.onerror = function() {
            reject(new Error('Não foi possível carregar o motor do teste de velocidade.'));
        };
        document.head.appendChild(script);
    });

    return window.JR_LIBRESPEED_SCRIPT_PROMISE;
}

function jrBuildDownloadResult(data) {
    const value = Number(data?.dlStatus);
    const duration = window.JR_SPEEDTEST_DOWNLOAD_STARTED_AT
        ? (Date.now() - window.JR_SPEEDTEST_DOWNLOAD_STARTED_AT) / 1000
        : 0;

    return {
        download_mbps: Number.isFinite(value) ? value : 0,
        duration_seconds: duration,
        bytes: 0,
        multiple_streams: true,
        connections: 6,
        ping_ms: Number(data?.pingStatus),
        jitter_ms: Number(data?.jitterStatus),
        engine: 'LibreSpeed'
    };
}

function jrBuildUploadResult(data) {
    const value = Number(data?.ulStatus);
    const duration = window.JR_SPEEDTEST_UPLOAD_STARTED_AT
        ? (Date.now() - window.JR_SPEEDTEST_UPLOAD_STARTED_AT) / 1000
        : 0;

    return {
        upload_mbps: Number.isFinite(value) ? value : 0,
        duration_seconds: duration,
        bytes: 0,
        multiple_streams: true,
        connections: 4,
        ping_ms: Number(data?.pingStatus),
        jitter_ms: Number(data?.jitterStatus),
        engine: 'LibreSpeed'
    };
}

async function startONUSpeedtest() {
    if (window.ONU_SPEEDTEST_RUNNING) {
        showToast('Já existe um teste em andamento.', 'warning');
        openONUSpeedtestModal();
        return;
    }

    const button = document.getElementById('onu-speedtest-btn');

    window.ONU_SPEEDTEST_RUNNING = true;
    window.ONU_SPEEDTEST_DOWNLOAD = null;
    window.ONU_SPEEDTEST_TOTAL_ELAPSED = null;
    window.JR_LIBRESPEED_LAST_DATA = null;
    window.JR_SPEEDTEST_LAST_STATE = -1;
    window.JR_SPEEDTEST_DOWNLOAD_STARTED_AT = 0;
    window.JR_SPEEDTEST_UPLOAD_STARTED_AT = 0;

    resetONUSpeedtestLiveUI();
    fillONUSpeedtestOpticalInfo();
    openONUSpeedtestModal();
    startONUSpeedtestTimer();

    try {
        if (button) {
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Testando...';
        }

        setONUSpeedtestStatus('Preparando teste...');
        await jrLoadLibreSpeed();

        const speedtest = new Speedtest();
        window.JR_LIBRESPEED_INSTANCE = speedtest;

        speedtest.setParameter('url_dl', JR_SPEEDTEST_BASE + 'teste-100mb.bin');
        speedtest.setParameter('url_ul', JR_SPEEDTEST_BASE + 'backend/empty.php');
        speedtest.setParameter('url_ping', JR_SPEEDTEST_BASE + 'backend/empty.php');
        speedtest.setParameter('url_getIp', JR_SPEEDTEST_BASE + 'backend/getIP.php');
        speedtest.setParameter('test_order', 'P_D_U');
        speedtest.setParameter('time_auto', false);
        speedtest.setParameter('time_dl_max', 20);
        speedtest.setParameter('time_ul_max', 20);
        speedtest.setParameter('xhr_dlMultistream', 10);
        speedtest.setParameter('xhr_ulMultistream', 6);
        speedtest.setParameter('xhr_multistreamDelay', 100);
        speedtest.setParameter('xhr_ul_blob_megabytes', 20);
        speedtest.setParameter('time_dlGraceTime', 2);
        speedtest.setParameter('time_ulGraceTime', 3);
        speedtest.setParameter('overheadCompensationFactor', 1.06);
        speedtest.setParameter('xhr_ignoreErrors', 1);
        speedtest.setParameter('count_ping', 10);
        speedtest.setParameter('telemetry_level', 'none');
        speedtest.setParameter('getIp_ispInfo', false);

        speedtest.onupdate = function(data) {
            if (!data) return;

            window.JR_LIBRESPEED_LAST_DATA = data;

            const state = Number(data.testState);
            const download = Number(data.dlStatus);
            const upload = Number(data.ulStatus);

            if (state !== window.JR_SPEEDTEST_LAST_STATE) {
                window.JR_SPEEDTEST_LAST_STATE = state;

                if (state === 1) {
                    window.JR_SPEEDTEST_DOWNLOAD_STARTED_AT = Date.now();
                    setONUSpeedtestPhase('download');
                }

                if (state === 2) {
                    if (window.ONU_SPEEDTEST_DOWNLOAD === null && Number.isFinite(download)) {
                        window.ONU_SPEEDTEST_DOWNLOAD = jrBuildDownloadResult(data);
                        setONUSpeedtestCompletedStage('download', window.ONU_SPEEDTEST_DOWNLOAD);
                    }
                    setONUSpeedtestStatus('Medindo latência e jitter...');
                }

                if (state === 3) {
                    if (window.ONU_SPEEDTEST_DOWNLOAD === null) {
                        window.ONU_SPEEDTEST_DOWNLOAD = jrBuildDownloadResult(data);
                        setONUSpeedtestCompletedStage('download', window.ONU_SPEEDTEST_DOWNLOAD);
                    }
                    window.JR_SPEEDTEST_UPLOAD_STARTED_AT = Date.now();
                    setONUSpeedtestPhase('upload');
                }
            }

            if (state === 1 && Number.isFinite(download) && download >= 0) {
                setONUSpeedtestText('onu-speedtest-download', download.toFixed(2));
                setONUSpeedGauge('onu-speedtest-download-gauge', download, 1000);
            }

            if (state === 3 && Number.isFinite(upload) && upload >= 0) {
                setONUSpeedtestText('onu-speedtest-upload', upload.toFixed(2));
                setONUSpeedGauge('onu-speedtest-upload-gauge', upload, 1000);
            }

            console.debug('[JR SPEEDTEST]', {
                state: state,
                download: data.dlStatus,
                upload: data.ulStatus,
                ping: data.pingStatus,
                jitter: data.jitterStatus,
                dlProgress: data.dlProgress,
                ulProgress: data.ulProgress
            });
        };

        speedtest.onend = function(aborted) {
            const data = window.JR_LIBRESPEED_LAST_DATA || {};

            if (aborted) {
                window.JR_LIBRESPEED_INSTANCE = null;
                finishONUSpeedtestWithError('Teste interrompido.');
                return;
            }

            const download = Number(data.dlStatus);
            const upload = Number(data.ulStatus);

            if (!Number.isFinite(download) || !Number.isFinite(upload)) {
                window.JR_LIBRESPEED_INSTANCE = null;
                finishONUSpeedtestWithError('O teste terminou sem resultados válidos.');
                return;
            }

            // Sempre usa os valores FINAIS do LibreSpeed.
            window.ONU_SPEEDTEST_DOWNLOAD = jrBuildDownloadResult(data);
            const uploadResult = jrBuildUploadResult(data);

            window.ONU_SPEEDTEST_TOTAL_ELAPSED = Math.max(
                0,
                (Date.now() - window.ONU_SPEEDTEST_STARTED_AT) / 1000
            );

            setONUSpeedtestCompletedStage('download', window.ONU_SPEEDTEST_DOWNLOAD);
            setONUSpeedtestCompletedStage('upload', uploadResult);
            showONUCompleteSpeedtestResult(window.ONU_SPEEDTEST_DOWNLOAD, uploadResult);

            console.log('[JR SPEEDTEST CONCLUIDO]', {
                download_mbps: download,
                upload_mbps: upload,
                ping_ms: Number(data.pingStatus),
                jitter_ms: Number(data.jitterStatus)
            });

            window.JR_LIBRESPEED_INSTANCE = null;
        };

        speedtest.start();

    } catch (error) {
        console.error('[JR SPEEDTEST]', error);
        window.JR_LIBRESPEED_INSTANCE = null;
        finishONUSpeedtestWithError(error.message || 'Erro ao iniciar o teste de velocidade.');
    }
}

async function pollONUDownload() { return null; }
async function startONUUpload() { return null; }
async function pollONUUpload() { return null; }

/* Helpers visuais existentes, preservados. */
function openONUSpeedtestModal() {

    const modalElement =
        document.getElementById(
            'onuSpeedtestResultModal'
        );


    if (!modalElement) {
        return;
    }


    const modal =
        bootstrap.Modal.getOrCreateInstance(
            modalElement,
            {
                backdrop: 'static',
                keyboard: false
            }
        );


    modal.show();
}


function resetONUSpeedtestLiveUI() {

    setONUSpeedtestText(
        'onu-speedtest-download',
        '--'
    );


    setONUSpeedtestText(
        'onu-speedtest-upload',
        '--'
    );


    setONUSpeedtestText(
        'onu-speedtest-duration',
        '0.00 s'
    );


    setONUSpeedtestText(
        'onu-speedtest-status',
        'Preparando...'
    );


    setONUSpeedtestStage(
        'download',
        'Aguardando',
        'waiting'
    );


    setONUSpeedtestStage(
        'upload',
        'Aguardando',
        'waiting'
    );


    const downloadGauge =
        document.getElementById(
            'onu-speedtest-download-gauge'
        );


    const uploadGauge =
        document.getElementById(
            'onu-speedtest-upload-gauge'
        );


    for (const gauge of [downloadGauge, uploadGauge]) {

        if (!gauge) {
            continue;
        }

        gauge.classList.remove(
            'testing',
            'done',
            'waiting'
        );

        gauge.style.setProperty(
            '--value',
            '0'
        );
    }


    if (uploadGauge) {
        uploadGauge.classList.add(
            'waiting'
        );
    }


    const warning =
        document.getElementById(
            'onu-speedtest-warning'
        );


    if (warning) {
        warning.style.display = 'none';
        warning.innerHTML = '';
    }


    const repeatButton =
        document.getElementById(
            'onu-speedtest-repeat-btn'
        );


    if (repeatButton) {
        repeatButton.disabled = true;
    }
}


function setONUSpeedtestText(id, value) {

    const element =
        document.getElementById(id);


    if (element) {
        element.textContent = value;
    }
}


function setONUSpeedtestStatus(text, type = 'running') {

    const element =
        document.getElementById(
            'onu-speedtest-status'
        );


    if (!element) {
        return;
    }


    element.textContent = text;

    element.classList.remove(
        'ok',
        'text-warning',
        'text-danger',
        'text-info'
    );


    if (type === 'ok') {
        element.classList.add('ok');
    } else if (type === 'error') {
        element.classList.add('text-danger');
    } else {
        element.classList.add('text-info');
    }
}


function setONUSpeedtestStage(type, text, state = 'waiting') {

    const stage =
        document.getElementById(
            'onu-speedtest-' +
            type +
            '-stage'
        );


    if (!stage) {
        return;
    }


    stage.classList.remove(
        'waiting',
        'done'
    );


    if (state === 'testing') {

        stage.innerHTML =
            '<span class="jr-speed-live-dot"></span>' +
            text;

    } else {

        stage.textContent = text;
    }


    if (state === 'waiting') {
        stage.classList.add('waiting');
    }


    if (state === 'done') {
        stage.classList.add('done');
    }
}


function setONUSpeedtestPhase(phase) {

    const downloadGauge =
        document.getElementById(
            'onu-speedtest-download-gauge'
        );


    const uploadGauge =
        document.getElementById(
            'onu-speedtest-upload-gauge'
        );


    if (phase === 'download') {

        setONUSpeedtestStatus(
            'Download em andamento...'
        );

        setONUSpeedtestStage(
            'download',
            'Testando download',
            'testing'
        );

        setONUSpeedtestStage(
            'upload',
            'Aguardando download',
            'waiting'
        );

        if (downloadGauge) {
            downloadGauge.classList.remove('done', 'waiting');
            downloadGauge.classList.add('testing');
            downloadGauge.style.setProperty('--value', '18');
        }

        if (uploadGauge) {
            uploadGauge.classList.remove('testing', 'done');
            uploadGauge.classList.add('waiting');
            uploadGauge.style.setProperty('--value', '0');
        }
    }


    if (phase === 'upload') {

        setONUSpeedtestStatus(
            'Upload em andamento...'
        );

        setONUSpeedtestStage(
            'upload',
            'Testando upload',
            'testing'
        );

        if (downloadGauge) {
            downloadGauge.classList.remove('testing', 'waiting');
            downloadGauge.classList.add('done');
        }

        if (uploadGauge) {
            uploadGauge.classList.remove('done', 'waiting');
            uploadGauge.classList.add('testing');
            uploadGauge.style.setProperty('--value', '18');
        }
    }
}


function startONUSpeedtestTimer() {

    stopONUSpeedtestTimer();


    window.ONU_SPEEDTEST_STARTED_AT =
        Date.now();


    window.ONU_SPEEDTEST_TIMER =
        setInterval(
            function () {

                if (!window.ONU_SPEEDTEST_STARTED_AT) {
                    return;
                }


                const elapsed =
                    (
                        Date.now() -
                        window.ONU_SPEEDTEST_STARTED_AT
                    ) / 1000;


                setONUSpeedtestText(
                    'onu-speedtest-duration',
                    elapsed.toFixed(1) + ' s'
                );

            },
            200
        );
}


function stopONUSpeedtestTimer() {

    if (window.ONU_SPEEDTEST_TIMER) {

        clearInterval(
            window.ONU_SPEEDTEST_TIMER
        );

        window.ONU_SPEEDTEST_TIMER = null;
    }
}


function extractONUSpeedtestMbps(type, result) {
    if (!result) return null;

    const raw = type === 'download'
        ? result.download_mbps
        : result.upload_mbps;

    if (raw === null || raw === undefined || raw === '' || typeof raw === 'boolean') return null;

    const value = Number(raw);
    return Number.isFinite(value) && value >= 0 ? value : null;
}

function updateONUSpeedtestLiveValue(type, result) {

    const value =
        extractONUSpeedtestMbps(
            type,
            result
        );


    if (value === null) {
        return;
    }


    setONUSpeedtestText(
        'onu-speedtest-' + type,
        value.toFixed(2)
    );


    setONUSpeedGauge(
        'onu-speedtest-' + type + '-gauge',
        value,
        1000
    );
}


function setONUSpeedtestCompletedStage(type, result) {

    updateONUSpeedtestLiveValue(
        type,
        result
    );


    setONUSpeedtestStage(
        type,
        'Concluído',
        'done'
    );


    const gauge =
        document.getElementById(
            'onu-speedtest-' +
            type +
            '-gauge'
        );


    if (gauge) {
        gauge.classList.remove('testing', 'waiting');
        gauge.classList.add('done');
    }
}


function finishONUSpeedtestWithError(message) {
    for (const id of ['onu-speedtest-download-gauge', 'onu-speedtest-upload-gauge']) {
        document.getElementById(id)?.classList.remove('testing');
    }


    stopONUSpeedtestTimer();

    window.ONU_SPEEDTEST_RUNNING =
        false;


    restoreSpeedtestButton();


    setONUSpeedtestStatus(
        'Erro no teste',
        'error'
    );


    const warning =
        document.getElementById(
            'onu-speedtest-warning'
        );


    if (warning) {

        warning.style.display = 'block';

        warning.classList.remove('alert-info');
        warning.classList.add('alert-danger');

        warning.innerHTML =
            '<i class="bi bi-exclamation-triangle me-1"></i>' +
            escapeONUSpeedtestHtml(message);
    }


    const repeatButton =
        document.getElementById(
            'onu-speedtest-repeat-btn'
        );


    if (repeatButton) {
        repeatButton.disabled = false;
    }


    showToast(
        message,
        'danger'
    );
}


function escapeONUSpeedtestHtml(value) {

    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}


/* =========================================================
   HELPERS VISUAIS DO SPEEDTEST
   ========================================================= */

function setONUSpeedGauge(elementId, value, maxValue) {

    const element =
        document.getElementById(
            elementId
        );


    if (!element) {
        return;
    }


    const numeric =
        Number(value);


    if (!Number.isFinite(numeric) || numeric < 0) {

        element.style.setProperty(
            '--value',
            '0'
        );

        return;
    }


    const max =
        Number(maxValue) > 0
            ? Number(maxValue)
            : 1000;


    const percent =
        Math.max(
            0,
            Math.min(
                100,
                (numeric / max) * 100
            )
        );


    element.style.setProperty(
        '--value',
        percent.toFixed(2)
    );
}


function findOverviewValue(label) {

    const container =
        document.getElementById(
            'overview-content'
        );


    if (!container) {
        return null;
    }


    const normalizedLabel =
        String(label)
            .trim()
            .toUpperCase();


    const rows =
        container.querySelectorAll(
            'tr'
        );


    for (const row of rows) {

        const cells =
            row.querySelectorAll(
                'td, th'
            );


        if (cells.length < 2) {
            continue;
        }


        const first =
            cells[0]
                .textContent
                .trim()
                .toUpperCase();


        if (first === normalizedLabel) {

            return cells[1]
                .textContent
                .trim();
        }
    }


    return null;
}


function fillONUSpeedtestOpticalInfo() {

    const rx =
        findOverviewValue(
            'RX POWER'
        );


    const pon =
        findOverviewValue(
            'PON ID'
        );


    const rxElement =
        document.getElementById(
            'onu-speedtest-rx'
        );


    const ponElement =
        document.getElementById(
            'onu-speedtest-pon'
        );


    if (rxElement) {

        rxElement.textContent =
            rx || '--';
    }


    if (ponElement) {

        ponElement.textContent =
            pon || '--';
    }
}


/* =========================================================
   MOSTRAR RESULTADO FINAL
   ========================================================= */

function showONUCompleteSpeedtestResult(
    downloadResult,
    uploadResult
) {

    const download =
        downloadResult &&
        downloadResult.download_mbps !== null
            ? Number(
                downloadResult.download_mbps
            ).toFixed(2)
            : 'N/D';


    const upload =
        uploadResult &&
        uploadResult.upload_mbps !== null
            ? Number(
                uploadResult.upload_mbps
            ).toFixed(2)
            : 'N/D';


    const downloadDuration =
        Number(
            downloadResult
                ?.duration_seconds
                || 0
        );


    const uploadDuration =
        Number(
            uploadResult
                ?.duration_seconds
                || 0
        );


    const totalDuration =
        downloadDuration +
        uploadDuration;


    document
        .getElementById(
            'onu-speedtest-download'
        )
        .textContent =
            download;


    document
        .getElementById(
            'onu-speedtest-upload'
        )
        .textContent =
            upload;


    stopONUSpeedtestTimer();


    window.ONU_SPEEDTEST_RUNNING =
        false;


    setONUSpeedtestText(
        'onu-speedtest-duration',
        Number(window.ONU_SPEEDTEST_TOTAL_ELAPSED ?? totalDuration).toFixed(2) +
        ' s'
    );


    setONUSpeedtestStatus(
        'Concluído',
        'ok'
    );


    setONUSpeedtestStage(
        'download',
        'Concluído',
        'done'
    );


    setONUSpeedtestStage(
        'upload',
        'Concluído',
        'done'
    );


    /*
     * Atualizar medidores circulares.
     * Escala visual padrão: 1 Gbps.
     * O valor numérico exibido continua sendo o resultado real.
     */

    setONUSpeedGauge(
        'onu-speedtest-download-gauge',
        downloadResult?.download_mbps,
        1000
    );


    setONUSpeedGauge(
        'onu-speedtest-upload-gauge',
        uploadResult?.upload_mbps,
        1000
    );


    /*
     * Aproveitar RX e PON que já estão visíveis
     * na tela do equipamento, sem nova consulta.
     */

    fillONUSpeedtestOpticalInfo();


    const warning =
        document.getElementById(
            'onu-speedtest-warning'
        );


    if (warning) {
        warning.classList.remove('alert-danger');
        warning.classList.add('alert-info');
    }


    const multipleStreams =
        !!(
            downloadResult
                ?.multiple_streams ||
            uploadResult
                ?.multiple_streams
        );


    if (
        warning &&
        multipleStreams
    ) {

        warning.style.display =
            'block';


        warning.innerHTML =
            '<i class="bi bi-info-circle"></i> ' +
            'Teste realizado com LibreSpeed usando múltiplas conexões HTTP no servidor JR CONECT.';

    } else if (warning) {

        warning.style.display =
            'none';

    }


    const repeatButton =
        document.getElementById(
            'onu-speedtest-repeat-btn'
        );


    if (repeatButton) {
        repeatButton.disabled = false;
    }


    restoreSpeedtestButton();


    /*
     * SALVAR HISTORICO
     */

    saveONUSpeedtestHistory(
        downloadResult,
        uploadResult
    );


    /*
     * O modal já está aberto desde o início do teste.
     * Garantimos apenas que continue visível ao concluir.
     */

    openONUSpeedtestModal();


    /*
     * TOAST
     */

    showToast(
        'Teste concluído: ' +
        download +
        ' Mbps ↓ / ' +
        upload +
        ' Mbps ↑',
        'success'
    );

}


/* =========================================================
   SALVAR HISTORICO
   ========================================================= */

async function saveONUSpeedtestHistory(
    downloadResult,
    uploadResult
) {

    try {

        const download =
            Number(
                downloadResult
                    ?.download_mbps
                    || 0
            );


        const upload =
            Number(
                uploadResult
                    ?.upload_mbps
                    || 0
            );


        const downloadDuration =
            Number(
                downloadResult
                    ?.duration_seconds
                    || 0
            );


        const uploadDuration =
            Number(
                uploadResult
                    ?.duration_seconds
                    || 0
            );


        const totalDuration =
            downloadDuration +
            uploadDuration;


        const multipleStreams =
            !!(
                downloadResult
                    ?.multiple_streams ||
                uploadResult
                    ?.multiple_streams
            );


        const serial =
            document
                .getElementById(
                    'device-id-badge'
                )
                ?.textContent
                ?.trim()
            || '';


        const response =
            await fetch(
                '/api/speedtest-history.php',
                {

                    method:
                        'POST',

                    headers: {

                        'Content-Type':
                            'application/json',

                        'Accept':
                            'application/json'

                    },

                    credentials:
                        'same-origin',

                    body:
                        JSON.stringify({

                            action:
                                'save',

                            device_id:
                                window.DEVICE_ID,

                            serial_number:
                                serial,

                            download_mbps:
                                download,

                            upload_mbps:
                                upload,

                            download_duration:
                                downloadDuration,

                            upload_duration:
                                uploadDuration,

                            total_duration:
                                totalDuration,

                            download_bytes:
                                downloadResult
                                    ?.bytes
                                || 0,

                            upload_bytes:
                                uploadResult
                                    ?.bytes
                                || 0,

                            multiple_streams:
                                multipleStreams

                        })

                }
            );


        const raw =
            await response.text();


        let result;


        try {

            result =
                JSON.parse(raw);

        } catch (error) {

            console.error(
                '[SPEEDTEST HISTORY] Resposta inválida:',
                raw
            );

            return;
        }


        if (!result.success) {

            console.error(
                '[SPEEDTEST HISTORY]',
                result
            );

            return;
        }


        console.log(
            '[SPEEDTEST HISTORY] Teste salvo:',
            result
        );


    } catch (error) {

        console.error(
            '[SPEEDTEST HISTORY]',
            error
        );

    }

}


/* =========================================================
   TESTAR NOVAMENTE
   ========================================================= */

function repeatONUSpeedtest() {

    if (window.ONU_SPEEDTEST_RUNNING) {
        return;
    }


    startONUSpeedtest();

}


/* =========================================================
   RESTAURAR BOTAO
   ========================================================= */

function restoreSpeedtestButton() {

    const button =
        document.getElementById(
            'onu-speedtest-btn'
        );


    if (!button) {
        return;
    }


    button.disabled =
        false;


    button.innerHTML =
        '<i class="bi bi-speedometer2"></i> ' +
        'Teste de Velocidade';

}

</script>


<link rel="stylesheet" href="/assets/css/device-control-fix.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="/assets/css/optical-history.css?v=20261005-1">
<script src="/assets/js/optical-history.js?v=20261022-onu8"></script>
<link rel="stylesheet" href="/assets/css/device-action-history.css?v=20261005-1">
<script src="/assets/js/device-action-history.js?v=20261005-2"></script>
<script src="/assets/js/device-detail.js?v=<?php echo time(); ?>"></script>
<script src="/assets/js/jrc-rede-hosts.js?v=JRC_REDE_HOSTS_V9"></script>
<script src="/assets/js/device-control-fix.js?v=<?php echo time(); ?>"></script>
<script src="/assets/js/jrc-acoes-width.js?v=2"></script>
<link rel="stylesheet" href="/assets/css/device-readability.css?v=20261005-6">
<link rel="stylesheet" href="/assets/css/scout-device.css?v=20261022-onu9">


<?php
include __DIR__ . '/views/layouts/footer.php';
?>

<style id="fb-fav-grid-ref">
  #acs-diagnostics-content .fb-fav-row { display: grid !important; grid-template-columns: 1fr 1fr; gap: 6px; }
  #acs-diagnostics-content .fb-fav-chip.is-on { border: 1px solid #e11d2e !important; background: transparent !important; color: #fff !important; box-shadow: none !important; }
  #acs-diagnostics-content .fb-action-item.is-on { background: #e11d2e !important; color: #fff !important; }
</style>
