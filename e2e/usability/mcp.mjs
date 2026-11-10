// A usability participant's only tools (M2-10): the driver's actions for their device, and their notes.
// An MCP server on stdio, which the participant's Claude session is given and nothing else.
//
//   node usability/mcp.mjs <participant> <driver port>
import { readFileSync } from 'node:fs';
import { createInterface } from 'node:readline';

const [participant, driverPort] = process.argv.slice(2);
const protocol = JSON.parse(readFileSync(new URL('./protocol.json', import.meta.url), 'utf8'));
const kind = protocol.personas[participant].device;
const tool = (name, description, properties = {}) => ({ name, description, inputSchema: { type: 'object', properties, required: Object.keys(properties), additionalProperties: false } });
const text = (description) => ({ type: 'string', description });

const pointer = kind === 'phone' ? 'Tap' : 'Click';
const tools = [
  tool('look', kind === 'keyboard'
    ? 'Look at the screen: the page\'s title, its address, its content as your screen-reading habits give it, and what has the focus. Or the device\'s prompt, if one is showing.'
    : 'Look at the screen: a picture of it, with the page\'s title, address and text. Or the device\'s prompt, if one is showing.'),
  ...(kind === 'keyboard' ? [
    tool('tab', 'Press Tab: the focus moves to the next thing.'),
    tool('shift_tab', 'Press Shift+Tab: the focus moves back.'),
    tool('enter', 'Press Enter on what has the focus.'),
    tool('space', 'Press Space on what has the focus, e.g. to tick a checkbox.'),
    tool('type', 'Type text into what has the focus.', { text: text('What to type') }),
  ] : [
    tool('click', `${pointer} a button, a link or a checkbox, by the words on it.`, { name: text('The words on it') }),
    tool('fill', 'Type into a field, by its label.', { label: text('The field\'s label'), text: text('What to type') }),
    tool('check', 'Tick a checkbox, by its label.', { label: text('The checkbox\'s label') }),
  ]),
  tool('go', 'Type an address into the browser\'s address bar and go there.', { address: text('The address') }),
  tool('back', 'Press the browser\'s Back button.'),
  tool('device', 'Answer the prompt your device is showing.', { answer: { type: 'string', enum: ['confirm', 'cancel'], description: 'confirm: Continue, with your fingerprint, face or screen lock; cancel: Cancel' } }),
  tool('notes_write', 'Write something down in your notes.', { text: text('What to write') }),
  tool('notes_read', 'Read your notes.'),
];

const send = (message) => process.stdout.write(`${JSON.stringify({ jsonrpc: '2.0', ...message })}\n`);

for await (const line of createInterface({ input: process.stdin })) {
  if (line.trim() === '') continue;
  const { id, method, params } = JSON.parse(line);

  if (method === 'initialize') {
    send({ id, result: { protocolVersion: params?.protocolVersion ?? '2025-06-18', capabilities: { tools: {} }, serverInfo: { name: 'acme-browser', version: '1.0.0' } } });
  } else if (method === 'tools/list') {
    send({ id, result: { tools } });
  } else if (method === 'tools/call') {
    const response = await fetch(`http://127.0.0.1:${driverPort}/act`, { method: 'POST', body: JSON.stringify({ name: params.name, args: params.arguments ?? {} }) })
      .then((r) => r.json(), (error) => ({ text: `The browser isn't responding: ${error.message}` }));
    const content = [{ type: 'text', text: response.text }];
    if (response.image) content.push({ type: 'image', data: response.image, mimeType: 'image/png' });
    send({ id, result: { content } });
  } else if (id !== undefined) {
    send({ id, error: { code: -32601, message: `No method ${method}` } });
  }
}
