<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/Security.php';
require_once __DIR__ . '/lib/AIConfig.php';
require_once __DIR__ . '/lib/ConcentratorConfig.php';
securityRequireRole(['admin']);
$securityCsrf = securityCsrfToken();

$pageTitle = 'Configurações';
$currentPage = 'configuration';

// Buscar configurações existentes
$conn = getDBConnection();

$genieacs = $conn->query("SELECT * FROM genieacs_credentials LIMIT 1")->fetch_assoc();
$mikrotik = $conn->query("SELECT * FROM mikrotik_credentials LIMIT 1")->fetch_assoc();
$telegram = $conn->query("SELECT * FROM telegram_config LIMIT 1")->fetch_assoc();

ensureConcentratorConfigTable($conn);
$concentrators = getConcentrators($conn);

ensureAIConfigTable($conn);
$ai = getAIConfig($conn);
$aiProviders = getAIProviderStatus($conn);
$activeAIProvider = $ai['provider'] ?? 'openai';
$cpeProfiles = \App\CPEProfiles::profiles();

include __DIR__ . '/views/layouts/header.php';
?>
<script>document.documentElement.classList.add('jrc-config-page')</script>
<link rel="stylesheet" href="/assets/css/jrc-config.css?v=33a2842710">
<!-- JRC_CONFIG_BG_V1 -->


<div class="row">
    <div class="col-12">
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i>
            Configure as credenciais para conexão com os serviços.
        </div>
    </div>
</div>

<!-- Navegação das abas -->
<div class="row">
    <div class="col-12">
        <ul class="nav nav-tabs" id="configTabs" role="tablist">

            <li class="nav-item" role="presentation">
                <button
                    class="nav-link active"
                    id="site-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#site-config"
                    type="button"
                    role="tab"
                >
                    <i class="bi bi-person-lock"></i>
                    Configuração do Site
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button
                    class="nav-link"
                    id="acs-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#acs-config"
                    type="button"
                    role="tab"
                >
                    <i class="bi bi-hdd-network"></i>
                    Configuração ACS
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button
                    class="nav-link"
                    id="mikrotik-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#mikrotik-config"
                    type="button"
                    role="tab"
                >
                    <i class="bi bi-ethernet"></i>
                    Configuração MikroTik
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button
                    class="nav-link"
                    id="concentrators-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#concentrators-config"
                    type="button"
                    role="tab"
                >
                    <i class="bi bi-diagram-3"></i>
                    Concentradores
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button
                    class="nav-link"
                    id="bot-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#bot-config"
                    type="button"
                    role="tab"
                >
                    <i class="fab fa-telegram"></i>
                    Configuração do Bot
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button
                    class="nav-link"
                    id="cpe-profiles-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#cpe-profiles-config"
                    type="button"
                    role="tab"
                >
                    <i class="bi bi-router"></i>
                    Perfis de Equipamentos
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button
                    class="nav-link"
                    id="ai-config-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#ai-config"
                    type="button"
                    role="tab"
                >
                    <i class="bi bi-stars"></i>
                    Configuração IA
                </button>
            </li>

        
            
</ul>

        <!-- Conteúdo das abas -->
        <div class="tab-content" id="configTabsContent">

            <!-- Configuração do site -->
            <div
                class="tab-pane fade show active"
                id="site-config"
                role="tabpanel"
            >

                <div class="card mt-3">

                    <div class="card-header">
                        <i class="bi bi-person-lock"></i>
                        Alterar credenciais de acesso
                    </div>

                    <div class="card-body">

                        <form id="form-change-password">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($securityCsrf, ENT_QUOTES, 'UTF-8'); ?>">

                            <div class="form-group">
                                <label>Senha atual</label>

                                <input
                                    type="password"
                                    name="current_password"
                                    class="form-control"
                                    required
                                >
                            </div>

                            <div class="form-group">
                                <label>Novo usuário</label>

                                <input
                                    type="text"
                                    name="new_username"
                                    class="form-control"
                                    value="<?php echo $_SESSION['username']; ?>"
                                    required
                                >
                            </div>

                            <div class="form-group">

                                <label>
                                    Nova senha
                                    (deixe em branco se não quiser alterar)
                                </label>

                                <input
                                    type="password"
                                    name="new_password"
                                    class="form-control"
                                    minlength="12"
                                >

                            </div>

                            <div class="form-group">

                                <label>
                                    Confirmar nova senha
                                </label>

                                <input
                                    type="password"
                                    name="confirm_password"
                                    class="form-control"
                                >

                            </div>

                            <button
                                type="submit"
                                class="btn btn-primary"
                            >
                                <i class="bi bi-save"></i>
                                Salvar alterações
                            </button>

                        </form>

                    </div>

                </div>

            </div>


            <!-- Configuração ACS -->
            <div
                class="tab-pane fade"
                id="acs-config"
                role="tabpanel"
            >

                <div class="card mt-3">

                    <div class="card-header">

                        <i class="bi bi-hdd-network"></i>
                        Configuração do GenieACS

                        <?php if ($genieacs && $genieacs['is_connected']): ?>

                            <span class="badge online float-end">

                                Conectado

                                <?php if (!empty($genieacs['role'])): ?>

                                    / Perfil [
                                    <?php
                                    echo htmlspecialchars(
                                        $genieacs['role']
                                    );
                                    ?>
                                    ]

                                <?php endif; ?>

                            </span>

                        <?php endif; ?>

                    </div>

                    <div class="card-body">

                        <form id="form-genieacs">

                            <div class="form-group">

                                <label>Host</label>

                                <input
                                    type="text"
                                    name="host"
                                    class="form-control"
                                    value="<?php echo $genieacs['host'] ?? '192.168.1.1'; ?>"
                                    placeholder="127.0.0.1"
                                    required
                                >

                            </div>

                            <div class="form-group">

                                <label>Porta</label>

                                <input
                                    type="number"
                                    name="port"
                                    class="form-control"
                                    value="<?php echo $genieacs['port'] ?? '7557'; ?>"
                                    placeholder="7557"
                                    required
                                >

                            </div>

                            <div class="form-group">

                                <label>
                                    Usuário (opcional)
                                </label>

                                <input
                                    type="text"
                                    name="username"
                                    class="form-control"
                                    value="<?php echo $genieacs['username'] ?? ''; ?>"
                                    placeholder="Usuário"
                                >

                            </div>

                            <div class="form-group">

                                <label>
                                    Senha (opcional)
                                </label>

                                <input
                                    type="password"
                                    name="password"
                                    class="form-control"
                                    value="<?php echo $genieacs['password'] ?? ''; ?>"
                                    placeholder="Senha"
                                >

                            </div>

                            <button
                                type="submit"
                                class="btn btn-success me-2"
                            >
                                <i class="bi bi-check-circle"></i>
                                Testar conexão
                            </button>

                            <button
                                type="button"
                                class="btn btn-primary"
                                onclick="saveGenieACS()"
                            >
                                <i class="bi bi-save"></i>
                                Salvar
                            </button>

                            <?php if ($genieacs && $genieacs['last_test']): ?>

                                <small class="text-muted d-block mt-2">

                                    Último teste:

                                    <?php
                                    echo timeAgo(
                                        $genieacs['last_test']
                                    );
                                    ?>

                                </small>

                            <?php endif; ?>

                        </form>

                    </div>

                </div>

            </div>


            <!-- Configuração MikroTik -->
            <div
                class="tab-pane fade"
                id="mikrotik-config"
                role="tabpanel"
            >

                <div class="card mt-3">

                    <div class="card-header">

                        <i class="bi bi-ethernet"></i>
                        Configuração do MikroTik

                        <?php if ($mikrotik && $mikrotik['is_connected']): ?>

                            <span class="badge online float-end">
                                Conectado
                            </span>

                        <?php endif; ?>

                    </div>

                    <div class="card-body">

                        <form id="form-mikrotik">

                            <div class="form-group">

                                <label>Host</label>

                                <input
                                    type="text"
                                    name="host"
                                    class="form-control"
                                    value="<?php echo $mikrotik['host'] ?? ''; ?>"
                                    placeholder="192.168.1.1"
                                    required
                                >

                            </div>

                            <div class="form-group">

                                <label>Porta da API</label>

                                <input
                                    type="number"
                                    name="port"
                                    class="form-control"
                                    value="<?php echo $mikrotik['port'] ?? '8728'; ?>"
                                    placeholder="8728"
                                    required
                                >

                            </div>

                            <div class="form-group">

                                <label>Usuário</label>

                                <input
                                    type="text"
                                    name="username"
                                    class="form-control"
                                    value="<?php echo $mikrotik['username'] ?? ''; ?>"
                                    placeholder="admin"
                                    required
                                >

                            </div>

                            <div class="form-group">

                                <label>Senha</label>

                                <input
                                    type="password"
                                    name="password"
                                    class="form-control"
                                    value="<?php echo $mikrotik['password'] ?? ''; ?>"
                                    placeholder="Senha"
                                    required
                                >

                            </div>

                            <button
                                type="submit"
                                class="btn btn-success me-2"
                            >
                                <i class="bi bi-check-circle"></i>
                                Testar conexão
                            </button>

                            <button
                                type="button"
                                class="btn btn-primary"
                                onclick="saveMikroTik()"
                            >
                                <i class="bi bi-save"></i>
                                Salvar
                            </button>

                            <?php if ($mikrotik && $mikrotik['last_test']): ?>

                                <small class="text-muted d-block mt-2">

                                    Último teste:

                                    <?php
                                    echo timeAgo(
                                        $mikrotik['last_test']
                                    );
                                    ?>

                                </small>

                            <?php endif; ?>

                        </form>

                    </div>

                </div>

            </div>


            <!-- Concentradores / BRAS -->
            <div
                class="tab-pane fade"
                id="concentrators-config"
                role="tabpanel"
            >
                <div class="card mt-3">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span>
                            <i class="bi bi-diagram-3"></i>
                            Concentradores / BRAS
                        </span>
                        <span>
                            <?php
                                $connectedConcentrators = array_values(array_filter(
                                    $concentrators,
                                    static fn(array $item): bool => !empty($item['is_connected'])
                                ));
                            ?>
                            <span class="badge bg-info"><?php echo count($concentrators); ?> cadastrado(s)</span>
                            <?php if (count($connectedConcentrators) > 0): ?>
                                <span class="badge online ms-1"><?php echo count($connectedConcentrators); ?> conectado(s)</span>
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            Cadastre o BRAS para o ACS autenticar diretamente no equipamento.
                            Nesta etapa, Huawei NE8000 via SSH é suportado. O teste é somente leitura e usa
                            <code>display version</code>.
                        </div>

                        <form id="form-concentrator">
                            <input type="hidden" name="id" id="concentrator-id" value="">

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Nome do concentrador</label>
                                        <input
                                            type="text"
                                            name="name"
                                            id="concentrator-name"
                                            class="form-control"
                                            placeholder="BRAS_NE8000"
                                            required
                                        >
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Fabricante</label>
                                        <select name="vendor" id="concentrator-vendor" class="form-control" required>
                                            <option value="huawei">Huawei</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Modelo</label>
                                        <input
                                            type="text"
                                            name="model"
                                            id="concentrator-model"
                                            class="form-control"
                                            value="NE8000"
                                            placeholder="NE8000"
                                            required
                                        >
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Endereço IPv4 / IPv6 / Host</label>
                                        <input
                                            type="text"
                                            name="host"
                                            id="concentrator-host"
                                            class="form-control"
                                            placeholder="138.204.112.5"
                                            required
                                        >
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Protocolo</label>
                                        <select name="protocol" id="concentrator-protocol" class="form-control" required>
                                            <option value="ssh">SSH</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Porta</label>
                                        <input
                                            type="number"
                                            name="port"
                                            id="concentrator-port"
                                            class="form-control"
                                            value="22"
                                            min="1"
                                            max="65535"
                                            required
                                        >
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Usuário SSH</label>
                                        <input
                                            type="text"
                                            name="username"
                                            id="concentrator-username"
                                            class="form-control"
                                            placeholder="Usuário técnico somente leitura"
                                            autocomplete="off"
                                            required
                                        >
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Senha SSH</label>
                                        <input
                                            type="password"
                                            name="password"
                                            id="concentrator-password"
                                            class="form-control"
                                            value=""
                                            autocomplete="new-password"
                                            placeholder="Informe a senha"
                                        >
                                        <small class="text-muted" id="concentrator-password-help">
                                            A senha é criptografada no servidor e não volta para o navegador.
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Nome correspondente no IXC</label>
                                        <input
                                            type="text"
                                            name="ixc_name"
                                            id="concentrator-ixc-name"
                                            class="form-control"
                                            placeholder="BRAS_NE8000"
                                        >
                                        <small class="text-muted">
                                            Usado para relacionar o concentrador retornado pelo IXC.
                                        </small>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>NAS IP do RADIUS</label>
                                        <input
                                            type="text"
                                            name="nas_ip"
                                            id="concentrator-nas-ip"
                                            class="form-control"
                                            placeholder="138.204.112.5"
                                        >
                                        <small class="text-muted">
                                            Permite escolher automaticamente o BRAS pela sessão PPPoE.
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex align-items-center gap-4 mb-3">
                                <div class="form-check">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        value="1"
                                        name="is_default"
                                        id="concentrator-default"
                                    >
                                    <label class="form-check-label" for="concentrator-default">
                                        Concentrador principal
                                    </label>
                                </div>

                                <div class="form-check">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        value="1"
                                        name="is_active"
                                        id="concentrator-active"
                                        checked
                                    >
                                    <label class="form-check-label" for="concentrator-active">
                                        Ativo
                                    </label>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-2">
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-plug"></i>
                                    Testar conexão
                                </button>

                                <button type="button" class="btn btn-primary" onclick="saveConcentrator()">
                                    <i class="bi bi-save"></i>
                                    Salvar
                                </button>

                                <button type="button" class="btn btn-secondary" onclick="newConcentrator()">
                                    <i class="bi bi-plus-circle"></i>
                                    Novo
                                </button>
                            </div>
                        </form>

                        <hr class="my-4">

                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <strong>Concentradores cadastrados</strong>
                            <small class="text-muted">A senha nunca é exibida nesta lista.</small>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-dark table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Nome</th>
                                        <th>Equipamento</th>
                                        <th>Endereço</th>
                                        <th>Usuário</th>
                                        <th>IXC / NAS</th>
                                        <th>Status</th>
                                        <th>Último teste</th>
                                        <th class="text-end">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($concentrators)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">
                                            Nenhum concentrador cadastrado.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($concentrators as $concentrator): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($concentrator['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                <?php if (!empty($concentrator['is_default'])): ?>
                                                    <span class="badge bg-info ms-1">Principal</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars(strtoupper($concentrator['vendor']) . ' ' . $concentrator['model'], ENT_QUOTES, 'UTF-8'); ?>
                                            </td>
                                            <td>
                                                <code><?php echo htmlspecialchars($concentrator['host'] . ':' . $concentrator['port'], ENT_QUOTES, 'UTF-8'); ?></code>
                                            </td>
                                            <td><?php echo htmlspecialchars($concentrator['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td>
                                                <div><?php echo htmlspecialchars($concentrator['ixc_name'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($concentrator['nas_ip'] ?: 'NAS não definido', ENT_QUOTES, 'UTF-8'); ?></small>
                                            </td>
                                            <td>
                                                <?php if (empty($concentrator['is_active'])): ?>
                                                    <span class="badge bg-secondary">Inativo</span>
                                                <?php elseif (!empty($concentrator['is_connected'])): ?>
                                                    <span class="badge online">Conectado</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark">Não testado</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo !empty($concentrator['last_test']) ? htmlspecialchars(timeAgo($concentrator['last_test']), ENT_QUOTES, 'UTF-8') : '—'; ?>
                                                <?php if (!empty($concentrator['last_error'])): ?>
                                                    <div><small class="text-danger"><?php echo htmlspecialchars($concentrator['last_error'], ENT_QUOTES, 'UTF-8'); ?></small></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-info"
                                                    onclick="editConcentrator(<?php echo (int)$concentrator['id']; ?>)"
                                                    title="Editar"
                                                >
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-success"
                                                    onclick="testSavedConcentrator(<?php echo (int)$concentrator['id']; ?>)"
                                                    title="Testar"
                                                >
                                                    <i class="bi bi-plug"></i>
                                                </button>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-danger"
                                                    onclick="deleteConcentrator(<?php echo (int)$concentrator['id']; ?>)"
                                                    title="Excluir"
                                                >
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="alert alert-secondary mb-0">
                            <i class="bi bi-shield-lock"></i>
                            A integração usa uma conta técnica do concentrador. Para monitoramento,
                            mantenha esse usuário com permissões de leitura apenas. Depois da autenticação,
                            a aba Monitoramento poderá consultar a sessão PPPoE e os contadores diretamente
                            no NE8000.
                        </div>
                    </div>
                </div>
            </div>


            <!-- Configuração do Bot -->
            <div
                class="tab-pane fade"
                id="bot-config"
                role="tabpanel"
            >

                <div class="card mt-3">

                    <div class="card-header">

                        <i class="fab fa-telegram"></i>
                        Configuração do Bot do Telegram

                        <?php if ($telegram && $telegram['is_connected']): ?>

                            <span class="badge online float-end">
                                Conectado
                            </span>

                        <?php endif; ?>

                    </div>

                    <div class="card-body">

                        <form id="form-telegram">

                            <div class="form-group">

                                <label>Token do Bot</label>

                                <input
                                    type="text"
                                    name="bot_token"
                                    class="form-control"
                                    value="<?php echo $telegram['bot_token'] ?? ''; ?>"
                                    placeholder="123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11"
                                    required
                                >

                                <small class="text-muted">
                                    Obtenha o token através do @BotFather
                                </small>

                            </div>

                            <div class="form-group">

                                <label>ID do Chat</label>

                                <input
                                    type="text"
                                    name="chat_id"
                                    class="form-control"
                                    value="<?php echo $telegram['chat_id'] ?? ''; ?>"
                                    placeholder="123456789"
                                    required
                                >

                                <small class="text-muted">
                                    Obtenha o ID através do @userinfobot
                                </small>

                            </div>

                            <button
                                type="submit"
                                class="btn btn-success me-2"
                            >
                                <i class="bi bi-check-circle"></i>
                                Testar conexão
                            </button>

                            <button
                                type="button"
                                class="btn btn-primary"
                                onclick="saveTelegram()"
                            >
                                <i class="bi bi-save"></i>
                                Salvar
                            </button>

                            <?php if ($telegram && $telegram['last_test']): ?>

                                <small class="text-muted d-block mt-2">

                                    Último teste:

                                    <?php
                                    echo timeAgo(
                                        $telegram['last_test']
                                    );
                                    ?>

                                </small>

                            <?php endif; ?>

                        </form>

                    </div>

                </div>

            </div>


            <!-- Perfis de Equipamentos -->
            <div
                class="tab-pane fade"
                id="cpe-profiles-config"
                role="tabpanel"
            >
                <div class="card mt-3">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span><i class="bi bi-router"></i> Perfis de Equipamentos / CPE</span>
                        <span>
                            <span class="badge bg-success me-1">Auto-discovery ativo</span>
                            <span class="badge bg-info"><?php echo count($cpeProfiles); ?> perfis fixos</span>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            O painel usa primeiro os perfis conhecidos e, quando o modelo é novo ou possui caminhos diferentes, executa descoberta automática somente-leitura na árvore TR-069 para localizar Wi-Fi, PPPoE e dados ópticos.
                        </div>

                        <div class="table-responsive">
                            <table class="table table-dark table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Fabricante</th>
                                        <th>Modelo</th>
                                        <th>Wi-Fi</th>
                                        <th>Óptico</th>
                                        <th>ID do perfil</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cpeProfiles as $profileId => $profile): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($profile['vendor'] ?? 'N/D', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><strong><?php echo htmlspecialchars($profile['model'] ?? 'N/D', ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                            <td>
                                                <?php
                                                    $wifi = $profile['wifi'] ?? [];
                                                    $wifiCount = count($wifi['ssid_24'] ?? []) + count($wifi['ssid_5'] ?? []);
                                                ?>
                                                <span class="badge bg-<?php echo $wifiCount ? 'success' : 'secondary'; ?>">
                                                    <?php echo $wifiCount ? 'Mapeado' : 'Padrão'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php
                                                    $optical = $profile['optical'] ?? [];
                                                    $opticalCount = count($optical['rx'] ?? []) + count($optical['tx'] ?? []);
                                                ?>
                                                <span class="badge bg-<?php echo $opticalCount ? 'success' : 'secondary'; ?>">
                                                    <?php echo $opticalCount ? 'RX/TX mapeado' : 'Padrão'; ?>
                                                </span>
                                            </td>
                                            <td><code><?php echo htmlspecialchars($profileId, ENT_QUOTES, 'UTF-8'); ?></code></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="alert alert-secondary mb-0">
                            <i class="bi bi-diagram-3"></i>
                            Perfis fixos: Huawei EG8145V5, Huawei HG8145V5, FiberHome HG6143D3 e Nokia G-240W-A. Modelos novos também podem funcionar automaticamente pelo mecanismo de discovery; perfis fixos continuam disponíveis para ajustes específicos de fabricante/firmware.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Configuração IA -->
            <div
                class="tab-pane fade"
                id="ai-config"
                role="tabpanel"
            >
                <div class="card mt-3">

                    <div class="card-header">
                        <i class="bi bi-stars"></i>
                        Configuração da IA

                        <span class="float-end">
                            <?php if (!empty($aiProviders['openai']['is_connected'])): ?>
                                <span class="badge online me-1">OpenAI conectado</span>
                            <?php endif; ?>
                            <?php if (!empty($aiProviders['anthropic']['is_connected'])): ?>
                                <span class="badge online">Claude conectado</span>
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="card-body">

                        <form id="form-ai">

                            <div class="form-group">
                                <label>Provedor</label>
                                <select
                                    name="provider"
                                    id="ai-provider"
                                    class="form-control"
                                    required
                                >
                                    <option
                                        value="openai"
                                        <?php echo ($activeAIProvider === 'openai') ? 'selected' : ''; ?>
                                    >
                                        OpenAI
                                    </option>
                                    <option
                                        value="anthropic"
                                        <?php echo ($activeAIProvider === 'anthropic') ? 'selected' : ''; ?>
                                    >
                                        Claude (Anthropic)
                                    </option>
                                </select>

                                <small class="text-muted">
                                    O painel usa a API do provedor pelo backend.
                                </small>
                            </div>

                            <div class="form-group">
                                <label>Token / API Key</label>

                                <input
                                    type="password"
                                    name="api_key"
                                    id="ai-api-key"
                                    class="form-control"
                                    value=""
                                    autocomplete="new-password"
                                    placeholder="<?php echo !empty($aiProviders[$activeAIProvider]['configured']) ? 'Chave já configurada — deixe em branco para manter' : 'Cole sua API key aqui'; ?>"
                                >

                                <small class="text-muted">
                                    A chave não é exibida novamente no navegador.
                                </small>
                            </div>

                            <div class="form-group">
                                <label>Modelo</label>

                                <input
                                    type="text"
                                    name="model"
                                    id="ai-model"
                                    class="form-control"
                                    list="ai-model-options"
                                    value="<?php echo htmlspecialchars($ai['model'] ?? defaultAIModel($activeAIProvider), ENT_QUOTES, 'UTF-8'); ?>"
                                    required
                                >

                                <datalist id="ai-model-options"></datalist>

                                <small class="text-muted" id="ai-model-help">
                                    Escolha o modelo disponível no provedor selecionado.
                                </small>
                            </div>

                            <button
                                type="submit"
                                class="btn btn-success me-2"
                            >
                                <i class="bi bi-check-circle"></i>
                                Testar conexão
                            </button>

                            <button
                                type="button"
                                class="btn btn-primary"
                                onclick="saveAI()"
                            >
                                <i class="bi bi-save"></i>
                                Salvar
                            </button>

                            <?php if ($ai && !empty($ai['last_test'])): ?>
                                <small class="text-muted d-block mt-2">
                                    Último teste:
                                    <?php echo timeAgo($ai['last_test']); ?>
                                </small>
                            <?php endif; ?>

                            <div class="alert alert-secondary mt-3 mb-0">
                                <i class="bi bi-shield-lock"></i>
                                O token fica no servidor e não é enviado para o JavaScript da tela.
                            </div>

                        </form>

                    </div>

                </div>
            </div>

            


        </div>

    </div>

</div>
<!-- Carregar JavaScript externo -->
<script>
window.AI_PROVIDER_CONFIG = <?php echo json_encode($aiProviders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
window.ACTIVE_AI_PROVIDER = <?php echo json_encode($activeAIProvider); ?>;
window.CONCENTRATORS = <?php echo json_encode($concentrators, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="/assets/js/configuration.js?v=<?php echo time(); ?>"></script>

<?php
include __DIR__ . '/views/layouts/footer.php';
?>