/* admin/admin.js — shared admin page behaviour (no inline scripts: CSP). */
(() => {
  const $ = (sel) => document.querySelector(sel);

  // Confirmation prompts: <form data-confirm="Message">
  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (e) => {
      if (!window.confirm(form.dataset.confirm)) e.preventDefault();
    });
  });

  // Disable the upload button once submitted.
  const uploadForm = $('#uploadForm');
  if (uploadForm) {
    uploadForm.addEventListener('submit', () => {
      const btn = $('#uploadBtn');
      if (btn) btn.disabled = true;
    });
  }

  // ---- Re-index wiring (index.php) ----
  const reBtn = $('#reindexBtn');
  const forceBtn = $('#forceReindexBtn');
  const reStatus = $('#reindexStatus');
  const warmBtn = $('#warmBtn');
  const warmStatus = $('#warmStatus');
  const config = $('#adminConfig');
  const csrfToken = config ? config.dataset.csrf : '';

  async function doReindex(force) {
    if (!reBtn || !forceBtn || !reStatus) return;
    reBtn.disabled = true;
    forceBtn.disabled = true;
    reStatus.textContent = force ? 'Full re-index…' : 'Indexing…';
    try {
      const url = force ? 'reindex.php?force=1' : 'reindex.php';
      const r = await fetch(url, {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
      });
      const j = await r.json();
      if (j.ok) {
        const s = j.summary || {};
        const parts = [];
        if (s.processed)  parts.push(s.processed + ' indexed');
        if (s.unchanged)  parts.push(s.unchanged + ' unchanged');
        if (s.skipped)    parts.push(s.skipped + ' skipped');
        if (s.cleaned)    parts.push(s.cleaned + ' cleaned');
        if (s.errors)     parts.push(s.errors + ' errors');
        reStatus.textContent = parts.length ? 'Done: ' + parts.join(', ') : 'Done.';
        reStatus.style.color = s.errors ? '#b91c1c' : '';
        // Warm the search index
        fetch('../data_index.php', { cache: 'no-store' }).catch(() => {});
      } else {
        reStatus.textContent = 'Failed: ' + (j.error || 'unknown error');
        reStatus.style.color = '#b91c1c';
      }
    } catch (e) {
      reStatus.textContent = 'Network error – server may have timed out. Try "Force full re-index".';
      reStatus.style.color = '#b91c1c';
    } finally {
      reBtn.disabled = false;
      forceBtn.disabled = false;
      const note = document.getElementById('autoReindexNote');
      if (note) note.remove();
    }
  }

  if (reBtn) reBtn.addEventListener('click', () => doReindex(false));
  if (forceBtn) forceBtn.addEventListener('click', () => doReindex(true));

  if (warmBtn && warmStatus) {
    warmBtn.addEventListener('click', async () => {
      warmBtn.disabled = true;
      warmStatus.textContent = 'Pinging…';
      try {
        const r = await fetch('../data_index.php', { cache: 'no-store' });
        warmStatus.textContent = r.ok ? 'OK' : ('HTTP ' + r.status);
      } catch (e) {
        warmStatus.textContent = 'Network error';
      } finally {
        warmBtn.disabled = false;
      }
    });
  }

  // Auto-trigger reindex after a successful upload.
  if (config && config.dataset.autoReindex === '1') {
    setTimeout(() => doReindex(false), 400);
  }
})();
