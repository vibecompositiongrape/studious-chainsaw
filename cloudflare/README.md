# LegalStudyBot Cloudflare Worker

`worker.js` is a complete replacement for the instructor-supplied Worker source.
It is an ES module with a default `fetch` handler and requires the existing
`DEEPSEEK_API_KEY` secret. It has no imported dependencies. Keep it outside
`public_html`; the teaching prompt belongs on the server.

## What caused the investigation

The supplied code requests `deepseek-reasoner` with `max_tokens: 1300`, forwards
only `delta.content`, discards `finish_reason` and usage, and unconditionally emits
`[DONE]`. A reasoning-only completion that exhausts its budget therefore appears
to the student as an empty successful stream. This mechanism matches the live
failures reproduced on 7 September 2026. The original bridge discarded the evidence
needed to establish the exact upstream finish reason for those historical requests.

The supplied Worker also hard-codes upstream `stream: true`; changing the client's
stream flag never changed this. The single successful quiz with `stream: false`
was not evidence of a non-streaming workaround.

DeepSeek's [thinking-mode documentation](https://api-docs.deepseek.com/guides/thinking_mode/)
describes separate reasoning and answer content and notes that temperature has no
effect in thinking mode. Its [API reference](https://api-docs.deepseek.com/api/create-chat-completion/)
documents the generation cap, `finish_reason`, explicit thinking controls and the
current model identifiers. The [change log](https://api-docs.deepseek.com/updates/)
records the migration from the legacy reasoner alias to V4 Flash thinking mode.

## Changes

- Use `deepseek-v4-flash` explicitly with thinking enabled and high reasoning effort.
- Raise the reasoning-plus-answer allowance to 16,384 tokens. The original short
  answer instructions remain intact. This is a ceiling, not a target; requests
  that use more reasoning can cost more and take longer than under the old cap.
- Retain the instructor's teaching prompt, source priorities, English exam filter,
  Chinese response instruction and both RAG payload formats.
- Preserve completion status and log token counts and content lengths, without
  recording course excerpts, questions, answers, API keys or reasoning text.
- Send explicit errors for exhausted budgets, empty output, interrupted streams,
  unexpected upstream output and service failures. Do not signal successful
  completion after an error.
- Handle split Unicode/CRLF chunks and trailing events, cancel upstream work on
  disconnection, and terminate requests after two minutes.

## Deployment

The existing GitHub workflow deploys the VPS only. Committing or merging this
directory does **not** deploy the Cloudflare Worker.

1. Replace the code of the existing `holy-shape-0165` Worker with the complete
   contents of `worker.js` and deploy it in Cloudflare. Retain its existing
   `DEEPSEEK_API_KEY` secret and its current hostname; no new secret is required.
2. Deploy the frontend response-handling changes in this same PR through the
   existing VPS deployment workflow. They are needed to display the Worker's
   structured errors. The revised Worker continues to use the answer format
   understood by the previous frontend for successful responses.
3. Start a new chat and test the self-check quiz, `teach me about proportionality`,
   and a follow-up question. In Worker logs, check `finishReason`, `answerChars`,
   `completionTokens`, `reasoningTokens` (when supplied), and `errorCode`.
   `output_limit` with zero answer characters indicates the reasoning cap still
   needs adjustment; it will now be visible rather than hidden.

Before deployment, save the current Worker version so it can be restored if needed.
No Cloudflare deployment or paid DeepSeek call was made while testing this replacement.

## Validation

From the repository root, run `node --test tests/*.test.cjs` with Node 22 or later.
The tests use mocked upstream streams, including a reasoning-only response with
`finish_reason: length` and an answer after more than 1,300 generated tokens.
They verify request settings, error propagation, frontend compatibility, RAG,
stream framing, cancellation and timeout handling. Live answer quality and the
new generation allowance still require validation after Worker deployment.
