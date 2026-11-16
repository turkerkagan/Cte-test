import { readFile } from 'node:fs/promises';
import { gzipSync } from 'node:zlib';
import { resolve } from 'node:path';

const directory = resolve(process.argv[2] ?? 'public/build');
const manifest = JSON.parse(await readFile(resolve(directory, 'manifest.json'), 'utf8'));
const entry = manifest['resources/js/app.tsx'];
if (!entry?.file) throw new Error('Application entry is missing from the build manifest.');
const bytes = gzipSync(await readFile(resolve(directory, entry.file))).length;
if (bytes > 160_000) throw new Error(`Application bundle exceeds 160 KB gzip: ${bytes} bytes.`);
console.log(`Application bundle: ${bytes} / 160000 bytes gzip.`);
