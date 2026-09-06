<?php
require_once __DIR__ . '/includes/auth.php';
require_role(['Admin']);

$pdo = db();

/** أعمدة النموذج بالترتيب */
function import_columns(): array
{
    return ['AssetNumber', 'DeviceName', 'DeviceNameEn', 'DeviceType', 'Brand', 'Model',
            'SerialNumber', 'EmployeeNo', 'AssignedDate', 'Location', 'LocationEn',
            'PurchaseDate', 'WarrantyExpiryDate', 'Status'];
}

function import_headers(): array
{
    return [t('asset.number'), t('asset.name'), t('asset.name') . ' (EN)', t('asset.type'),
            t('asset.brand'), t('asset.model'), t('asset.serial'), t('user.empno'),
            t('asset.handover'), t('asset.location'), t('asset.location') . ' (EN)',
            t('asset.purchase'), t('asset.warranty'), t('asset.status')];
}

// ---------- نموذج فارغ ----------
if (($_GET['template'] ?? '') === '1') {
    xlsx_download('assets_import_template', import_headers(), [[
        'AST-2001', 'جهاز مكتبي - مثال', 'Desktop - Example', 'Desktop', 'Dell', 'OptiPlex 7010',
        'SN-XX-00001', '10005', '2025-01-15', 'الدور الأول', 'Floor 1',
        '2025-01-01', '2028-01-01', 'Active',
    ]], t('imp.title'));
}

$errors  = [];
$preview = null;

// ---------- الخطوة 1: رفع ومعاينة ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {

    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = t('auth.err_session');
    } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = t('imp.err_upload');
    } else {
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['xlsx', 'csv'], true)) {
            $errors[] = t('imp.err_type');
        } else {
            $rows = $ext === 'xlsx'
                ? read_xlsx($_FILES['file']['tmp_name'])
                : read_csv($_FILES['file']['tmp_name']);

            if (count($rows) < 2) {
                $errors[] = t('imp.err_empty');
            } else {
                array_shift($rows);                       // سطر العناوين
                $cols = import_columns();

                // مراجع للتحقق
                $existing = $pdo->query('SELECT AssetNumber, AssetID FROM Assets')->fetchAll(PDO::FETCH_KEY_PAIR);
                $serials  = $pdo->query('SELECT SerialNumber, AssetNumber FROM Assets')->fetchAll(PDO::FETCH_KEY_PAIR);
                $staff    = $pdo->query('SELECT EmployeeNo, UserID FROM Users')->fetchAll(PDO::FETCH_KEY_PAIR);

                // خرائط التسميات العربية/الإنجليزية إلى قيم القاعدة
                $typeMap = $statusMap = [];
                foreach (device_types() as $d)    { $typeMap[mb_strtolower(device_type_label($d))] = $d; $typeMap[mb_strtolower($d)] = $d; }
                foreach (asset_statuses() as $st) { $statusMap[mb_strtolower(asset_status_label($st))] = $st; $statusMap[mb_strtolower($st)] = $st; }

                $preview  = [];
                $seenNum  = [];
                $seenSer  = [];

                foreach ($rows as $n => $raw) {
                    $r = [];
                    foreach ($cols as $i => $c) {
                        $r[$c] = trim((string) ($raw[$i] ?? ''));
                    }

                    $rowErrors = [];

                    if ($r['AssetNumber'] === '')  { $rowErrors[] = t('asset.number') . ': ' . t('imp.err_required'); }
                    if ($r['DeviceName'] === '')   { $rowErrors[] = t('asset.name')   . ': ' . t('imp.err_required'); }
                    if ($r['SerialNumber'] === '') { $rowErrors[] = t('asset.serial') . ': ' . t('imp.err_required'); }

                    if ($r['AssetNumber'] !== '' && isset($seenNum[$r['AssetNumber']]))   { $rowErrors[] = t('asset.number') . ': ' . t('imp.err_dup_file'); }
                    if ($r['SerialNumber'] !== '' && isset($seenSer[$r['SerialNumber']])) { $rowErrors[] = t('asset.serial') . ': ' . t('imp.err_dup_file'); }
                    $seenNum[$r['AssetNumber']] = true;
                    $seenSer[$r['SerialNumber']] = true;

                    // الرقم التسلسلي مملوك لجهاز آخر؟
                    if ($r['SerialNumber'] !== '' && isset($serials[$r['SerialNumber']])
                        && $serials[$r['SerialNumber']] !== $r['AssetNumber']) {
                        $rowErrors[] = t('imp.err_dup_serial');
                    }

                    $type = $typeMap[mb_strtolower($r['DeviceType'])] ?? null;
                    if ($r['DeviceType'] !== '' && $type === null) { $rowErrors[] = t('asset.type') . ': ' . t('imp.err_type_value'); }
                    $r['DeviceType'] = $type ?: 'Desktop';

                    $r['Status'] = $statusMap[mb_strtolower($r['Status'])] ?? 'Active';

                    $userId = null;
                    if ($r['EmployeeNo'] !== '') {
                        if (!isset($staff[$r['EmployeeNo']])) { $rowErrors[] = t('imp.err_employee') . ': ' . $r['EmployeeNo']; }
                        else { $userId = (int) $staff[$r['EmployeeNo']]; }
                    }
                    $r['AssignedUserID'] = $userId;

                    foreach (['AssignedDate', 'PurchaseDate', 'WarrantyExpiryDate'] as $d) {
                        $r[$d] = normalise_date($r[$d]);
                    }

                    $preview[] = [
                        'line'   => $n + 2,
                        'data'   => $r,
                        'errors' => $rowErrors,
                        'mode'   => $rowErrors ? 'error' : (isset($existing[$r['AssetNumber']]) ? 'update' : 'add'),
                    ];
                }

                $_SESSION['import_preview'] = $preview;
            }
        }
    }
}

// ---------- الخطوة 2: التنفيذ ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {

    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = t('auth.err_session');
    } elseif (empty($_SESSION['import_preview'])) {
        $errors[] = t('imp.expired_session');
    } else {
        $added = $updated = 0;
        $pdo->beginTransaction();
        try {
            foreach ($_SESSION['import_preview'] as $row) {
                if ($row['mode'] === 'error') { continue; }
                $d = $row['data'];

                $params = [
                    ':num' => $d['AssetNumber'], ':name' => $d['DeviceName'],
                    ':nameEn' => $d['DeviceNameEn'] !== '' ? $d['DeviceNameEn'] : null,
                    ':type' => $d['DeviceType'], ':brand' => $d['Brand'], ':model' => $d['Model'],
                    ':serial' => $d['SerialNumber'], ':user' => $d['AssignedUserID'],
                    ':adate' => $d['AssignedDate'],
                    ':locn' => $d['Location'] !== '' ? $d['Location'] : null,
                    ':locnEn' => $d['LocationEn'] !== '' ? $d['LocationEn'] : null,
                    ':pdate' => $d['PurchaseDate'], ':wdate' => $d['WarrantyExpiryDate'],
                    ':st' => $d['Status'],
                ];

                if ($row['mode'] === 'update') {
                    $pdo->prepare(
                        'UPDATE Assets SET DeviceName=:name, DeviceNameEn=:nameEn, DeviceType=:type,
                            Brand=:brand, Model=:model, SerialNumber=:serial, AssignedUserID=:user,
                            AssignedDate=:adate, Location=:locn, LocationEn=:locnEn,
                            PurchaseDate=:pdate, WarrantyExpiryDate=:wdate, Status=:st
                         WHERE AssetNumber=:num'
                    )->execute($params);
                    $updated++;
                } else {
                    $pdo->prepare(
                        'INSERT INTO Assets (AssetNumber, DeviceName, DeviceNameEn, DeviceType, Brand, Model,
                            SerialNumber, AssignedUserID, AssignedDate, Location, LocationEn,
                            PurchaseDate, WarrantyExpiryDate, Status)
                         VALUES (:num,:name,:nameEn,:type,:brand,:model,:serial,:user,:adate,:locn,:locnEn,:pdate,:wdate,:st)'
                    )->execute($params);
                    $added++;
                }
            }
            $pdo->commit();
            unset($_SESSION['import_preview']);
            set_flash('success', sprintf(t('imp.done'), $added, $updated));
            redirect('assets.php');
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $errors[] = t('imp.nothing');
        }
    }
}

$pageTitle = t('imp.title');
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head with-action">
    <div>
        <h1><?= e(t('imp.title')) ?></h1>
        <p><?= e(t('imp.sub')) ?></p>
    </div>
    <a href="import.php?template=1" class="btn btn-inline btn-ghost"><?= e(t('imp.template')) ?></a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!$preview): ?>

    <section class="panel form-panel narrow">
        <div class="panel-head"><h2><?= e(t('imp.file')) ?></h2></div>
        <p class="lede-block"><?= e(t('imp.howto')) ?></p>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload">
            <div class="field">
                <label for="file"><?= e(t('imp.file')) ?> (.xlsx / .csv)</label>
                <input type="file" id="file" name="file" accept=".xlsx,.csv" required>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-inline"><?= e(t('imp.upload')) ?></button>
            </div>
        </form>
    </section>

<?php else:
    $add = $upd = $bad = 0;
    foreach ($preview as $p) {
        if ($p['mode'] === 'add') { $add++; } elseif ($p['mode'] === 'update') { $upd++; } else { $bad++; }
    }
?>

    <section class="panel">
        <div class="panel-head">
            <h2><?= e(t('imp.preview')) ?></h2>
            <span class="cell-muted"><?= e(sprintf(t('imp.summary'), count($preview), $add, $upd, $bad)) ?></span>
        </div>

        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th><?= e(t('imp.row')) ?></th>
                        <th><?= e(t('asset.number')) ?></th>
                        <th><?= e(t('asset.name')) ?></th>
                        <th><?= e(t('asset.type')) ?></th>
                        <th><?= e(t('asset.serial')) ?></th>
                        <th><?= e(t('asset.assigned')) ?></th>
                        <th><?= e(t('imp.result')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($preview as $p): $d = $p['data']; ?>
                    <tr>
                        <td class="ident"><?= (int) $p['line'] ?></td>
                        <td class="ident"><?= e($d['AssetNumber']) ?></td>
                        <td class="cell-strong"><?= e($d['DeviceName']) ?></td>
                        <td class="cell-muted"><?= e(device_type_label($d['DeviceType'])) ?></td>
                        <td class="ident"><?= e($d['SerialNumber']) ?></td>
                        <td class="cell-muted"><?= e($d['EmployeeNo'] ?: '—') ?></td>
                        <td>
                            <?php if ($p['mode'] === 'error'): ?>
                                <span class="badge is-bad"><?= e(t('imp.row_error')) ?></span>
                                <span class="cell-muted block"><?= e(implode(' · ', $p['errors'])) ?></span>
                            <?php elseif ($p['mode'] === 'update'): ?>
                                <span class="badge is-warn"><?= e(t('imp.will_update')) ?></span>
                            <?php else: ?>
                                <span class="badge is-ok"><?= e(t('imp.will_add')) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="form-actions" style="padding:14px 18px">
            <?php if ($add + $upd > 0): ?>
                <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="confirm">
                    <button type="submit" class="btn btn-inline"><?= e(t('imp.confirm')) ?></button>
                </form>
            <?php endif; ?>
            <a href="import.php" class="btn btn-inline btn-ghost"><?= e(t('imp.back')) ?></a>
        </div>
    </section>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
