<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/Security.php';

securityRequireRole(['admin']);
$securityCsrf = securityCsrfToken();

$pageTitle = 'Usuários';
$currentPage = 'users';

$conn = getDBConnection();

$message = '';
$error = '';

/*
|--------------------------------------------------------------------------
| AÇÕES
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!securityVerifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $error = 'Token de segurança inválido. Atualize a página e tente novamente.';
        $action = '';
    } else {
        $action = $_POST['action'] ?? '';
    }

    /*
    |--------------------------------------------------------------------------
    | NOVO USUÁRIO
    |--------------------------------------------------------------------------
    */

    if ($action === 'create') {

        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'atendimento';

        if ($username === '' || $password === '') {
            $error = 'Usuário e senha são obrigatórios.';
        } elseif (strlen($password) < 8) {
            $error = 'A senha deve possuir pelo menos 8 caracteres.';
        } else {

            $allowedRoles = [
                'admin',
                'noc',
                'atendimento'
            ];

            if (!in_array($role, $allowedRoles, true)) {
                $role = 'atendimento';
            }

            $check = $conn->prepare("
                SELECT id
                FROM users
                WHERE username = ?
                LIMIT 1
            ");

            $check->bind_param("s", $username);
            $check->execute();

            $exists = $check->get_result()->fetch_assoc();

            $check->close();

            if ($exists) {

                $error = 'Esse nome de usuário já existe.';

            } else {

                $hash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $stmt = $conn->prepare("
                    INSERT INTO users
                    (
                        name,
                        username,
                        password,
                        role,
                        active
                    )
                    VALUES (?, ?, ?, ?, 1)
                ");

                $stmt->bind_param(
                    "ssss",
                    $name,
                    $username,
                    $hash,
                    $role
                );

                if ($stmt->execute()) {
                    $message = 'Usuário criado com sucesso.';
                } else {
                    $error = 'Não foi possível criar o usuário.';
                }

                $stmt->close();
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ATIVAR / DESATIVAR
    |--------------------------------------------------------------------------
    */

    if ($action === 'toggle') {

        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {

            $stmt = $conn->prepare("
                UPDATE users
                SET active = IF(active = 1, 0, 1)
                WHERE id = ?
            ");

            $stmt->bind_param("i", $id);

            if ($stmt->execute()) {
                $message = 'Status do usuário atualizado.';
            } else {
                $error = 'Não foi possível atualizar o usuário.';
            }

            $stmt->close();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | TROCAR SENHA
    |--------------------------------------------------------------------------
    */

    if ($action === 'password') {

        $id = (int)($_POST['id'] ?? 0);

        $newPassword =
            $_POST['new_password'] ?? '';

        if (
            $id <= 0 ||
            strlen($newPassword) < 8
        ) {

            $error =
                'A nova senha deve possuir pelo menos 8 caracteres.';

        } else {

            $hash = password_hash(
                $newPassword,
                PASSWORD_DEFAULT
            );

            $stmt = $conn->prepare("
                UPDATE users
                SET password = ?
                WHERE id = ?
            ");

            $stmt->bind_param(
                "si",
                $hash,
                $id
            );

            if ($stmt->execute()) {
                $message = 'Senha alterada com sucesso.';
            } else {
                $error = 'Não foi possível alterar a senha.';
            }

            $stmt->close();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | EDITAR
    |--------------------------------------------------------------------------
    */

    if ($action === 'edit') {

        $id = (int)($_POST['id'] ?? 0);

        $name =
            trim($_POST['name'] ?? '');

        $role =
            $_POST['role'] ?? 'atendimento';

        $allowedRoles = [
            'admin',
            'noc',
            'atendimento'
        ];

        if (!in_array($role, $allowedRoles, true)) {
            $role = 'atendimento';
        }

        if ($id > 0) {

            $stmt = $conn->prepare("
                UPDATE users
                SET
                    name = ?,
                    role = ?
                WHERE id = ?
            ");

            $stmt->bind_param(
                "ssi",
                $name,
                $role,
                $id
            );

            if ($stmt->execute()) {
                $message = 'Usuário atualizado com sucesso.';
            } else {
                $error = 'Não foi possível atualizar o usuário.';
            }

            $stmt->close();
        }
    }
}


/*
|--------------------------------------------------------------------------
| LISTAR USUÁRIOS
|--------------------------------------------------------------------------
*/

$users = [];

$result = $conn->query("
    SELECT
        id,
        name,
        username,
        role,
        active,
        last_login,
        created_at
    FROM users
    ORDER BY username
");

while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}


/*
|--------------------------------------------------------------------------
| HEADER
|--------------------------------------------------------------------------
*/

include __DIR__ . '/views/layouts/header.php';

?>
<script>document.documentElement.classList.add('jrc-neutro-page')</script>
<link rel="stylesheet" href="/assets/css/jrc-neutro.css?v=8d5503daf8">
<!-- JRC_NEUTRO_V1 -->


<style>

.users-header {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    margin-bottom:24px;
}

.users-header h1 {
    margin:0;
}

.users-card {
    background:#102b3d;
    border:1px solid #21485e;
    border-radius:12px;
    padding:20px;
    margin-bottom:20px;
}

.users-table {
    width:100%;
    border-collapse:collapse;
}

.users-table th,
.users-table td {
    padding:12px 10px;
    border-bottom:1px solid #24475b;
    text-align:left;
}

.users-table th {
    color:#8db5ca;
    font-size:12px;
    text-transform:uppercase;
}

.user-status {
    display:inline-block;
    padding:4px 9px;
    border-radius:20px;
    font-size:11px;
    font-weight:bold;
}

.user-active {
    background:#145c3c;
    color:#6ff0af;
}

.user-disabled {
    background:#642c31;
    color:#ff9da5;
}

.user-role {
    font-size:12px;
    color:#8fd8ff;
}

.action-btn {
    border:0;
    padding:7px 10px;
    border-radius:6px;
    cursor:pointer;
    margin-right:4px;
}

.btn-edit {
    background:#126782;
    color:white;
}

.btn-password {
    background:#5744cc;
    color:white;
}

.btn-toggle {
    background:#ba7a00;
    color:white;
}

.users-form-grid {
    display:grid;
    grid-template-columns:
        repeat(auto-fit,minmax(180px,1fr));
    gap:15px;
}

.users-form-grid label {
    display:block;
    margin-bottom:6px;
    color:#a9c4d4;
    font-size:13px;
}

.users-form-grid input,
.users-form-grid select {
    width:100%;
    padding:10px;
    border-radius:6px;
    border:1px solid #315569;
    background:#081d2a;
    color:#fff;
}

.users-submit {
    margin-top:16px;
    background:#0288a8;
    border:0;
    color:white;
    padding:10px 16px;
    border-radius:7px;
    cursor:pointer;
}

.alert-success {
    background:#123f31;
    color:#75e7b3;
    padding:12px;
    border-radius:8px;
    margin-bottom:20px;
}

.alert-error {
    background:#4c2027;
    color:#ff9fa8;
    padding:12px;
    border-radius:8px;
    margin-bottom:20px;
}

.user-actions form {
    display:inline;
}

</style>


<div class="content-wrapper">

    <div class="users-header">

        <div>
            <h1>
                <i class="bi bi-people"></i>
                Usuários
            </h1>

            <div style="color:#8eabba;margin-top:5px;">
                Gerenciamento de acesso ao JR CONECT ACS
            </div>
        </div>

    </div>


    <?php if ($message): ?>

        <div class="alert-success">
            <i class="bi bi-check-circle"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>

    <?php endif; ?>


    <?php if ($error): ?>

        <div class="alert-error">
            <i class="bi bi-exclamation-triangle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>

    <?php endif; ?>


    <div class="users-card">

        <h3>
            <i class="bi bi-person-plus"></i>
            Novo usuário
        </h3>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($securityCsrf, ENT_QUOTES, 'UTF-8'); ?>">

            <input
                type="hidden"
                name="action"
                value="create"
            >

            <div class="users-form-grid">

                <div>
                    <label>Nome</label>

                    <input
                        type="text"
                        name="name"
                        placeholder="Nome do colaborador"
                    >
                </div>

                <div>
                    <label>Usuário</label>

                    <input
                        type="text"
                        name="username"
                        required
                        autocomplete="off"
                    >
                </div>

                <div>
                    <label>Senha</label>

                    <input
                        type="password"
                        name="password"
                        minlength="8"
                        required
                        autocomplete="new-password"
                    >
                </div>

                <div>
                    <label>Perfil</label>

                    <select name="role">

                        <option value="atendimento">
                            Atendimento
                        </option>

                        <option value="noc">
                            NOC / Suporte
                        </option>

                        <option value="admin">
                            Administrador
                        </option>

                    </select>

                </div>

            </div>

            <button
                type="submit"
                class="users-submit"
            >
                <i class="bi bi-plus-circle"></i>
                Criar usuário
            </button>

        </form>

    </div>


    <div class="users-card">

        <h3>
            <i class="bi bi-person-lines-fill"></i>
            Usuários cadastrados
        </h3>

        <div style="overflow-x:auto;">

            <table class="users-table">

                <thead>

                    <tr>
                        <th>Nome</th>
                        <th>Usuário</th>
                        <th>Perfil</th>
                        <th>Status</th>
                        <th>Último acesso</th>
                        <th>Criado em</th>
                        <th>Ações</th>
                    </tr>

                </thead>

                <tbody>

                <?php foreach ($users as $user): ?>

                    <tr>

                        <td>
                            <?php
                            echo htmlspecialchars(
                                $user['name'] ?: '-'
                            );
                            ?>
                        </td>

                        <td>
                            <strong>
                                <?php
                                echo htmlspecialchars(
                                    $user['username']
                                );
                                ?>
                            </strong>
                        </td>

                        <td>
                            <span class="user-role">

                            <?php

                            $roleLabels = [
                                'admin' => 'Administrador',
                                'noc' => 'NOC / Suporte',
                                'atendimento' => 'Atendimento'
                            ];

                            echo htmlspecialchars(
                                $roleLabels[$user['role']]
                                ?? $user['role']
                            );

                            ?>

                            </span>
                        </td>

                        <td>

                            <?php if ($user['active']): ?>

                                <span class="
                                    user-status
                                    user-active
                                ">
                                    ATIVO
                                </span>

                            <?php else: ?>

                                <span class="
                                    user-status
                                    user-disabled
                                ">
                                    BLOQUEADO
                                </span>

                            <?php endif; ?>

                        </td>

                        <td>
                            <?php
                            echo htmlspecialchars(
                                $user['last_login']
                                ?: 'Nunca'
                            );
                            ?>
                        </td>

                        <td>
                            <?php
                            echo htmlspecialchars(
                                $user['created_at']
                            );
                            ?>
                        </td>

                        <td class="user-actions">

                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($securityCsrf, ENT_QUOTES, 'UTF-8'); ?>">

                                <input
                                    type="hidden"
                                    name="action"
                                    value="toggle"
                                >

                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?php
                                    echo (int)$user['id'];
                                    ?>"
                                >

                                <button
                                    type="submit"
                                    class="
                                        action-btn
                                        btn-toggle
                                    "
                                >

                                    <?php
                                    echo $user['active']
                                        ? 'Bloquear'
                                        : 'Ativar';
                                    ?>

                                </button>

                            </form>


                            <button
                                type="button"
                                class="
                                    action-btn
                                    btn-password
                                "
                                onclick="
                                    changePassword(
                                        <?php
                                        echo (int)$user['id'];
                                        ?>
                                    )
                                "
                            >
                                Senha
                            </button>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>


<form
    method="POST"
    id="passwordForm"
    style="display:none;"
>
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($securityCsrf, ENT_QUOTES, 'UTF-8'); ?>">

    <input
        type="hidden"
        name="action"
        value="password"
    >

    <input
        type="hidden"
        name="id"
        id="passwordUserId"
    >

    <input
        type="hidden"
        name="new_password"
        id="passwordValue"
    >

</form>


<script>

function changePassword(userId) {

    const password = prompt(
        'Digite a nova senha (mínimo 8 caracteres):'
    );

    if (!password) {
        return;
    }

    if (password.length < 8) {

        alert(
            'A senha deve possuir pelo menos 8 caracteres.'
        );

        return;
    }

    document.getElementById(
        'passwordUserId'
    ).value = userId;

    document.getElementById(
        'passwordValue'
    ).value = password;

    document.getElementById(
        'passwordForm'
    ).submit();
}

</script>


<?php

include __DIR__ . '/views/layouts/footer.php';

?>
