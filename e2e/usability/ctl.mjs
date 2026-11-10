// The moderator's side of a participant's driver (M2-10): node usability/ctl.mjs <driver port> <action> [JSON]
const [port, name, args] = process.argv.slice(2);
const response = await fetch(`http://127.0.0.1:${port}/moderator`, { method: 'POST', body: JSON.stringify({ name, args: args ? JSON.parse(args) : {} }) });
process.stdout.write(`${await response.text()}\n`);
process.exit(response.ok ? 0 : 1);
