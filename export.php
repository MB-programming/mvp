<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$pid = (int)($_GET['project_id'] ?? 0);
if (!$pid) { http_response_code(400); exit('معرف المشروع غير صحيح'); }

try {
    $stmt = getDB()->prepare("SELECT * FROM projects WHERE id = ?");
    $stmt->execute([$pid]);
    $project = $stmt->fetch();
} catch (Throwable) {
    http_response_code(500); exit('خطأ في قاعدة البيانات');
}

if (!$project) { http_response_code(404); exit('المشروع غير موجود'); }

$projectDir = realpath(PROJECTS_BASE_PATH) . '/' . $pid;
if (!is_dir($projectDir)) { http_response_code(404); exit('مجلد المشروع غير موجود'); }

// Sanitize project name for filename
$zipName = preg_replace('/[^a-zA-Z0-9\-_؀-ۿ]/u', '_', $project['name']);
$zipName = trim($zipName, '_') ?: 'project_' . $pid;
$zipName .= '.zip';

$tmpFile = tempnam(sys_get_temp_dir(), 'aice_');

$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500); exit('فشل إنشاء ملف ZIP');
}

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($it as $file) {
    if ($file->getFilename() === '.htaccess') continue;
    $real     = $file->getRealPath();
    $relative = substr($real, strlen($projectDir) + 1);
    $zip->addFile($real, $relative);
}

$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: no-store');
header('Pragma: no-cache');

readfile($tmpFile);
unlink($tmpFile);
exit;
