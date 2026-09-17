# AGENTS.md — Geum Cheon ERP

Panduan kerja untuk agent di repo ini. Ikuti konvensi yang sudah ada; jangan
mengubah pola tanpa alasan jelas.

## Stack

- PHP 8.2+, Laravel 12, PostgreSQL (db `erp_gci`, `DB_CONNECTION=pgsql`)
- Inertia 2 + Vue 3 + TypeScript + Vite 6, Tailwind v3
- Breeze (auth), Sanctum (API), Pest v4 (test), Pint (lint)
- dompdf (PDF), maatwebsite/excel (export), endroid/qr-code (QR), Ziggy (`route()`)

## Commands

- Test: `php artisan test` (atau `vendor/bin/pest`)
- Lint/format PHP: `vendor/bin/pint`
- Typecheck + build frontend: `npm run build` (`vue-tsc && vite build`)
- Dev frontend: `npm run dev`
- Dev semua (server+queue+log+vite): `composer run dev`
- Migrate + seed: `php artisan migrate --seed`

Selalu jalankan `vendor/bin/pint` dan `npm run build` setelah perubahan PHP/Vue.

## Arsitektur

- Route web: `routes/web.php` (kebab-case, module launchers: `master-data`,
  `incoming-data`, `production-data`, `administration`). API: `routes/api.php`.
- Controller: `app/Http/Controllers` (root) — index/create/store/edit/update/destroy.
- Domain logic transaksional di `app/Services` (`WoService`, `ReceiveMaterialService`),
  bukan di controller.
- Policy: `app/Policies` (13 policy). Otorisasi WAJIB lewat `Gate::authorize(...)`
  di setiap action. Permission dicek via middleware alias `permission`.
- Custom validation: `app/Http/Requests` (mis. `StorePartRequest`).
- Excel export: `app/Exports`. Helper: `app/Support` (mis. `QrSvg`).
- Migrasi: prefix `2026_*` untuk modul ERP; `0001_*` milik Breeze.
- Seeder: `MasterDataSeeder`, `BomSeeder`, `RolesAndPermissionsSeeder`,
  `ConfigMasterSeeder`. Admin dev: `admin@geumcheon.local`.

## Frontend

- Struktur halaman: `resources/js/Pages/<Module>/...`
  (`Master/`, `Incoming/`, `Production/`, `Administration/`, `Profile/`, `Auth/`).
- Layout: pakai `AppLayout.vue` untuk halaman baru. `AuthenticatedLayout.vue`
  hanya untuk halaman bawaan Breeze.
- Komponen reusable: `resources/js/Components` (ActionButton, BackButton, Modal,
  Pagination, FlashMessages, dll). Jangan duplikasi, pakai yang ada.
- Tipe di `resources/js/types` — update saat menambah field/props.
- Form pakai `useForm` dari `@inertiajs/vue3`; tampilkan error via `InputError`.
- **Warna wajib dari token tema** (`tailwind.config.js`): `primary`,
  `primary-hover`, `primary-light`, `surface`, `background`, `borderline`,
  `ink-primary`, `ink-secondary`, `success`, `warning`, `danger`, `info`.
  Jangan pakai warna Tailwind mentah (mis. `blue-500`).
- Flash message dari server: `success` / `error`, diakses via props `flash`.

## Konvensi kode

- **Multilingual (id/en/ko):** Jangan hardcode teks user-facing. Frontend pakai
  `useI18n()` + `t('namespace.key')` (katalog `resources/js/i18n/catalogs/*.ts`,
  namespace = nama file; wajib 3 bahasa seimbang). Backend pakai `__()` dengan
  source-string key; katalog di `lang/{id,en,ko}.json` + `lang/<locale>/*.php`.
  Pilihan bahasa user persist via `POST /locale` (session + cookie 1 tahun);
  default `id`, tidak tergantung `APP_LOCALE`. Data/enum/user-content JANGAN
  diterjemahkan. PDF/Excel/QR tetap tanpa terjemahan.
- Tanpa komentar kode kecuali diminta.
- Query pencarian: pakai `ilike` (Postgres), `when(...)`, `paginate(...)->withQueryString()`.
- Nama tabel/kolom snake_case; model Eloquent di `app/Models`.

## Testing — penting

- Konfigurasi di `phpunit.xml` masih memakai PostgreSQL (DB sqlite di-comment).
  Karena itu test berjalan terhadap database Postgres, **bukan** in-memory.
- `tests/Pest.php` membungkus suite `Feature` dengan `RefreshDatabase`, tetapi
  `tests/Feature/WorkOrderHttpTest.php` memakai `DatabaseTransactions` dan
  bergantung pada data hasil seeder (`admin@geumcheon.local`, part number
  `AAN30056405`, `CBKG07256C`, `4000W4A003A`, `PINCB01`, `5040JA3071C`).
- Jalankan seeder sebelum menjalankan test yang bergantung data master.

## Catatan domain

- Tipe part: `FG`, `MATERIAL`, `WIP` (huruf besar). WO hanya untuk part `FG`.
- Alur Work Order: `planned` → `release` → `in_progress` → `complete` / `cancel`.
  Substitusi material divalidasi terhadap `part_substitutes` yang `is_active`.
- Stok per-part ledger dengan FIFO: `PartStock` (+ `received_at`), konsumsi
  dicatat di `WorkOrderConsumption`.
- Incoming: Purchase Order (import) vs Local PO (tanpa vessel/container),
  Arrival + container inspection, Receive menerbitkan tag/label QR.
