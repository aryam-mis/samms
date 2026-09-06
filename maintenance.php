<?php
require_once __DIR__ . '/includes/auth.php';
require_role(['Admin', 'Manager', 'ITSupport']);

$pdo  = db();
$q    = trim($_GET['q'] ?? '');
$tech = (int) ($_GET['tech'] ?? 0);
$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';

$where = [];
$params = [];

if ($q !== '') {
    $where[] = like_any(
        ['a.AssetNumber', 'a.DeviceName', 'a.DeviceNameEn',
         'm.ActionTaken', 'm.ActionTakenEn'],
        $q, $params
    );
}
if ($tech > 0) {
    $where[] = 'm.ITSupportID = :tech';
    $params[':tech'] = $tech;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = 'm.MaintenanceDate >= :from';
    $params[':from'] = $from . ' 00:00:00';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = 'm.MaintenanceDate <= :to';
    $params[':to'] = $to . ' 23:59:59';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$baseSql  = "FROM Maintenance m
             JOIN MaintenanceRequests r ON r.RequestID = m.RequestID
             JOIN Assets a ON a.AssetID = r.AssetID
             JOIN Users  u ON u.UserID  = m.ITSupportID
             $whereSql";

$selectSql = "SELECT m.MaintenanceID, m.RequestID, m.MaintenanceDate, m.ActionTaken, m.ActionTakenEn,
                     m.PartsReplaced, m.PartsReplacedEn, m.Notes, m.NotesEn, m.CompletionDate,
                     a.AssetID, a.AssetNumber, a.DeviceName, a.DeviceNameEn,
                     u.FullName, u.FullNameEn
              $baseSql";

if (($_GET['export'] ?? '') !== '') {
    $stmt = $pdo->prepare($selectSql . ' ORDER BY m.MaintenanceDate DESC');
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $m) {
        $out[] = [
            $m['MaintenanceID'], $m['RequestID'], $m['AssetNumber'], loc($m, 'DeviceName'),
            loc($m, 'FullName'), $m['MaintenanceDate'], loc($m, 'ActionTaken'),
            loc($m, 'PartsReplaced'), loc($m, 'Notes'), $m['CompletionDate'],
        ];
    }
    handle_export('maintenance_log', [
        t('maint.number'), t('req.number'), t('asset.number'), t('req.asset'), t('maint.tech'),
        t('maint.date'), t('maint.action'), t('maint.parts'), t('maint.notes'), t('maint.completion'),
    ], $out, t('maint.title'));
}

$perPage = 15;
$countStmt = $pdo->prepare("SELECT COUNT(*) $baseSql");
$countStmt->execute($params);
$total  = (int) $countStmt->fetchColumn();
$pages  = max(1, (int) ceil($total / $perPage));
$page   = min(current_page(), $pages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare($selectSql . " ORDER BY m.MaintenanceDate DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$records = $stmt->fetchAll();

$techs = $pdo->query(
    "SELECT UserID, FullName, FullNameEn FROM Users WHERE Role IN ('ITSupport','Admin') ORDER BY FullName"
)->fetchAll();

$pageTitle = t('maint.title');
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <h1><?= e(t('maint.title')) ?></h1>
    <p><?= e(t('maint.sub')) ?></p>
</div>

<form class="filters" method="get" action="maintenance.php">
    <div class="field">
        <label for="q"><?= e(t('c.search')) ?></label>
        <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('c.search_ph')) ?>">
    </div>
    <div class="field">
        <label for="tech"><?= e(t('maint.tech')) ?></label>
        <select id="tech" name="tech">
            <option value=""><?= e(t('c.all')) ?></option>
            <?php foreach ($techs as $tc): ?>
                <option value="<?= (int) $tc['UserID'] ?>" <?= $tech === (int) $tc['UserID'] ? 'selected' : '' ?>>
                    <?= e(loc($tc, 'FullName')) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="from"><?= e(t('c.date')) ?> —</label>
        <input type="date" id="from" name="from" value="<?= e($from) ?>">
    </div>
    <div class="field">
        <label for="to">—</label>
        <input type="date" id="to" name="to" value="<?= e($to) ?>">
    </div>
    <div class="filter-actions">
        <button type="submit" class="btn btn-inline"><?= e(t('c.filter')) ?></button>
        <a href="maintenance.php" class="btn btn-inline btn-ghost"><?= e(t('c.reset')) ?></a>
    </div>
</form>

<section class="panel">
    <div class="panel-head">
        <h2><?= e(sprintf(t('c.total_rows'), $total)) ?></h2>
        <span class="export-group">
            <a href="<?= e(url_with(['export' => 'xlsx'])) ?>" class="btn btn-inline"><?= e(t('c.export_excel')) ?></a>
            <a href="<?= e(url_with(['export' => 'csv'])) ?>" class="btn btn-inline btn-ghost"><?= e(t('c.export_csv')) ?></a>
        </span>
    </div>

    <?php if (!$records): ?>
        <p class="empty"><?= e(t('c.no_results')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th><?= e(t('maint.number')) ?></th>
                        <th><?= e(t('req.asset')) ?></th>
                        <th><?= e(t('maint.tech')) ?></th>
                        <th><?= e(t('maint.date')) ?></th>
                        <th><?= e(t('maint.action')) ?></th>
                        <th><?= e(t('maint.parts')) ?></th>
                        <th><?= e(t('req.number')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($records as $m): ?>
                    <tr>
                        <td><span class="ident">#<?= (int) $m['MaintenanceID'] ?></span></td>
                        <td class="cell-strong">
                            <a href="asset_view.php?id=<?= (int) $m['AssetID'] ?>"><?= e(loc($m, 'DeviceName')) ?></a>
                            <span class="cell-muted block ident"><?= e($m['AssetNumber']) ?></span>
                        </td>
                        <td class="cell-muted"><?= e(loc($m, 'FullName')) ?></td>
                        <td class="cell-muted"><?= e(fmt_date($m['MaintenanceDate'])) ?></td>
                        <td><span class="truncate" title="<?= e(loc($m, 'ActionTaken')) ?>"><?= e(loc($m, 'ActionTaken')) ?></span></td>
                        <td class="cell-muted"><?= e(loc($m, 'PartsReplaced') ?: '—') ?></td>
                        <td><a href="request_view.php?id=<?= (int) $m['RequestID'] ?>" class="ident">#<?= (int) $m['RequestID'] ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <div class="pager">
                <a class="btn btn-inline btn-ghost <?= $page <= 1 ? 'disabled' : '' ?>"
                   href="<?= e(url_with(['p' => max(1, $page - 1)])) ?>"><?= e(t('c.prev')) ?></a>
                <span class="pager-info"><?= e(sprintf(t('c.page_of'), $page, $pages)) ?></span>
                <a class="btn btn-inline btn-ghost <?= $page >= $pages ? 'disabled' : '' ?>"
                   href="<?= e(url_with(['p' => min($pages, $page + 1)])) ?>"><?= e(t('c.next')) ?></a>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
