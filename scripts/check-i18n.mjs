/**
 * Guard i18n: pastikan semua pesan katalog bisa dikompilasi vue-i18n.
 *
 * Karakter `@`, `|`, `{`, `}` bersifat khusus di format pesan vue-i18n.
 * Contoh jebakan: "nama@perusahaan.com" melempar "Invalid linked format"
 * saat render → seluruh halaman jadi kosong (blank putih).
 *
 * Jalankan: node scripts/check-i18n.mjs
 */
import { readFileSync, writeFileSync, readdirSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const dir = join(root, 'resources/js/i18n/catalogs');
const compiler = join(root, 'node_modules/@intlify/message-compiler/dist/message-compiler.mjs');

const { baseCompile } = await import(pathToFileURL(compiler).href);

const out = mkdtempSync(join(tmpdir(), 'i18n-'));
const problems = [];

for (const file of readdirSync(dir).filter((f) => f.endsWith('.ts'))) {
  const mjs = join(out, file.replace(/\.ts$/, '.mjs'));
  writeFileSync(mjs, readFileSync(join(dir, file), 'utf8'));
  const catalogs = (await import(pathToFileURL(mjs).href)).default;

  for (const [locale, messages] of Object.entries(catalogs)) {
    const walk = (obj, path) => {
      for (const [key, value] of Object.entries(obj)) {
        const full = `${path}.${key}`;
        if (value && typeof value === 'object') {
          walk(value, full);
        } else if (typeof value === 'string') {
          try {
            baseCompile(value, { onError: (e) => { throw e; } });
          } catch (e) {
            problems.push({ file, locale, key: full, message: value, error: `${e.constructor.name}: ${e.message}` });
          }
        }
      }
    };
    walk(messages, file.replace(/\.ts$/, ''));
  }
}

if (problems.length > 0) {
  console.error(`✗ i18n: ${problems.length} pesan tidak valid:`);
  for (const p of problems) {
    console.error(`  - [${p.locale}] ${p.key} (${p.file})`);
    console.error(`    pesan: ${JSON.stringify(p.message)}`);
    console.error(`    error: ${p.error}`);
  }
  process.exit(1);
}

console.log('✓ i18n: semua pesan katalog valid.');
