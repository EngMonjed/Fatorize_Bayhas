<?php
/**
 * عداد زيارات بسيط للموقع.
 * يخزّن العدد في ملف visits_count.json بجانب هذا الملف.
 *
 * الاستخدام من صفحة الموقع:
 *   counter.php?action=increment  -> يزيد العدد بواحد ويرجّعه
 *   counter.php?action=get        -> يرجّع العدد الحالي بدون زيادة
 *
 * ملاحظة: لازم يكون المجلد يلي فيه هالملف قابل للكتابة (writable)
 * حتى يقدر ينشئ ملف visits_count.json بنفسه أول مرة.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

$dataFile = __DIR__ . '/visits_count.json';
$action = isset($_GET['action']) && $_GET['action'] === 'increment' ? 'increment' : 'get';

function read_count($file) {
    if (!file_exists($file)) {
        return 0;
    }
    $raw = @file_get_contents($file);
    $data = json_decode($raw, true);
    if (is_array($data) && isset($data['count']) && is_numeric($data['count'])) {
        return (int) $data['count'];
    }
    return 0;
}

if ($action === 'increment') {
    // فتح الملف بوضع القراءة/الكتابة، وإنشاؤه لو مش موجود
    $fp = fopen($dataFile, 'c+');
    if ($fp === false) {
        // ما قدرنا نفتح/ننشئ الملف (مشكلة صلاحيات على الأغلب)
        echo json_encode(['count' => read_count($dataFile), 'error' => 'cannot_open_file']);
        exit;
    }

    if (flock($fp, LOCK_EX)) {
        $size = filesize($dataFile);
        $raw = $size > 0 ? fread($fp, $size) : '';
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['count']) || !is_numeric($data['count'])) {
            $data = ['count' => 0];
        }
        $data['count'] = (int) $data['count'] + 1;

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        echo json_encode(['count' => $data['count']]);
        exit;
    }

    fclose($fp);
    echo json_encode(['count' => read_count($dataFile), 'error' => 'lock_failed']);
    exit;
}

// action = get
echo json_encode(['count' => read_count($dataFile)]);
