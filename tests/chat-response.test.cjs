const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readAssistantResponse, ResponseError } = require('../public_html/assets/chat-response.js');

function sse(text, chunkSize = 7) {
  const bytes = new TextEncoder().encode(text);
  return new Response(new ReadableStream({
    start(controller) {
      for (let i = 0; i < bytes.length; i += chunkSize) controller.enqueue(bytes.slice(i, i + chunkSize));
      controller.close();
    },
  }), { headers: { 'content-type': 'text/event-stream; charset=utf-8' } });
}
const delta = (text) => `data: ${JSON.stringify({ choices: [{ delta: { content: text } }] })}\n\n`;

test('streams text across split UTF-8 and CRLF boundaries and reads final unterminated DONE', async () => {
  const updates = [];
  const text = (': open\n\n' + delta('Basic ') + delta('基本法') + 'data: [DONE]').replaceAll('\n', '\r\n');
  assert.equal(await readAssistantResponse(sse(text, 1), (value) => updates.push(value)), 'Basic 基本法');
  assert.deepEqual(updates, ['Basic ', 'Basic 基本法']);
});

test('surfaces streamed provider errors without leaking raw messages', async () => {
  await assert.rejects(readAssistantResponse(sse('data: {"error":{"code":"insufficient_balance","message":"private details"}}\n\n'), () => {}),
    (error) => error instanceof ResponseError && error.message.includes('insufficient_balance') && !error.message.includes('private details'));
});

test('reproduced quiz response with only keep-alives and DONE is a failure', async () => {
  await assert.rejects(readAssistantResponse(sse(': open\n\n: tick\n\ndata: [DONE]\n\n'), () => {}), /finished without an answer/);
});

test('does not treat reasoning-only tokens as a student answer', async () => {
  await assert.rejects(readAssistantResponse(sse('data: {"choices":[{"delta":{"reasoning_content":"internal reasoning","content":null}}]}\n\ndata: [DONE]\n\n'), () => {}), /finished without an answer/);
});

test('accepts a complete JSON answer when the service does not stream', async () => {
  assert.equal(await readAssistantResponse(Response.json({ choices: [{ message: { content: 'Answer' } }] }), () => {}), 'Answer');
});

test('reports HTTP, JSON error, and unexpected HTML responses', async () => {
  await assert.rejects(readAssistantResponse(new Response('', { status: 502 }), () => {}), /HTTP 502/);
  await assert.rejects(readAssistantResponse(Response.json({ error: 'service failed' }), () => {}), /could not complete/);
  await assert.rejects(readAssistantResponse(new Response('<html>Error</html>', { headers: { 'content-type': 'text/html' } }), () => {}), /unexpected response format/);
});

test('rejects malformed events and incomplete or length-limited answers', async () => {
  await assert.rejects(readAssistantResponse(sse('data: {broken}\n\n'), () => {}), /unreadable/);
  await assert.rejects(readAssistantResponse(sse(delta('Partial')), () => {}), /interrupted/);
  await assert.rejects(readAssistantResponse(sse(delta('Partial') + 'data: {"choices":[{"finish_reason":"length"}]}\n\ndata: [DONE]\n\n'), () => {}), /response limit/);
});

test('cleans up a reader after a read failure', async () => {
  const response = new Response(new ReadableStream({ start(controller) { controller.error(new Error('connection lost')); } }),
    { headers: { 'content-type': 'text/event-stream' } });
  await assert.rejects(readAssistantResponse(response, () => {}), /connection lost/);
  assert.equal(response.body.locked, false);
});
