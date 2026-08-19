/* assets/app.js — chat wiring + RAG + streaming + logging for LegalStudyBot. */
(() => {
  const $ = (sel, root = document) => root.querySelector(sel);

  // --- Config (from <body data-*>; CSP forbids inline scripts) ---
  const WORKER_URL = document.body.dataset.workerUrl || '';
  const RAG_URL = document.body.dataset.ragUrl || '/data_index.php';
  const CONSENT_KEY = 'lsb_consent';
  const CONSENT_VERSION = 'v1'; // bump to re-prompt everyone after wording changes

  // --- DOM ---
  const consentGate = $('#consent-gate');
  const c1 = $('#c1');
  const c2 = $('#c2');
  const agreeBtn = $('#agreeBtn');

  const app = $('#app');
  const heroCard = $('#heroCard');
  const chatLog = $('#chatLog');

  const chatForm = $('#chatForm');
  const input = $('#prompt');
  const sendBtn = $('#sendBtn');
  const quizBtn = $('#quizBtn');

  const langPicker = $('#langPicker');
  const newChatBtn = $('#newChatBtn');
  const menuBtn = $('#menuBtn');
  const menuPanel = $('#menuPanel');
  const copyBtn = $('#copyBtn');
  const emailBtn = $('#emailBtn');
  const emailModal = $('#emailModal');
  const emailInput = $('#emailInput');
  const emailSend = $('#emailSend');
  const emailCancel = $('#emailCancel');
  const emailStatus = $('#emailStatus');

  const currentLang = () => (langPicker ? langPicker.value : 'en');

  // ---------- Consent gate (persisted, versioned) ----------
  function consentStored() {
    try { return localStorage.getItem(CONSENT_KEY) === CONSENT_VERSION; }
    catch { return false; }
  }
  function hideConsent() {
    if (consentGate) consentGate.classList.add('hide');
    if (app) app.hidden = false;
    autosize(); // now measurable
  }
  function wireConsent() {
    if (!consentGate || !agreeBtn || !c1 || !c2) {
      hideConsent();
      return;
    }
    if (consentStored()) {
      hideConsent();
      return;
    }
    const maybeEnable = () => { agreeBtn.disabled = !(c1.checked && c2.checked); };
    c1.addEventListener('change', maybeEnable);
    c2.addEventListener('change', maybeEnable);
    maybeEnable();
    agreeBtn.addEventListener('click', () => {
      if (agreeBtn.disabled) return;
      try { localStorage.setItem(CONSENT_KEY, CONSENT_VERSION); } catch {}
      hideConsent();
      if (input) input.focus();
    });
  }

  // ---------- Rendering ----------
  function escapeHTML(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  // Inline transforms applied to already-escaped text.
  function renderInline(s) {
    // Inline code first so its contents skip later transforms.
    s = s.replace(/`([^`\n]+)`/g, '<code>$1</code>');

    // Bold / italics (markdown).
    s = s.replace(/\*{3}([^*]+)\*{3}/g, '<strong><em>$1</em></strong>')
         .replace(/\*{2}([^*]+)\*{2}/g, '<strong>$1</strong>')
         .replace(/__([^_]+)__/g, '<strong>$1</strong>')
         .replace(/(^|[\s(])\*([^*]+)\*(?=[\s).,!?:;]|$)/g, '$1<em>$2</em>');

    // Citation chips: "[Slide 14]" optionally followed by "(Deck title)".
    s = s.replace(
      /\[Slide\s+(\d+)\]\s*(?:[（(]([^()（）<>]{1,80})[)）])?/gi,
      (m, n, deck) => `<span class="cite">Slide ${n}${deck ? ' · ' + deck.trim() : ''}</span>`
    );

    // Auto-italicise case names "X v Y" (outside existing tags).
    s = s.replace(
      /\b(?!<em>)([A-Z][\w\-.']+(?:\s+[A-Z][\w\-.']+)*\s+v\s+[A-Z][\w\-.']+(?:\s+[A-Z][\w\-.']+)*)\b(?!<\/em>)/g,
      '<em>$1</em>'
    );
    return s;
  }

  // Minimal, safe markdown: text is HTML-escaped before any tags are added,
  // and only a fixed set of tags is ever emitted.
  function renderAnswerHTML(raw) {
    if (!raw) return '<p><em>Sorry—no content.</em></p>';
    const lines = escapeHTML(raw).split(/\r?\n/);
    const out = [];
    let list = null; // 'ul' | 'ol'
    let para = [];

    const flushPara = () => {
      if (para.length) {
        out.push(`<p>${renderInline(para.join(' '))}</p>`);
        para = [];
      }
    };
    const flushList = () => {
      if (list) { out.push(`</${list}>`); list = null; }
    };

    for (const lineRaw of lines) {
      const line = lineRaw.trim();
      if (line === '') { flushPara(); flushList(); continue; }

      let m;
      if ((m = line.match(/^(#{2,4})\s+(.*)$/))) {
        flushPara(); flushList();
        const tag = m[1].length <= 3 ? 'h3' : 'h4';
        out.push(`<${tag}>${renderInline(m[2])}</${tag}>`);
      } else if ((m = line.match(/^(?:[-*•]|–)\s+(.*)$/))) {
        flushPara();
        if (list !== 'ul') { flushList(); out.push('<ul>'); list = 'ul'; }
        out.push(`<li>${renderInline(m[1])}</li>`);
      } else if ((m = line.match(/^\d{1,2}[.)]\s+(.*)$/))) {
        flushPara();
        if (list !== 'ol') { flushList(); out.push('<ol>'); list = 'ol'; }
        out.push(`<li>${renderInline(m[1])}</li>`);
      } else if (/^-{3,}$/.test(line)) {
        flushPara(); flushList();
      } else {
        flushList();
        para.push(line);
      }
    }
    flushPara(); flushList();
    return out.join('') || '<p><em>Sorry—no content.</em></p>';
  }

  // ---------- Chat DOM helpers ----------
  const chatMain = document.querySelector('.chat-main');
  function scrollChatToBottom() {
    if (chatMain) chatMain.scrollTop = chatMain.scrollHeight;
  }

  function addUserBubble(text) {
    const row = document.createElement('div');
    row.className = 'msg user';
    const bubble = document.createElement('div');
    bubble.className = 'bubble';
    bubble.textContent = text;
    row.appendChild(bubble);
    chatLog.appendChild(row);
    scrollChatToBottom();
  }

  function makeAssistantRow() {
    const row = document.createElement('div');
    row.className = 'msg assistant';
    const meta = document.createElement('div');
    meta.className = 'bot-meta';
    meta.innerHTML = '<span class="badge">StudyBot</span><span>from your course materials</span>';
    const bubble = document.createElement('div');
    bubble.className = 'bubble';
    row.appendChild(meta);
    row.appendChild(bubble);
    chatLog.appendChild(row);
    return { row, bubble };
  }

  function addAssistantActions(row, answerText) {
    const actions = document.createElement('div');
    actions.className = 'bot-actions';

    const copy = document.createElement('button');
    copy.type = 'button';
    copy.className = 'mini';
    copy.textContent = 'Copy';
    copy.addEventListener('click', () => {
      navigator.clipboard.writeText(answerText).then(() => toast('Copied'), () => {});
    });

    const quiz = document.createElement('button');
    quiz.type = 'button';
    quiz.className = 'mini';
    quiz.textContent = 'Quiz me on this';
    quiz.addEventListener('click', () => sendQuiz());

    actions.appendChild(copy);
    actions.appendChild(quiz);
    row.appendChild(actions);
  }

  function addThinking() {
    const row = document.createElement('div');
    row.className = 'msg assistant';
    row.dataset.thinking = '1';
    const bubble = document.createElement('div');
    bubble.className = 'bubble';
    bubble.innerHTML = '<span class="thinking-label">Thinking…</span>';
    row.appendChild(bubble);
    chatLog.appendChild(row);
    scrollChatToBottom();
    return row;
  }

  function addErrorBubble(message) {
    const { row, bubble } = makeAssistantRow();
    bubble.innerHTML = `<p><em>${escapeHTML(message)}</em></p>`;
    scrollChatToBottom();
    return row;
  }

  let toastTimer = null;
  function toast(message) {
    let el = $('#lsbToast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'lsbToast';
      el.className = 'toast';
      el.setAttribute('role', 'status');
      document.body.appendChild(el);
    }
    el.textContent = message;
    el.style.display = '';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.style.display = 'none'; }, 2200);
  }

  // ---------- RAG ----------
  const MAX_RAG_CHARS = 120000;

  async function fetchRAG(question, topk = 2) {
    try {
      const url = `${RAG_URL}?q=${encodeURIComponent(question)}&topk=${topk}`;
      const r = await fetch(url, { cache: 'no-store' });
      if (!r.ok) return '';
      const j = await r.json();
      if (!Array.isArray(j)) return '';

      const sorted = j
        .filter((x) => x.text && typeof x.score === 'number')
        .sort((a, b) => b.score - a.score);

      const snippets = [];
      let totalChars = 0;
      for (const item of sorted) {
        const text = item.text.trim();
        if (totalChars + text.length > MAX_RAG_CHARS) break;
        snippets.push(text);
        totalChars += text.length;
      }
      return snippets.join('\n\n---\n\n');
    } catch {
      return '';
    }
  }

  // ---------- Logging (fire-and-forget) ----------
  function logEvent(kind, content) {
    try {
      fetch('log_client.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          kind,
          text: content,
          lang: currentLang(),
          thread: 'student',
          ts: Date.now(),
        }),
      }).catch(() => {});
    } catch {}
  }

  // ---------- Chat state + streaming ----------
  const messages = [];
  let busy = false;

  function setBusy(state) {
    busy = state;
    if (input) input.disabled = state;
    if (sendBtn) sendBtn.disabled = state;
    if (quizBtn) quizBtn.disabled = state;
  }

  async function sendMessage(text, { ragQuery } = {}) {
    if (!input || !chatLog || busy) return;
    text = (text || '').trim();
    if (!text) return;
    if (consentGate && !consentGate.classList.contains('hide') && !consentStored()) return;

    if (heroCard) heroCard.classList.add('hide');

    addUserBubble(text);
    messages.push({ role: 'user', content: text });

    const thinkRow = addThinking();
    input.value = '';
    autosize();
    setBusy(true);

    const ragContext = await fetchRAG(ragQuery || text, 2);

    const body = {
      messages,
      rag_context: ragContext,
      lang: currentLang(),
      thread: 'student',
      stream: true,
    };

    try {
      const resp = await fetch(WORKER_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });

      if (!resp.ok || !resp.body) {
        thinkRow.remove();
        addErrorBubble('Sorry—no response from the server. Please try again in a moment.');
        return;
      }

      const reader = resp.body.getReader();
      const dec = new TextDecoder();
      let buffer = '';
      let acc = '';
      let assistantRow = null;
      let assistantBubble = null;

      let needsUpdate = false;
      let streaming = true;

      const pumpRender = () => {
        if (needsUpdate && assistantBubble) {
          assistantBubble.innerHTML = renderAnswerHTML(acc);
          needsUpdate = false;
          scrollChatToBottom();
        }
        if (streaming) requestAnimationFrame(pumpRender);
      };
      requestAnimationFrame(pumpRender);

      while (true) {
        const { value, done } = await reader.read();
        if (done) break;
        buffer += dec.decode(value, { stream: true });
        const lines = buffer.split(/\r?\n/);
        buffer = lines.pop() || '';

        for (const line of lines) {
          if (!line.startsWith('data:')) continue;
          const payload = line.slice(5).trim();
          if (!payload || payload === '[DONE]') continue;

          let j;
          try { j = JSON.parse(payload); } catch { continue; }
          const delta = j?.choices?.[0]?.delta?.content;
          if (typeof delta !== 'string') continue;

          if (!assistantRow) {
            thinkRow.remove();
            const created = makeAssistantRow();
            assistantRow = created.row;
            assistantBubble = created.bubble;
          }

          acc += delta;
          needsUpdate = true;
        }
      }

      streaming = false;

      if (assistantBubble) {
        assistantBubble.innerHTML = renderAnswerHTML(acc || 'Sorry—no content.');
        addAssistantActions(assistantRow, acc);
        scrollChatToBottom();
      } else {
        thinkRow.remove();
        addErrorBubble('Sorry—empty response. Please try again.');
      }

      messages.push({ role: 'assistant', content: acc });

      logEvent('q', text);
      if (acc) logEvent('a', acc);
    } catch (e) {
      thinkRow.remove();
      addErrorBubble('Network error contacting the assistant. Please check your connection and retry.');
    } finally {
      setBusy(false);
      input.focus();
    }
  }

  function sendQuiz() {
    const zh = currentLang() === 'zh';
    const prompt = zh
      ? '请根据课程幻灯片内容出一个简短自测：2道单选题+1道简答题。每题标注来源 [Slide N]（附课件标题）。文末以 "--- 答案 ---" 给出答案与一句话解释。'
      : 'Create a short self-check quiz from the course slides: 2 multiple-choice questions and 1 short-answer question. Cite [Slide N] (with deck title) for each. After "--- Answers ---", give the answers with one-sentence explanations.';
    // Retrieve context for the current topic if there is one, else a broad query.
    const lastUser = [...messages].reverse().find((m) => m.role === 'user');
    sendMessage(prompt, { ragQuery: lastUser ? lastUser.content : 'key concepts overview' });
  }

  // ---------- Transcript helpers ----------
  function transcriptText() {
    return Array.from(chatLog.querySelectorAll('.msg')).map((row) => {
      const who = row.classList.contains('user') ? 'Student:' : 'StudyBot:';
      const bubble = row.querySelector('.bubble');
      return who + ' ' + (bubble ? bubble.textContent.trim() : '');
    }).join('\n\n');
  }

  // ---------- UI wiring ----------
  function autosize() {
    if (!input) return;
    input.style.height = 'auto';
    const h = input.scrollHeight;
    // scrollHeight is 0 while the app is display:none — leave CSS in charge.
    input.style.height = h ? Math.min(h, 160) + 'px' : '';
  }

  function closeMenu() {
    if (menuPanel) menuPanel.classList.add('hide');
    if (menuBtn) menuBtn.setAttribute('aria-expanded', 'false');
  }

  function wireUI() {
    if (chatForm) {
      chatForm.addEventListener('submit', (e) => {
        e.preventDefault();
        sendMessage(input.value);
      });
    }
    if (input) {
      input.addEventListener('input', autosize);
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          sendMessage(input.value);
        }
      });
    }
    if (quizBtn) quizBtn.addEventListener('click', sendQuiz);

    document.querySelectorAll('.suggest-chip').forEach((chip) => {
      chip.addEventListener('click', () => sendMessage(chip.dataset.prompt || chip.textContent));
    });

    if (newChatBtn) {
      newChatBtn.addEventListener('click', () => {
        if (busy) return;
        messages.length = 0;
        chatLog.innerHTML = '';
        if (heroCard) heroCard.classList.remove('hide');
        input.value = '';
        autosize();
        input.focus();
      });
    }

    // Overflow menu
    if (menuBtn && menuPanel) {
      menuBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const open = menuPanel.classList.toggle('hide') === false;
        menuBtn.setAttribute('aria-expanded', String(open));
      });
      document.addEventListener('click', (e) => {
        if (!menuPanel.contains(e.target) && e.target !== menuBtn) closeMenu();
      });
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeMenu();
      });
    }

    if (copyBtn) {
      copyBtn.addEventListener('click', () => {
        closeMenu();
        const text = transcriptText();
        if (!text) { toast('Nothing to copy yet'); return; }
        navigator.clipboard.writeText(text).then(() => toast('Transcript copied'), () => {});
      });
    }

    // Email transcript dialog
    if (emailBtn && emailModal) {
      emailBtn.addEventListener('click', () => {
        closeMenu();
        if (!transcriptText()) { toast('Nothing to send yet'); return; }
        emailStatus.textContent = '';
        emailStatus.className = 'status';
        emailModal.classList.remove('hide');
        emailInput.focus();
      });
      emailCancel.addEventListener('click', () => emailModal.classList.add('hide'));
      emailModal.addEventListener('click', (e) => {
        if (e.target === emailModal) emailModal.classList.add('hide');
      });
      emailSend.addEventListener('click', async () => {
        const email = emailInput.value.trim();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
          emailStatus.textContent = 'Please enter a valid email address.';
          emailStatus.className = 'status err';
          return;
        }
        emailSend.disabled = true;
        emailStatus.textContent = 'Sending…';
        emailStatus.className = 'status';
        try {
          const form = new URLSearchParams();
          form.set('email', email);
          form.set('transcript', transcriptText());
          const r = await fetch('email_transcript.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: form.toString(),
          });
          const txt = await r.text();
          if (r.ok && /success/i.test(txt)) {
            emailStatus.textContent = 'Sent! Check your inbox.';
            emailStatus.className = 'status ok';
            setTimeout(() => emailModal.classList.add('hide'), 1400);
          } else {
            emailStatus.textContent = 'Could not send the email. Please try again later.';
            emailStatus.className = 'status err';
          }
        } catch {
          emailStatus.textContent = 'Network error. Please try again.';
          emailStatus.className = 'status err';
        } finally {
          emailSend.disabled = false;
        }
      });
    }
  }

  // ---------- Mobile keyboard handling ----------
  // On iOS the on-screen keyboard doesn't shrink the layout viewport; it
  // scrolls the page instead, pushing the header off-screen and leaving the
  // composer hidden. Sizing #app to the visual viewport keeps the frame
  // intact with the composer resting on the keyboard.
  function wireViewport() {
    const vv = window.visualViewport;
    if (!vv || !app) return;
    let raf = 0;
    const sync = () => {
      cancelAnimationFrame(raf);
      raf = requestAnimationFrame(() => {
        const full = window.innerHeight;
        const visible = Math.round(vv.height);
        // Keyboard open → pin to visible area; closed → back to CSS 100dvh.
        app.style.height = visible < full - 1 ? visible + 'px' : '';
        window.scrollTo(0, 0);
        scrollChatToBottom();
      });
    };
    vv.addEventListener('resize', sync);
    vv.addEventListener('scroll', sync);
  }

  function init() {
    wireConsent();
    wireUI();
    wireViewport();
    autosize();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
