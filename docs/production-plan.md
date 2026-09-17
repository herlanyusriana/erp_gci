# Production Plan — Spec Implementasi

Dokumen ini adalah hasil diskusi final dengan user. Tujuannya supaya siapapun
(AI atau developer) bisa mengimplementasikan modul **Production Plan** tanpa
menebak-nebak lagi.

Repo: `erp_gci` — Laravel 12 + Inertia 2 + Vue 3 (TS) + PostgreSQL + Tailwind v3.

---

## 1. Latar belakang & perubahan alur

Saat ini modul Production hanya punya **Work Order** (WO dibuat manual, lalu
release, lalu consume FIFO). User ingin **membalik alurnya**:

> **Production Plan dulu, baru Work Order di-generate dari plan.**
> Bukan WO dulu lalu ditarik ke plan.

Alasan: di lapangan, papan perencanaan harian ("DAILY PLANNING PRODUCTION")
adalah dokumen pertama. Papan ini yang menentukan mesin mana mengerjakan item
apa, berapa qty, untuk hari ini dan 2 hari ke depan. WO adalah turunannya.

Referensi bentuk papan (dari foto user): dikelompokkan per **mesin**, tiap mesin
berisi baris item + qty + referensi JO + keterangan. Nama mesin **tetap** (fixed
skeleton), bukan diisi ulang tiap hari.

---

## 2. Keputusan final (SUDAH DIKONFIRMASI USER)

| # | Keputusan | Nilai |
|---|---|---|
| 1 | Penempatan menu | Module **Production** → submenu **Production Plan** dan **Work Orders** |
| 2 | Arah alur | Production Plan **dulu**, WO digenerate dari plan |
| 3 | Nama mesin di papan | **Tetap** dari master `machines` — selalu tampil, termasuk mesin kosong |
| 4 | Urutan mesin di papan | **Alfabetis `machine_name`** (keputusan C — tanpa field `sort_order` baru) |
| 5 | Arti kolom D / D+1 / D+2 | **TARGET produksi per hari** (bukan per shift) |
| 6 | Pembagian WO | Satu WO **boleh dipecah fleksibel 1–3 hari** atau 1 hari penuh |
| 7 | Aksi trash (🗑) | **Lepas WO dari plan saja** — WO tetap ada, status **TIDAK** dibatalkan |
| 8 | Avail WO Qty | `work_order.qty − (target_d + target_d1 + target_d2)` — **dihitung, tidak disimpan** |
| 9 | Kolom "JO" pada dokumen | = **`work_orders.wo_no`** |
| 10 | Header halaman | Production Plan · Search · Start Date · Day Plan · tombol **+ WO Baru** |
| 11 | Sumber WIP Part | **Derive dari BOM** (WIP child di dalam WO), tapi **bisa di-override** planner via `wip_part_id` |
| 12 | Machine di plan item | **Jadwal saja** — disimpan di `production_plan_items.machine_id`, **tidak** mengubah `work_order_items.machine_id` (routing BOM tetap utuh) |
| 13 | Alokasi > qty WO | **Boleh** — `Avail WO Qty` bisa negatif; tampilkan dengan warna `danger` sebagai tanda over-allocate |
| 14 | WO `released`/`in_progress` | **Tetap tampil** di papan plan dan masih bisa diperbarui |
| 15 | Target harian | Minimal salah satu dari `target_d/d1/d2` terisi; boleh semua null saat draft |

### Kolom tabel Production Plan

```
Nama Machine | Urutan | FG Part | WIP Part | Avail WO Qty | D Qty | D+1 Qty | D+2 Qty | Aksi
```

### Aksi per baris

| Ikon | Aksi | Detail |
|---|---|---|
| ⬆ / ⬇ | Ubah urutan | Reorder WO dalam mesin yang sama (`sequence`) |
| 👁 | Lihat detail | Buka `work-orders.show` |
| ✏ | Edit | Edit assignment (ganti mesin) + target harian |
| ➕ | Tambah WO baru | Buat WO baru, inherit mesin + urutan baris tsb |
| 🗑 | Lepas dari plan | Hapus plan item; **WO tetap ada**, status tidak diubah |

---

## 3. Ditunda (JANGAN diimplementasikan dulu)

User eksplisit minta ini **skip dulu**:

- Notasi progres `1/5`, `2/2`, `3/4`, `0%` — belum dibahas, jangan diasumsikan.
- Kolom **ACTUAL** — "nanti dulu". Kemungkinan manual atau auto dari posting
  output produksi; belum diputuskan.
- Integrasi shift (SHIFT 1/2/3) — plan ini per **hari**, bukan per shift.

`#DIV/O!` dan `0%` yang muncul di foto Excel adalah artefak rumus rusak, **bukan
data asli**. Jangan direplikasi.

---

## 4. Desain data

### 4.1 Tabel `production_plans`

```php
Schema::create('production_plans', function (Blueprint $table) {
    $table->id();
    $table->date('plan_date')->unique();      // Start Date / hari plan
    $table->text('notes')->nullable();
    $table->unsignedBigInteger('created_by')->nullable();
    $table->unsignedBigInteger('updated_by')->nullable();
    $table->timestamps();
});
```

Satu `plan_date` = satu plan (window tetap D / D+1 / D+2 dari tanggal tsb).

### 4.2 Tabel `production_plan_items`

```php
Schema::create('production_plan_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('production_plan_id')->constrained('production_plans')->cascadeOnDelete();
    $table->foreignId('machine_id')->nullable()->constrained('machines')->nullOnDelete();
    $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
    $table->foreignId('fg_part_id')->nullable()->constrained('parts')->nullOnDelete();
    $table->foreignId('wip_part_id')->nullable()->constrained('parts')->nullOnDelete();
    $table->unsignedInteger('sequence')->default(0);   // urutan dalam mesin
    $table->decimal('target_d', 20, 4)->nullable();    // target hari D
    $table->decimal('target_d1', 20, 4)->nullable();   // target D+1
    $table->decimal('target_d2', 20, 4)->nullable();   // target D+2
    $table->unsignedBigInteger('created_by')->nullable();
    $table->unsignedBigInteger('updated_by')->nullable();
    $table->timestamps();
    $table->index(['production_plan_id', 'machine_id', 'sequence']);
});
```

Catatan:
- Minimal salah satu `target_*` terisi; nullable semua.
- `work_order_id` nullable karena plan item bisa ada tanpa WO (belum/gagal generate)
  dan karena trash = lepas relasi.
- `wip_part_id` null = pakai hasil derive dari BOM.
- Prefix migrasi mengikuti konvensi repo: `2026_*`.

### 4.3 Model

- `app/Models/ProductionPlan.php`
  - `items()` hasMany `ProductionPlanItem`
  - `fillable`: `plan_date`, `notes`, `created_by`, `updated_by`
- `app/Models/ProductionPlanItem.php`
  - `plan()` belongsTo `ProductionPlan`
  - `machine()` belongsTo `Machine`
  - `workOrder()` belongsTo `WorkOrder`
  - `fgPart()` belongsTo `Part` (FK `fg_part_id`)
  - `wipPart()` belongsTo `Part` (FK `wip_part_id`)
  - `fillable` semua kolom di atas
  - cast decimal → float

---

## 5. Alur UI

```
Buka Production Plan
  → pilih Start Date
  → papan tampil: semua machine AKTIF, urut alfabetis machine_name
    → tiap machine menampilkan plan item miliknya (urut `sequence`)
    → machine kosong tetap tampil (skeleton tetap)

  → klik [+ WO Baru]
      → pilih FG (searchable, HANYA part type FG)
      → pilih Machine
      → isi Qty WO
      → (opsional) WIP Part
      → target D / D+1 / D+2 (default: D = qty WO)
      → submit
        → server panggil WoService::createWorkOrder(...)
        → buat WorkOrder (status `planned`)
        → buat ProductionPlanItem berelasi ke WO tsb
```

**Avail WO Qty** dihitung on-the-fly saat render, contoh:

```
WO Qty       = 1500
D            =  500
D+1          =  500
D+2          =  500
Avail WO Qty =    0
```

Kalau Avail > 0, planner boleh menambah target hari lain (fleksibel 1–3 hari).
Kalau target diisi 1 hari penuh (D = 1500), itu juga sah.
Kalau Avail < 0, artinya over-allocate — tetap boleh, tandai `danger`.

---

## 6. Backend yang perlu dibuat

### 6.1 Controller `app/Http/Controllers/ProductionPlanController.php`

- `index(Request)` — render papan.
  - Terima `date` (default `today()`).
  - `firstOrCreate` plan untuk tanggal itu (atau tampilkan kosong).
  - Return `machines` (aktif, `orderBy('machine_name')`) + `plan` + `items` (eager load machine, workOrder.part, fgPart, wipPart).
- `store(Request)` — buat WO + plan item.
  - Validasi: `fg_part_id` (required, exists, **harus part type FG**), `machine_id` (required, exists, aktif), `qty` (required, numeric, min), `wip_part_id` (nullable), `target_d/d1/d2` (nullable numeric), `plan_date`.
  - Panggil `WoService::createWorkOrder(...)` di dalam transaksi.
  - Buat `ProductionPlanItem`.
- `update(Request, ProductionPlanItem)` — edit machine + target harian.
- `reorder(Request)` — update `sequence` banyak baris sekaligus (drag/naik-turun).
- `detach(ProductionPlanItem)` — hapus plan item (lepas WO dari plan). **Jangan** ubah status WO.
- `destroy(ProductionPlan)` — hapus plan (opsional).

Semua action **WAJIB** `Gate::authorize(...)`.

### 6.2 Service

Generate WO tetap lewat `app/Services/WoService.php` — **jangan** duplikasi logic
explode BOM di controller. Kalau butuh method bantu (mis. resolve WIP part dari
BOM), tambahkan di service, bukan controller.

### 6.3 Routes (`routes/web.php`)

```php
Route::get('production-plans', [ProductionPlanController::class, 'index'])->name('production-plans.index');
Route::post('production-plans', [ProductionPlanController::class, 'store'])->name('production-plans.store');
Route::patch('production-plans/items/{item}', [ProductionPlanController::class, 'update'])->name('production-plans.items.update');
Route::post('production-plans/items/reorder', [ProductionPlanController::class, 'reorder'])->name('production-plans.items.reorder');
Route::delete('production-plans/items/{item}', [ProductionPlanController::class, 'detach'])->name('production-plans.items.detach');
```

Letakkan di blok Production, dekat route `work-orders`.

### 6.4 Otorisasi

- Tambah `ProductionPlanPolicy` (lihat pola 13 policy yang sudah ada).
- Tambah permission: `production_plan.view`, `production_plan.create`,
  `production_plan.update`, `production_plan.delete` (underscore, mengikuti
  konvensi repo: `work_order.*`, `purchase_order.*`).
- Daftarkan di `RolesAndPermissionsSeeder` dan daftarkan policy di
  `AuthServiceProvider` / auto-discovery.

---

## 7. Frontend yang perlu dibuat

- Halaman: `resources/js/Pages/Production/Plan/Index.vue`
  - Pakai `AppLayout.vue`, `BackButton.vue`, `InputError.vue`, `Modal.vue`.
  - Form pakai `useForm` dari `@inertiajs/vue3`.
  - FG selector **searchable** (ketik part number/nama) — BUKAN dropdown panjang.
    Ini preferensi kuat user: dataset besar wajib typeahead.
- Update `resources/js/Pages/Production/ModuleLauncher.vue` — tambah tile
  **Production Plan** di atas/di samping Work Order.
- Update `resources/js/types/index.d.ts` — tambah `ProductionPlan`,
  `ProductionPlanItem`.
- Modal create/edit pakai `Components/Modal.vue` (konvensi repo: JANGAN form
  inline di atas tabel — user menilai itu "slop").

### Aturan UI (WAJIB)

- **Warna hanya dari token tema** (`tailwind.config.js`): `primary`,
  `primary-hover`, `primary-light`, `surface`, `background`, `borderline`,
  `ink-primary`, `ink-secondary`, `success`, `warning`, `danger`, `info`.
  **Jangan** pakai warna Tailwind mentah (`blue-500`, `gray-200`, dll).
- Bahasa UI: **Indonesia**.
- Ikon pakai SVG (Heroicons inline), bukan emoji.
- Tombol aksi icon-only wajib ada `title` / `aria-label`.

---

## 8. Belum diputuskan (tanyakan dulu, jangan diasumsikan)

Dua hal ini sudah **ditunda eksplisit** oleh user (lihat §3) dan belum punya
jawaban. Jangan diam-diam diasumsikan — tanyakan dulu atau tandai TODO:

1. **ACTUAL** — manual di akhir shift, atau otomatis dari posting output
   produksi (WIP/FG masuk `part_stocks`)?
2. **Progres `1/5` / `2/2` / `%`** — belum dibahas sama sekali.

> Catatan: pertanyaan lama soal sumber WIP Part, semantik Day Plan, machine per
> WO, dan alokasi 3 kolom vs tabel terpisah **sudah dijawab** — lihat tabel
> keputusan §2 (poin 5, 6, 11, 12, 13, 15).

---

## 9. Konvensi repo (ringkas dari `AGENTS.md`)

- Gate::authorize di **setiap** action; permission via middleware alias `permission`.
- Domain logic transaksional di `app/Services`, bukan controller.
- Query search: `ilike` (Postgres), `when(...)`, `paginate(...)->withQueryString()`.
- Nama tabel/kolom snake_case.
- Testing: **Pest v4**, jalan ke **PostgreSQL nyata** (bukan in-memory).
  `tests/Feature/WorkOrderHttpTest.php` pakai `DatabaseTransactions` dan
  bergantung data seeder. Jalankan seeder dulu.
- Setelah perubahan: `vendor/bin/pint` dan `npm run build`.
- Migrasi prefix `2026_*` untuk modul ERP.
- Part type: `FG`, `MATERIAL`, `WIP` (huruf besar). **WO hanya untuk part FG.**

---

## 10. Definition of Done

- [ ] Migrasi `production_plans` + `production_plan_items` jalan.
- [ ] Model + relasi lengkap.
- [ ] `ProductionPlanController` dengan otorisasi.
- [ ] `+ WO Baru` berhasil membuat WO lewat `WoService` + plan item.
- [ ] Papan menampilkan semua mesin aktif urut alfabetis, item urut `sequence`.
- [ ] Target D/D+1/D+2 bisa diisi fleksibel (1 hari atau dipecah 3 hari).
- [ ] `Avail WO Qty` terhitung benar = `qty − (d + d1 + d2)`.
- [ ] Trash melepas WO dari plan **tanpa** mengubah status WO.
- [ ] Reorder (naik/turun) mengubah `sequence` dan persist.
- [ ] Menu muncul di Production → Production Plan.
- [ ] `vendor/bin/pint` bersih, `npm run build` lolos.
- [ ] Test: create WO dari plan, edit target, detach tidak mengubah status WO,
      Avail terhitung benar.
