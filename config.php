<?php
// ─── Database ───────────────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'ai_code_editor');
define('DB_USER', 'root');
define('DB_PASS', '');

// ─── Gemini API ─────────────────────────────────────────────────────────────
define('GEMINI_API_KEY', 'YOUR_GEMINI_API_KEY_HERE');
define('GEMINI_MODEL', 'gemini-2.0-flash');

// ─── File System ─────────────────────────────────────────────────────────────
define('PROJECTS_BASE_PATH', __DIR__ . '/projects');
define('MAX_FILE_SIZE_CONTEXT', 100 * 1024); // 100 KB per file in AI context
define('MAX_FILES_CONTEXT', 25);             // Max files sent to AI
