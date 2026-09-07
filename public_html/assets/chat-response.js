/* Read the Worker's answer without silently discarding service errors. */
((root) => {
  class ResponseError extends Error {}

  async function readAssistantResponse(response, onText) {
    let answer = '';
    let finishReason = null;
    const accept = (payload) => {
      if (payload?.error) {
        const code = payload.error.code || payload.error.type;
        // Do not expose raw provider messages, which may contain request details.
        const reference = typeof code === 'string' && /^[a-zA-Z0-9_-]{1,64}$/.test(code)
          ? ` (${code})` : '';
        throw new ResponseError(`The assistant service could not complete the request${reference}. Please try again shortly.`);
      }
      const choice = payload?.choices?.[0];
      if (choice?.finish_reason) finishReason = choice.finish_reason;
      const text = choice?.delta?.content ?? choice?.message?.content;
      if (typeof text === 'string' && text) {
        answer += text;
        onText(answer);
      }
    };

    if (!response.ok) {
      throw new ResponseError(`The assistant service returned HTTP ${response.status}. Please try again shortly.`);
    }
    const type = (response.headers.get('content-type') || '').toLowerCase();
    if (type.includes('application/json')) {
      let payload;
      try { payload = await response.json(); }
      catch { throw new ResponseError('The assistant service returned an unreadable response. Please try again.'); }
      accept(payload);
    } else {
      if (!type.includes('text/event-stream') || !response.body) {
        throw new ResponseError('The assistant service returned an unexpected response format. Please try again.');
      }
      const reader = response.body.getReader();
      const decoder = new TextDecoder();
      let buffer = '';
      let eventLines = [];
      let ended = false;
      const dispatch = () => {
        if (!eventLines.length) return;
        const data = eventLines.join('\n').trim();
        eventLines = [];
        if (data === '[DONE]') { ended = true; return; }
        if (!data) return;
        let payload;
        try { payload = JSON.parse(data); }
        catch { throw new ResponseError('The assistant service returned an unreadable response. Please try again.'); }
        accept(payload);
      };
      const line = (value) => {
        if (!value) dispatch();
        else if (value.startsWith('data:')) eventLines.push(value.slice(5).replace(/^ /, ''));
      };
      try {
        while (!ended) {
          const { value, done } = await reader.read();
          buffer += done ? decoder.decode() : decoder.decode(value, { stream: true });
          // Keep a trailing CR until the next chunk so split CRLF is one newline.
          const lines = buffer.split(/\r\n|\n|\r(?!$)/);
          buffer = lines.pop() || '';
          for (const value of lines) {
            line(value);
            if (ended) break;
          }
          if (done && !ended) {
            if (buffer) line(buffer.replace(/\r$/, ''));
            dispatch();
            break;
          }
        }
      } finally {
        try { await reader.cancel(); } catch {}
        reader.releaseLock();
      }
      if (!ended && !finishReason && answer.trim()) {
        throw new ResponseError('The response was interrupted before it finished. Please try again.');
      }
    }
    if (finishReason === 'length') {
      throw new ResponseError('The assistant reached its response limit before completing the answer. Please try a shorter question.');
    }
    if (!answer.trim()) {
      throw new ResponseError('The assistant service finished without an answer. Please try again shortly.');
    }
    return answer;
  }

  const api = { readAssistantResponse, ResponseError };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.ChatResponse = api;
})(globalThis);
