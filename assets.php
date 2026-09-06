<?php
require_once __DIR__ . '/includes/auth.php';
require_role(['Admin', 'Manager', 'ITSupport']);

$pdo = db();

// ----- الفلاتر -----
$q      = trim($_GET['q'] ?? '');
$type   = $_GET['type'] ?? '';
$status = $_GET['status'] ?? '';

$where  = [];
$params = [];

if ($q !== '') {
    $where[] = like_any(
        ['a.AssetNumber', 'a.SerialNumber', 'a.DeviceName', 'a.DeviceNameEn', 'a.Brand', 'a.Model'],
        $q, $params
    );
}
if (in_array($type, device_types(), true)) {
    $where[] = 'a.DeviceType = :type';
    $params[':type'] = $type;
}
if (in_array($status, asset_statuses(), true)) {
    $where[] = 'a.Status = :status';
    $params[':status'] = $status;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$baseSql = "FROM Assets a LEFT JOIN Users u ON u.UserID = a.AssignedUserID $whereSql";

$selectSql = "SELECT a.AssetID, a.AssetNumber, a.DeviceName, a.DeviceNameEn, a.DeviceType,
                     a.Brand, a.Model, a.SerialNumber, a.Location, a.LocationEn,
                     a.PurchaseDate, a.WarrantyExpiryDate, a.Status,
                     u.FullName AS Owner, u.FullNameEn AS OwnerEn
              $baseSql";

// ----- التصدير -----
if (($_GET['export'] ?? '') !== '') {
    // التصدير يحمل أعمدة تحليلية إضافية: القسم وتاريخ التسليم وعمر الجهاز
    // وسريان الضمان وعدد مرات الصيانة وتاريخ آخر صيانة.
    $exportSql = "SELECT a.AssetNumber, a.DeviceName, a.DeviceNameEn, a.DeviceType,
                         a.Brand, a.Model, a.SerialNumber,
                         a.AssignedDate, a.Location, a.LocationEn,
                         a.PurchaseDate, a.WarrantyExpiryDate, a.Status,
                         u.FullName AS Owner, u.FullNameEn AS OwnerEn,
                         u.Department, u.DepartmentEn,
                         (SELECT COUNT(*) FROM MaintenanceRequests mr
                           WHERE mr.AssetID = a.AssetID)                     AS ReqCount,
                         (SELECT MAX(m.MaintenanceDate)
                            FROM Maintenance m
                            JOIN MaintenanceRequests mr2 ON mr2.RequestID = m.RequestID
                           WHERE mr2.AssetID = a.AssetID)                    AS LastMaint
                  $baseSql";
    $stmt = $pdo->prepare($exportSql . ' ORDER BY a.AssetNumber');
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $ageYears = $r['PurchaseDate']
            ? round((time() - strtotime($r['PurchaseDate'])) / 31557600, 1)
            : '';
        $underWarranty = $r['WarrantyExpiryDate']
            ? (strtotime($r['WarrantyExpiryDate']) >= time() ? t('c.yes') : t('c.no'))
            : '';
        $out[] = [
            $r['AssetNumber'], loc($r, 'DeviceName'), device_type_label($r['DeviceType']),
            $r['Brand'], $r['Model'], $r['SerialNumber'],
            loc($r, 'Owner') ?: t('c.unassigned'),
            loc($r, 'Department'),
            loc($r, 'Location'),
            $r['AssignedDate'],
            $r['PurchaseDate'], $r['WarrantyExpiryDate'],
            $underWarranty, $ageYears,
            asset_status_label($r['Status']),
            (int) $r['ReqCount'],
            $r['LastMaint'],
        ];
    }
    handle_export('assets', [
        t('asset.number'), t('asset.name'), t('asset.type'), t('asset.brand'), t('asset.model'),
        t('asset.serial'), t('asset.assigned'), t('user.department'), t('asset.location'),
        t('asset.assigned_date'), t('asset.purchase'), t('asset.warranty'),
        t('exp.under_warranty'), t('exp.age_years'), t('asset.status'),
        t('exp.request_count'), t('asset.last_maint'),
    ], $out, t('asset.title'));
}

// ----- الترقيم -----
$perPage = 15;
$countStmt = $pdo->prepare("SELECT COUNT(*) $baseSql");
$countStmt->execute($params);
$total  = (int) $countStmt->fetchColumn();
$pages  = max(1, (int) ceil($total / $perPage));
$page   = min(current_page(), $pages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare($selectSql . " ORDER BY a.AssetNumber LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$assets = $stmt->fetchAll();

$pageTitle = t('asset.title');
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head with-action">
    <div>
        <h1><?= e(t('asset.title')) ?></h1>
        <p><?= e(t('asset.sub')) ?></p>
    </div>
    <?php if (has_role(['Admin'])): ?>
        <a href="asset_form.php" class="btn btn-inline">+ <?= e(t('asset.add')) ?></a>
    <?php endif; ?>
</div>

<form class="filters" method="get" action="assets.php">
    <div class="field">
        <label for="q"><?= e(t('c.search')) ?></label>
        <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('c.search_ph')) ?>">
    </div>
    <div class="field">
        <label for="type"><?= e(t('asset.type')) ?></label>
        <select id="type" name="type">
            <option value=""><?= e(t('c.all')) ?></option>
            <?php foreach (device_types() as $d): ?>
                <option value="<?= e($d) ?>" <?= $type === $d ? 'selected' : '' ?>><?= e(device_type_label($d)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="status"><?= e(t('asset.status')) ?></label>
        <select id="status" name="status">
            <option value=""><?= e(t('c.all')) ?></option>
            <?php foreach (asset_statuses() as $s): ?>
                <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(asset_status_label($s)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-actions">
        <button type="submit" class="btn btn-inline"><?= e(t('c.filter')) ?></button>
        <a href="assets.php" class="btn btn-inline btn-ghost"><?= e(t('c.reset')) ?></a>
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

    <?php if (!$assets): ?>
        <p class="empty"><?= e(t('c.no_results')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th><?= e(t('asset.number')) ?></th>
                        <th><?= e(t('asset.name')) ?></th>
                        <th><?= e(t('asset.type')) ?></th>
                        <th><?= e(t('asset.serial')) ?></th>
                        <th><?= e(t('asset.assigned')) ?></th>
                        <th><?= e(t('asset.location')) ?></th>
                        <th><?= e(t('asset.status')) ?></th>
                        <th><?= e(t('c.actions')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($assets as $a): ?>
                    <tr>
                        <td><span class="ident"><?= e($a['AssetNumber']) ?></span></td>
                        <td class="cell-strong">
                            <a href="asset_view.php?id=<?= (int) $a['AssetID'] ?>"><?= e(loc($a, 'DeviceName')) ?></a>
                            <span class="cell-muted block"><?= e($a['Brand'] . ' ' . $a['Model']) ?></span>
                        </td>
                        <td><?= e(device_type_label($a['DeviceType'])) ?></td>
                        <td><span class="ident"><?= e($a['SerialNumber']) ?></span></td>
                        <td class="cell-muted"><?= e(loc($a, 'Owner') ?: t('c.unassigned')) ?></td>
                        <td class="cell-muted"><?= e(loc($a, 'Location')) ?></td>
                        <td><?= badge($a['Status'], asset_status_label($a['Status'])) ?></td>
                        <td class="row-actions">
                            <a href="asset_view.php?id=<?= (int) $a['AssetID'] ?>"><?= e(t('c.view')) ?></a>
                            <?php if (has_role(['Admin'])): ?>
                                <a href="asset_form.php?id=<?= (int) $a['AssetID'] ?>"><?= e(t('c.edit')) ?></a>
                            <?php endif; ?>
                        </td>
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
