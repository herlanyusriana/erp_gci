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

## Skills — WAJIB (baca sebelum eksekusi)

Sebelum mulai mengerjakan atau mengedit apa pun, **load skill yang relevan**
dengan domain pekerjaan. Jangan menunggu diminta, jangan mengandalkan ingatan.
Sebutkan skill yang dipakai di awal respons.

Alur wajib:

1. Identifikasi domain pekerjaan (mis. Laravel backend, Vue/Inertia, Tailwind,
   test, database, diagram).
2. Load semua skill yang relevan lewat tool `skill` **sebelum** menulis kode.
3. Ikuti isi skill; kalau bertentangan dengan `AGENTS.md`, aturan repo menang.
4. Untuk pekerjaan lintas domain, load lebih dari satu skill (mis. refactor
   controller + test: `laravel-best-practices` + `testing-best-practices`).

Peta domain ke skill di repo ini:

| Domain pekerjaan | Skill yang wajib di-load |
| --- | --- |
| Controller, model, migration, query, service, policy, job, cache | `laravel-best-practices` |
| Halaman/komponen Vue + Inertia, form, navigasi, deferred/polling | `inertia-vue-development` |
| Styling Tailwind, layout, komponen UI, token warna | `tailwindcss-development` |
| Menulis/mengubah test Pest | `testing-best-practices` |
| Schema, index, query lambat, migration performa | `database-optimizer` (dan `postgres-pro` untuk query kompleks) |
| Diagram alur/arsitektur/sequence | `archify` |
| Konvensi/aturan repo (`.ai/rules`), standarisasi pola | `infer-conventions` |
| Deploy / Laravel Cloud | `deploying-to-cloud` |

Catatan penting:

- Skill Laravel Boost tersedia di `.agents/skills/` (di-load otomatis opencode
  sebagai external skill). Daftar: `laravel-best-practices`,
  `inertia-vue-development`, `tailwindcss-development`, `testing-best-practices`,
  `deploying-to-cloud`, `infer-conventions`.
- Skill global ada di `~/.config/opencode/skills/` (mis. `database-optimizer`,
  `archify`, `postgres-pro`, `code-reviewer`, `debugging-wizard`).
- CodeGraph MCP tersedia untuk navigasi simbol/call path; pakai sebelum
  menjelajah file secara manual.

## Arsitektur

- Route web: `routes/web.php` (kebab-case, module launchers: `master-data`,
  `incoming-data`, `production-data`, `administration`). API: `routes/api.php`.
- Controller: `app/Http/Controllers` (root) — index/create/store/edit/update/destroy.
- Domain logic transaksional di `app/Services` (`WoService`, `ReceiveMaterialService`),
  bukan di controller.
- Policy: `app/Policies` (14 policy). Otorisasi WAJIB lewat `Gate::authorize(...)`
  di setiap action. Permission dicek via middleware alias `permission`.
- API mobile: `app/Http/Controllers/Api` (Sanctum token + `throttle:api`),
  Flutter "Material Tracker". Auth: `POST /api/auth/login` (+`X-Device-Name`).
- Custom validation: `app/Http/Requests` (mis. `StorePartRequest`) + `app/Rules`
  (mis. `UomCode` — validasi satuan ke master `uoms`).
- Excel export: `app/Exports`. Helper: `app/Support` (mis. `QrSvg`,
  `UomCatalog` — sumber tunggal kode UOM, normalisasi, & default).
- Migrasi: prefix `2026_*` untuk modul ERP; `0001_*` milik Breeze.
- Seeder: `MasterDataSeeder`, `BomSeeder`, `RolesAndPermissionsSeeder`,
  `ConfigMasterSeeder`, `UomSeeder`. Admin dev: `admin@geumcheon.local`.
  Urutan di `DatabaseSeeder`: Roles → Config → Uom → MasterData → Bom.
  `RolesAndPermissionsSeeder` juga membuat user demo per role
  (`<role>@geumcheon.local`, password `password`) untuk uji permission.
  Matriks akses: `super-admin` semua; `management` semua kecuali `role`/`user`;
  `it-admin` kelola config/role/user; role lain granular (lihat seeder).

## Frontend

- Struktur halaman: `resources/js/Pages/<Module>/...`
  (`Master/`, `Incoming/`, `Production/`, `Administration/`, `Outgoing/`,
  `Profile/`, `Auth/`).
- Layout: pakai `AppLayout.vue` untuk halaman baru. Halaman auth (login, reset,
  verifikasi) pakai `AuthLayout.vue` (split-screen: panel brand navy + form).
  `AuthenticatedLayout.vue` hanya untuk halaman bawaan Breeze.
- Register publik dimatikan (route & halaman dihapus); akun dibuat admin via
  Administration → Manajemen pengguna. Halaman `Welcome` dihapus; `GET /`
  redirect ke `login` (guest) / `launcher` (auth).
- Aset brand di `public/images/` (`logo.png`, `logo-white.png`, `logo-mark*.png`)
  dan `public/favicon.ico`, dihasilkan dari `assets/logo-big.jpg`.
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
- **Jebakan vue-i18n:** karakter `@`, `|`, `{`, `}` khusus di format pesan.
  Contoh: `"nama@perusahaan.com"` melempar `Invalid linked format` saat render →
  **seluruh halaman jadi blank putih**. Escape literal `@` sebagai `{'@'}`.
  Guard otomatis: `node scripts/check-i18n.mjs` (jalan sebelum `vite build`).
- Tanpa komentar kode kecuali diminta.
- Query pencarian: pakai `ilike` (Postgres), `when(...)`, `paginate(...)->withQueryString()`.
- Nama tabel/kolom snake_case; model Eloquent di `app/Models`.

## Testing — penting

- Konfigurasi di `phpunit.xml` masih memakai PostgreSQL (DB sqlite di-comment).
  Karena itu test berjalan terhadap database Postgres, **bukan** in-memory —
  dan saat ini menunjuk ke database `erp_gci` yang sama dengan development.
- **Konflik strategi:** `tests/Pest.php` memasang `RefreshDatabase` ke seluruh
  suite `Feature` (menghapus + migrate ulang DB, **tanpa** seed), sementara
  `WorkOrderHttpTest`, `WorkOrderAllocationTest`, `MaterialIssueApiTest`,
  `MaterialIssueWebTest`, `RolePermissionTest`, `MachineLabelTest`,
  `PartTypeGuardTest`, dan `UomCatalogTest` memakai `DatabaseTransactions` dan
  mengandalkan data seeder
  (`admin@geumcheon.local`, part `AAN30056405`, `CBKG07256C`, `4000W4A003A`,
  `PINCB01`, `5040JA3071C`).
- Akibatnya: menjalankan salah satu test `RefreshDatabase` **menghapus** data
  seeder, lalu test berbasis seeder berikutnya gagal (`actingAs(null)` /
  TypeError). Test berbasis seeder lulus bila dijalankan sendiri setelah
  `migrate --seed`.
- Menjalankan `migrate --seed` sebelum suite **tidak** memperbaiki ini; suite
  menghapusnya sendiri. Perbaikannya harus di level strategi test (pisahkan
  grup / pakai factory / `.env.testing` DB terpisah).
- Test yang tidak boleh dianggap regresi: kegagalan `ProfileTest` (hapus akun)
  karena `User` memakai `SoftDeletes` sedangkan test Breeze mengharap hard
  delete.

## Catatan domain

- Tipe part: `FG`, `MATERIAL`, `WIP` (huruf besar). WO hanya untuk part `FG`.
- Alur Work Order: `planned` → `release` → `in_progress` → `complete` / `cancel`.
- **Produksi WIP per proses** (`production_results`): release/issue **hanya
  mengonsumsi RM** (child non-WIP); output WIP/FG lahir dari `Production Result`
  per step (`parent_part`), bukan lagi otomatis saat release. Backflush: child
  WIP dikonsumsi FIFO, child RM di-skip (sudah dikonsumsi saat issue). Step
  sebelumnya harus selesai (stok WIP cukup). `complete` memperingatkan bila
  output FG < qty WO. Layanan: `App\Services\ProductionResultService`.
- **Alokasi material WO** (`work_order_item_allocations`): satu item WO boleh
  dipenuhi dari beberapa part sekaligus. Main material BOM hanyalah **acuan**
  (tidak memegang stok); stok ada pada substitute aktif (`part_substitutes`).
  Alokasi **boleh sebagian** — sisa yang tidak dialokasikan dicatat sebagai
  shortage saat release dan menurunkan output secara proporsional.
  `work_order_items.selected_part_id` dipertahankan untuk kompatibilitas
  (diisi part dengan alokasi terbesar).
- Stok per-part ledger dengan FIFO: `PartStock` (+ `received_at`), konsumsi
  dicatat di `WorkOrderConsumption`.
- Incoming: Purchase Order (import) vs Local PO (tanpa vessel/container),
  Arrival + container inspection, Receive menerbitkan tag/label QR.
- **Label QR mesin**: `/machines/{machine}/label` (QR JSON `{type:"machine",
  machine_id, machine_code, machine_name}`); resolve di mobile lewat
  `POST /api/machines/resolve`.
- **Issue out to production** (mobile "Material Tracker" → Outgoing): release WO
  lewat scan label. Endpoint `GET /api/work-orders`, `GET /api/work-orders/{wo}/
  release-context` (kebutuhan + rekomendasi tag FIFO), `POST /api/stock-tags/
  resolve`, `POST /api/work-orders/{wo}/release` (atomik + `idempotency_key`).
  Konsumsi **tag spesifik** (`ReceiveMaterialService::consumeFromTag`), bukan
  FIFO; tag valid apa pun untuk part kebutuhan diterima (rekomendasi FIFO hanya
  saran). Dokumen `material_issues` + `material_issue_items` dibuat saat release;
  bon dicetak di web (`/material-issues/{id}/print`). Permission `stock.issue`.
  QR label berisi **JSON** (`{tag, receive_id, part_id, part_no, part_name, qty,
  net_weight, qty_unit, invoice, supplier}`) — app mobile **wajib parse JSON**
  dan mengirim `tag` (bukan raw JSON). Asal material (`invoice`, `supplier`)
  diambil server dari `part_stocks.receive_id` saat konsumsi dan disimpan di
  `material_issue_items`, lalu tampil di bon.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.4. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record a rule with `record-rule` only when the user explicitly asks for one. Instructions for the work at hand are not rules, no matter how emphatic: "remove this typo", "use X here" are work to do, not rules to record. Never record a rule on your own initiative, as a byproduct of a change, or to summarize what you just did. When the user does ask, pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Use `record-rule` rather than your native memory or notes tool, because native memory is personal and session-scoped, while only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/Pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v2

- Use all Inertia features from v1 and v2. Check the documentation before making changes to ensure the correct approach.
- New features: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app/Console/Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.

- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>
