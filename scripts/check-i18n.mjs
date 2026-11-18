/**
 * Guard i18n: dua pemeriksaan.
 *
 * 1. Format pesan — karakter `@`, `|`, `{`, `}` bersifat khusus di format pesan
 *    vue-i18n. Contoh jebakan: "nama@perusahaan.com" melempar
 *    "Invalid linked format" saat render → seluruh halaman jadi kosong.
 * 2. Key terpakai — setiap `t('namespace.key')` literal di `resources/js`
 *    harus ada di katalog (id/en/ko). Vue-i18n merender path mentah bila key
 *    tidak ada, jadi key hilang = label rusak tanpa error.
 *
 * Key dinamis (`t(`...${x}`)` atau `t('prefix_' + x)`) tidak bisa diverifikasi
 * per nilai; yang diperiksa hanya prefiks statisnya: katalog harus punya
 * setidaknya satu key dengan prefiks itu. Jumlah key dinamis dilaporkan agar
 * manusia tahu bagian mana yang tidak tercakup.
 *
 * Jalankan: node scripts/check-i18n.mjs
 */
import { readFileSync, writeFileSync, readdirSync, mkdtempSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname, relative } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const dir = join(root, 'resources/js/i18n/catalogs');
const compiler = join(root, 'node_modules/@intlify/message-compiler/dist/message-compiler.mjs');

const { baseCompile } = await import(pathToFileURL(compiler).href);

const out = mkdtempSync(join(tmpdir(), 'i18n-'));
const problems = [];
const catalogs = {};
const LOCALES = ['id', 'en', 'ko'];

for (const file of readdirSync(dir).filter((f) => f.endsWith('.ts'))) {
  const mjs = join(out, file.replace(/\.ts$/, '.mjs'));
  writeFileSync(mjs, readFileSync(join(dir, file), 'utf8'));
  const namespace = file.replace(/\.ts$/, '');
  const catalog = (await import(pathToFileURL(mjs).href)).default;
  catalogs[namespace] = catalog;

  for (const [locale, messages] of Object.entries(catalog)) {
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

/** Semua file sumber frontend, kecuali katalog i18n. */
function collectFiles(directory, acc = []) {
  for (const entry of readdirSync(directory)) {
    const full = join(directory, entry);
    if (statSync(full).isDirectory()) {
      if (entry !== 'catalogs') {
        collectFiles(full, acc);
      }
    } else if (/\.(vue|ts)$/.test(entry) && !entry.endsWith('.d.ts')) {
      acc.push(full);
    }
  }

  return acc;
}

/** Ambil nilai pada path bertitik, atau undefined. */
function lookup(messages, path) {
  let current = messages;

  for (const part of path.split('.')) {
    if (current === null || typeof current !== 'object' || !(part in current)) {
      return undefined;
    }
    current = current[part];
  }

  return current;
}

/** Daftar seluruh path leaf (string) di satu katalog locale. */
function leafPaths(messages, prefix = '', acc = []) {
  for (const [key, value] of Object.entries(messages ?? {})) {
    const full = prefix === '' ? key : `${prefix}.${key}`;
    if (value && typeof value === 'object') {
      leafPaths(value, full, acc);
    } else {
      acc.push(full);
    }
  }

  return acc;
}

/**
 * Klasifikasi satu panggilan `t(...)`.
 * literal  → { kind, value }
 * dynamic  → { kind, prefix }  prefiks statis sebelum interpolasi/konkatenasi
 */
function classifyCall(source, openParen) {
  let i = openParen + 1;
  while (i < source.length && /\s/.test(source[i])) {
    i++;
  }

  const quote = source[i];

  if (quote === '`') {
    let end = i + 1;
    let raw = '';
    while (end < source.length && source[end] !== '`') {
      raw += source[end];
      end++;
    }

    return { kind: 'dynamic', prefix: raw.split('${')[0] };
  }

  if (quote === "'" || quote === '"') {
    let j = i + 1;
    let value = '';
    while (j < source.length) {
      if (source[j] === '\\') {
        value += source[j + 1] ?? '';
        j += 2;
        continue;
      }
      if (source[j] === quote) {
        break;
      }
      value += source[j];
      j++;
    }

    let after = j + 1;
    while (after < source.length && /\s/.test(source[after])) {
      after++;
    }

    if (source[after] === '+') {
      return { kind: 'dynamic', prefix: value };
    }

    return { kind: 'literal', value };
  }

  return { kind: 'dynamic', prefix: '' };
}

function lineOf(source, index) {
  let line = 1;
  for (let i = 0; i < index; i++) {
    if (source[i] === '\n') {
      line++;
    }
  }

  return line;
}

/** Prefiks dinamis punya setidaknya satu key yang cocok? */
function hasPrefixMatch(namespace, prefix) {
  const catalog = catalogs[namespace];
  if (!catalog) {
    return false;
  }

  const trimmed = prefix.endsWith('.') ? prefix.slice(0, -1) : prefix;
  if (trimmed === '') {
    return true;
  }

  return leafPaths(catalog.id).some((path) => path === trimmed || path.startsWith(`${trimmed}.`) || path.startsWith(trimmed));
}

const missing = new Map();
const dynamicCalls = [];
const nonNamespaced = [];

for (const file of collectFiles(join(root, 'resources/js'))) {
  const source = readFileSync(file, 'utf8');
  const relativeFile = relative(root, file);
  const callRe = /\bt\s*\(/g;
  let match;

  while ((match = callRe.exec(source)) !== null) {
    const openParen = match.index + match[0].length - 1;
    const call = classifyCall(source, openParen);
    const line = lineOf(source, match.index);

    if (call.kind === 'dynamic') {
      dynamicCalls.push({ file: relativeFile, line, prefix: call.prefix });
      continue;
    }

    const key = call.value;
    if (!key.includes('.')) {
      nonNamespaced.push({ file: relativeFile, line, key });
      continue;
    }

    const separator = key.indexOf('.');
    const namespace = key.slice(0, separator);
    const path = key.slice(separator + 1);

    if (!catalogs[namespace]) {
      missing.set(key, [...(missing.get(key) ?? []), `${relativeFile}:${line}`]);
      continue;
    }

    const absentIn = LOCALES.filter((locale) => {
      const value = lookup(catalogs[namespace][locale], path);

      return value === undefined || (value !== null && typeof value === 'object');
    });

    if (absentIn.length > 0) {
      missing.set(key, [...(missing.get(key) ?? []), `${relativeFile}:${line}`]);
    }
  }
}

const dynamicPrefixProblems = [];
for (const call of dynamicCalls) {
  if (call.prefix === '' || !call.prefix.includes('.')) {
    continue;
  }

  const namespace = call.prefix.split('.')[0];
  if (!hasPrefixMatch(namespace, call.prefix.slice(namespace.length + 1))) {
    dynamicPrefixProblems.push(call);
  }
}

let failed = false;

if (problems.length > 0) {
  failed = true;
  console.error(`✗ i18n: ${problems.length} pesan tidak valid:`);
  for (const p of problems) {
    console.error(`  - [${p.locale}] ${p.key} (${p.file})`);
    console.error(`    pesan: ${JSON.stringify(p.message)}`);
    console.error(`    error: ${p.error}`);
  }
}

if (missing.size > 0) {
  failed = true;
  console.error(`✗ i18n: ${missing.size} key tidak ditemukan di katalog:`);
  for (const [key, locations] of [...missing.entries()].sort(([a], [b]) => a.localeCompare(b))) {
    console.error(`  - ${key}`);
    for (const location of locations) {
      console.error(`      ${location}`);
    }
  }
}

if (dynamicPrefixProblems.length > 0) {
  failed = true;
  console.error(`✗ i18n: ${dynamicPrefixProblems.length} key dinamis tanpa padanan di katalog:`);
  for (const call of dynamicPrefixProblems) {
    console.error(`  - ${call.file}:${call.line}  prefiks "${call.prefix}"`);
  }
}

if (nonNamespaced.length > 0) {
  console.warn(`⚠ i18n: ${nonNamespaced.length} key tanpa namespace (tidak diverifikasi):`);
  for (const call of nonNamespaced) {
    console.warn(`  - ${call.file}:${call.line}  "${call.key}"`);
  }
}

if (failed) {
  process.exit(1);
}

if (dynamicCalls.length > 0) {
  console.log(`ℹ i18n: ${dynamicCalls.length} key dinamis tidak dicek per nilai (prefiksnya diverifikasi).`);
}

console.log('✓ i18n: semua pesan katalog valid dan semua key terpakai ditemukan.');
