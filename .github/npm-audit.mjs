// npm audit, failing on every advisory but the ones excepted, each with its reason and a date to look
// again by: an exception past its date fails too. Run in the package's directory, with the exceptions'
// file: { "GHSA-…": { "until": "YYYY-MM-DD", "why": "…" } }.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const exceptions = JSON.parse(readFileSync(process.argv[2], 'utf8'));
let report;

try {
    report = execFileSync('npm', ['audit', '--json'], { encoding: 'utf8' });
} catch (error) {
    // npm audit exits with 1 when it finds any.
    report = error.stdout;
}

const found = new Map();

for (const [name, vulnerability] of Object.entries(JSON.parse(report).vulnerabilities ?? {})) {
    for (const via of vulnerability.via) {
        if ('object' === typeof via) {
            found.set(via.url.split('/').pop(), `${name} ${via.range}: ${via.title} (${via.url})`);
        }
    }
}

const today = new Date().toISOString().slice(0, 10);
let failed = false;

for (const [id, advisory] of found) {
    const exception = exceptions[id];

    if (undefined === exception) {
        console.error(`::error::${advisory}`);
        failed = true;
    } else if (exception.until < today) {
        console.error(`::error::The exception for ${id} was to be looked at again by ${exception.until}: ${advisory}`);
        failed = true;
    } else {
        console.log(`Excepted until ${exception.until}: ${advisory}. ${exception.why}`);
    }
}

for (const id of Object.keys(exceptions)) {
    if (!found.has(id)) {
        console.log(`::warning::${id} is no longer reported: remove its exception.`);
    }
}

process.exit(failed ? 1 : 0);
