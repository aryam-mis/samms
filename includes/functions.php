<?php
/**
 * SAMMS - دوال مساعدة مشتركة / Shared helpers
 */

require_once __DIR__ . '/lang.php';

/** تهريب المخرجات لمنع XSS */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** إعادة توجيه وإنهاء التنفيذ */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/** حفظ رسالة تظهر مرة واحدة في الصفحة التالية */
function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

/** رمز CSRF للنماذج */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool
{
    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

/** حقل مخفي جاهز للنماذج */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** يتحقق من CSRF في طلبات POST ويوقف التنفيذ عند الفشل */
function require_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', t('auth.err_session'));
        redirect(basename($_SERVER['PHP_SELF']));
    }
}

// ---------------------------------------------------------------------
//  تسميات مترجمة
// ---------------------------------------------------------------------

function asset_status_label(string $s): string  { return t('astatus.' . $s); }
function request_status_label(string $s): string { return t('rstatus.' . $s); }
function priority_label(string $p): string      { return t('prio.' . $p); }
function device_type_label(string $d): string   { return t('dtype.' . $d); }
function role_label(string $r): string          { return t('role.' . $r); }
function fault_type_label(string $f): string    { return t('fault.' . $f); }

/** القوائم المرجعية — تُستخدم في الفلاتر والنماذج */
function asset_statuses(): array  { return ['Active', 'UnderMaintenance', 'OutOfService', 'Retired']; }
function request_statuses(): array { return ['New', 'InProgress', 'Completed', 'Cancelled']; }
function priorities(): array      { return ['Low', 'Medium', 'High']; }
function device_types(): array    { return ['Desktop', 'Laptop', 'Printer', 'Monitor', 'NetworkDevice']; }
function roles(): array           { return ['Admin', 'Employee', 'ITSupport', 'Manager']; }
function fault_types(): array     { return ['Hardware', 'Software', 'Network', 'Power', 'Consumable', 'Peripheral', 'Other']; }

/** فئة CSS تحدد لون الحالة */
function status_class(string $status): string
{
    return [
        'Active'           => 'is-ok',
        'Completed'        => 'is-ok',
        'UnderMaintenance' => 'is-warn',
        'InProgress'       => 'is-warn',
        'OutOfService'     => 'is-bad',
        'Retired'          => 'is-muted',
        'High'             => 'is-bad',
        'Medium'           => 'is-warn',
        'Low'              => 'is-muted',
        'New'              => 'is-new',
        'Cancelled'        => 'is-muted',
    ][$status] ?? 'is-muted';
}

/** شارة حالة جاهزة */
function badge(string $value, string $label): string
{
    return '<span class="badge ' . e(status_class($value)) . '">' . e($label) . '</span>';
}

// ---------------------------------------------------------------------
//  التواريخ
// ---------------------------------------------------------------------

function fmt_date(?string $datetime, bool $withTime = false): string
{
    if (empty($datetime)) {
        return '—';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '—';
    }
    return date($withTime ? 'Y-m-d H:i' : 'Y-m-d', $ts);
}

function fmt_datetime(?string $datetime): string
{
    return fmt_date($datetime, true);
}

// ---------------------------------------------------------------------
//  الروابط والصفحات
// ---------------------------------------------------------------------

/** يبني رابطاً للصفحة الحالية مع تعديل بعض المعاملات */
function url_with(array $params): string
{
    $qs = array_merge($_GET, $params);
    foreach ($qs as $k => $v) {
        if ($v === '' || $v === null) {
            unset($qs[$k]);
        }
    }
    $page = basename($_SERVER['PHP_SELF']);
    return $qs ? $page . '?' . http_build_query($qs) : $page;
}

/** رقم الصفحة الحالي */
function current_page(): int
{
    return max(1, (int) ($_GET['p'] ?? 1));
}

// ---------------------------------------------------------------------
//  تصدير CSV
// ---------------------------------------------------------------------

/**
 * يرسل ملف CSV قياسي (UTF-8 مع BOM) ثم ينهي التنفيذ.
 *
 * هذا المسار مخصص للأدوات البرمجية والاستيراد إلى أنظمة أخرى.
 * لفتح النتائج في Excel مباشرة استخدمي xlsx_download — فـ Excel
 * يحدد فاصل CSV من إعدادات ويندوز، وهو ما يكسر العرض عربياً.
 */
function csv_download(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------
//  بناء شرط البحث
// ---------------------------------------------------------------------

/**
 * يبني شرط LIKE على عدة أعمدة بمعاملات فريدة.
 * PDO في وضع الـ prepared statements الأصلي لا يسمح بإعادة استخدام
 * نفس الـ placeholder أكثر من مرة، لذلك يأخذ كل عمود معاملاً خاصاً به.
 */
function like_any(array $columns, string $value, array &$params, string $prefix = 'q'): string
{
    $parts = [];
    foreach (array_values($columns) as $i => $col) {
        $ph = ':' . $prefix . $i;
        $parts[]       = $col . ' LIKE ' . $ph;
        $params[$ph]   = '%' . $value . '%';
    }
    return '(' . implode(' OR ', $parts) . ')';
}

// ---------------------------------------------------------------------
//  تصدير Excel حقيقي (.xlsx)
// ---------------------------------------------------------------------

/**
 * عدد الأحرف الفعلي في نص UTF-8.
 * لا يعتمد على إضافة mbstring — strlen وحده يعدّ البايتات فيعطي
 * ثلاثة أضعاف الطول للنص العربي.
 */
function str_len(string $s): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($s, 'UTF-8');
    }
    $n = preg_match_all('/./u', $s);
    return $n !== false && $n > 0 ? $n : strlen($s);
}

/** يحوّل رقم عمود إلى حرفه: 1=A ... 27=AA */
function col_letter(int $n): string
{
    $s = '';
    while ($n > 0) {
        $n--;
        $s = chr(65 + ($n % 26)) . $s;
        $n = intdiv($n, 26);
    }
    return $s;
}

/** تهريب النص ليصلح داخل XML */
function xml_escape(string $v): string
{
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v);
    return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** هل تُكتب القيمة كرقم في Excel؟ */
function is_xlsx_number($v): bool
{
    $v = trim((string) $v);
    if ($v === '' || !preg_match('/^-?\d+(\.\d+)?$/', $v)) {
        return false;
    }
    // الأرقام التي تبدأ بصفر (مثل رقم وظيفي) تبقى نصاً حتى لا يُحذف الصفر
    return !(strlen($v) > 1 && $v[0] === '0');
}

/**
 * يبني ملف xlsx بسيطاً ويرسله للتنزيل.
 *
 * xlsx هو أرشيف ZIP يحتوي ملفات XML بترميز UTF-8، لذلك لا توجد
 * أي مشكلة ترميز أو فاصل أعمدة مهما كانت إعدادات جهاز المستخدم.
 * تُكتب النصوص inline لتفادي الحاجة إلى جدول sharedStrings.
 */
function xlsx_download(string $filename, array $headers, array $rows, ?string $sheetName = null): void
{
    if (!class_exists('ZipArchive')) {          // احتياط لو كانت الإضافة معطّلة
        csv_download($filename, $headers, $rows);
    }

    $sheetName = $sheetName ?: 'Sheet1';
    $rtl       = is_rtl() ? ' rightToLeft="1"' : '';

    // عرض الأعمدة تقديرياً حسب أطول محتوى
    $widths = [];
    foreach ($headers as $i => $h) {
        $widths[$i] = min(60, max(12, str_len((string) $h) + 4));
    }
    foreach ($rows as $row) {
        foreach (array_values($row) as $i => $cell) {
            $len = str_len((string) $cell) + 3;
            $widths[$i] = min(60, max($widths[$i] ?? 12, $len));
        }
    }

    $cols = '';
    foreach ($widths as $i => $w) {
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    }

    // بناء الصفوف
    $buildRow = function (array $values, int $rowNum, bool $bold) {
        $xml = '<row r="' . $rowNum . '">';
        foreach (array_values($values) as $i => $v) {
            $ref   = col_letter($i + 1) . $rowNum;
            $style = $bold ? ' s="1"' : '';
            if (!$bold && is_xlsx_number($v)) {
                $xml .= '<c r="' . $ref . '"' . $style . '><v>' . trim((string) $v) . '</v></c>';
            } else {
                $xml .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">'
                      . xml_escape((string) $v) . '</t></is></c>';
            }
        }
        return $xml . '</row>';
    };

    $sheetData = $buildRow($headers, 1, true);
    $n = 2;
    foreach ($rows as $row) {
        $sheetData .= $buildRow($row, $n++, false);
    }

    $lastCol = col_letter(max(1, count($headers)));

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"' . $rtl . '><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols>' . $cols . '</cols>'
        . '<sheetData>' . $sheetData . '</sheetData>'
        . '<autoFilter ref="A1:' . $lastCol . (count($rows) + 1) . '"/>'
        . '</worksheet>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . xml_escape($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FF10222A"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="3">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE1EFEA"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '</cellXfs>'
        . '</styleSheet>';

    $tmp = tempnam(sys_get_temp_dir(), 'samms_xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

/** يوجّه التصدير حسب الصيغة المطلوبة في الرابط */
function handle_export(string $filename, array $headers, array $rows, ?string $sheetName = null): void
{
    $format = $_GET['export'] ?? '';
    if ($format === 'xlsx') {
        xlsx_download($filename, $headers, $rows, $sheetName);
    } elseif ($format === 'csv') {
        csv_download($filename, $headers, $rows);
    }
}

// ---------------------------------------------------------------------
//  شعار النظام
// ---------------------------------------------------------------------

/**
 * شعار SAMMS كـ SVG مضمّن.
 *
 * طوبولوجيا شبكة: خمس عُقد بعدد حروف الاسم، الوسطى مرفوعة كمركز
 * يربط البقية — تعبيراً عن نظام يربط أجهزة موزّعة على مبنى كامل.
 * مرسوم بـ currentColor فلا خلفية له ويأخذ لون النص المحيط تلقائياً.
 */
function logo_svg(int $height = 30, string $class = 'logo'): string
{
    $w = (int) round($height * 140 / 44);

    return '<svg class="' . e($class) . '" width="' . $w . '" height="' . $height . '"'
         . ' viewBox="0 0 140 44" fill="none" role="img" aria-label="SAMMS">'
         // الأسلاك
         . '<g stroke="currentColor" stroke-width="2.1" stroke-linecap="round" opacity=".72">'
         . '<path d="M70 15 12 32"/>'
         . '<path d="M70 15 41 32"/>'
         . '<path d="M70 15 99 32"/>'
         . '<path d="M70 15 128 32"/>'
         . '<path d="M12 32h29"/>'
         . '<path d="M99 32h29"/>'
         . '</g>'
         // العُقد الطرفية
         . '<g fill="currentColor">'
         . '<circle cx="12" cy="32" r="4.1"/>'
         . '<circle cx="41" cy="32" r="4.1"/>'
         . '<circle cx="99" cy="32" r="4.1"/>'
         . '<circle cx="128" cy="32" r="4.1"/>'
         . '</g>'
         // العقدة المركزية
         . '<circle class="logo-hub" cx="70" cy="15" r="6.2" fill="currentColor"/>'
         . '</svg>';
}

// ---------------------------------------------------------------------
//  قراءة ملفات الجداول
// ---------------------------------------------------------------------

/** يحوّل مرجع خلية (مثل C7) إلى فهرس العمود صفرياً */
function col_index(string $ref): int
{
    preg_match('/^([A-Z]+)/', strtoupper($ref), $m);
    $n = 0;
    foreach (str_split($m[1] ?? 'A') as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

/** يستخرج كل النصوص داخل وسوم <t> من جزء XML */
function xml_text_of(string $fragment): string
{
    preg_match_all('/<(?:\w+:)?t\b[^>]*>(.*?)<\/(?:\w+:)?t>/s', $fragment, $m);
    $text = implode('', $m[1]);
    return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * يقرأ أول ورقة من ملف xlsx ويرجّعها كمصفوفة صفوف.
 *
 * xlsx أرشيف ZIP: النصوص إما في جدول sharedStrings مشترك أو inline
 * داخل الخلية. القراءة هنا نصية بحتة دون الاعتماد على إضافة XML،
 * حتى يعمل الاستيراد على أي تنصيب PHP.
 */
function read_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) {
        return [];
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return [];
    }

    // جدول النصوص المشتركة
    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        preg_match_all('/<si\b[^>]*>(.*?)<\/si>/s', $xml, $m);
        foreach ($m[1] as $si) {
            $shared[] = xml_text_of($si);
        }
    }

    // أول ورقة
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, 'xl/worksheets/') === 0 && substr($name, -4) === '.xml') {
                $sheetXml = $zip->getFromName($name);
                break;
            }
        }
    }
    $zip->close();

    if (!$sheetXml) {
        return [];
    }

    $rows = [];
    preg_match_all('/<row\b[^>]*>(.*?)<\/row>/s', $sheetXml, $rowMatches);

    foreach ($rowMatches[1] as $rowXml) {
        $cells = [];

        // خلايا بمحتوى، وخلايا فارغة ذاتية الإغلاق
        preg_match_all('/<c\b([^>]*?)(?:\/>|>(.*?)<\/c>)/s', $rowXml, $cm, PREG_SET_ORDER);

        foreach ($cm as $c) {
            $attrs = $c[1];
            $inner = $c[2] ?? '';

            preg_match('/\br="([A-Z]+\d+)"/', $attrs, $rm);
            $idx = isset($rm[1]) ? col_index($rm[1]) : count($cells);

            preg_match('/\bt="(\w+)"/', $attrs, $tm);
            $type = $tm[1] ?? '';

            if ($type === 's') {
                preg_match('/<v>(.*?)<\/v>/s', $inner, $vm);
                $val = $shared[(int) ($vm[1] ?? -1)] ?? '';
            } elseif ($type === 'inlineStr' || $type === 'str') {
                $val = $type === 'str'
                    ? (preg_match('/<v>(.*?)<\/v>/s', $inner, $vm) ? html_entity_decode($vm[1], ENT_QUOTES | ENT_XML1, 'UTF-8') : '')
                    : xml_text_of($inner);
            } else {
                preg_match('/<v>(.*?)<\/v>/s', $inner, $vm);
                $val = html_entity_decode($vm[1] ?? '', ENT_QUOTES | ENT_XML1, 'UTF-8');
            }

            $cells[$idx] = trim($val);
        }

        if ($cells) {
            $max = max(array_keys($cells));
            for ($i = 0; $i <= $max; $i++) {
                if (!isset($cells[$i])) { $cells[$i] = ''; }
            }
            ksort($cells);
            $rows[] = array_values($cells);
        }
    }

    return $rows;
}

/** يقرأ ملف CSV بترميز UTF-8 (يتجاهل BOM وسطر sep= إن وُجد) */
function read_csv(string $path): array
{
    $rows = [];
    if (($fh = fopen($path, 'r')) === false) {
        return $rows;
    }
    $first = true;
    while (($line = fgetcsv($fh)) !== false) {
        if ($first) {
            $first = false;
            if (isset($line[0])) {
                $line[0] = preg_replace('/^\xEF\xBB\xBF/', '', $line[0]);
            }
            if (isset($line[0]) && stripos($line[0], 'sep=') === 0) {
                continue;
            }
        }
        if (array_filter($line, fn($v) => trim((string) $v) !== '')) {
            $rows[] = array_map(fn($v) => trim((string) $v), $line);
        }
    }
    fclose($fh);
    return $rows;
}

/** يحوّل رقم تاريخ Excel التسلسلي أو نصاً إلى Y-m-d، أو null */
function normalise_date(string $v): ?string
{
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    if (preg_match('/^\d{5}(\.\d+)?$/', $v)) {          // تسلسل Excel
        $ts = ((int) $v - 25569) * 86400;
        return gmdate('Y-m-d', $ts);
    }
    $ts = strtotime($v);
    return $ts === false ? null : date('Y-m-d', $ts);
}

/**
 * رابط الرجوع: يعيد المستخدم إلى الصفحة التي جاء منها إن كانت داخل النظام،
 * وإلى الصفحة الافتراضية إن دخل بالرابط مباشرة. يمنع إعادة التوجيه الخارجي.
 */
function back_url(string $fallback): string
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref === '') {
        return $fallback;
    }

    // parse_url يعيد المضيف بلا منفذ، بينما HTTP_HOST يحمله عند وجوده،
    // فتُجرَّد المنافذ من الطرفين قبل المقارنة.
    $refHost  = parse_url($ref, PHP_URL_HOST);
    $selfHost = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
    if ($refHost !== null && $refHost !== $selfHost) {
        return $fallback;          // مرجع خارجي — يُتجاهل
    }

    $path  = parse_url($ref, PHP_URL_PATH) ?? '';
    $file  = basename($path);
    $self  = basename($_SERVER['SCRIPT_NAME'] ?? '');

    // العودة لنفس الصفحة تعني دورة مغلقة
    if ($file === '' || $file === $self || !preg_match('/^[a-z_]+\.php$/', $file)) {
        return $fallback;
    }

    $query = parse_url($ref, PHP_URL_QUERY);
    return $file . ($query ? '?' . $query : '');
}

/** يطبع رابط الرجوع كسهم مع نص. */
function back_link(string $fallback, string $label = ''): string
{
    $label = $label !== '' ? $label : t('c.back');
    return '<a href="' . e(back_url($fallback)) . '" class="back-link">'
         . '<span class="back-arrow" aria-hidden="true">&#8250;</span>'
         . e($label) . '</a>';
}
