<?php
// ─── Database ────────────────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'ai_code_editor');
define('DB_USER', 'root');
define('DB_PASS', '');

// ─── File System ─────────────────────────────────────────────────────────────
define('PROJECTS_BASE_PATH', __DIR__ . '/projects');
define('MAX_FILE_SIZE_CONTEXT', 100 * 1024); // 100 KB per file
define('MAX_FILES_CONTEXT', 25);

// ═══════════════════════════════════════════════════════════════════════════
// AI Providers — أضف مفاتيحك هنا
// ═══════════════════════════════════════════════════════════════════════════
define('AI_PROVIDERS', [

    // ── Google Gemini (Free tier available) ──────────────────────────────
    'gemini' => [
        'name'    => 'Google Gemini',
        'api_key' => 'YOUR_GEMINI_API_KEY',   // https://aistudio.google.com/apikey
        'models'  => [
            'gemini-2.0-flash'         => ['label' => 'Gemini 2.0 Flash',     'free' => true],
            'gemini-1.5-flash'         => ['label' => 'Gemini 1.5 Flash',     'free' => true],
            'gemini-1.5-flash-8b'      => ['label' => 'Gemini 1.5 Flash-8B',  'free' => true],
            'gemini-2.5-flash-preview-04-17' => ['label' => 'Gemini 2.5 Flash', 'free' => true],
        ],
    ],

    // ── Groq — Llama / Mixtral (مجاني بالكامل) ───────────────────────────
    'groq' => [
        'name'    => 'Groq (Llama & Mixtral)',
        'api_key' => 'YOUR_GROQ_API_KEY',     // https://console.groq.com/keys
        'models'  => [
            'llama-3.3-70b-versatile' => ['label' => 'Llama 3.3 70B',   'free' => true],
            'llama3-70b-8192'         => ['label' => 'Llama 3 70B',     'free' => true],
            'mixtral-8x7b-32768'      => ['label' => 'Mixtral 8x7B',    'free' => true],
            'gemma2-9b-it'            => ['label' => 'Gemma 2 9B',      'free' => true],
        ],
    ],

    // ── DeepSeek (رصيد مجاني للمستخدمين الجدد) ─────────────────────────
    'deepseek' => [
        'name'    => 'DeepSeek',
        'api_key' => 'YOUR_DEEPSEEK_API_KEY', // https://platform.deepseek.com/api_keys
        'models'  => [
            'deepseek-chat'     => ['label' => 'DeepSeek V3 Chat',  'free' => true],
            'deepseek-reasoner' => ['label' => 'DeepSeek R1',       'free' => true],
        ],
    ],

    // ── OpenRouter (نماذج :free مجانية تماماً) ───────────────────────────
    'openrouter' => [
        'name'    => 'OpenRouter (Free Models)',
        'api_key' => 'YOUR_OPENROUTER_API_KEY', // https://openrouter.ai/keys
        'models'  => [
            'deepseek/deepseek-r1:free'                     => ['label' => 'DeepSeek R1 (Free)',       'free' => true],
            'deepseek/deepseek-chat-v3-0324:free'           => ['label' => 'DeepSeek V3 (Free)',       'free' => true],
            'meta-llama/llama-3.3-70b-instruct:free'        => ['label' => 'Llama 3.3 70B (Free)',     'free' => true],
            'meta-llama/llama-3.1-8b-instruct:free'         => ['label' => 'Llama 3.1 8B (Free)',      'free' => true],
            'qwen/qwen3-30b-a3b:free'                       => ['label' => 'Qwen3 30B (Free)',         'free' => true],
            'qwen/qwen3-8b:free'                            => ['label' => 'Qwen3 8B (Free)',          'free' => true],
            'microsoft/phi-4-reasoning-plus:free'           => ['label' => 'Phi-4 Reasoning (Free)',   'free' => true],
            'mistralai/mistral-7b-instruct:free'            => ['label' => 'Mistral 7B (Free)',        'free' => true],
            'google/gemma-3-27b-it:free'                    => ['label' => 'Gemma 3 27B (Free)',       'free' => true],
        ],
    ],

]);
