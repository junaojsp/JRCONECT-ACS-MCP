<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/Security.php';

use App\RateLimiter;

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('/dashboard.php');
}

$error = '';
$conn = getDBConnection();
$csrf = securityCsrfToken();

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $givenCsrf = (string)($_POST['csrf_token'] ?? '');

    if (!securityVerifyCsrf($givenCsrf)) {
        $error = 'Sessão de login expirada. Atualize a página e tente novamente.';
    } elseif ($username === '' || $password === '') {
        $error = 'Usuário e senha são obrigatórios.';
    } else {
        $identifier = strtolower($username) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        try {
            $limiter = new RateLimiter($conn);
            $limit = $limiter->check($identifier, 'web-login', 8, 300);
            if (!$limit['allowed']) {
                $retry = max(1, (int)($limit['retry_after'] ?? 60));
                $error = 'Muitas tentativas de login. Tente novamente em ' . $retry . ' segundos.';
            }
        } catch (Throwable $rateError) {
            error_log('[SECURITY] Falha no rate limiter do login: ' . $rateError->getMessage());
        }

        if ($error === '') {
            $securityReady = securityColumnsReady($conn);
            $extraFields = $securityReady
                ? ', two_factor_secret, two_factor_enabled, two_factor_recovery, two_factor_confirmed_at'
                : '';

            $stmt = $conn->prepare(
                'SELECT id, name, username, password, role, active' . $extraFields . '
                 FROM users
                 WHERE username = ?
                 LIMIT 1'
            );
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$user || (int)($user['active'] ?? 0) !== 1 || !password_verify($password, (string)$user['password'])) {
                $error = 'Usuário ou senha inválidos.';
                usleep(250000);
            } else {
                securityLoginComplete($conn, $user);
                redirect('/dashboard.php');
            }
        }
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>JRC-ACS • Acesso</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg?v=20260921">
<link rel="stylesheet" href="/assets/css/login-acs.css?v=20261008-animado"><!-- JRC NOC SHARED THEME -->
<link rel="stylesheet" href="/assets/css/jrc-theme.css?v=1">
<script src="/assets/js/jrc-theme.js?v=1" defer></script>
<!-- /JRC NOC SHARED THEME -->
</head>
<body><main class="login" id="login">
<section class="visual" aria-label="JRC-ACS, plataforma de gestão de equipamentos">
<img class="art" src="/assets/img/acs-login-hero.webp" alt="Roteador com globo holográfico ciano e conexões digitais">
<div class="specks" aria-hidden="true"></div><div class="scan" aria-hidden="true"></div><div class="signal" aria-hidden="true"></div><div class="signal second" aria-hidden="true"></div><div class="shade"></div>
<div class="brand"><img src="/assets/img/jrconect-logo.svg?v=20260921" alt="JR CONECT Telecom"><div>JR CONECT<span>.</span><small>TELECOM</small></div></div>
<div class="hero"><div class="eyebrow">GESTÃO CENTRALIZADA DE EQUIPAMENTOS</div><h1>JRC-ACS</h1><h2>Sua rede conectada.<br>Seu controle centralizado.</h2><p>Provisionamento, diagnóstico e gestão de dispositivos em um único ambiente.</p></div>
<button class="pause" type="button" aria-pressed="false">Pausar animação</button>
</section>
<section class="form-panel"><div class="form-wrap"><div class="access">ACESSO RESTRITO</div><h2>Bem-vindo ao<br>JRC-ACS</h2><p class="intro">Entre com suas credenciais para acessar<br>a plataforma de gestão de equipamentos.</p>
<?php if ($error): ?>
<div class="login-error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>
<form id="login-form" method="POST" action="" autocomplete="on"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"><label class="field" for="username">Usuário</label><input class="text" id="username" name="username" autocomplete="username" autofocus value="<?php echo htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Seu usuário" required>
<label class="field" for="password">Senha</label><div class="password"><input class="text" id="password" name="password" type="password" autocomplete="current-password" placeholder="Digite sua senha" required><button class="show" type="button" aria-controls="password" aria-pressed="false">Mostrar</button></div>
<button class="submit" type="submit">Acessar plataforma <span aria-hidden="true">→</span></button></form>
<div class="foot">JR CONECT Telecom · Uso interno<br>Gestão centralizada de equipamentos</div></div></section></main>
<script src="/assets/js/login-acs.js?v=20261008" defer></script></body></html>
