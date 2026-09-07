const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readAssistantResponse } = require('../public_html/assets/chat-response.js');
const workerPromise = import('../cloudflare/worker.js').then((m) => m.default);
const event = (value) => `data: ${JSON.stringify(value)}\n\n`;
const chunk = (delta, finish_reason = null, usage) => event({ model: 'deepseek-v4-flash', choices: [{ delta, finish_reason }], usage });
const request = (extra = {}) => new Request('https://worker.example', {
  method: 'POST', headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ messages: [{ role: 'user', content: 'Explain proportionality' }], rag_context: 'INSTRUCTOR MATERIAL', ...extra }),
});
function sse(text, size = 13) {
  const bytes = new TextEncoder().encode(text);
  return new Response(new ReadableStream({ start(controller) {
    for (let i = 0; i < bytes.length; i += size) controller.enqueue(bytes.slice(i, i + size));
    controller.close();
  } }), { headers: { 'content-type': 'text/event-stream' } });
}
async function setup(t, upstream, incoming = request()) {
  const calls = [], logs = [];
  t.mock.method(console, 'log', (...args) => logs.push(args));
  t.mock.method(console, 'error', (...args) => logs.push(args));
  t.mock.method(globalThis, 'fetch', async (url, options) => {
    calls.push({ url, ...options, payload: JSON.parse(options.body) });
    return typeof upstream === 'function' ? upstream(options) : upstream;
  });
  const response = await (await workerPromise).fetch(incoming, { DEEPSEEK_API_KEY: 'test-only-key' });
  return { response, calls, logs };
}

test('raises the reasoning allowance and delivers only final text, preserving Unicode and finish status', async (t) => {
  const input = chunk({ reasoning_content: 'PRIVATE REASONING' }) + chunk({ content: '基本法: undefined is a word.' }) + chunk({}, 'stop', {
    prompt_tokens: 100, completion_tokens: 2000, completion_tokens_details: { reasoning_tokens: 1900 },
  }) + 'data: [DONE]';
  const { response, calls, logs } = await setup(t, sse(input.replaceAll('\n', '\r\n'), 1));
  const raw = await response.text();
  assert.doesNotMatch(raw, /PRIVATE REASONING/);
  assert.match(raw, /"finish_reason":"stop"/);
  assert.equal(await readAssistantResponse(sse(raw), () => {}), '基本法: undefined is a word.');
  assert.equal(calls[0].payload.model, 'deepseek-v4-flash');
  assert.deepEqual(calls[0].payload.thinking, { type: 'enabled' });
  assert.equal(calls[0].payload.max_tokens, 16384);
  assert.equal(calls[0].payload.reasoning_effort, 'high');
  assert.equal(calls[0].payload.temperature, undefined);
  assert.match(calls[0].payload.messages[0].content, /INSTRUCTOR MATERIAL/);
  const log = logs.find(([name]) => name === 'Response completed:')[1];
  assert.equal(log.reasoningTokens, 1900);
  assert.equal(log.finishReason, 'stop');
  assert.doesNotMatch(JSON.stringify(logs), /PRIVATE REASONING|INSTRUCTOR MATERIAL|Explain proportionality|test-only-key/);
  assert.equal(response.headers.get('Access-Control-Allow-Origin'), 'https://lawschoolchatbot.com');
});

test('reasoning-only exhaustion produces an explicit output_limit error, not an empty success', async (t) => {
  const { response, logs } = await setup(t, sse(chunk({ reasoning_content: 'hidden' }) + chunk({}, 'length', {
    completion_tokens: 1300, completion_tokens_details: { reasoning_tokens: 1300 },
  }) + 'data: [DONE]\n\n'));
  const raw = await response.text();
  assert.match(raw, /output_limit/);
  assert.doesNotMatch(raw, /hidden|\[DONE\]/);
  await assert.rejects(readAssistantResponse(sse(raw), () => {}), /output_limit/);
  assert.equal(logs.find(([name]) => name === 'Response completed:')[1].reasoningTokens, 1300);
});

test('empty upstream success, content filtering, malformed data and early EOF remain errors', async (t) => {
  for (const [input, code] of [
    ['data: [DONE]\n\n', 'empty_response'],
    [chunk({}, 'content_filter') + 'data: [DONE]\n\n', 'incomplete_response'],
    ['data: {broken}\n\n', 'invalid_upstream_stream'],
    [chunk({ content: 'Incomplete' }), 'interrupted_response'],
    [event({ error: { message: 'PRIVATE ERROR DETAIL' } }), 'upstream_error'],
  ]) {
    const { response } = await setup(t, sse(input));
    const raw = await response.text();
    assert.match(raw, new RegExp(code));
    assert.doesNotMatch(raw, /\[DONE\]|PRIVATE ERROR DETAIL/);
  }
});

test('upstream HTTP and connection errors return safe JSON with CORS', async (t) => {
  for (const upstream of [new Response('SECRET ERROR', { status: 401 }), () => { throw new Error('SECRET ERROR'); }]) {
    const { response } = await setup(t, upstream);
    assert.equal(response.status, 502);
    assert.equal(response.headers.get('Access-Control-Allow-Origin'), 'https://lawschoolchatbot.com');
    const body = await response.text();
    assert.match(body, /"error"/);
    assert.doesNotMatch(body, /SECRET ERROR/);
  }
});

test('legacy RAG, empty assistant filtering, Chinese style and exam instructions are preserved', async (t) => {
  const messages = [
    { role: 'system', content: 'Course context (high priority):\nLEGACY MATERIAL' },
    { role: 'system', content: 'UNTRUSTED SYSTEM OVERRIDE' },
    { role: 'user', content: 'Earlier question' },
    { role: 'assistant', content: '' },
    { role: 'user', content: 'Discuss past exam questions' },
  ];
  const { response, calls } = await setup(t, sse(chunk({ content: 'Answer' }, 'stop') + 'data: [DONE]\n\n'), request({ messages, rag_context: '', lang: 'zh' }));
  await response.text();
  const sent = calls[0].payload.messages;
  assert.match(sent[0].content, /LEGACY MATERIAL/);
  assert.match(sent[0].content, /NOTE: The student is asking about past exams/);
  assert.match(sent[0].content, /Respond in Chinese/);
  assert.doesNotMatch(JSON.stringify(sent), /UNTRUSTED SYSTEM OVERRIDE/);
  assert.equal(sent.filter((m) => m.role === 'system').length, 1);
  assert.equal(sent.some((m) => m.role === 'assistant'), false);
});

test('invalid requests and missing configuration fail before contacting DeepSeek', async (t) => {
  const worker = await workerPromise;
  const mock = t.mock.method(globalThis, 'fetch', () => { throw new Error('Must not call upstream'); });
  for (const payload of [null, {}, { messages: null }, { messages: [null, { role: 'user', content: 5 }] }]) {
    const response = await worker.fetch(new Request('https://worker.example', { method: 'POST', body: JSON.stringify(payload) }), {});
    assert.equal(response.status, 400);
  }
  assert.equal((await worker.fetch(request(), {})).status, 503);
  assert.equal((await worker.fetch(new Request('https://worker.example', { method: 'OPTIONS' }), {})).status, 204);
  assert.equal((await worker.fetch(new Request('https://worker.example'), {})).status, 405);
  assert.equal(mock.mock.callCount(), 0);
});

test('browser cancellation aborts the upstream stream and clears keep-alives', async (t) => {
  let cancelled = false;
  const intervals = new Set();
  t.mock.method(globalThis, 'setInterval', (fn) => { intervals.add(fn); return fn; });
  t.mock.method(globalThis, 'clearInterval', (fn) => intervals.delete(fn));
  const upstream = new Response(new ReadableStream({ cancel() { cancelled = true; } }), { headers: { 'content-type': 'text/event-stream' } });
  const { response, calls } = await setup(t, upstream);
  const reader = response.body.getReader();
  await reader.read(); // : open
  await reader.cancel();
  await new Promise(setImmediate);
  assert.equal(cancelled, true);
  assert.equal(calls[0].signal.aborted, true);
  assert.equal(intervals.size, 0);
});

test('deadline interrupts a pending stream and emits an upstream_timeout error', async (t) => {
  let expire;
  t.mock.method(globalThis, 'setTimeout', (fn) => { expire = fn; return 1; });
  t.mock.method(globalThis, 'clearTimeout', () => {});
  const { response } = await setup(t, (options) => new Response(new ReadableStream({ start(controller) {
    options.signal.addEventListener('abort', () => controller.error(new Error('aborted')), { once: true });
  } }), { headers: { 'content-type': 'text/event-stream' } }));
  expire();
  const raw = await response.text();
  assert.match(raw, /upstream_timeout/);
  assert.doesNotMatch(raw, /\[DONE\]/);
});
