# Audit — Material Tracker (APK Flutter)

Repo: `erp_gci_mobile` @ `a0646eb` (`main`, bersih; `design/` + `scripts/` untracked).
Sifat audit: read-only. Tidak ada file di repo mobile yang diubah.
Tanggal: 2026-11-18.

Metode: 3 subagent paralel (issue/release, production, loading+warehouse) +
irisan sendiri (data layer, keamanan, i18n, test coverage, tema). Setiap temuan
Critical diverifikasi ulang ke kode server di `erp_gci` — kolom **Bukti**
menandai mana yang sudah dikonfirmasi.

---

## 1. Baseline (diukur, bukan diasumsikan)

| Pemeriksaan | Hasil |
| --- | --- |
| `flutter analyze` | 5 issue, **semua `info`** (0 error/warning) |
| `flutter test` | **214 lulus** (~11 s) |
| `flutter build apk --debug` | ✓ |
| Katalog i18n | 275 key × id/en/ko, **seimbang, 0 drift**, 0 duplikat |
| Hardcoded string (di luar `l.t`) | **56** di 17 file |
| `Semantics`/`semanticLabel` | hanya 2 file |
| `PopScope`/`WillPopScope` | **0** di seluruh `lib/` |
| `isOfflineProvider` dipakai | 4 layar dari 17 layar pemanggil API |
| `textScaler` handling | 0 |
| TODO/FIXME | 1 (`warehouse_home_screen.dart:142`) |
| `print`/`debugPrint` | 0 |
| `Colors.*` mentah di luar tema | 17 (+1 hex `0xFFD6EAF5`) |

Angka 214 test itu menyesatkan: 12 layar tidak punya rujukan test sama sekali,
dan salah satu layar yang paling banyak dites (`issue_screen.dart`) **tidak
dipakai di produksi**.

---

## 2. Critical

### C1. `scanned_qty` per-part diperlakukan per-baris → pengiriman macet permanen
`lib/models/loading_context.dart:56-59` · `loading_scan_screen.dart:283,309,377`

Server mengirim `scanned_qty` **per part**, bukan per baris
(`DeliveryService.php:719` — `$scannedByPart[$item->part_id]`). Jadi dua baris
dengan FG yang sama (sequence berbeda — normal untuk satu customer banyak seq)
mendapat angka yang sama, dan `LoadingItem.remaining` menghitung
`row.qty − partTotal`. Begitu satu tag dipindai, **kedua** baris jadi
`remaining <= 0` → chip hijau "OK", tombol scan baris nonaktif, dan tombol FG
utama nonaktif (`allCovered`). Progres tetap `< totalTags` sehingga delivery
tidak pernah bisa `loading_complete`.

**Bukti:** dikonfirmasi di `DeliveryService::loadingContext` (`:708-720`).
Bonus bug di baris yang sama: `scanned_tags` dan `total_tags` menghitung
**part** (`count(array_filter($covered))`), bukan tag — jadi progres memang
tidak akan pernah mencapai total.

**Dampak:** operator diblokir total, harus menelepon kantor.
**Perbaikan:** ubah kontrak server → `items.scanned_qty` per `item.id`, baca
langsung di `LoadingItem.fromJson`. Sampai itu ada, hitung `allCovered`/`remaining`
per grup `(partId, uom)` di `LoadingContext` dan jangan menonaktifkan baris yang
`qty`-nya sendiri belum tertutup.

### C2. OQC bisa ditandai per troli tapi tidak pernah bisa diselesaikan
`loading_scan_screen.dart:244-267,384-438`

APK hanya memanggil `POST /deliveries/{id}/trolley-qc`. API **tidak punya**
endpoint pass/reject: `routes/api.php` hanya punya `trolley-qc` (`:59`),
sedangkan web punya `deliveries.qc.pass` / `deliveries.qc.reject`
(`routes/web.php:81-82`). `DeliveryService::submitOutgoing` menolak selama
`!isQcPassed()`, jadi truk tidak bisa dikirim dari APK.

**Bukti:** dikonfirmasi — grep `qc` di `routes/api.php` hanya menghasilkan 3 baris
(trolley-qc).
**Dampak:** semua troli hijau di layar, dokumen tetap `picked` selamanya;
seseorang harus jalan ke PC untuk menekan Pass.
**Perbaikan:** tambah `pass-qc`/`reject-qc` di `DeliveryApiController` +
`DeliveryRepository`, tampilkan aksi "Selesaikan OQC"/"Tolak OQC" setelah semua
troli `checked`, dan kunci tombol per-troli bila `qcStatus != 'pending'`.

### C3. Laporan hasil produksi tidak idempotent → output & stok dobel
`production_session_screen.dart:478-494` · `issue_repository.dart:158-176`

`reportResultRaw` tidak mengirim `idempotency_key`, dan server tidak punya
kuncinya (`storeResult` validasi hanya `parent_part_id/qty_good/qty_reject/
result_date/shift/period_*`), tidak ada unique index pada
`(work_order, parent_part, result_date, shift, period_start)`.
`ProductionResult::create` tanpa syarat.

**Bukti:** dikonfirmasi di `MaterialIssueApiController::storeResult:579-592`.
**Dampak:** `receiveTimeout` 30 s setelah server commit → operator menekan Save
lagi → **dua** baris `production_results`, `part_stocks` terkonsumsi dua kali.
APK tidak punya delete/edit hasil, jadi hanya bisa dibetulkan di web.
**Perbaikan:** salin pola yang sudah terbukti dari alur release
(`IssueState._idempotencyKey`, `issue_provider.dart:161-171`). Tambahkan guard
re-entrancy di `_submit`, dan kirim qty terlaporkan per periode dari
`/production-session` supaya tombol bisa menampilkan "sudah dilaporkan".

### C4. Tidak ada batas atas `qty_good`/`qty_reject` — salah ketik langsung permanen
`production_session_screen.dart:469-476`

Hanya `good == null || good <= 0` yang dicek; `reject` di-parse `?? 0` tanpa
batas. Server hanya membatasi `qty_good gt:0`, `qty_reject min:0`, dan kumulatif
`qty_good ≤ work_order.qty`. `qty_reject` **tidak punya batas atas**, padahal ia
menggerakkan konsumsi: `need = child_qty × (qty_good + qty_reject)`
(`ProductionResultService.php:105-106`).

**Bukti:** dikonfirmasi — `min:0` tanpa `max`, dan cap hanya pada kumulatif good.
**Dampak:** NG `1000` alih-alih `100` mengonsumsi 10× WIP/RM; Good `10000`
menghabiskan sisa rencana WO. Tidak bisa dibatalkan dari APK.
**Perbaikan:** hitung `remaining = target − produced` sebelum POST (result-context
sudah mengirim `target_qty`/`produced_qty`) dan tolak/konfirmasi bila
`good + reject > remaining`; validasi `finite && >= 0` untuk reject.

### C5. Keluar dari layar issue menghapus seluruh sesi scan tanpa konfirmasi
`guided_issue_screen.dart:56-73` · tidak ada `PopScope` di `lib/`

Baris scan hanya hidup di `issueProvider`. Masuk ulang menjalankan `load()` →
`state = IssueState(loading: true)` (`issue_provider.dart:172`), jadi semua baris
hilang. Tidak ada `PopScope`/`WillPopScope` sama sekali, jadi panah back default
langsung pop tanpa dialog.

**Bukti:** `grep -rn "PopScope" lib/` → 0 hasil.
**Dampak:** operator sudah menarik 20 tag fisik, kembali ke launcher untuk cek
sesuatu, semua scan hilang dan harus diulang.
**Perbaikan:** bungkus `Scaffold` dengan `PopScope(canPop: lines.isEmpty &&
pendingRelease == null)` + dialog "N tag belum direlease — buang?", dan simpan
baris ke local store berkunci WO agar bisa dipulihkan.

### C6. "Selesai (Sisa Menyusul)" membuang scan yang belum disubmit
`guided_issue_screen.dart:719-728`

`_finishDeferRemaining` hanya `_refreshLists()` + `context.go('/warehouse-home')`.
Tombol muncul tepat ketika `_hasAnyIssued` — yaitu saat operator sudah merilis
item 1 dan masih memegang scan item 2-3.

**Dampak:** label menjanjikan sisa menyusul, padahal baris itu dibuang saat
`load()` berikutnya. Bon kurang, produksi melaporkan material hilang padahal
fisiknya ada di troli.
**Perbaikan:** bila `state.lines.isNotEmpty`, tampilkan konfirmasi ("X tag belum
direlease akan hilang") dan tawarkan `submit()` dulu; tombol lama hanya muncul
saat `lines.isEmpty`.

### C7. Release gagal membuat layar WO berikutnya menampilkan data WO lama
`issue_provider.dart:168` · `guided_issue_screen.dart:59-72,612-618`

`load()` diawali `if (state.pendingRelease != null) return;` sehingga
`load(workOrderId)` untuk WO **berbeda** tidak melakukan apa pun; `state.context`
tetap milik WO lama. `_stageButton` lalu hanya mengembalikan tombol
"retryPreviousRelease" di semua stage, yang memanggil `retryPendingRelease()` →
memposting attempt beku untuk WO A.

**Dampak:** operator memilih WO-B, layar menampilkan material WO-A, dan
satu-satunya tombol di layar merilis material untuk **WO A**. `reset()` juga
diblokir (`:432-438`), jadi satu-satunya jalan keluar adalah mematikan paksa APK.
**Perbaikan:** kunci state per WO — `NotifierProvider.family<…, int>` atau simpan
`workOrderId` di `IssueState` dan bandingkan di `load()`. Attempt yang belum
selesai ditampilkan sebagai kartu eksplisit ("WO-A release belum terkonfirmasi —
Retry / Buang"), bukan merebut layar WO lain.

### C8. Daftar penerimaan mesin selalu kosong saat dibuka
`machine_receipt_list.dart:25,53-66` · `production_receipt_provider.dart:116-136`

`ReceiptListNotifier.build()` mengembalikan `const ReceiptListState()` (loading
`false`, list kosong). Tidak ada yang memanggil `load()` — hanya tombol refresh
dan pull-to-refresh. `MachineReceiptListScreen` adalah `ConsumerWidget`, jadi
tidak ada `initState`.

**Bukti:** dikonfirmasi — satu-satunya pemanggil `load()` ada di `:45` dan `:69`
(keduanya aksi manual). `state.error != null` juga jatuh ke cabang kosong, jadi
timeout tampil sebagai "belum ada penerimaan".
**Dampak:** operator selesai konfirmasi, membuka daftar, melihat "tidak ada
penerimaan", menyimpulkan scan gagal → scan ulang atau jalan ke kantor.
**Perbaikan:** jadikan `family` berkunci `(machineId, date)` dan picu `load()`
dari `build` dengan state awal `loading: true`; cabang `error` sebelum cabang
kosong dengan tombol retry.

---

## 3. Required

### Issue / release

| # | Lokasi | Temuan | Perbaikan |
| --- | --- | --- | --- |
| R1 | `guided_issue_screen.dart:496-521,683-702,934-947` | "LAPORKAN SHORTAGE" tanpa scan = jalan buntu: `_confirmShortage` set stage 4, `submit()` langsung `false` saat `lines.isEmpty` tanpa set `error`, jadi operator dapat `errorServer` dan hanya bisa BATAL. Padahal shortage justru kasus tanpa material. | Gate tombol stage 4 pada `lines.isNotEmpty` + pesan spesifik, atau kirim payload shortage-only; bedakan "tidak ada yang disubmit" dari kegagalan server. |
| R2 | `issue_bundle_screen.dart:75` | Qty manual `1.500` (ribuan gaya id) di-parse `1.5` → selisih 1000×. Input formatter mengizinkan `[0-9.,]` dan keyboard numerik menawarkan `.`. | Satu helper `parseQty()` (`,` → `.`, lalu buang titik ribuan bila polanya `^\d{1,3}(\.\d{3})+$`), pakai di `_manualQty` dan `issue_screen.dart:700`; tampilkan hasil parse live. |
| R3 | `issue_screen.dart` (785 baris) | Layar mati di produksi (hanya diimpor 6 file test). Logika `_submit`/`_scan` menyimpang dari alur hidup. Test pending-release & golden menguji layar yang tidak pernah dilihat operator. | Hapus, lalu pindahkan kasus `pending_release_test.dart` + entri golden/a11y ke `GuidedIssueScreen`. |
| R4 | `guided_issue_screen.dart:878-882` | Cabang `showBundleScreen == false` tidak pernah terjadi di aplikasi (default `true`, router tidak mengirim flag), tapi justru cabang itulah yang dipakai kedua suite widget. Jalur nyata (issuePlan → bundle screen → `copyWithQty`) tidak punya widget test. | Hapus flag dan cabangnya; mount kedua test dengan bundle screen + fake `issuePlan`. |
| R5 | `guided_issue_screen.dart:44,127-141,143-174,619-624` | Stage 0/1 tidak terjangkau (`_stage` hanya 2/3/4). `StepHeader(currentStep: _stage - 1)` mulai di indeks 1, dan `step_header.dart:40` menandai node 0 `isDone` → langkah "WO" selalu bertanda centang padahal tidak pernah dilihat. | Hapus `_stageWo`/`case 0`/`case 1`, ubah `_stage` jadi enum 3 langkah, kirim `_stage.index`. |
| R6 | `guided_issue_screen.dart:380-387` vs `:845` | "Bersihkan filter" hanya null-kan `_rackCode`; `setLocation()` sudah menulis `location_code` ke setiap release berikutnya, dan rak tercatat itu tidak pernah ditampilkan. | Panggil `clearLocation()` saat filter cocok dengan `state.location`, dan tampilkan rak tercatat di sebelah chip filter. |
| R7 | `guided_issue_screen.dart:216` vs `:730-731` | "Release langsung (Ada WIP)" aktif saat ada attempt pending, tapi handler langsung `return` tanpa toast (dead button). | `onPressed: state.isLocked ? null : _releaseWithWip`. |
| R8 | `guided_issue_screen.dart:697,934-947` | Double-tap "YA, RELEASE" memunculkan `errorServer` padahal request pertama sedang jalan (`submit()` `false` saat `isBusy` tanpa set `error`). | Guard `if (ref.read(issueProvider).isBusy) return;` di `_submit`/`_releaseMaterial`/`_releaseWithWip`; toast hanya bila `error != null`. |
| R9 | `guided_issue_screen.dart:435` | Chip "Disarankan" bisa hilang: `identical(tag, tags.first)` dibandingkan dengan list **tak terfilter**. | Hitung dari `filtered.firstOrNull`, bandingkan `(partId, tag)`. |
| R10 | `guided_issue_screen.dart:176` | Tidak ada indikator offline, padahal tiap scan butuh dua round-trip. `isOfflineProvider` hanya dipakai layar mati. | `ref.watch(isOfflineProvider)` + strip peringatan di atas `StepHeader`, nonaktifkan scan saat offline. |
| R11 | `guided_issue_screen.dart:94-99` | Konteks release hanya bisa dimuat ulang saat error keras; tidak ada refresh. | `IconButton(Icons.refresh)` di AppBar (nonaktif saat `isLocked`). |
| R12 | `issue_bundle_screen.dart` (seluruh file) | 100% hardcoded Indonesia, bahkan tidak mengimpor `AppLocalizations`. Termasuk layar keputusan berapa material keluar gudang. | Tambah key (`bundlePickTitle`, `bundleAll`, `bundleClear`, `manualQtyLabel`, `qtyWo`, `stockAtMachine`, `netRequirement`, `fromPacking`, `remainingNeed`, …) ke 3 katalog. |
| R13 | `guided_issue_screen.dart` (11 string) + `_ShortagePanel` | Hardcoded Indonesia di alur hidup; `_ShortagePanel` `StatelessWidget` tanpa akses `AppLocalizations` — jalur shortage mustahil diterjemahkan. | Pakai `AppLocalizations.of(context)` di `_ShortagePanel.build`; pakai `cancel`/`done` yang sudah ada. |
| R14 | `issue_provider.dart:450` | `issueProvider` tidak pernah di-dispose; `reset()` menolak clear saat `pendingRelease != null`. | `autoDispose` + persist attempt di luar provider, atau `family` per WO; tambah `ref.onDispose`. |
| R15 | `issue_bundle_screen.dart:77` + `issue_plan.dart:178` | Validasi bundle memakai net requirement **per tag**, sedangkan `addScan` memakai cap kumulatif (`required + remaining`) → tag kedua ditolak `overRequired` setelah konfirmasi. | Kirim `remainingFor(item)` ke `IssueBundleScreen`, pakai `min(netRequirement, remaining)`. |
| R16 | `guided_issue_screen.dart:885-893` | Tag milik item lain tidak memindahkan layar ke item itu → operator mengira scan gagal, scan ulang, kena `alreadyScanned`. | `setState(() => _materialIndex = items.indexOf(target))` setelah `addScan` sukses. |

### Loading

| # | Lokasi | Temuan | Perbaikan |
| --- | --- | --- | --- |
| R17 | `loading_scan_screen.dart` (`removeScan` 0 pemanggil) | Tidak ada undo untuk scan salah. `removeScan` ada di repository tapi tidak dipakai; server punya `DELETE /deliveries/{id}/scans/{scan}`. Setelah `moveToPreparation` semua scan ditolak. | Tampilkan tag terpindai + aksi hapus (konfirmasi); di `_askQty` jangan pre-fill sisa penuh. |
| R18 | `loading_scan_screen.dart:120-124` | `_resolveRow` mengembalikan baris **pertama** yang cocok tanpa bertanya; hanya kasus "tanpa part_id" yang masuk `_pickRow`. Tag bisa dikreditkan ke seq/customer salah. | Kumpulkan semua kecocokan → `matches.length == 1 ? matches.first : await _pickRow(matches)`. |
| R19 | `loading_scan_screen.dart:486-494` + `loading_context.dart:37-40` + `DeliveryService.php:670` | Layar loading tidak pernah menampilkan **Model**; payload hanya `part:id,part_number,part_name`. Melanggar aturan Part No/Name + Model. | Tambah `model` ke select server, `LoadingItem.model`, render di `_PlanRow` dan `_pickRow`. |
| R20 | `loading_scan_screen.dart:256` + `:108,262` | `'Troli ${code} OK/NG'` hardcoded; `_message = e.message` menampilkan pesan server mentah, dan grup middleware `api` tidak mendaftarkan `SetLocale` (hanya `web`) → semua error loading berbahasa Indonesia. | Key `oqcTrolleyResult`; kirim `Accept-Language` dari `ApiClient.onRequest` + daftarkan `SetLocale` di grup `api`. |
| R21 | `loading_scan_screen.dart:278` + `loading_list_screen.dart:26` + `warehouse_home_screen.dart:68` | Error state tanpa retry; layar scan tidak punya refresh dan menyimpan `_context` lokal sehingga tidak pernah invalidate provider. | Pakai pola `_ErrorView` + retry `ref.invalidate(loadingContextProvider(id))`; bungkus body dengan `RefreshIndicator`. |
| R22 | `loading_scan_screen.dart:521,526,534,602` + `loading_list_screen.dart:186` + `warehouse_home_screen.dart:298` | Teks keputusan di bawah lantai 12pt: "Sisa" 11, chip status 11, label `_Metric` **10**, chip "Qty/Issued/Sisa" **10**. | Pakai `AppTheme.textLabel` (12) untuk nilai keputusan; 11 hanya untuk metadata, dan pakai konstanta `textCaption`. |
| R23 | `loading_scan_screen.dart:517-540` + `warehouse_home_screen.dart:238-247` | Tiga teks/chip dalam `Row` tanpa `Flexible`/`Wrap` → overflow di 360dp dengan label en/ko. | Ganti ke `Wrap(spacing: 12, runSpacing: 4)`. |
| R24 | `material_hari_ini_screen.dart:202-224` | Tab D / D+1 tinggi 38dp (`InkWell` + `Container(height: 38)`), di bawah minimum 48dp. | `height: 48` atau `ConstrainedBox(minHeight: 48)`. |
| R25 | `issue/scan_screen.dart:34-41,71-85` | Tidak ada input kode manual saat label rusak; `errorBuilder` tanpa aksi; `MobileScanner` tanpa `formats:` → barcode karton (EAN) bisa diterima dan ditolak server dengan pesan membingungkan. | Tambah "ketik kode manual", retry + hint izin di `errorBuilder`, `formats: [BarcodeFormat.qrCode]`. |

### Warehouse

| # | Lokasi | Temuan | Perbaikan |
| --- | --- | --- | --- |
| R26 | `warehouse_home_screen.dart:234` | Kolom Model **selalu kosong**: membaca `wo['model']`, padahal API mengirim `part.model` (`MaterialIssueApiController.php:119`). Tiap kartu tampil `AAN30056405 · ` dengan separator menggantung. | `part['model'] ?? '—'`. |
| R27 | `warehouse_home_screen.dart:137-148` | "Sedang Diproses" dan "Selesai Hari Ini" di-hardcode kosong; query `status: in_progress` justru dimasukkan ke `pending:` → WO yang sedang jalan ditandai merah "Perlu Diproses". Tidak ada `RefreshIndicator`. | Pisah berdasarkan `wo['status']`; hapus TODO; bungkus `ListView` dengan `RefreshIndicator`. |
| R28 | `warehouse_home_screen.dart` (17 situs) | Seluruh layar berbahasa Indonesia, termasuk `_todayFormatted()` yang menyusun array `days`/`months` sendiri → tanggal selalu Indonesia. | Tambah key + `MaterialLocalizations.of(context).formatMediumDate(now)` atau `DateFormat` ber-locale. |

### Production

| # | Lokasi | Temuan | Perbaikan |
| --- | --- | --- | --- |
| R29 | `production_session_screen.dart:383,452-455` | Tombol Save mengabaikan `period.canReport`; saat `reportablePeriods` kosong `_selectedPeriod` jatuh ke `periods.first` dan tombol tetap aktif → 422 setelah 30 s. | Tambah `|| !period.canReport` + early return dengan toast. |
| R30 | `production_session_screen.dart:269-271,305-308` | Ganti shift/part tidak mengosongkan qty yang sudah diketik → angka part A tercatat ke part B / shift lain. | Kosongkan kedua controller di `onChanged`. |
| R31 | `issue_repository.dart:158-176` | `reportResultRaw` tidak mengirim `result_date`; shift 3 (23:30–07:30) yang dilaporkan 00:45 dicap tanggal berikutnya oleh server (`ProductionResultService.php:70,95`). | Kirim `session_date` dari `ProductionSessionService::context` (sudah dihitung di `:103-105`). |
| R32 | `production_session_screen.dart:511-514` | Bottom sheet downtime bisa ditutup swipe/scrim; catatan + posisi wheel hilang tanpa konfirmasi. | `isDismissible: false, enableDrag: false` + tombol tutup yang konfirmasi bila `_dirty`. |
| R33 | `production_session_screen.dart:416,729-735` | Hapus downtime satu ketukan tanpa konfirmasi/undo, ikon 18px di dalam area scroll. | Pakai pola `AlertDialog` yang sudah ada di `_switchOperator` (`:69-88`). |
| R34 | `production_session_screen.dart:440-446` | `_selectedPart` diam-diam jatuh ke `parts.first` bila part terpilih hilang setelah invalidate → qty dikirim dengan `partId`/`workOrderId` part lain. | Reset `_partId` + toast, atau blokir submit sampai operator memilih ulang. |
| R35 | `production_history_screen.dart:90-99` | `resolveMachine` tanpa `mounted` guard dan tanpa `catch` → `setState` setelah dispose; kode salah ketik tidak memberi umpan balik apa pun. | `try/catch`, `if (!mounted) return;`, toast; ganti text field dengan `ScanScreen(target: machine)`. |
| R36 | `step_entry_dialog.dart:60,92-102` | Pre-fill qty = `target_qty` **seluruh WO** (mengabaikan `produced_qty`) dan tanpa guard in-flight → satu tap Save melaporkan seluruh WO. | Pre-fill `max(0, target − produced)`; tambah `_saving`. |
| R37 | `step_entry_dialog.dart:126-129` | `BuildContext` dipakai setelah `await` (temuan analyzer `use_build_context_synchronously`). | `if (!mounted) return;` setelah `showDialog`. |

### Lintas modul

| # | Lokasi | Temuan | Perbaikan |
| --- | --- | --- | --- |
| R38 | `app_localizations.dart` | Key `scanTag` dan `saving` **tidak ada di ketiga katalog** → `t()` mengembalikan key mentah. Tombol utama konfirmasi penerimaan berbunyi **"scanTag"**, tombol Save berbunyi **"saving"** saat submit. | Tambah kedua key ke id/en/ko. |
| R39 | repo mobile | Tidak ada guard paritas katalog seperti `scripts/check-i18n.mjs` di web — inilah sebab R38 lolos. | Port pemeriksa sederhana (key dipakai vs didefinisikan, 3 locale seimbang) ke CI/pre-commit. |
| R40 | 15 file | 56 string hardcoded Indonesia: `warehouse_home` 15 (+1 di loading), `production_hub` 6, `guided_issue` 11, `issue_bundle` 4, `production_history` 4, `step_entry_dialog` 3, repo/auth 5, lain-lain. | Migrasi ke katalog; prioritaskan layar utama per peran. |
| R41 | 14 dari 17 layar | Tidak ada banner offline meski `isOfflineProvider` sudah ada. Deteksi juga cacat: `orElse: () => false` dan `Connectivity().onConnectivityChanged` tidak memancarkan nilai awal → banner tidak muncul sampai ada event perubahan. | Seed nilai awal (`Connectivity().checkConnectivity()`), lalu render strip di semua layar pemanggil API; nonaktifkan tombol kirim saat offline. |
| R42 | 12 layar | Nol rujukan test: `role_picker`, `loading_list`, `loading_scan`, `production_history`, `production_hub`, `production_result`, `step_entry_dialog`, `machine_receipt_list`, `result_entry_screen`, `splash`, `warehouse_home`, `wip_board`. | Tambah widget test minimal per layar (empty/error/retry). |
| R43 | `models/auth_user.dart` | Hanya `id`/`name`/`email` — tanpa role/permission, sehingga UI tidak bisa membatasi menu. `RolePickerScreen` adalah percabangan 2 arah hardcoded. | Kirim `roles`/`permissions` di `GET /api/user`, turunkan menu dari situ. |

---

## 4. Optional

1. `material_board_screen.dart:12` dan `material_hari_ini_screen.dart:12` — `materialBoardProvider` dideklarasikan **dua kali** → dua cache, `invalidate` di satu layar tidak menyegarkan layar lain.
2. `_ErrorView` ×4 salinan byte-identik + `_Metric`/`_MaterialCard`/`_StatusChip` duplikat dengan tipografi berbeda → promosikan ke `lib/Components/`.
3. `loading_scan_screen.dart:498-504` — chip hijau "OK" dipakai untuk "baris selesai dipindai", padahal "OK" di layar yang sama berarti lolos OQC.
4. `loading_scan_screen.dart:36-76` — `_busy` baru diset di `_send`, tidak mencakup fase kamera/dialog.
5. `app_config.dart:26` — `AppConfig.scanCooldown` (1200 ms) **tidak dibaca siapa pun**; debounce yang didokumentasikan tidak ada.
6. `loading_scan_screen.dart:69-76` — label troli diterima tanpa konfirmasi dan tanpa qty.
7. `keluar_material_screen.dart:137-147` + `material_hari_ini_screen.dart:131-141` — empty state menyamakan "tidak ada data" dengan "tidak ada hasil filter".
8. `loading_list_screen.dart:145-170` — `status`/`qcStatus`/`deliveryDate` diparse tapi tidak dirender; operator tidak bisa membedakan `draft` (masih bisa scan) dari `picked`.
9. `warehouse_home_screen.dart:14-19` — `List<Map<String, dynamic>>` di UI layer, tanpa model bertipe; inilah cara R26 lolos.
10. `AppTabBar.dart:21,35` — `Colors.white` di luar tema; tab bar tidak memuat `/warehouse-home` sehingga dari tab Material tidak ada jalan kembali ke hub.
11. `material_details_screen.dart:73-75` — badge stok dihitung ulang per kartu WO dari total material-wide.
12. `loading_context.dart:11-13,106,164,198` — `TrolleyScan.checked`, `truckNo`, `driverName`, `deliveryDate`, `ScanResult.isTrolley` tidak punya pembaca; `truckNo`/`driverName` justru yang dibutuhkan operator untuk memastikan truk benar.
13. `production_hub_screen.dart` — komentar menyebut "Empat menu … Riwayat" tapi hanya 3 tile; `/production-history` **tidak bisa dijangkau dari UI**.
14. `production_hub_screen.dart:62` — satu-satunya hex mentah `0xFFD6EAF5`; tidak ada token padanannya.
15. `wip_board_screen.dart:30,37,45` — `Text('$e')` menampilkan `ApiException(500): …`; empty state hardcoded; `onRefresh` tidak menunggu data baru.
16. `production_result_screen.dart:27-53` — alarm dihitung dari jam perangkat dan grid 1 jam, bukan `periods[*].end` dari server (periode istirahat 2 jam).
17. `confirm_screen.dart:370-383` — tombol "lihat daftar" aktif dengan `machineId: 0`; setelah sukses machine direset sehingga harus scan ulang tiap batch.
18. `production_receipt_repository.dart:88-96` — melempar pesan Indonesia hardcoded, membuang pesan server yang sudah terlokalisasi.
19. `production_session_screen.dart:673-675` — badge downtime dibentuk dengan `l.t('categoryDowntime').split(' ').first` → rusak bila urutan kata diterjemahkan.
20. `step_entry_dialog.dart:97` — `ref.watch` di dalam callback async.
21. `production_session_screen.dart:844` — error downtime menampilkan prefix `ApiException(422)`.
22. `issue_plan.dart:52,151` — `IssuePacking.shortLabel`, `IssuePlan.checkedBundles` tidak dipakai.
23. `receiptListProvider` bukan `family` → baris mesin A bertahan di layar mesin B.

---

## 5. Nit

- `guided_issue_screen.dart:178` — `ctx.items[_materialIndex]` tanpa guard batas (sedangkan `:599` punya).
- `guided_issue_screen.dart:891` — `l.t(error ?? 'tagNotNeeded')`, cabang `??` tidak terjangkau.
- `issue_bundle_screen.dart:41-49,916` — back dari bundle screen membuang scan tanpa umpan balik.
- `guided_issue_screen.dart:736-739` — toast sukses langsung digantikan `context.go`.
- `guided_issue_screen.dart:168` — `'Butuh ${item.required}'` mencetak `16000.0`; tiga konvensi format angka di satu file.
- `issue_bundle_screen.dart:321-322` — baris "Kebutuhan bersih" ditentukan dengan membandingkan **string label** → hilang penekanannya setelah diterjemahkan.
- `issue_provider.dart:419` — `409` diklasifikasikan sebagai penolakan pasti; perlu konfirmasi ke backend apakah 409 bisa terjadi setelah commit.
- `issue_screen.dart:287` — `updateQty` mengubah `0` menjadi `0.0001`.
- `guided_issue_screen.dart:308,316` — prefiks urutan `'1. '`/`'2. '` menempel di string label.
- `loading_scan_screen.dart:87-113` — `_send` hanya menangkap `ApiException`; kegagalan parse lolos dan meninggalkan spinner tanpa pesan.
- `loading_scan_screen.dart:245` — `_setQc` tidak membersihkan `_message` lama.
- `warehouse_home_screen.dart:240-245` — chip campur bahasa (`Qty:` / `Sisa:` / `Issued:`).
- `warehouse_home_screen.dart:179-182` — "Lihat semua" menuju `/work-orders` yang sama dari tiga seksi.
- `material_board_screen.dart:74` — pencarian hanya saat submit, sedangkan dua layar lain memfilter tiap ketikan.
- `material_board_screen.dart:280` — komentar menyebut 44dp "aman untuk sarung tangan"; 44 < 48 dan hanya tercapai lewat `tapTargetSize` default.
- `material_board_screen.dart:60-63` — satu-satunya logout ada di app bar papan material.
- `scan_screen.dart:60-63` — `toggleTorch()` tanpa `try/catch`.
- `machine_receipt_list.dart:74` — `(_, __)` (`unnecessary_underscores`, salah satu dari 5 info analyzer).
- `wip_board_screen.dart:99` — `qty.toStringAsFixed(0)` menampilkan 12.5 sebagai `13`.
- `wip_board_screen.dart:109` — `'${tags.length} tag'` hardcoded.
- `step_entry_dialog.dart:11` — halaman penuh didaftarkan sebagai `/production-result/:id` tanpa tautan masuk.
- `README.md:32` — menyebut base URL `https://nooneasku.online/api`, sedangkan `app_config.dart:10` default `https://vps.hertech.xyz/api`.
- `production_history_screen.dart:148` — `part_number · part_name` tanpa Model, padahal `machine_receipt_list.dart:115-123` sudah memuatnya.
- `intl` dan `cupertino_icons` dideklarasikan di `pubspec.yaml` tapi **tidak pernah diimpor** di `lib/`.

---

## 6. Yang sudah baik (jangan diubah tanpa alasan)

- `token_store.dart` memakai `FlutterSecureStorage` untuk token **dan** locale.
- `api_client.dart`: Bearer + `X-Device-Name`, 401 → `onUnauthorized`, `validateStatus` hanya 2xx.
- Idempotency alur release: `_sendAttempt` menyimpan attempt + key pada network error/5xx/408, `retryPendingRelease()` memposting payload beku yang identik byte-per-byte; key hanya dirotasi setelah penolakan pasti atau sukses. Ini pola yang benar dan harus disalin ke hasil produksi.
- Server-side dedupe scan (`DeliveryService::recordScan:503-515`) mencegah data ganda walau request diulang.
- `ScanScreen`: `DetectionSpeed.noDuplicates` + latch `_handled` + pop langsung → satu label satu pop per sesi kamera; controller di-dispose (`:28-32`).
- Katalog i18n seimbang sempurna (275×3) dan 231 key benar-benar dipakai.
- `dist/` (4 APK, 71 MB) benar-benar di-gitignore; `android/key.properties` punya guard signing dengan override `REQUIRE_RELEASE_SIGNING`.
- `AppTheme.textCaption`/`textLabel` sudah didefinisikan sebagai ambang keputusan — masalahnya hanya kedisiplinan pemakaian.
- Nol `print`/`debugPrint`, nol blok kode ter-comment.

---

## 7. Urutan perbaikan yang diusulkan

Urutan ini dipilih berdasarkan "kerusakan data dulu, blokir operasi kedua, kosmetik terakhir".

| Gelombang | Isi | Alasan |
| --- | --- | --- |
| **1 (server + APK, wajib sebelum trial)** | C1, C2, C3, C4 | Keempatnya menghasilkan stok/angka salah atau memblokir pengiriman, dan tidak bisa dibatalkan dari APK. C1/C2 butuh perubahan server; C3/C4 bisa APK dulu. |
| **2 (APK, kehilangan data operator)** | C5, C6, C7, C8 | Scan hilang / WO salah / daftar bohong. Perbaikannya lokal di APK, tidak menunggu server. |
| **3 (i18n + aksesibilitas)** | R38, R39, R40, R42, R43, R20, R28, R12, R13 | Operator en/ko memakai tombol ber-key mentah dan layar berbahasa Indonesia. R39 (guard paritas) mencegah kambuh. |
| **4 (sisanya)** | R1–R37, R41, Optional | Perbaikan perilaku dan konsistensi. |

---

## 8. Keputusan yang menunggu

1. **Gelombang 1 boleh menyentuh server?** C1 (kontrak `scanned_qty` per baris) dan C2 (`pass-qc`/`reject-qc` di API) tidak bisa diselesaikan hanya dari APK. Alternatif tanpa server: C1 diperbaiki di sisi klien dengan pengelompokan per `(partId, uom)` — lebih rapuh; C2 tetap butuh endpoint.
2. **C3/C4 tanpa menunggu server?** Bisa: idempotency key + batas qty di APK sekarang, unique index di DB menyusul.
3. **`issue_screen.dart` dihapus?** (R3) Menghapus 785 baris + memindahkan 4 file test. Ini keputusan yang berisiko menyentuh test, jadi perlu persetujuan.
4. **Ruang lingkup perbaikan mobile:** semua gelombang, atau gelombang 1–2 dulu lalu evaluasi?

## 9. Temuan web yang masih terbuka (dari audit sebelumnya, belum diputuskan)

- **A1** `qty_good > 0` di `ProductionResultService.php:39-41` — tolak hasil yang seluruhnya reject.
- **A2** permission `resolveTag` (`stock.issue`) tidak selaras dengan release.
- **A3** `resolveTag` mengembalikan `qty` mentah, bukan qty tersedia.
- **A4** tiga definisi "sisa qty" yang berbeda.
- **A5** payload issue tanpa Model (sama dengan R19/R26 di mobile).
- **B1** release dari web membooking FIFO tanpa membuat `material_issues`; **B2** APK jadi tidak bisa scan setelah web release; **B3** hasil dari web tidak pernah dapat label; **B4** receive-material hanya ada di APK; **B5** koreksi hanya via web padahal akun `apk_only` tidak bisa login; **B6** `machine_id` per grup vs receipt per mesin.
- Gross weight: `Receive/Form` sudah bersih; residu ada di `Arrival/Form.vue:840` dan `LocalPo/Form.vue:359` — belum diputuskan apakah ikut dihapus.
