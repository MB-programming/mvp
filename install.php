<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>تثبيت - AI Code Editor</title>
<style>
  body { font-family: Arial, sans-serif; max-width: 600px; margin: 60px auto; padding: 20px; background: #f5f5f5; }
  .card { background: #fff; border-radius: 8px; padding: 30px; box-shadow: 0 2px 10px rgba(0,0,0,.1); }
  h1 { color: #333; margin-top: 0; }
  .ok  { color: #27ae60; } .fail { color: #e74c3c; }
  .step { padding: 8px 0; border-bottom: 1px solid #eee; }
  .btn { display:inline-block; margin-top:20px; padding:12px 24px; background:#007acc;
         color:#fff; text-decoration:none; border-radius:5px; }
  pre { background:#f8f8f8; padding:12px; border-radius:4px; font-size:.85em; overflow:auto; }
</style>
</head>
<body>
<div class="card">
<h1>🚀 تثبيت AI Code Editor</h1>

<?php
require_once __DIR__ . '/config.php';

$steps = [];

// 1. Create projects directory
if (!is_dir(PROJECTS_BASE_PATH)) {
    $ok = mkdir(PROJECTS_BASE_PATH, 0755, true);
} else {
    $ok = true;
}
$steps[] = ['مجلد المشاريع', $ok, PROJECTS_BASE_PATH];

// 2. Write .htaccess to block direct access
$htaccess = PROJECTS_BASE_PATH . '/.htaccess';
if (!file_exists($htaccess)) {
    $ok = (bool) file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
} else {
    $ok = true;
}
$steps[] = ['.htaccess الحماية', $ok, $htaccess];

// 3. Database
$dbOk = false;
$dbError = '';
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT),
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "`
                CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `" . DB_NAME . "`");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `projects` (
        `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `name`        VARCHAR(255) NOT NULL,
        `description` TEXT NULL,
        `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `chat_history` (
        `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `project_id` INT UNSIGNED NOT NULL,
        `role`       ENUM('user','model') NOT NULL,
        `content`    MEDIUMTEXT NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
        INDEX idx_project_created (`project_id`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $dbOk = true;
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}
$steps[] = ['قاعدة البيانات والجداول', $dbOk, $dbError ?: 'تم إنشاء الجداول بنجاح'];

foreach ($steps as [$label, $ok, $detail]) {
    $icon = $ok ? '✓' : '✗';
    $cls  = $ok ? 'ok' : 'fail';
    echo "<div class='step'><span class='$cls'>$icon</span> <strong>$label</strong><br>
          <small>$detail</small></div>\n";
}

$allOk = array_reduce($steps, fn($c, $s) => $c && $s[1], true);
?>

<?php if ($allOk): ?>
<div class="ok" style="margin-top:20px;font-size:1.1em;">
  ✓ اكتمل التثبيت بنجاح!
</div>
<p style="color:#e74c3c;"><strong>⚠️ احذف هذا الملف (install.php) فوراً بعد التثبيت لأسباب أمنية.</strong></p>
<a href="index.php" class="btn">→ انتقل إلى التطبيق</a>
<?php else: ?>
<div class="fail" style="margin-top:20px;">
  ✗ فشل التثبيت. راجع الأخطاء أعلاه وتحقق من إعدادات config.php
</div>
<?php endif; ?>

<h3 style="margin-top:30px;">كود SQL (للتنفيذ اليدوي إذا لزم)</h3>
<pre>
CREATE DATABASE IF NOT EXISTS `ai_code_editor`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `ai_code_editor`;

CREATE TABLE IF NOT EXISTS `projects` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_history` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT UNSIGNED NOT NULL,
  `role`       ENUM('user','model') NOT NULL,
  `content`    MEDIUMTEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
  INDEX idx_project_created (`project_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
</pre>
</div>
</body>
</html>
