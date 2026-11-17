// npm audit, failing on every advisory but the ones excepted, each with its reason and a date to look
// again by: an exception past its date fails too. Run in the package's directory, with the exceptions'
// file: { "GHSA-…": { "until": "YYYY-MM-DD", "why": "…" } }.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const exceptions = JSON.parse(readFileSync(process.argv[2], 'utf8'));

for (const [id, exception] of Object.entries(exceptions)) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(exception.until ?? '') || Number.isNaN(Date.parse(exception.until))) {
        console.error(`::error::The exception for ${id} has no date YYYY-MM-DD to be looked at again by.`);
        process.exit(1);
    }
}

let output;
let exited = 0;

try {
    output = execFileSync('npm', ['audit', '--json'], { encoding: 'utf8' });
} catch (error) {
    // npm audit exits with 1 when it finds any, and when it can't audit.
    output = error.stdout ?? '';
    exited = error.status ?? 1;
}

let report;

try {
    report = JSON.parse(output);
} catch {
    report = {};
}

// Fail closed: without a report there's nothing to tell it by.
if (undefined !== report.error || 'object' !== typeof report.metadata?.vulnerabilities || 'object' !== typeof report.vulnerabilities) {
    console.error(`::error::npm audit gave no report (exit ${exited}): ${JSON.stringify(report.error ?? output.slice(0, 500))}`);
    process.exit(1);
}

const found = new Map();

for (const [name, vulnerability] of Object.entries(report.vulnerabilities)) {
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

if (0 !== exited && 0 === found.size) {
    console.error(`::error::npm audit exited with ${exited} but reported no advisory.`);
    failed = true;
}

process.exit(failed ? 1 : 0);
