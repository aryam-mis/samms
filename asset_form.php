<?php
require_once __DIR__ . '/includes/auth.php';
require_role(['Admin']);

$pdo  = db();
$id   = (int) ($_GET['id'] ?? 0);
$isEdit = $id > 0;
$errors = [];

// القيم الافتراضية
$a = [
    'AssetNumber' => '', 'DeviceName' => '', 'DeviceNameEn' => '', 'DeviceType' => 'Desktop',
    'Brand' => '', 'Model' => '', 'SerialNumber' => '', 'AssignedUserID' => '', 'AssignedDate' => '',
    'Location' => '', 'LocationEn' => '', 'PurchaseDate' => '', 'WarrantyExpiryDate' => '',
    'Status' => 'Active',
];

if ($isEdit) {
    $stmt = $pdo->prepare('SELECT * FROM Assets WHERE AssetID = :id');
    $stmt->execute([':id' => $id]);
    $found = $stmt->fetch();
    if (!$found) {
        set_flash('error', t('c.not_found'));
        redirect('assets.php');
    }
    $a = array_merge($a, $found);
}

$people = $pdo->query(
    "SELECT UserID, FullName, FullNameEn FROM Users WHERE IsActive = 1 ORDER BY FullName"
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = t('auth.err_session');
    }

    foreach (array_keys($a) as $k) {
        $a[$k] = trim($_POST[$k] ?? '');
    }

    if ($a['AssetNumber'] === '')  { $errors[] = t('asset.number') . ' — ' . t('auth.err_empty'); }
    if ($a['DeviceName'] === '')   { $errors[] = t('asset.name')   . ' — ' . t('auth.err_empty'); }
    if ($a['SerialNumber'] === '') { $errors[] = t('asset.serial') . ' — ' . t('auth.err_empty'); }
    if (!in_array($a['DeviceType'], device_types(), true))  { $a['DeviceType'] = 'Desktop'; }
    if (!in_array($a['Status'], asset_statuses(), true))    { $a['Status'] = 'Active'; }

    // فحص التكرار
    $dupNum = $pdo->prepare('SELECT COUNT(*) FROM Assets WHERE AssetNumber = :v AND AssetID <> :id');
    $dupNum->execute([':v' => $a['AssetNumber'], ':id' => $id]);
    if ((int) $dupNum->fetchColumn() > 0) { $errors[] = t('asset.err_number'); }

    $dupSer = $pdo->prepare('SELECT COUNT(*) FROM Assets WHERE SerialNumber = :v AND AssetID <> :id');
    $dupSer->execute([':v' => $a['SerialNumber'], ':id' => $id]);
    if ((int) $dupSer->fetchColumn() > 0) { $errors[] = t('asset.err_serial'); }

    if (!$errors) {
        $data = [
            ':num'   => $a['AssetNumber'],
            ':name'  => $a['DeviceName'],
            ':nameEn'=> $a['DeviceNameEn'] !== '' ? $a['DeviceNameEn'] : null,
            ':type'  => $a['DeviceType'],
            ':brand' => $a['Brand'],
            ':model' => $a['Model'],
            ':serial'=> $a['SerialNumber'],
            ':user'  => $a['AssignedUserID'] !== '' ? (int) $a['AssignedUserID'] : null,
            ':adate' => $a['AssignedDate'] !== '' ? $a['AssignedDate'] : null,
            ':locn'  => $a['Location'] !== '' ? $a['Location'] : null,
            ':locnEn'=> $a['LocationEn'] !== '' ? $a['LocationEn'] : null,
            ':pdate' => $a['PurchaseDate'] !== '' ? $a['PurchaseDate'] : null,
            ':wdate' => $a['WarrantyExpiryDate'] !== '' ? $a['WarrantyExpiryDate'] : null,
            ':st'    => $a['Status'],
        ];

        if ($isEdit) {
            $data[':id'] = $id;
            $pdo->prepare(
                'UPDATE Assets SET AssetNumber=:num, DeviceName=:name, DeviceNameEn=:nameEn,
                    DeviceType=:type, Brand=:brand, Model=:model, SerialNumber=:serial,
                    AssignedUserID=:user, AssignedDate=:adate, Location=:locn, LocationEn=:locnEn,
                    PurchaseDate=:pdate, WarrantyExpiryDate=:wdate, Status=:st
                 WHERE AssetID=:id'
            )->execute($data);
        } else {
            $pdo->prepare(
                'INSERT INTO Assets (AssetNumber, DeviceName, DeviceNameEn, DeviceType, Brand, Model,
                    SerialNumber, AssignedUserID, AssignedDate, Location, LocationEn, PurchaseDate, WarrantyExpiryDate, Status)
                 VALUES (:num,:name,:nameEn,:type,:brand,:model,:serial,:user,:adate,:locn,:locnEn,:pdate,:wdate,:st)'
            )->execute($data);
            $id = (int) $pdo->lastInsertId();
        }

        set_flash('success', t('c.saved'));
        redirect('asset_view.php?id=' . $id);
    }
}

$pageTitle = $isEdit ? t('asset.edit') : t('asset.add');
require_once __DIR__ . '/includes/header.php';
?>

<?= back_link('assets.php') ?>

<div class="page-head">
    <h1><?= e($pageTitle) ?></h1>
    <p><a href="assets.php">&larr; <?= e(t('asset.title')) ?></a></p>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="post" class="panel form-panel">
    <?= csrf_field() ?>

    <div class="form-grid">
        <div class="field">
            <label for="AssetNumber"><?= e(t('asset.number')) ?> *</label>
            <input type="text" id="AssetNumber" name="AssetNumber" value="<?= e($a['AssetNumber']) ?>" required>
        </div>
        <div class="field">
            <label for="SerialNumber"><?= e(t('asset.serial')) ?> *</label>
            <input type="text" id="SerialNumber" name="SerialNumber" value="<?= e($a['SerialNumber']) ?>" required>
        </div>

        <div class="field">
            <label for="DeviceName"><?= e(t('asset.name')) ?> *</label>
            <input type="text" id="DeviceName" name="DeviceName" value="<?= e($a['DeviceName']) ?>" required>
        </div>
        <div class="field">
            <label for="DeviceNameEn"><?= e(t('asset.name')) ?> — <?= e(t('c.english_optional')) ?></label>
            <input type="text" id="DeviceNameEn" name="DeviceNameEn" dir="ltr" value="<?= e($a['DeviceNameEn']) ?>">
        </div>

        <div class="field">
            <label for="DeviceType"><?= e(t('asset.type')) ?></label>
            <select id="DeviceType" name="DeviceType">
                <?php foreach (device_types() as $d): ?>
                    <option value="<?= e($d) ?>" <?= $a['DeviceType'] === $d ? 'selected' : '' ?>><?= e(device_type_label($d)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="Status"><?= e(t('asset.status')) ?></label>
            <select id="Status" name="Status">
                <?php foreach (asset_statuses() as $s): ?>
                    <option value="<?= e($s) ?>" <?= $a['Status'] === $s ? 'selected' : '' ?>><?= e(asset_status_label($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="Brand"><?= e(t('asset.brand')) ?></label>
            <input type="text" id="Brand" name="Brand" dir="ltr" value="<?= e($a['Brand']) ?>">
        </div>
        <div class="field">
            <label for="Model"><?= e(t('asset.model')) ?></label>
            <input type="text" id="Model" name="Model" dir="ltr" value="<?= e($a['Model']) ?>">
        </div>

        <div class="field">
            <label for="AssignedUserID"><?= e(t('asset.assigned')) ?></label>
            <select id="AssignedUserID" name="AssignedUserID">
                <option value=""><?= e(t('c.unassigned')) ?></option>
                <?php foreach ($people as $p): ?>
                    <option value="<?= (int) $p['UserID'] ?>" <?= (string) $a['AssignedUserID'] === (string) $p['UserID'] ? 'selected' : '' ?>>
                        <?= e(loc($p, 'FullName')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="AssignedDate"><?= e(t('asset.handover')) ?></label>
            <input type="date" id="AssignedDate" name="AssignedDate" value="<?= e($a['AssignedDate']) ?>">
        </div>
        <div class="field">
            <label for="Location"><?= e(t('asset.location')) ?></label>
            <input type="text" id="Location" name="Location" value="<?= e($a['Location']) ?>">
        </div>

        <div class="field">
            <label for="LocationEn"><?= e(t('asset.location')) ?> — <?= e(t('c.english_optional')) ?></label>
            <input type="text" id="LocationEn" name="LocationEn" dir="ltr" value="<?= e($a['LocationEn']) ?>">
        </div>
        <div class="field"></div>

        <div class="field">
            <label for="PurchaseDate"><?= e(t('asset.purchase')) ?></label>
            <input type="date" id="PurchaseDate" name="PurchaseDate" value="<?= e($a['PurchaseDate']) ?>">
        </div>
        <div class="field">
            <label for="WarrantyExpiryDate"><?= e(t('asset.warranty')) ?></label>
            <input type="date" id="WarrantyExpiryDate" name="WarrantyExpiryDate" value="<?= e($a['WarrantyExpiryDate']) ?>">
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-inline"><?= e(t('c.save')) ?></button>
        <a href="assets.php" class="btn btn-inline btn-ghost"><?= e(t('c.cancel')) ?></a>
    </div>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
