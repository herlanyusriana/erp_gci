# Task List: Saran Tag Berbasis Rak & FIFO

PRD: `docs/prd/issue-tag-suggestion.md`
Plan: `tasks/issue-tag-suggestion/plan.md`

---

## Task 1: Sertakan `location_code` pada setiap tag

**Description:** Tag dari `PartStockTagService::forParts()` membawa `location_code`
dari penerimaan terkait, ditambah `part_stock_id` dan nullable `invoice_date`
(`YYYY-MM-DD`) agar mobile dapat mengurutkan FIFO lintas part secara tepat.

**Acceptance criteria:**
- [x] Setiap tag pada hasil `forParts()` menyertakan `location_code`.
- [x] Tag tanpa penerimaan (stok hasil produksi) mengirim `location_code` null.
- [x] Urutan FIFO tidak berubah.
- [x] `part_stock_id` tepat sesuai stock; `invoice_date` tepat/null pada kedua
  daftar `materials[].tags` dan `recommended_tags`.

**Verification:**
- [x] Focused: `php artisan test --compact tests/Feature/Api/MaterialIssueApiTest.php`
  — 73 tes, 431 assertions; tes lokasi sebelumnya tetap lulus.
- [x] Full: `php artisan test --compact` — 306 tes, 1746 assertions.
- [x] Pint: `vendor/bin/pint --dirty --format agent` lulus.
- [x] `npm run build` lulus (advisory chunk >500 kB).

**Dependencies:** None

**Files likely touched:**
- `app/Services/PartStockTagService.php`
- `tests/Feature/Api/MaterialIssueApiTest.php`

**Estimated scope:** Small (2 files)

---

## Task 2: `MaterialTag` membawa `locationCode`

**Description:** Model Flutter `MaterialTag` mem-parse `location_code` dari API,
sehingga UI bisa menampilkan rak per tag.

**Acceptance criteria:**
- [x] `MaterialTag.locationCode` dan `RecommendedTag.locationCode` terisi dari JSON.
- [x] `locationCode` null atau field lama yang tidak ada tidak menyebabkan error.
- [x] Model test memverifikasi nilai null, non-null, dan payload lama.
- [x] `MaterialTag` dan `RecommendedTag` mem-parse optional `invoiceDate` dan
  `partStockId` dari field kanonis, termasuk numeric string dan server lama.

**Verification:**
- [x] Tests pass: `flutter test --no-pub test/models_test.dart` (26 tes dalam suite akhir).
- [x] Analyze: tanpa error/warning baru; 3 info baseline di luar scope.

**Dependencies:** Task 1

**Files likely touched:**
- `lib/models/release_context.dart`
- `test/models_test.dart`

**Estimated scope:** Small (2 files)

---

## Task 3: Layar saran tag per material

**Description:** Layar issue menampilkan daftar saran tag per material, lengkap
dengan rak dan qty, urut FIFO. Tag paling awal ditandai sebagai saran. Operator
memindai tag yang muncul; qty terisi otomatis dari data stok tag.

**Acceptance criteria:**
- [x] Daftar tag tampil per material dengan `tag`, part, rak, qty tersedia, uom, invoice, supplier.
- [x] Tag FIFO teratas ditandai "disarankan"; received_at nulls last, invoice_date
  nulls last, part_stock_id ASC. Tie lintas part tidak mengikuti alfabet rak/tag.
- [x] Scan tag mengisi qty tersedia otomatis; tidak ada input qty manual.
- [x] Tag tanpa lokasi menampilkan "tanpa lokasi" (ID/EN/KO).

**Verification:**
- [x] Tests pass: `scanner_polish_test.dart` dan `issue_tag_suggestion_test.dart`.
- [ ] Manual check: buka satu WO dan lihat daftar saran.

**Dependencies:** Task 2

**Files likely touched:**
- `lib/features/issue/guided_issue_screen.dart`
- `lib/core/l10n/app_localizations.dart`
- `test/scanner_polish_test.dart`

**Estimated scope:** Medium (3 files)

---

### Checkpoint: Saran Tampil
- [x] Pilih WO → daftar saran tag tampil dengan rak dan qty (widget test).
- [x] `flutter test --no-pub` lulus: 148 tes.

---

## Task 4: Scan rak sebagai filter + notice

**Description:** Tombol scan rak menyaring daftar agar hanya menampilkan tag di
rak yang dipindai. Bila rak tidak berisi tag untuk material terkait, tampilkan
notice yang menyebut rak dan jumlah tag yang sebenarnya tersedia.

**Acceptance criteria:**
- [x] Scan rak menyaring daftar tag ke rak tersebut, tanpa mengubah lokasi dokumen.
- [x] Rak tanpa tag menampilkan notice dan hitungan alternatif nyata.
- [x] Scan rak tidak wajib; daftar penuh tetap terlihat tanpa scan rak.
- [x] Ada aksi membersihkan filter rak.

**Verification:**
- [x] Tests pass: `scanner_polish_test.dart` dan `issue_tag_suggestion_test.dart`.
- [ ] Manual check: scan rak yang salah → notice muncul.

**Dependencies:** Task 3

**Files likely touched:**
- `lib/features/issue/guided_issue_screen.dart`
- `lib/core/l10n/app_localizations.dart`
- `test/scanner_polish_test.dart`

**Estimated scope:** Medium (3 files)

---

## Task 5: Pindah material tanpa mundur

**Description:** Setelah satu material selesai di-issue, lanjut ke material
berikutnya tanpa mengulang scan rak dan tanpa kembali ke tahap awal. Filter rak
diperlakukan sesuai keputusan open question.

**Acceptance criteria:**
- [x] Pindah material tidak mengembalikan ke tahap scan rak.
- [x] `_rackCode` tidak direset paksa saat pindah material; bertahan sampai dibersihkan.
- [x] Release per material tetap berfungsi; baris material lain tidak terhapus.
- [x] Tidak mundur ke tahap awal saat ada material berikutnya.

**Verification:**
- [x] Tests pass: `flutter test --no-pub` (148 tes).
- [ ] Manual check: satu WO dengan dua material selesai tanpa mundur.

**Dependencies:** Task 4

**Files likely touched:**
- `lib/features/issue/guided_issue_screen.dart`
- `test/scanner_polish_test.dart`

**Estimated scope:** Small (2 files)

---

### Checkpoint: Alur Lengkap
- [x] Dua material selesai tanpa mundur (widget test dengan scanner injection).
- [x] Release per material tetap jalan; double-submit ditolak, attempt berjalan
  tidak dibatalkan lewat reset/load; retry mempertahankan key dan payload exact.
- [x] Seluruh 148 tes lulus; analyze tanpa error/warning baru, 3 info baseline
  di production result, receipt, dan warehouse home.

## Bukti dan Batas Verifikasi

- 35 tes mobile baru kumulatif: 7 model/parser, 17 FIFO/provider/widget, dan 11
  pending-release; tes scanner lama
  disesuaikan untuk alur filter opsional, tidak dihapus.
- RED diamati sebelum perbaikan: parser lokasi belum ada, qty resolve belum
  dikurangi booking, saran/scan langsung belum tersedia, filter/next-material
  belum ada, submit ganda menunggu tanpa guard, FIFO tie-break dan pemilihan
  material tersisa belum benar, overflow header pada 320 px + teks 150%.
- GREEN: suite lengkap 148 tes. UI ID/EN/KO dan ukuran sempit diuji widget;
  kamera fisik dan manual check tetap belum diverifikasi.
- Follow-up RED: 2 tes backend gagal karena tie keys belum dikirim; widget
  stock-ID tie memilih tag salah, dan parser kanonis belum tersedia. GREEN:
  2 tes backend baru lulus (45 assertions), focused mobile 43 tes lulus.
- Follow-up menambah 2 tes backend dan 6 tes mobile. Kontrak exact/null diperiksa
  pada 2 part; urutan backend meliputi receipt, invoice, stock ID, dan nulls last.
  Widget equal-receipt membuktikan prioritas invoice dan ID lintas part, badge
  rekomendasi yang benar, serta urutan yang berlawanan dengan alfabet.
- FIFO tie keys kini dikirim backend. Server lama tanpa field tambahan tetap
  didukung dengan fallback urutan sumber stabil. Keterbatasan metadata pada
  implementasi awal sudah diselesaikan untuk server dengan kontrak baru.
- Full-tag overissue tetap pertanyaan terbuka; validasi sisa WO tidak dilonggarkan.

## Blocker: Ambiguous Release Mengikat Key dan Payload

- [x] Snapshot immutable menyimpan work order, key, item ID, tag/part ID, qty,
  serta lokasi. Payload berbeda tidak boleh memakai key pending.
- [x] Fake server menyimpan dokumen A sebelum timeout dan mengembalikan dokumen
  yang sama saat retry. Release B diblokir; retry A hanya mengkredit A dan tetap
  menyimpan scan B. B baru dapat dikirim dengan key baru setelah A sukses.
- [x] Edit/remove/add tag/qty, perubahan lokasi, reset, dan reload WO tidak
  mengubah atau menghapus attempt ambigu. Tidak ada aksi cancel yang melupakan key.
- [x] Guided dan standard issue menampilkan recovery Retry release sebelumnya;
  selector/rak/tag editing dikunci dan copy recovery ID/EN/KO diuji.
- [x] HTTP 422/409 pertama membuka edit (penolakan terkonfirmasi); 408/500 tetap
  terkunci. Payload baru setelah penolakan terkonfirmasi menggunakan key baru.
  Setelah timeout, bahkan retry 422 tidak membuka edit/melupakan attempt/key.
- [x] RED diamati sebelum perbaikan; focused pending 11 tes, focused gabungan 57
  tes, full Flutter 148 tes lulus. Analyze: 3 info baseline, tanpa error/warning.
- Pending attempt masih berbasis sesi provider; process-death/restart recovery
  belum diimplementasikan/diklaim. Tidak ada perubahan backend/booking/overissue
  dalam perbaikan ini.
