<?php
declare(strict_types=1);

/**
 * Dedicated read-only router list under the reports navigation.
 * Consumes the same local MySQL inventory snapshot/classifier as
 * /api/jrc-router-signal-summary.php (not the optical IXC signal report).
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/JrcRouterReportRows.php';
requireLogin();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$pageTitle = 'Relatórios - Roteadores';
$currentPage = 'logs';

$queryInput = $_GET['q'] ?? '';
$search = is_string($queryInput) ? substr(trim($queryInput), 0, 80) : '';
$pageInput = $_GET['page'] ?? 1;
$pageCandidate = filter_var($pageInput, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 100000],
]);
$page = $pageCandidate !== false ? (int)$pageCandidate : 1;
$perPage = 20;

$rows = [];
$totalRouters = 0;
$deviceCount = 0;
$cacheAge = null;
$cacheDate = null;
$error = null;
try {
    $db = getDBConnection();
    $result = $db->query('SELECT snapshot_id,total,completed_at,
        TIMESTAMPDIFF(SECOND,completed_at,NOW()) AS age_seconds
        FROM jrc_device_inventory_state WHERE id=1');
    $state = $result ? $result->fetch_assoc() : null;
    if (!$state || (int)$state['total'] < 1) {
        throw new RuntimeException('O inventário ainda não foi coletado.');
    }
    $cacheAge = max(0, (int)($state['age_seconds'] ?? 0));
    $cacheDate = (string)$state['completed_at'];

    // Read the exact same published snapshot pointer as the overview.
    // The SELECT contains no SQL interpolation or subscriber input.
    $stmt = $db->prepare(
        'SELECT payload_json FROM jrc_device_inventory_rows
         WHERE snapshot_id = ? ORDER BY device_id'
    );
    if (!$stmt) throw new RuntimeException('Falha na consulta do inventário.');
    $snapshotId = (string)$state['snapshot_id'];
    $stmt->bind_param('s', $snapshotId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($item = $result->fetch_assoc()) {
        $device = json_decode((string)$item['payload_json'], true);
        if (!is_array($device)) {
            throw new RuntimeException('Um registro do inventário está inválido.');
        }
        ++$deviceCount;
        $projected = JrcRouterReportRows::project($device);
        if ($projected === null) continue;
        ++$totalRouters;
        if (JrcRouterReportRows::matches($projected, $search)) {
            $rows[] = $projected;
        }
    }
    $stmt->close();
    if ($deviceCount !== (int)$state['total']) {
        throw new RuntimeException('O inventário mudou durante a leitura. Atualize a página.');
    }
} catch (Throwable $exception) {
    error_log('JRC router report: ' . get_class($exception));
    $error = 'Não foi possível consultar o inventário neste momento.';
    $rows = [];
}

$filtered = count($rows);
$totalPages = max(1, (int)ceil($filtered / $perPage));
$page = min($page, $totalPages);
$visible = array_slice($rows, ($page - 1) * $perPage, $perPage);

/** Escape every value from the local snapshot before rendering HTML. */
function jrcRouterEsc(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jrcRouterPageUrl(int $page, string $search): string
{
    return '/router-report.php?' . http_build_query(
        ['q' => $search, 'page' => $page], '', '&', PHP_QUERY_RFC3986
    );
}

include __DIR__ . '/views/layouts/header.php';
?>

<style>
.jrc-router-report { max-width: 100%; }
.jrc-router-report .jrc-router-card {
    background: #0e2136; border: 1px solid #264760;
    border-radius: 10px; padding: 18px; margin-bottom: 14px;
}
.jrc-router-report .jrc-router-muted { color: #9db3c7; }
.jrc-router-report .jrc-router-table { overflow-x: auto; }
.jrc-router-report table { width: 100%; border-collapse: collapse; }
.jrc-router-report th {
    font-size: 12px; font-weight: 650; text-transform: uppercase;
    color: #9db3c7; background: #14283e;
}
.jrc-router-report th, .jrc-router-report td {
    padding: 13px 12px; border-bottom: 1px solid #294259;
}
.jrc-router-report td { color: #e5edf6; }
.jrc-router-report input {
    background: #0b1b2c; color: #fff; border: 1px solid #31506b;
    border-radius: 6px; padding: 9px 12px; max-width: 390px; width: 100%;
}
.jrc-router-report .jrc-router-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.jrc-router-report .jrc-router-btn {
    display: inline-block; padding: 8px 13px; color: #fff;
    background: #1564b1; border: 0; border-radius: 6px;
    text-decoration: none; cursor: pointer;
}
.jrc-router-report .jrc-router-btn.secondary { background: #233f58; }
.jrc-router-report .jrc-router-btn:focus-visible {
    outline: 2px solid #81beff; outline-offset: 3px;
}
</style>

<div class="jrc-router-report">
    <div class="jrc-router-card">
        <div class="jrc-router-actions" style="justify-content: space-between;">
            <div>
                <h2 style="font-size: 22px; margin: 0 0 8px;">Relatórios / Roteadores</h2>
                <p class="jrc-router-muted" style="margin: 0;">
                    Equipamentos identificados como roteadores comuns no inventário ACS.
                    Sem exigir leitura óptica de dispositivos que não possuem ONU integrada.
                </p>
            </div>
            <a class="jrc-router-btn secondary" href="/reports-hub.php">Voltar aos relatórios</a>
        </div>
        <div class="jrc-router-actions" style="margin-top: 16px;">
            <strong>Roteadores: <?= jrcRouterEsc($totalRouters) ?></strong>
            <span class="jrc-router-muted">· Encontrados na busca: <?= jrcRouterEsc($filtered) ?></span>
            <?php if ($cacheAge !== null): ?>
                <span class="jrc-router-muted">
                    · Inventário coletado há <?= jrcRouterEsc((int)ceil($cacheAge / 60)) ?> min
                </span>
            <?php endif; ?>
        </div>
        <?php if ($cacheAge !== null && $cacheAge > 900): ?>
            <p class="jrc-router-muted" role="status" style="margin-top: 12px;">
                Atenção: inventário com mais de 15 minutos. Os dados podem estar desatualizados.
            </p>
        <?php endif; ?>
    </div>

    <div class="jrc-router-card">
        <form action="/router-report.php" method="get" class="jrc-router-actions">
            <label for="routerSearch" class="visually-hidden">Buscar roteador ou cliente</label>
            <input id="routerSearch" name="q" value="<?= jrcRouterEsc($search) ?>"
                placeholder="Buscar equipamento, cliente, serial ou IP" maxlength="80">
            <button type="submit" class="jrc-router-btn">Buscar</button>
            <a href="/router-report.php" class="jrc-router-btn secondary">Limpar</a>
        </form>
    </div>

    <div class="jrc-router-card">
        <?php if ($error !== null): ?>
            <p role="alert"><?= jrcRouterEsc($error) ?></p>
        <?php elseif (!$visible): ?>
            <p class="jrc-router-muted">Nenhum roteador encontrado com esse filtro.</p>
        <?php else: ?>
            <div class="jrc-router-table">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Equipamento</th>
                            <th scope="col">Cliente (PPPoE)</th>
                            <th scope="col">Serial</th>
                            <th scope="col">IP WAN</th>
                            <th scope="col">Última comunicação</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($visible as $item): ?>
                        <tr>
                            <td><strong><?= jrcRouterEsc($item['equipment'] ?: 'Roteador') ?></strong></td>
                            <td><?= jrcRouterEsc($item['pppoe'] ?: $item['customer'] ?: '—') ?></td>
                            <td><?= jrcRouterEsc($item['serial'] ?: '—') ?></td>
                            <td><?= jrcRouterEsc($item['ip'] ?: '—') ?></td>
                            <td><?= jrcRouterEsc($item['last_inform'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if (!$error && $totalPages > 1): ?>
            <nav aria-label="Páginas dos roteadores" class="jrc-router-actions"
                 style="justify-content: flex-end; margin-top: 14px;">
                <?php if ($page > 1): ?>
                    <a class="jrc-router-btn secondary" href="<?= jrcRouterEsc(jrcRouterPageUrl($page - 1, $search)) ?>">Anterior</a>
                <?php endif; ?>
                <span class="jrc-router-muted">Página <?= jrcRouterEsc($page) ?> de <?= jrcRouterEsc($totalPages) ?></span>
                <?php if ($page < $totalPages): ?>
                    <a class="jrc-router-btn" href="<?= jrcRouterEsc(jrcRouterPageUrl($page + 1, $search)) ?>">Próxima</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/views/layouts/footer.php'; ?>
