<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AI Code Editor</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="app">

  <!-- ═══ Header ══════════════════════════════════════════════════════ -->
  <header class="app-header">
    <span class="logo">⚡ AI Code Editor</span>
    <span class="project-title" id="project-title">اختر مشروعاً</span>
    <div class="header-actions">
      <a id="btn-export" class="btn btn-ghost btn-sm" download title="تصدير المشروع كـ ZIP">
        📦 تصدير ZIP
      </a>
      <button id="btn-clear-chat" class="btn btn-ghost btn-sm" title="مسح سجل المحادثة">
        🗑️ مسح المحادثة
      </button>
    </div>
  </header>

  <!-- ═══ Body ═════════════════════════════════════════════════════════ -->
  <div class="app-body">

    <!-- ── Sidebar ───────────────────────────────────────────────── -->
    <aside class="sidebar">

      <!-- Projects -->
      <div class="sidebar-section projects">
        <div class="section-header">
          <span>المشاريع</span>
          <div class="actions">
            <button class="btn-icon" id="btn-new-project" title="مشروع جديد">＋</button>
          </div>
        </div>
        <div class="project-list" id="project-list">
          <div style="padding:10px 12px;color:var(--text-dim);font-size:12px;">جاري التحميل...</div>
        </div>
      </div>

      <!-- File Explorer -->
      <div class="sidebar-section explorer">
        <div class="section-header">
          <span>المستكشف</span>
          <div class="actions">
            <button class="btn-icon" id="btn-new-folder" title="مجلد جديد">🗂️</button>
            <button class="btn-icon" id="btn-new-file"   title="ملف جديد">📄+</button>
          </div>
        </div>
        <div class="file-tree-wrap" id="file-tree-wrap">
          <div style="padding:10px 12px;color:var(--text-dim);font-size:12px;">اختر مشروعاً لعرض الملفات.</div>
        </div>
      </div>

    </aside>

    <!-- ── Main Content ──────────────────────────────────────────── -->
    <main class="main-content">

      <!-- No project selected screen -->
      <div class="no-project" id="no-project-screen">
        <div class="big-icon">🚀</div>
        <p>أنشئ أو اختر مشروعاً من القائمة الجانبية للبدء</p>
        <button class="btn btn-primary" id="btn-new-project-center">＋ مشروع جديد</button>
      </div>

      <!-- Main interface (hidden until project selected) -->
      <div class="panes hidden" id="main-interface">

        <!-- Chat Pane -->
        <div class="chat-pane">
          <div class="pane-header">
            <span>💬 المحادثة مع الذكاء الاصطناعي</span>
            <span style="font-size:10px;color:var(--text-dim);">Enter للإرسال · Shift+Enter لسطر جديد</span>
          </div>

          <div class="chat-messages" id="chat-messages">
            <div class="chat-empty">
              <span class="icon">🤖</span>
              <strong>اختر مشروعاً للبدء</strong>
            </div>
          </div>

          <div class="chat-input-area">
            <textarea
              id="chat-input"
              placeholder="اطلب من الذكاء الاصطناعي إنشاء ملفات أو تعديل الكود... (مثال: أنشئ صفحة تسجيل دخول احترافية)"
              rows="3"
            ></textarea>
            <button id="btn-send" class="btn btn-primary" style="align-self:flex-end;">إرسال</button>
          </div>
        </div>

        <!-- Editor Pane -->
        <div class="editor-pane">
          <div id="editor-tab-bar" class="editor-tab-bar"></div>

          <div class="editor-toolbar">
            <span class="file-path-display" id="editor-file-path">لا يوجد ملف مفتوح</span>
            <button id="btn-save-file" class="btn btn-success btn-sm" title="Ctrl+S">
              💾 حفظ
            </button>
          </div>

          <textarea
            id="code-editor"
            spellcheck="false"
            autocomplete="off"
            autocorrect="off"
            autocapitalize="off"
            disabled
            placeholder="انقر على ملف لفتحه..."
          ></textarea>

          <div class="editor-status">
            <span id="editor-status-line">—</span>
            <span style="margin-right:auto;color:var(--text-dim);">Ctrl+S للحفظ</span>
          </div>
        </div>

      </div><!-- /main-interface -->

    </main>

  </div><!-- /app-body -->

</div><!-- /app -->

<!-- Toast container -->
<div id="toast-container"></div>

<script src="assets/js/app.js"></script>
<script>
  // Wire up the center "new project" button (needs to run after app.js defines promptNewProject)
  document.getElementById('btn-new-project-center')
    .addEventListener('click', () => promptNewProject());
</script>
</body>
</html>
