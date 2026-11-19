# Task 07: Validasi angka hasil produksi (plafon + hasil reject saja)

**Source:** `tasks/audit-fixes/mobile-audit.md` — C4, dan temuan web A1

**What to build:** Server menolak angka hasil produksi yang mustahil, menerima
laporan yang seluruhnya reject, dan mengirim sisa qty ke APK supaya peringatan
bisa muncul sebelum request.

## Latar

Hari ini `report()` hanya memeriksa `qty_good > 0` dan
`qty_reject >= 0`. `qty_reject` tidak punya batas atas, padahal ia menggerakkan
konsumsi material (`need = child_qty × (qty_good + qty_reject)`). Sebaliknya,
laporan yang seluruhnya reject (good 0, reject > 0) **ditolak** — padahal itu
kondisi nyata saat mesin menghasilkan scrap penuh. Session juga tidak mengirim
target/sisa, sehingga APK tidak bisa menampilkan batas.

## Acceptance criteria

- [x] `ProductionSessionService::context()` mengirim, untuk setiap part:
      `target_qty` (qty WO), `produced_qty` (sum `qty_good` part itu pada WO),
      dan `remaining_qty` (`max(0, target − produced)`).
- [x] `ProductionResultService::report()` menerima `qty_good = 0` **bila
      `qty_reject > 0`** (laporan reject saja); menolak hanya bila keduanya nol.
- [x] `report()` menolak satu laporan dengan `qty_good + qty_reject >
      qty WO` (plafon per laporan) memakai pesan validasi yang menyebut qty WO.
- [x] `qty_reject` wajib finite dan `>= 0` (yang sudah ada dipertahankan).
- [x] Plafon kumulatif `qty_good` yang sudah ada tidak dilonggarkan.
- [x] Test: good 0 + reject 5 → tersimpan; good 0 + reject 0 → 422; reject
      melebihi qty WO → 422; session memuat `remaining_qty` yang turun setelah
      satu laporan.

## Verification

- [x] Tests pass: `ProductionResultTest` 18 passed / 148 assertions, `ProductionSessionApiTest` 13 passed / 62 assertions
- [x] No regressions: `MaterialIssueApiTest` + `ProductionLabelQueueTest` 98 passed / 583 assertions
- [x] Lint: `vendor/bin/pint --dirty --format agent` — passed
- [ ] Manual check: laporkan reject saja dari APK → tersimpan, output good tidak
      bertambah, stok material berkurang sesuai reject

## Blocked by

None — can start immediately. (Task 05 menyentuh file yang sama; kerjakan 05
lebih dulu agar diff tidak bertabrakan.)

## Files likely touched

- `app/Services/ProductionSessionService.php`
- `app/Services/ProductionResultService.php`
- `tests/Feature/ProductionResultTest.php`
- `tests/Feature/Api/ProductionSessionApiTest.php`

## Estimated scope

M — 4 file

## Rollback

Aturan validasi dan field payload dapat dikembalikan tanpa migration. Data yang
sudah tersimpan tidak berubah.

## Notes

- **Plafon kumulatif reject belum diputuskan** (lihat Open Question 1). Yang
  ditegakkan di sini hanya plafon per laporan, cukup untuk mencegah salah ketik
  satu nol. Jangan mengarang aturan bisnis (mis. reject ≤ 10% qty) tanpa
  keputusan.
- `target_qty` = qty WO, bukan qty periode. Karena itu APK hanya menampilkan
  peringatan; server tetap sumber kebenaran.
