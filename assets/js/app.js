/* AI Code Editor – Frontend Application
 * Native Vanilla JS, no dependencies
 */

// ─── State ────────────────────────────────────────────────────────────────
const state = {
  projects:         [],
  currentProject:   null,
  currentFilePath:  null,
  fileModified:     false,
  busy:             false,
  pendingFiles:     [],
  providers:        [],     // loaded from server
  // selected provider/model persisted in localStorage
  get provider()  { return localStorage.getItem('ai_provider')  || 'gemini'; },
  set provider(v) { localStorage.setItem('ai_provider', v); },
  get model()     { return localStorage.getItem('ai_model')     || ''; },
  set model(v)    { localStorage.setItem('ai_model', v); },
};

// ─── API Helper ───────────────────────────────────────────────────────────
async function api(action, method = 'GET', body = null) {
  const url = `api.php?action=${action}`;
  const opts = { method };

  if (body) {
    opts.headers = { 'Content-Type': 'application/json' };
    opts.body    = JSON.stringify(body);
  }

  const res  = await fetch(url, opts);
  const data = await res.json().catch(() => ({ error: 'خطأ في تحليل الاستجابة' }));

  if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
  return data;
}

// ─── Toast Notifications ─────────────────────────────────────────────────
function toast(msg, type = 'success') {
  const el = document.createElement('div');
  el.className = `toast toast-${type}`;
  el.textContent = msg;
  document.getElementById('toast-container').appendChild(el);
  setTimeout(() => el.remove(), 3200);
}

// ─── Modal ────────────────────────────────────────────────────────────────
function openModal({ title, fields = [], confirmText = 'تأكيد', dangerConfirm = false, onConfirm }) {
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';

  const formHtml = fields.map(f => `
    <div class="form-group">
      <label>${f.label}</label>
      ${f.type === 'textarea'
        ? `<textarea id="mf_${f.key}" rows="3" placeholder="${f.placeholder || ''}">${f.default || ''}</textarea>`
        : `<input id="mf_${f.key}" type="${f.type || 'text'}" placeholder="${f.placeholder || ''}" value="${f.default || ''}">`
      }
    </div>`).join('');

  overlay.innerHTML = `
    <div class="modal">
      <h3>${title}</h3>
      ${formHtml}
      <div class="modal-actions">
        <button class="btn btn-ghost" id="modal-cancel">إلغاء</button>
        <button class="btn ${dangerConfirm ? 'btn-danger' : 'btn-primary'}" id="modal-confirm">${confirmText}</button>
      </div>
    </div>`;

  document.body.appendChild(overlay);

  const first = overlay.querySelector('input, textarea');
  if (first) setTimeout(() => first.focus(), 50);

  overlay.querySelector('#modal-cancel').onclick  = () => overlay.remove();
  overlay.querySelector('#modal-confirm').onclick = () => {
    const values = {};
    fields.forEach(f => { values[f.key] = overlay.querySelector(`#mf_${f.key}`).value.trim(); });
    overlay.remove();
    onConfirm(values);
  };

  overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });

  // Enter to confirm (unless textarea)
  overlay.addEventListener('keydown', e => {
    if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
      overlay.querySelector('#modal-confirm').click();
    }
    if (e.key === 'Escape') overlay.remove();
  });
}

function confirm(msg, onYes) {
  openModal({
    title: msg, fields: [], confirmText: 'نعم، حذف',
    dangerConfirm: true, onConfirm: onYes,
  });
}

// ─── Projects ─────────────────────────────────────────────────────────────
async function loadProjects() {
  try {
    const data = await api('list_projects');
    state.projects = data.projects;
    renderProjectList();
  } catch (e) {
    toast('فشل تحميل المشاريع: ' + e.message, 'error');
  }
}

function renderProjectList() {
  const list = document.getElementById('project-list');
  if (state.projects.length === 0) {
    list.innerHTML = '<div style="padding:10px 12px;color:var(--text-dim);font-size:12px;">لا توجد مشاريع بعد.</div>';
    return;
  }

  list.innerHTML = state.projects.map(p => `
    <div class="project-item ${state.currentProject?.id === p.id ? 'active' : ''}"
         data-id="${p.id}" data-name="${escHtml(p.name)}">
      <span class="proj-icon">📁</span>
      <span class="proj-name" title="${escHtml(p.name)}">${escHtml(p.name)}</span>
      <button class="project-del" data-id="${p.id}" title="حذف المشروع">✕</button>
    </div>`).join('');

  list.querySelectorAll('.project-item').forEach(el => {
    el.addEventListener('click', e => {
      if (!e.target.classList.contains('project-del')) {
        selectProject(+el.dataset.id, el.dataset.name);
      }
    });
  });

  list.querySelectorAll('.project-del').forEach(btn => {
    btn.addEventListener('click', e => {
      e.stopPropagation();
      confirm(`حذف المشروع "${btn.closest('.project-item').dataset.name}" وجميع ملفاته نهائياً؟`,
        () => deleteProject(+btn.dataset.id));
    });
  });
}

function promptNewProject() {
  openModal({
    title: 'مشروع جديد',
    fields: [
      { key: 'name', label: 'اسم المشروع', placeholder: 'مثال: My Portfolio' },
      { key: 'description', label: 'وصف مختصر (اختياري)', type: 'textarea',
        placeholder: 'صف المشروع باختصار...' },
    ],
    confirmText: 'إنشاء',
    onConfirm: async ({ name, description }) => {
      if (!name) return toast('اسم المشروع مطلوب', 'error');
      try {
        const data = await api('create_project', 'POST', { name, description });
        toast('تم إنشاء المشروع ✓');
        await loadProjects();
        selectProject(data.project.id, data.project.name);
      } catch (e) { toast(e.message, 'error'); }
    },
  });
}

async function deleteProject(id) {
  try {
    await api('delete_project', 'POST', { id });
    if (state.currentProject?.id === id) {
      state.currentProject = null;
      showNoProject();
    }
    toast('تم حذف المشروع');
    await loadProjects();
  } catch (e) { toast(e.message, 'error'); }
}

async function selectProject(id, name) {
  state.currentProject = { id, name };
  state.currentFilePath = null;
  state.pendingFiles    = [];
  state.fileModified    = false;

  document.getElementById('project-title').textContent = name;
  document.getElementById('btn-export').href = `export.php?project_id=${id}`;
  showMainInterface();
  renderProjectList();

  await Promise.all([loadFileTree(), loadChatHistory()]);
}

// ─── File Tree ────────────────────────────────────────────────────────────
async function loadFileTree() {
  if (!state.currentProject) return;
  try {
    const data = await api(`list_files&project_id=${state.currentProject.id}`);
    renderFileTree(data.files);
  } catch (e) { toast('فشل تحميل الملفات: ' + e.message, 'error'); }
}

function renderFileTree(files) {
  const wrap = document.getElementById('file-tree-wrap');
  if (files.length === 0) {
    wrap.innerHTML = '<div style="padding:8px 12px;color:var(--text-dim);font-size:12px;">لا توجد ملفات. اطلب من الذكاء الاصطناعي إنشاء ملفات.</div>';
    return;
  }
  wrap.innerHTML = '';
  const tree = document.createElement('div');
  tree.className = 'file-tree';
  renderTreeNodes(files, tree);
  wrap.appendChild(tree);
}

function renderTreeNodes(nodes, container) {
  nodes.forEach(node => {
    const item = document.createElement('div');
    item.className = 'tree-item';

    if (node.type === 'dir') {
      item.innerHTML = `
        <span class="tree-icon">📂</span>
        <span class="tree-name">${escHtml(node.name)}</span>
        <button class="tree-del" data-path="${escHtml(node.path)}" title="حذف المجلد">✕</button>`;

      const children = document.createElement('div');
      children.className = 'tree-children';
      if (node.children?.length) renderTreeNodes(node.children, children);

      let open = true;
      item.querySelector('.tree-name').addEventListener('click', () => {
        open = !open;
        children.style.display = open ? '' : 'none';
        item.querySelector('.tree-icon').textContent = open ? '📂' : '📁';
      });

      container.appendChild(item);
      container.appendChild(children);
    } else {
      const icon = fileIcon(node.extension);
      item.className += state.currentFilePath === node.path ? ' active' : '';
      item.innerHTML = `
        <span class="tree-icon">${icon}</span>
        <span class="tree-name" title="${escHtml(node.path)}">${escHtml(node.name)}</span>
        <button class="tree-del" data-path="${escHtml(node.path)}" title="حذف الملف">✕</button>`;

      item.querySelector('.tree-name').addEventListener('click', () => openFile(node.path));
      container.appendChild(item);
    }

    item.querySelector('.tree-del').addEventListener('click', async e => {
      e.stopPropagation();
      const path = e.currentTarget.dataset.path;
      confirm(`حذف "${path}"؟`, async () => {
        try {
          await api('delete_file', 'POST', { project_id: state.currentProject.id, path });
          if (state.currentFilePath === path) closeEditor();
          await loadFileTree();
          toast('تم الحذف');
        } catch (err) { toast(err.message, 'error'); }
      });
    });
  });
}

function fileIcon(ext) {
  const map = {
    php: '🐘', js: '🟨', ts: '🔷', html: '🌐', htm: '🌐',
    css: '🎨', scss: '🎨', json: '📋', md: '📝', txt: '📄',
    py: '🐍', java: '☕', rb: '💎', go: '🐹', rs: '🦀',
    sql: '🗄️', xml: '📰', svg: '🖼️', png: '🖼️', jpg: '🖼️',
    jpeg: '🖼️', gif: '🖼️', sh: '⚡', bash: '⚡', env: '🔒',
    yaml: '⚙️', yml: '⚙️', toml: '⚙️', ini: '⚙️',
  };
  return map[ext] || '📄';
}

async function openFile(path) {
  if (state.fileModified) {
    if (!window.confirm('لديك تغييرات غير محفوظة. هل تريد المتابعة؟')) return;
  }
  try {
    const data = await api(`read_file&project_id=${state.currentProject.id}&path=${encodeURIComponent(path)}`);
    state.currentFilePath = path;
    state.fileModified    = false;

    const editor = document.getElementById('code-editor');
    editor.value    = data.content;
    editor.disabled = false;

    document.getElementById('editor-file-path').textContent = path;
    document.getElementById('editor-status-line').textContent = `${data.size} bytes`;

    // Update active state in tree
    document.querySelectorAll('.tree-item').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tree-item').forEach(el => {
      if (el.querySelector('.tree-del')?.dataset.path === path) el.classList.add('active');
    });

    updateEditorTab(path);
  } catch (e) { toast('فشل قراءة الملف: ' + e.message, 'error'); }
}

function updateEditorTab(path) {
  const bar  = document.getElementById('editor-tab-bar');
  const name = path.split('/').pop();
  bar.innerHTML = `
    <div class="editor-tab active">
      <span>${escHtml(name)}</span>
      <button class="tab-close" title="إغلاق">✕</button>
    </div>`;
  bar.querySelector('.tab-close').addEventListener('click', closeEditor);
}

function closeEditor() {
  state.currentFilePath = null;
  state.fileModified    = false;
  const editor = document.getElementById('code-editor');
  editor.value    = '';
  editor.disabled = true;
  document.getElementById('editor-file-path').textContent = 'لا يوجد ملف مفتوح';
  document.getElementById('editor-tab-bar').innerHTML = '';
  document.querySelectorAll('.tree-item').forEach(el => el.classList.remove('active'));
}

async function saveFile() {
  if (!state.currentProject || !state.currentFilePath) return;
  const content = document.getElementById('code-editor').value;
  try {
    await api('write_file', 'POST', {
      project_id: state.currentProject.id,
      path:       state.currentFilePath,
      content,
    });
    state.fileModified = false;
    toast('تم الحفظ ✓');
    document.getElementById('editor-status-line').textContent = `${content.length} bytes`;
  } catch (e) { toast('فشل الحفظ: ' + e.message, 'error'); }
}

function promptNewFile() {
  if (!state.currentProject) return toast('اختر مشروعاً أولاً', 'error');
  openModal({
    title: 'ملف جديد',
    fields: [{ key: 'path', label: 'مسار الملف (نسبي)', placeholder: 'مثال: index.html أو css/style.css' }],
    confirmText: 'إنشاء',
    onConfirm: async ({ path }) => {
      if (!path) return toast('المسار مطلوب', 'error');
      try {
        await api('write_file', 'POST', { project_id: state.currentProject.id, path, content: '' });
        await loadFileTree();
        openFile(path);
        toast('تم إنشاء الملف ✓');
      } catch (e) { toast(e.message, 'error'); }
    },
  });
}

function promptNewFolder() {
  if (!state.currentProject) return toast('اختر مشروعاً أولاً', 'error');
  openModal({
    title: 'مجلد جديد',
    fields: [{ key: 'path', label: 'اسم المجلد', placeholder: 'مثال: css أو src/components' }],
    confirmText: 'إنشاء',
    onConfirm: async ({ path }) => {
      if (!path) return toast('المسار مطلوب', 'error');
      try {
        await api('create_folder', 'POST', { project_id: state.currentProject.id, path });
        await loadFileTree();
        toast('تم إنشاء المجلد ✓');
      } catch (e) { toast(e.message, 'error'); }
    },
  });
}

// ─── Chat ─────────────────────────────────────────────────────────────────
async function loadChatHistory() {
  if (!state.currentProject) return;
  try {
    const data = await api(`get_chat&project_id=${state.currentProject.id}`);
    const container = document.getElementById('chat-messages');
    container.innerHTML = '';

    if (data.messages.length === 0) {
      container.innerHTML = `
        <div class="chat-empty">
          <span class="icon">🤖</span>
          <strong>مرحباً! أنا مساعدك البرمجي الذكي</strong>
          <p>اطلب مني إنشاء ملفات أو تعديلها أو كتابة أكواد جديدة.</p>
          <p style="color:var(--text-dim);font-size:12px;">مثال: "أنشئ صفحة HTML احترافية مع CSS"</p>
        </div>`;
      return;
    }

    data.messages.forEach(m => appendMessage(m.role, m.content));
    scrollChat();
  } catch (e) { toast('فشل تحميل المحادثة: ' + e.message, 'error'); }
}

function appendMessage(role, content, pendingFiles = []) {
  const container = document.getElementById('chat-messages');

  // Remove empty state
  const empty = container.querySelector('.chat-empty');
  if (empty) empty.remove();

  const el = document.createElement('div');
  el.className = `msg msg-${role === 'user' ? 'user' : 'ai'}`;

  const label = role === 'user' ? '👤 أنت' : '🤖 AI';
  let html = `<div class="msg-label">${label}</div><div class="msg-text">${escHtml(content)}</div>`;

  if (pendingFiles.length > 0) {
    const ops = pendingFiles.map(f => {
      const badge = f.action === 'delete' ? 'badge-delete' : 'badge-write';
      const label = f.action === 'delete' ? 'حذف' : 'كتابة';
      return `<div class="file-op-item">
        <span class="file-op-badge ${badge}">${label}</span>
        <span>${escHtml(f.path)}</span>
      </div>`;
    }).join('');

    html += `
      <div class="file-ops" data-ops-idx="${state.pendingFiles.length - 1}">
        <div class="file-ops-header">
          <span>📁 تغييرات الملفات (${pendingFiles.length})</span>
          <div style="display:flex;gap:6px;">
            <button class="btn btn-success btn-sm btn-apply-files">✓ تطبيق</button>
            <button class="btn btn-ghost btn-sm btn-dismiss-files">تجاهل</button>
          </div>
        </div>
        <div class="file-ops-list">${ops}</div>
      </div>`;
  }

  el.innerHTML = html;

  if (pendingFiles.length > 0) {
    const applyBtn   = el.querySelector('.btn-apply-files');
    const dismissBtn = el.querySelector('.btn-dismiss-files');
    const opsDiv     = el.querySelector('.file-ops');

    applyBtn.addEventListener('click', async () => {
      applyBtn.disabled = true;
      applyBtn.textContent = 'جاري التطبيق...';
      await applyFiles(pendingFiles, opsDiv);
    });

    dismissBtn.addEventListener('click', () => {
      opsDiv.innerHTML = '<div style="padding:8px 10px;color:var(--text-dim);font-size:12px;">تم تجاهل التغييرات</div>';
    });
  }

  container.appendChild(el);
}

function appendTypingIndicator() {
  const container = document.getElementById('chat-messages');
  const el = document.createElement('div');
  el.id = 'typing-indicator';
  el.className = 'typing-indicator';
  el.innerHTML = '<div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div>';
  container.appendChild(el);
  scrollChat();
  return el;
}

function scrollChat() {
  const c = document.getElementById('chat-messages');
  c.scrollTop = c.scrollHeight;
}

async function sendMessage() {
  if (!state.currentProject) return toast('اختر مشروعاً أولاً', 'error');
  if (state.busy) return;

  const input   = document.getElementById('chat-input');
  const message = input.value.trim();
  if (!message) return;

  state.busy = true;
  setBusy(true);
  input.value = '';

  appendMessage('user', message);
  const indicator = appendTypingIndicator();
  scrollChat();

  try {
    const data = await api('chat', 'POST', {
      project_id: state.currentProject.id,
      message,
      provider:   state.provider,
      model:      state.model,
    });

    indicator.remove();

    if (data.files?.length > 0) {
      state.pendingFiles.push(data.files);
      appendMessage('model', data.message, data.files);
    } else {
      appendMessage('model', data.message);
    }

    scrollChat();
  } catch (e) {
    indicator.remove();
    appendMessage('model', `❌ خطأ: ${e.message}`);
    scrollChat();
  } finally {
    state.busy = false;
    setBusy(false);
  }
}

async function applyFiles(files, opsElement) {
  try {
    const data = await api('apply_files', 'POST', {
      project_id: state.currentProject.id,
      files,
    });

    const allOk  = data.results.every(r => r.ok);
    const count  = data.results.filter(r => r.ok).length;

    opsElement.innerHTML = `
      <div style="padding:8px 10px;color:${allOk ? 'var(--success)' : 'var(--warning)'};font-size:12px;">
        ✓ تم تطبيق ${count}/${data.results.length} ملف
      </div>`;

    await loadFileTree();
    toast(`تم تطبيق ${count} ملف ✓`);

    // Auto-open first written file
    const first = files.find(f => f.action !== 'delete');
    if (first) openFile(first.path);
  } catch (e) {
    toast('فشل التطبيق: ' + e.message, 'error');
    opsElement.querySelector('.btn-apply-files').disabled = false;
    opsElement.querySelector('.btn-apply-files').textContent = '✓ إعادة المحاولة';
  }
}

async function clearChat() {
  if (!state.currentProject) return;
  confirm('حذف سجل المحادثة بالكامل؟', async () => {
    try {
      await api('clear_chat', 'POST', { project_id: state.currentProject.id });
      await loadChatHistory();
      toast('تم مسح المحادثة');
    } catch (e) { toast(e.message, 'error'); }
  });
}

// ─── UI helpers ───────────────────────────────────────────────────────────
function setBusy(busy) {
  document.getElementById('btn-send').disabled        = busy;
  document.getElementById('chat-input').disabled      = busy;
  document.getElementById('btn-send').textContent     = busy ? '...' : 'إرسال';
}

function showNoProject() {
  document.getElementById('no-project-screen').classList.remove('hidden');
  document.getElementById('main-interface').classList.add('hidden');
  document.getElementById('project-title').textContent = 'لا يوجد مشروع';
  document.getElementById('btn-export').removeAttribute('href');
}

function showMainInterface() {
  document.getElementById('no-project-screen').classList.add('hidden');
  document.getElementById('main-interface').classList.remove('hidden');
}

function escHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

// ─── Keyboard Shortcuts ───────────────────────────────────────────────────
document.addEventListener('keydown', e => {
  // Ctrl+S → Save file
  if ((e.ctrlKey || e.metaKey) && e.key === 's') {
    e.preventDefault();
    if (state.currentFilePath) saveFile();
  }

  // Ctrl+Enter → Send chat
  if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
    if (document.activeElement === document.getElementById('chat-input')) {
      e.preventDefault();
      sendMessage();
    }
  }
});

// ─── Model Selector ──────────────────────────────────────────────────────
async function loadModels() {
  try {
    const data = await fetch('api.php?action=list_models').then(r => r.json());
    state.providers = data.providers || [];
    renderProviderSelect();
  } catch (e) {
    console.warn('Failed to load models', e);
  }
}

function renderProviderSelect() {
  const selP = document.getElementById('sel-provider');
  const selM = document.getElementById('sel-model');

  selP.innerHTML = state.providers.map(p => {
    const badge = p.has_key ? '✓' : '⚠';
    return `<option value="${p.id}" ${p.id === state.provider ? 'selected' : ''}>${badge} ${p.name}</option>`;
  }).join('');

  updateModelSelect();

  selP.addEventListener('change', () => {
    state.provider = selP.value;
    // Default to first model of new provider
    const prov = state.providers.find(p => p.id === selP.value);
    state.model = prov?.models[0]?.id || '';
    updateModelSelect();
    updateProviderStatus();
  });

  selM.addEventListener('change', () => {
    state.model = selM.value;
  });

  updateProviderStatus();
}

function updateModelSelect() {
  const selM  = document.getElementById('sel-model');
  const prov  = state.providers.find(p => p.id === state.provider);
  if (!prov) return;

  selM.innerHTML = prov.models.map(m => {
    const freeTag = m.free ? ' 🆓' : '';
    return `<option value="${m.id}" ${m.id === state.model ? 'selected' : ''}>${m.label}${freeTag}</option>`;
  }).join('');

  // Sync state.model to whatever is selected
  state.model = selM.value;
}

function updateProviderStatus() {
  const status = document.getElementById('provider-status');
  const prov   = state.providers.find(p => p.id === state.provider);
  if (!prov) { status.textContent = ''; return; }
  status.textContent = prov.has_key ? '🟢' : '🔴';
  status.title       = prov.has_key
    ? `${prov.name}: مفتاح API متوفر`
    : `${prov.name}: يرجى إضافة API Key في config.php`;
}

// ─── API Keys Modal ───────────────────────────────────────────────────────
function openApiKeysModal() {
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';

  const blocks = state.providers.map(p => {
    const hasKey = p.has_key;
    const icon   = hasKey ? '🟢' : '🔴';
    const freeModels = p.models.filter(m => m.free).length;

    return `
      <div class="provider-block">
        <div class="keys-provider-header">
          <span>${icon} ${escHtml(p.name)}</span>
          ${freeModels > 0 ? `<span class="free-badge">🆓 ${freeModels} نموذج مجاني</span>` : ''}
        </div>
        <div style="display:flex;align-items:center;gap:6px;">
          <input type="password" id="key_${p.id}"
            placeholder="أدخل API Key لـ ${escHtml(p.name)}"
            style="flex:1;background:var(--input-bg);border:1px solid var(--border);border-radius:4px;
                   color:var(--text);font-family:var(--font-code);font-size:12px;padding:5px 8px;outline:none;"
          >
          <span style="font-size:12px;color:var(--text-dim);white-space:nowrap;">${hasKey ? '✓ محفوظ' : 'غير مضبوط'}</span>
        </div>
        <div style="font-size:11px;color:var(--text-dim);margin-top:4px;">
          ${getProviderLink(p.id)}
        </div>
      </div>`;
  }).join('');

  overlay.innerHTML = `
    <div class="modal" style="max-width:540px;">
      <h3>🔑 إعداد مفاتيح API</h3>
      <p style="font-size:12px;color:var(--text-dim);margin-bottom:14px;">
        أدخل مفتاح واحد على الأقل للبدء. المفاتيح تُحفظ في <code>config.php</code> على السيرفر.
      </p>
      ${blocks}
      <div class="modal-actions">
        <button class="btn btn-ghost" id="modal-cancel">إغلاق</button>
        <button class="btn btn-primary" id="modal-save-keys">💾 حفظ المفاتيح</button>
      </div>
    </div>`;

  document.body.appendChild(overlay);

  overlay.querySelector('#modal-cancel').onclick = () => overlay.remove();
  overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });

  overlay.querySelector('#modal-save-keys').onclick = async () => {
    const updates = {};
    state.providers.forEach(p => {
      const val = overlay.querySelector(`#key_${p.id}`)?.value.trim();
      if (val) updates[p.id] = val;
    });

    if (!Object.keys(updates).length) {
      toast('لم تُدخل أي مفتاح', 'error');
      return;
    }

    try {
      await api('save_api_keys', 'POST', { keys: updates });
      toast('تم حفظ المفاتيح ✓ — جاري إعادة تحميل النماذج...');
      overlay.remove();
      await loadModels();
    } catch (e) {
      toast('فشل الحفظ: ' + e.message, 'error');
    }
  };
}

function getProviderLink(id) {
  const links = {
    gemini:      '<a href="https://aistudio.google.com/apikey" target="_blank" style="color:#4fc3f7;">احصل على مفتاح Gemini مجاناً ↗</a>',
    groq:        '<a href="https://console.groq.com/keys" target="_blank" style="color:#4fc3f7;">احصل على مفتاح Groq مجاناً ↗</a>',
    deepseek:    '<a href="https://platform.deepseek.com/api_keys" target="_blank" style="color:#4fc3f7;">احصل على مفتاح DeepSeek ↗</a>',
    openrouter:  '<a href="https://openrouter.ai/keys" target="_blank" style="color:#4fc3f7;">احصل على مفتاح OpenRouter مجاناً ↗</a>',
  };
  return links[id] || '';
}

// ─── Init ─────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  // Wire up static buttons
  document.getElementById('btn-new-project').addEventListener('click', promptNewProject);
  document.getElementById('btn-clear-chat').addEventListener('click', clearChat);
  document.getElementById('btn-api-keys').addEventListener('click', openApiKeysModal);
  document.getElementById('btn-send').addEventListener('click', sendMessage);
  document.getElementById('btn-save-file').addEventListener('click', saveFile);
  document.getElementById('btn-new-file').addEventListener('click', promptNewFile);
  document.getElementById('btn-new-folder').addEventListener('click', promptNewFolder);

  // Chat input: Enter = send, Shift+Enter = newline
  document.getElementById('chat-input').addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  });

  // Track editor modifications
  document.getElementById('code-editor').addEventListener('input', () => {
    state.fileModified = true;
  });

  // Tab key in editor inserts spaces
  document.getElementById('code-editor').addEventListener('keydown', e => {
    if (e.key === 'Tab') {
      e.preventDefault();
      const ta    = e.target;
      const start = ta.selectionStart;
      const end   = ta.selectionEnd;
      ta.value = ta.value.substring(0, start) + '  ' + ta.value.substring(end);
      ta.selectionStart = ta.selectionEnd = start + 2;
    }
  });

  loadProjects();
  loadModels();
});
