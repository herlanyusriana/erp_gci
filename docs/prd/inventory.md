# PRD: Inventory (Stok Gudang)

## Problem Statement

Stok gudang saat ini tersebar di beberapa tabel transaksi yang berbeda:
penerimaan (`part_stocks`), booking material (`work_order_material_bookings`),
penerimaan produksi (`production_material_receipts`), dan hasil produksi.
Tidak ada satu sumber mutasi yang bisa menjelaskan kenapa angka stok sekarang
segini. Ketika ditanya "berapa total stok COMP BASE dan dari mana asalnya",
tidak ada jawaban yang bisa di-trace tanpa merangkai ulang beberapa tabel.

Orang gudang juga harus mencari barang fisik di rak, tetapi layar stok yang ada
hanya daftar tag tanpa kemampuan memfilter berdasarkan lokasi rak.

## Solution

Satu layar Inventory (web) untuk stok gudang yang menampilkan:

1. Tampilan utama per part: total stok per material, lalu drill-down ke per tag.
2. Kartu stok (stock card) per part: riwayat mutasi lengkap berupa masuk,
   keluar, dan penyesuaian, masing-masing dengan tanggal, qty, alasan, dan
   saldo berjalan.
3. Filter dan kolom lokasi rak, supaya operator bisa menemukan posisi fisik.
4. Penyesuaian manual (stock opname) yang tercatat, hanya untuk petugas berizin.

Sumber mutasi adalah tabel ledger `stock_movements` yang append-only, terpisah
dari tabel transaksi. Saldo awal (opening balance) diambil dari `part_stocks.qty`
saat modul dirilis; ledger mencatat mutasi ke depan. Tidak ada backfill riwayat
mutasi lama.

## User Stories

1. Sebagai warehouse, saya ingin melihat total stok per material, supaya saya
   tahu kapan harus memesan ulang.
2. Sebagai warehouse, saya ingin membuka kartu stok sebuah material dan melihat
   tiap mutasi (masuk/keluar/penyesuaian) beserta saldo berjalan, supaya saya
   bisa menjelaskan angka stok saat audit.
3. Sebagai warehouse, saya ingin memfilter stok berdasarkan lokasi rak, supaya
   saya bisa menemukan barang fisik dengan cepat.
4. Sebagai management, saya ingin melihat saldo stok per material dalam satu
   layar, tanpa harus memahami tabel transaksi internal.
5. Sebagai petugas yang diberi izin, saya ingin mencatat penyesuaian stok
   (stock opname) dengan alasan, supaya selisih fisik tercatat dan dapat
   diaudit.
6. Sebagai auditor, saya ingin setiap mutasi punya penanggung jawab dan alasan,
   supaya riwayat stok dapat ditelusuri.
7. Sebagai warehouse, saya ingin melihat detail per tag (invoice, supplier,
   lokasi, qty) setelah membuka satu material, supaya saya tahu asal tiap unit.
8. Sebagai petugas tanpa izin penyesuaian, saya ingin tetap bisa melihat stok
   tetapi tidak bisa mengubahnya, supaya ada pemisahan hak akses.

## Objective

Menyediakan satu sumber kebenaran stok gudang yang dapat dijelaskan dan diaudit:
tampilan per part dengan kartu stok lengkap, filter lokasi, dan penyesuaian
terkontrol, tanpa mencampur stok produksi (WIP/FG) ke dalamnya.

## Technical Decisions

- **Cakupan**: stok gudang saja. WIP dan FG tetap di papan produksi (`wip-board`),
  tidak masuk inventory.
- **Tampilan**: per part sebagai tampilan utama, drill-down ke per tag.
- **Ledger**: tabel baru `stock_movements` append-only. Setiap mutasi punya
  part_id, arah (in/out/adjust), qty, uom, alasan (receive/issue/receipt/adjust),
  referensi transaksi (nullable), user, timestamp, dan saldo berjalan.
- **Saldo awal**: `part_stocks.qty` saat rilis dijadikan opening balance; ledger
  mulai mencatat mutasi sejak rilis. Tidak ada backfill mutasi lama.
- **Lokasi**: filter + kolom lokasi rak pada daftar per tag.
- **Penyesuaian**: lewat aksi `adjust` yang tercatat di ledger, dengan permission
  khusus. Tidak ada edit langsung `part_stocks.qty`.
- **Platform**: web saja (Inertia Vue).
- **Integrasi mutasi**: setiap titik yang mengubah `part_stocks.qty` menulis ke
  ledger: receive (in), transfer ke produksi/issue (out), dan adjustment (in/out).

## Project Structure

```
app/Models/StockMovement.php          -> model ledger
app/Services/StockLedgerService.php   -> tulis + baca mutasi, hitung saldo berjalan
app/Http/Controllers/InventoryController.php -> halaman inventory (web)
app/Policies/StockMovementPolicy.php  -> view + adjust permission
resources/js/Pages/Inventory/Index.vue      -> tampilan per part
resources/js/Pages/Inventory/Show.vue       -> kartu stok + detail per tag
database/migrations/..._create_stock_movements_table.php
tests/Feature/InventoryTest.php
```

## Commands

    Test:  php artisan test --filter=InventoryTest
    Lint:  vendor/bin/pint --dirty --format agent
    Build: npm run build

## Testing Strategy

- Test integrasi: setiap mutasi stok menulis baris ledger dengan saldo berjalan
  yang benar dan tidak negatif.
- Test kartu stok: urutan mutasi dan saldo berjalan sesuai input.
- Test penyesuaian: user dengan izin bisa adjust, tanpa izin ditolak 403.
- Test tampilan: daftar per part menampilkan total stok; drill-down menampilkan
  detail per tag dan filter lokasi.
- Test opening balance: stok saat rilis tercatat sebagai saldo awal, mutasi
  berikutnya menambah/mengurangi dari saldo tersebut.
- Prior art: `StockSourceTest.php`, `ProductionReceiptApiTest.php`,
  `MachineMaterialConsumptionTest.php` (pola DatabaseTransactions + seeded data).

## Boundaries

- **Always:**
  - Setiap perubahan `part_stocks.qty` harus menulis baris `stock_movements`
    dalam transaksi yang sama.
  - Saldo berjalan tidak boleh negatif; mutasi yang membuat negatif ditolak.
  - Data user/enum/user-content tidak diterjemahkan; label UI memakai i18n 3 bahasa.
- **Ask first:**
  - Skema kolom ledger baru dan integrasinya ke setiap titik mutasi stok.
  - Permission baru untuk penyesuaian stok.
- **Never:**
  - Edit `part_stocks.qty` secara langsung tanpa lewat ledger.
  - Backfill riwayat mutasi dari transaksi lama.
  - Mencampur stok WIP/FG ke dalam inventory.

## Out of Scope

- Stok WIP dan FG (tetap di papan produksi).
- APK; inventory ini web saja.
- Backfill riwayat mutasi lama.
- Pemesanan ulang otomatis (reorder point).
- Penilaian/valuasi stok (harga).

## Success Criteria

- [ ] Layar inventory menampilkan total stok per part, lalu drill-down ke per tag.
- [ ] Kartu stok menampilkan mutasi masuk/keluar/penyesuaian dengan saldo berjalan.
- [ ] Tabel `stock_movements` append-only dan tidak ada saldo negatif.
- [ ] Filter dan kolom lokasi rak berfungsi pada daftar per tag.
- [ ] Penyesuaian stok hanya bisa oleh user berizin, dan tercatat dengan alasan.
- [ ] Opening balance dari `part_stocks.qty` tercatat saat rilis; mutasi ke depan
      berjalan dari saldo itu.
- [ ] Test penuh ERP lulus tanpa regresi pada modul stok yang sudah ada.

## Open Questions

- Apakah kartu stok perlu ekspor (Excel/PDF), atau cukup tampilan di layar?
- Apakah penyesuaian stok butuh dua-tingkat (draf lalu approve supervisor),
  atau cukup satu aksi langsung dengan izin khusus?
