<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ─── Helpers ─────────────────────────────────────────────────────────────────

function jsonOk(mixed $data): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonErr(string $msg, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Resolve a project-relative path to an absolute path, preventing traversal.
 * Works for both existing and new (not-yet-created) paths.
 */
function resolvePath(int $projectId, string $relativePath = ''): string|false
{
    $base = realpath(PROJECTS_BASE_PATH);
    if ($base === false) return false;

    $projectDir = $base . DIRECTORY_SEPARATOR . $projectId;

    if ($relativePath === '') return $projectDir;

    // Reject null bytes
    if (str_contains($relativePath, "\0")) return false;

    // Allow only safe characters: letters, digits, ., -, _, /, space
    if (!preg_match('/^[a-zA-Z0-9.\-_ \\/]+$/', $relativePath)) return false;

    // Reject any traversal sequences
    if (preg_match('/\.\./', $relativePath)) return false;

    // Normalize separators and strip leading slash
    $rel = ltrim(str_replace('\\', '/', $relativePath), '/');

    $full = $projectDir . '/' . $rel;

    // For existing paths use realpath to resolve symlinks
    if (file_exists($full)) {
        $real = realpath($full);
        if ($real === false) return false;
        if (!str_starts_with($real . '/', $projectDir . '/') && $real !== $projectDir) {
            return false;
        }
        return $real;
    }

    // For new paths: manually normalize without relying on realpath
    $parts    = explode('/', $projectDir . '/' . $rel);
    $resolved = [];
    foreach ($parts as $part) {
        if ($part === '..' ) { array_pop($resolved); }
        elseif ($part !== '.' && $part !== '') { $resolved[] = $part; }
    }
    $normalized = implode('/', $resolved);

    if (!str_starts_with($normalized . '/', $projectDir . '/')) return false;

    return $full;
}

// ─── File-tree helpers ───────────────────────────────────────────────────────

function buildTree(string $dir, string $baseDir): array
{
    if (!is_dir($dir)) return [];
    $items = @scandir($dir);
    if ($items === false) return [];

    $tree = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === '.htaccess') continue;

        $fullPath = $dir . '/' . $item;
        $relPath  = ltrim(str_replace($baseDir, '', $fullPath), '/');

        if (is_dir($fullPath)) {
            $tree[] = [
                'type'     => 'dir',
                'name'     => $item,
                'path'     => $relPath,
                'children' => buildTree($fullPath, $baseDir),
            ];
        } else {
            $tree[] = [
                'type'      => 'file',
                'name'      => $item,
                'path'      => $relPath,
                'size'      => filesize($fullPath),
                'extension' => strtolower(pathinfo($item, PATHINFO_EXTENSION)),
            ];
        }
    }

    usort($tree, fn($a, $b) =>
        $a['type'] !== $b['type']
            ? ($a['type'] === 'dir' ? -1 : 1)
            : strcmp($a['name'], $b['name'])
    );

    return $tree;
}

function flatFiles(string $dir, string $baseDir = ''): array
{
    if ($baseDir === '') $baseDir = $dir;
    $result = [];
    if (!is_dir($dir)) return $result;
    $items = @scandir($dir);
    if (!$items) return $result;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === '.htaccess') continue;
        $full = $dir . '/' . $item;
        $rel  = ltrim(str_replace($baseDir, '', $full), '/');

        if (is_dir($full)) {
            $result = array_merge($result, flatFiles($full, $baseDir));
        } else {
            $result[] = ['path' => $rel, 'full' => $full];
        }
    }
    return $result;
}

// ─── Context builder ─────────────────────────────────────────────────────────

function buildContext(int $projectId): string
{
    $dir = resolvePath($projectId);
    if (!$dir || !is_dir($dir)) return 'لا توجد ملفات في المشروع بعد.';

    $files = flatFiles($dir);
    if (empty($files)) return 'لا توجد ملفات في المشروع بعد.';

    $ctx   = '';
    $count = 0;
    foreach ($files as $f) {
        if ($count >= MAX_FILES_CONTEXT) {
            $ctx .= "\n... (تم اقتطاع الملفات المتبقية لحد السياق)\n";
            break;
        }
        $size = filesize($f['full']);
        if ($size > MAX_FILE_SIZE_CONTEXT) {
            $ctx .= "\n=== {$f['path']} ===\n[الملف كبير جداً ({$size} bytes) - تم تخطيه]\n";
        } else {
            $ctx .= "\n=== {$f['path']} ===\n" . file_get_contents($f['full']) . "\n";
        }
        $count++;
    }
    return $ctx;
}

// ─── AI Providers ─────────────────────────────────────────────────────────────

function buildSystemPrompt(string $projectName, string $ctx): string
{
    return <<<PROMPT
أنت مساعد برمجي خبير مدمج في محرر أكواد يُسمى "AI Code Editor".
مهمتك مساعدة المستخدم في إنشاء وتعديل ملفات المشروع.

المشروع الحالي: {$projectName}

ملفات المشروع الحالية:
{$ctx}

════════════════════════════════════
تعليمات إلزامية — اتبعها دائماً:
════════════════════════════════════

عند إنشاء أو تعديل ملفات يجب أن يكون ردك JSON صحيحاً تماماً:
{
  "message": "شرح ما فعلته",
  "files": [
    {
      "action": "write",
      "path": "المسار/النسبي/للملف.ext",
      "content": "محتوى الملف الكامل"
    }
  ]
}

لحذف ملف: { "action": "delete", "path": "..." }
بدون تغييرات: { "message": "إجابتك", "files": [] }

قواعد:
- المسارات نسبية لجذر المشروع — لا تستخدم "/" في البداية ولا ".."
- اكتب محتوى الملف كاملاً دائماً
- ردك بالكامل يجب أن يكون JSON صالحاً فقط — لا نص خارجه
PROMPT;
}

/** Strip markdown code fence then parse JSON */
function parseAIResponse(string $text): array
{
    $text = trim($text);
    // Remove ```json ... ``` or ``` ... ```
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/\s*```$/s', '', $text);
    $text = trim($text);

    $parsed = json_decode($text, true);
    if ($parsed !== null && isset($parsed['message'])) return $parsed;

    // Attempt to extract a JSON object from inside a larger text
    if (preg_match('/\{[\s\S]*"message"[\s\S]*\}/s', $text, $m)) {
        $inner = json_decode($m[0], true);
        if ($inner !== null && isset($inner['message'])) return $inner;
    }

    return ['message' => $text, 'files' => []];
}

function callGemini(string $apiKey, string $model, string $system, array $history, string $userMsg): array
{
    $contents = [];
    foreach ($history as $msg) {
        // Gemini uses 'model' role, not 'assistant'
        $contents[] = [
            'role'  => $msg['role'],
            'parts' => [['text' => $msg['content']]],
        ];
    }
    $contents[] = ['role' => 'user', 'parts' => [['text' => $userMsg]]];

    $body = [
        'system_instruction' => ['parts' => [['text' => $system]]],
        'contents'           => $contents,
        'generationConfig'   => [
            'temperature'      => 0.7,
            'maxOutputTokens'  => 8192,
            'responseMimeType' => 'application/json',
        ],
    ];

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . $model . ':generateContent?key=' . $apiKey;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) return ['error' => 'cURL: ' . $curlErr];
    if ($httpCode !== 200) {
        $err = json_decode($raw, true);
        return ['error' => 'Gemini: ' . ($err['error']['message'] ?? "HTTP $httpCode")];
    }

    $data = json_decode($raw, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (empty($text)) return ['error' => 'الرد من Gemini فارغ'];

    return parseAIResponse($text);
}

/** OpenAI-compatible: Groq, DeepSeek, OpenRouter */
function callOpenAICompat(string $provider, string $apiKey, string $model, string $system, array $history, string $userMsg): array
{
    static $endpoints = [
        'groq'       => 'https://api.groq.com/openai/v1/chat/completions',
        'deepseek'   => 'https://api.deepseek.com/v1/chat/completions',
        'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
    ];

    $url = $endpoints[$provider] ?? '';
    if (!$url) return ['error' => "URL غير معروف للمزود: $provider"];

    $messages = [['role' => 'system', 'content' => $system]];
    foreach ($history as $msg) {
        $messages[] = [
            'role'    => $msg['role'] === 'model' ? 'assistant' : 'user',
            'content' => $msg['content'],
        ];
    }
    $messages[] = ['role' => 'user', 'content' => $userMsg];

    $body = [
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => 0.7,
        'max_tokens'  => 8192,
    ];

    // JSON mode — supported by Groq & DeepSeek but not all OpenRouter models
    if (in_array($provider, ['groq', 'deepseek'])) {
        $body['response_format'] = ['type' => 'json_object'];
    }

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ];
    if ($provider === 'openrouter') {
        $headers[] = 'HTTP-Referer: https://ai-code-editor.local';
        $headers[] = 'X-Title: AI Code Editor';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) return ['error' => 'cURL: ' . $curlErr];

    $data = json_decode($raw, true);
    if ($httpCode !== 200) {
        $errMsg = $data['error']['message'] ?? "HTTP $httpCode";
        return ['error' => "$provider: $errMsg"];
    }

    $text = $data['choices'][0]['message']['content'] ?? '';
    if (empty($text)) return ['error' => "الرد فارغ من $provider"];

    return parseAIResponse($text);
}

/** Main dispatcher — routes to the correct provider function */
function callAI(string $provider, string $model, string $projectName, string $ctx, array $history, string $userMsg): array
{
    $providers = AI_PROVIDERS;

    if (!isset($providers[$provider])) {
        return ['error' => "مزود غير معروف: $provider"];
    }

    $apiKey = $providers[$provider]['api_key'] ?? '';
    if (empty($apiKey) || str_starts_with($apiKey, 'YOUR_')) {
        return ['error' => "لم يتم إعداد API Key لـ {$providers[$provider]['name']} — افتح config.php وأضف مفتاحك"];
    }

    if (empty($model) || !isset($providers[$provider]['models'][$model])) {
        // Default to first model in provider
        $model = array_key_first($providers[$provider]['models']);
    }

    $system = buildSystemPrompt($projectName, $ctx);

    return match ($provider) {
        'gemini'                  => callGemini($apiKey, $model, $system, $history, $userMsg),
        'groq', 'deepseek', 'openrouter'
                                  => callOpenAICompat($provider, $apiKey, $model, $system, $history, $userMsg),
        default                   => ['error' => "مزود غير مدعوم: $provider"],
    };
}

// ─── Router ──────────────────────────────────────────────────────────────────

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Parse JSON body
$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $input = $decoded;
    }
    $input = array_merge($_POST, $input);
}

try {
    switch ($action) {

        // ── Projects ─────────────────────────────────────────────────────────

        case 'list_projects':
            $rows = getDB()
                ->query("SELECT id, name, description, created_at, updated_at
                         FROM projects ORDER BY updated_at DESC")
                ->fetchAll();
            jsonOk(['projects' => $rows]);

        case 'get_project':
            $id   = (int)($_GET['id'] ?? 0);
            $stmt = getDB()->prepare("SELECT * FROM projects WHERE id = ?");
            $stmt->execute([$id]);
            $p = $stmt->fetch();
            if (!$p) jsonErr('المشروع غير موجود', 404);
            jsonOk(['project' => $p]);

        case 'create_project':
            $name = trim($input['name'] ?? '');
            $desc = trim($input['description'] ?? '');

            if ($name === '') jsonErr('اسم المشروع مطلوب');
            if (!preg_match('/^[\p{L}0-9 \-_]+$/u', $name))
                jsonErr('الاسم يحتوي على حروف غير مسموح بها');
            if (mb_strlen($name) > 100) jsonErr('الاسم طويل جداً');

            $db   = getDB();
            $stmt = $db->prepare("INSERT INTO projects (name, description) VALUES (?, ?)");
            $stmt->execute([$name, $desc]);
            $pid  = (int)$db->lastInsertId();

            $dir = PROJECTS_BASE_PATH . '/' . $pid;
            if (!mkdir($dir, 0755, true)) jsonErr('فشل إنشاء مجلد المشروع', 500);

            jsonOk(['project' => ['id' => $pid, 'name' => $name, 'description' => $desc]]);

        case 'delete_project':
            $pid = (int)($input['id'] ?? 0);
            if (!$pid) jsonErr('معرف المشروع غير صحيح');

            $dir = resolvePath($pid);
            if ($dir && is_dir($dir)) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($it as $f) {
                    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
                }
                rmdir($dir);
            }

            $stmt = getDB()->prepare("DELETE FROM projects WHERE id = ?");
            $stmt->execute([$pid]);
            jsonOk(['success' => true]);

        // ── Files ─────────────────────────────────────────────────────────────

        case 'list_files':
            $pid = (int)($_GET['project_id'] ?? 0);
            if (!$pid) jsonErr('project_id مطلوب');

            $dir = resolvePath($pid);
            if (!$dir) jsonErr('مسار غير صحيح');
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            jsonOk(['files' => buildTree($dir, $dir)]);

        case 'read_file':
            $pid  = (int)($_GET['project_id'] ?? 0);
            $path = $_GET['path'] ?? '';
            if (!$pid || $path === '') jsonErr('معاملات ناقصة');

            $full = resolvePath($pid, $path);
            if (!$full) jsonErr('مسار الملف غير صالح (محاولة اختراق)');
            if (!is_file($full)) jsonErr('الملف غير موجود', 404);

            jsonOk([
                'content'   => file_get_contents($full),
                'path'      => $path,
                'size'      => filesize($full),
                'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            ]);

        case 'write_file':
            $pid     = (int)($input['project_id'] ?? 0);
            $path    = $input['path'] ?? '';
            $content = $input['content'] ?? '';

            if (!$pid || $path === '') jsonErr('معاملات ناقصة');

            $full = resolvePath($pid, $path);
            if (!$full) jsonErr('مسار الملف غير صالح (محاولة اختراق)');

            $dir = dirname($full);
            if (!is_dir($dir) && !mkdir($dir, 0755, true))
                jsonErr('فشل إنشاء المجلد', 500);

            if (file_put_contents($full, $content) === false)
                jsonErr('فشل الكتابة إلى الملف', 500);

            getDB()->prepare("UPDATE projects SET updated_at=NOW() WHERE id=?")->execute([$pid]);
            jsonOk(['success' => true, 'path' => $path]);

        case 'delete_file':
            $pid  = (int)($input['project_id'] ?? 0);
            $path = $input['path'] ?? '';
            if (!$pid || $path === '') jsonErr('معاملات ناقصة');

            $full = resolvePath($pid, $path);
            if (!$full) jsonErr('مسار غير صالح');
            if (!file_exists($full)) jsonErr('الملف غير موجود', 404);

            if (is_dir($full)) {
                if (count(scandir($full)) > 2) jsonErr('المجلد ليس فارغاً');
                rmdir($full);
            } else {
                unlink($full);
            }
            jsonOk(['success' => true]);

        case 'create_folder':
            $pid  = (int)($input['project_id'] ?? 0);
            $path = $input['path'] ?? '';
            if (!$pid || $path === '') jsonErr('معاملات ناقصة');

            $full = resolvePath($pid, $path);
            if (!$full) jsonErr('مسار غير صالح');
            if (is_dir($full)) jsonErr('المجلد موجود بالفعل');

            if (!mkdir($full, 0755, true)) jsonErr('فشل إنشاء المجلد', 500);
            jsonOk(['success' => true]);

        // ── Chat ──────────────────────────────────────────────────────────────

        case 'get_chat':
            $pid  = (int)($_GET['project_id'] ?? 0);
            if (!$pid) jsonErr('project_id مطلوب');

            $stmt = getDB()->prepare(
                "SELECT role, content, created_at FROM chat_history
                 WHERE project_id = ? ORDER BY created_at ASC"
            );
            $stmt->execute([$pid]);
            jsonOk(['messages' => $stmt->fetchAll()]);

        case 'clear_chat':
            $pid = (int)($input['project_id'] ?? 0);
            if (!$pid) jsonErr('project_id مطلوب');

            getDB()->prepare("DELETE FROM chat_history WHERE project_id = ?")->execute([$pid]);
            jsonOk(['success' => true]);

        case 'list_models':
            $result = [];
            foreach (AI_PROVIDERS as $key => $p) {
                $hasKey = !empty($p['api_key']) && !str_starts_with($p['api_key'], 'YOUR_');
                $models = [];
                foreach ($p['models'] as $mid => $info) {
                    $models[] = ['id' => $mid, 'label' => $info['label'], 'free' => $info['free']];
                }
                $result[] = [
                    'id'      => $key,
                    'name'    => $p['name'],
                    'has_key' => $hasKey,
                    'models'  => $models,
                ];
            }
            jsonOk(['providers' => $result]);

        case 'chat':
            $pid      = (int)($input['project_id'] ?? 0);
            $msg      = trim($input['message'] ?? '');
            $provider = trim($input['provider'] ?? 'gemini');
            $model    = trim($input['model']    ?? '');

            if (!$pid) jsonErr('project_id مطلوب');
            if ($msg === '') jsonErr('الرسالة لا يمكن أن تكون فارغة');

            $db   = getDB();
            $stmt = $db->prepare("SELECT id, name FROM projects WHERE id = ?");
            $stmt->execute([$pid]);
            $project = $stmt->fetch();
            if (!$project) jsonErr('المشروع غير موجود', 404);

            // Last 20 messages (reverse to get oldest first)
            $stmt = $db->prepare(
                "SELECT role, content FROM chat_history
                 WHERE project_id = ? ORDER BY created_at DESC LIMIT 20"
            );
            $stmt->execute([$pid]);
            $history = array_reverse($stmt->fetchAll());

            $context  = buildContext($pid);
            $response = callAI($provider, $model, $project['name'], $context, $history, $msg);

            if (isset($response['error'])) jsonErr($response['error'], 502);

            // Persist messages
            $db->prepare("INSERT INTO chat_history (project_id, role, content) VALUES (?, 'user', ?)")
               ->execute([$pid, $msg]);
            $db->prepare("INSERT INTO chat_history (project_id, role, content) VALUES (?, 'model', ?)")
               ->execute([$pid, $response['message'] ?? '']);
            $db->prepare("UPDATE projects SET updated_at=NOW() WHERE id=?")->execute([$pid]);

            jsonOk([
                'message' => $response['message'] ?? '',
                'files'   => $response['files']   ?? [],
            ]);

        case 'apply_files':
            $pid   = (int)($input['project_id'] ?? 0);
            $files = $input['files'] ?? [];

            if (!$pid) jsonErr('project_id مطلوب');
            if (!is_array($files) || empty($files)) jsonErr('لا توجد ملفات للتطبيق');

            $results = [];
            foreach ($files as $f) {
                $act  = $f['action'] ?? 'write';
                $path = $f['path']   ?? '';
                if ($path === '') continue;

                $full = resolvePath($pid, $path);
                if (!$full) {
                    $results[] = ['path' => $path, 'ok' => false, 'error' => 'مسار غير صالح'];
                    continue;
                }

                if ($act === 'delete') {
                    $ok = !file_exists($full) || unlink($full);
                    $results[] = ['path' => $path, 'ok' => $ok, 'action' => 'deleted'];
                } else {
                    $dir = dirname($full);
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $ok = file_put_contents($full, $f['content'] ?? '') !== false;
                    $results[] = ['path' => $path, 'ok' => $ok, 'action' => 'written'];
                }
            }

            getDB()->prepare("UPDATE projects SET updated_at=NOW() WHERE id=?")->execute([$pid]);
            jsonOk(['success' => true, 'results' => $results]);

        case 'save_api_keys':
            $keys    = $input['keys'] ?? [];
            $allowed = array_keys(AI_PROVIDERS);

            if (!is_array($keys) || empty($keys)) jsonErr('لا توجد مفاتيح للحفظ');

            $configPath = __DIR__ . '/config.php';
            $config     = file_get_contents($configPath);

            foreach ($keys as $provider => $key) {
                if (!in_array($provider, $allowed, true)) continue;
                $key = preg_replace('/[^a-zA-Z0-9\-_.:\/ ]/', '', trim($key));
                if (empty($key)) continue;

                // Replace the existing placeholder in the config file
                $config = preg_replace(
                    "/('" . preg_quote($provider, '/') . "'\s*=>\s*\[[\s\S]*?'api_key'\s*=>\s*')[^']*(')/U",
                    '${1}' . $key . '${2}',
                    $config
                );
            }

            if (file_put_contents($configPath, $config) === false) {
                jsonErr('فشل الكتابة إلى config.php — تحقق من صلاحيات الملف', 500);
            }

            jsonOk(['success' => true]);

        default:
            jsonErr('إجراء غير معروف', 404);
    }
} catch (PDOException $e) {
    jsonErr('خطأ في قاعدة البيانات: ' . $e->getMessage(), 500);
} catch (Throwable $e) {
    jsonErr('خطأ: ' . $e->getMessage(), 500);
}
