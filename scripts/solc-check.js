#!/usr/bin/env node
/**
 * Compile every .sol file under the given files/directories with solc-js and fail on any error.
 *
 *   npm install            (in scripts/, installs solc)
 *   node scripts/solc-check.js build/solidity
 *
 * Set SOLC_QUIET=1 to hide the per-file "ok" lines.
 */
const fs = require('fs');
const path = require('path');

let solc;
try {
  solc = require('solc');
} catch (e) {
  console.error("solc-js is not installed. Run `npm install` inside the scripts/ directory first.");
  process.exit(2);
}

function collect(target, out) {
  const stat = fs.statSync(target);
  if (stat.isDirectory()) {
    for (const entry of fs.readdirSync(target).sort()) collect(path.join(target, entry), out);
  } else if (target.endsWith('.sol')) {
    out.push(target);
  }
}

const files = [];
for (const arg of process.argv.slice(2)) collect(arg, files);

if (files.length === 0) {
  console.error('No .sol files found. Usage: node scripts/solc-check.js <file-or-directory>...');
  process.exit(2);
}

let failed = 0;
for (const file of files) {
  const input = {
    language: 'Solidity',
    sources: { [path.basename(file)]: { content: fs.readFileSync(file, 'utf8') } },
    settings: { outputSelection: { '*': { '*': ['abi'] } } },
  };
  const output = JSON.parse(solc.compile(JSON.stringify(input)));
  const errors = (output.errors || []).filter((e) => e.severity === 'error');

  if (errors.length > 0) {
    failed++;
    console.log(`FAIL ${file}`);
    for (const e of errors) console.log('   ' + e.formattedMessage.trim().split('\n').join('\n   '));
  } else if (!process.env.SOLC_QUIET) {
    console.log(`ok   ${file}`);
  }
}

console.log(`\nsolc ${solc.version()}: ${files.length - failed}/${files.length} file(s) compile`);
process.exit(failed > 0 ? 1 : 0);
