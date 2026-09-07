const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const ChatResponse = require('../public_html/assets/chat-response.js');

function element() {
  const classes = new Set();
  return {
    children: [], handlers: {}, style: {}, dataset: {}, value: '',
    classList: { add: (x) => classes.add(x), contains: (x) => classes.has(x) },
    addEventListener(name, fn) { this.handlers[name] = fn; },
    appendChild(child) { this.children.push(child); child.parent = this; },
    remove() { if (this.parent) this.parent.children = this.parent.children.filter((x) => x !== this); },
    focus() {},
  };
}

test('a failed chat turn is visible but excluded from the next request; completed turns persist', async () => {
  const nodes = Object.fromEntries(['#chatForm', '#prompt', '#chatLog', '#sendBtn', '#quizBtn'].map((name) => [name, element()]));
  const requests = [];
  const pending = [];
  const frames = new Map();
  let nextFrame = 0;
  const document = {
    body: { dataset: { workerUrl: 'https://worker.example' } },
    querySelector: (name) => nodes[name] || null,
    querySelectorAll: () => [], createElement: element, readyState: 'complete',
  };
  const context = {
    document, window: {}, ChatResponse,
    localStorage: { getItem: () => 'v1' },
    requestAnimationFrame: (fn) => { frames.set(++nextFrame, fn); return nextFrame; },
    cancelAnimationFrame: (id) => frames.delete(id),
    fetch: async (url, options) => {
      if (url.startsWith('/data_index.php')) return Response.json([]);
      if (url === 'log_client.php') return new Response('OK');
      requests.push(JSON.parse(options.body));
      return pending.shift();
    },
  };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../public_html/assets/app.js'), 'utf8'), context);
  const send = async (text, response) => {
    pending.push(response);
    nodes['#prompt'].value = text;
    nodes['#chatForm'].handlers.submit({ preventDefault() {} });
    for (let attempt = 0; nodes['#sendBtn'].disabled && attempt < 100; attempt++) {
      await new Promise(setImmediate);
    }
    assert.equal(nodes['#sendBtn'].disabled, false);
    assert.equal(frames.size, 0);
  };
  await send('Failed quiz', new Response(': tick\n\ndata: [DONE]\n\n', { headers: { 'content-type': 'text/event-stream' } }));
  assert.match(nodes['#chatLog'].children.at(-1).children.at(-1).innerHTML, /finished without an answer/);
  await send('New question', Response.json({ choices: [{ message: { content: 'Completed answer' } }] }));
  assert.deepEqual(requests[1].messages, [{ role: 'user', content: 'New question' }]);
  await send('Follow-up', Response.json({ choices: [{ message: { content: 'Follow-up answer' } }] }));
  assert.deepEqual(requests[2].messages, [
    { role: 'user', content: 'New question' },
    { role: 'assistant', content: 'Completed answer' },
    { role: 'user', content: 'Follow-up' },
  ]);
});
