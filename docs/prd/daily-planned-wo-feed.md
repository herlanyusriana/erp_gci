# PRD: Daily Planned WO Feed (Material Tracker)

Module id: `daily-planned-wo-feed`
Inisiatif: `docs/prd/material-tracker-clarity.md`

## Problem Statement

Operator produksi membuka APK Material Tracker dan melihat daftar WO yang tidak
sesuai kenyataan hari itu. WO yang muncul adalah WO berstatus `planned` yang
belum tentu dijadwalkan, sementara angka yang tertera adalah qty WO penuh, bukan
qty yang harus dikerjakan hari itu. Akibatnya operator harus menebak WO mana yang
benar-benar menjadi tugasnya sekarang, dan bisa keliru menyiapkan material untuk
pekerjaan yang jadwalnya masih besok atau lusa.

Lebih buruk lagi, ketika planner belum mengisi jadwal sama sekali, WO yang sudah
release tidak muncul di mana pun tanpa penjelasan — operator tidak tahu apakah
pekerjaannya belum dijadwalkan atau memang hilang dari sistem.

## Solution

APK hanya menampilkan WO yang **dijadwalkan pada tanggal berjalan**, dengan angka
**qty hari itu saja**. Jadwal dibaca dari Production Plan yang sudah diatur
planner: `target_d` untuk tanggal rencana, `target_d1` untuk besoknya, dan
`target_d2` untuk lusa. Target kosong atau nol berarti WO itu bukan pekerjaan
hari ini.

Setiap baris WO menampilkan dua angka yang mudah dibedakan: **Rencana D** (qty
hari itu) dan **Sudah di-issue** (material yang sudah keluar), sehingga operator
langsung tahu berapa lagi yang perlu discan. Kalau ada WO yang sudah release
tetapi belum dijadwalkan, APK menampilkan penghitung kecil agar operator bisa
memberi tahu planner alih-alih menebak-nebak.

## User Stories

1. Sebagai operator produksi, saya ingin hanya melihat WO yang dijadwalkan hari
   ini, supaya saya tidak salah mengerjakan pekerjaan yang jadwalnya masih besok
   atau lusa.
2. Sebagai operator produksi, saya ingin melihat qty hari ini, bukan qty WO
   penuh, supaya saya menyiapkan material sesuai kebutuhan hari itu.
3. Sebagai operator produksi, saya ingin melihat berapa material yang sudah
   di-issue, supaya saya tahu sisa yang masih harus discan.
4. Sebagai operator produksi, saya ingin WO yang sudah selesai materialnya
   hilang dari daftar, supaya daftar hanya berisi pekerjaan yang benar-benar
   tersisa.
5. Sebagai operator produksi, saya ingin tahu berapa WO yang menunggu
   dijadwalkan, supaya saya bisa melapor ke planner ketika pekerjaan saya tidak
   muncul.
6. Sebagai operator produksi, saya ingin melihat WO yang sudah berjalan
   (`in_progress`) selain yang belum release, supaya saya bisa menambah material
   untuk WO yang sudah mulai dikerjakan.
7. Sebagai operator produksi, saya ingin daftar diurutkan mengikuti alur routing,
   supaya urutannya sama dengan papan Production Plan di web dan mudah dicari.
8. Sebagai operator produksi, saya ingin seluruh WO hari itu tampil tanpa
   terpotong, supaya tidak ada pekerjaan yang hilang dari pandangan.
9. Sebagai operator produksi, saya ingin mencari WO berdasarkan nomor WO maupun
   nomor part FG, supaya saya cepat menemukan pekerjaan yang saya maksud.
10. Sebagai operator produksi, saya ingin daftar memakai tanggal pabrik (WIB),
    supaya pekerjaan pagi tidak salah tampil di tanggal sebelumnya.
11. Sebagai operator produksi, saya ingin melihat tanggal jadwal pada baris WO,
    supaya saya yakin angka itu memang untuk hari ini.
12. Sebagai operator produksi, saya ingin pesan jelas ketika tidak ada pekerjaan
    hari ini, supaya saya tidak mengira aplikasi sedang error.
13. Sebagai planner, saya ingin APK tidak pernah menampilkan WO yang belum saya
    jadwalkan, supaya jadwal di Production Plan benar-benar menjadi satu-satunya
    sumber kebenaran pekerjaan harian.
14. Sebagai planner, saya ingin sisa pekerjaan hari yang belum selesai **tidak**
    terbawa otomatis ke hari berikutnya, supaya saya tetap yang memutuskan
    penjadwalan ulang di papan.
15. Sebagai pengguna dengan permission terbatas, saya ingin penyaringan baru ini
    tidak memberi saya akses tambahan, supaya batas otorisasi yang ada tetap
    utuh.
16. Sebagai operator produksi, saya ingin APK memberi tahu saya saat tidak bisa
    mengambil data, supaya saya bisa mencoba lagi alih-alih menganggap tidak ada
    pekerjaan.

## Objective

Menyelaraskan daftar WO di APK Material Tracker dengan jadwal harian Production
Plan, sehingga operator hanya melihat pekerjaan tanggal berjalan beserta qty
hari itu.

Pengguna: operator produksi pengguna APK Material Tracker.

Ukuran keberhasilan: saat APK dibuka, daftar hanya memuat WO yang punya target
hari itu lebih dari nol, angkanya adalah qty hari itu, dan tidak ada WO
terjadwal yang hilang dari daftar.

## Technical Decisions

### Aturan jadwal

Satu WO di Production Plan dapat memiliki beberapa baris mesin. Baris-baris itu
adalah satu unit pekerjaan per WO, bukan unit terpisah di APK.

| Tanggal APK dibuka | Kolom Production Plan yang dibaca |
|---|---|
| `plan_date` | `target_d` |
| `plan_date` + 1 hari | `target_d1` |
| `plan_date` + 2 hari | `target_d2` |
| di luar rentang itu | tidak tampil |

### Keputusan yang mengikat

- **Zona waktu pabrik menentukan "hari ini".** Server berjalan UTC, sehingga
  tanggal dihitung memakai zona waktu pabrik (WIB). APK **tidak** mengirim
  tanggal dari perangkat; server yang menentukan. Alasannya: jam perangkat bisa
  salah, dan tanggal yang salah akan menyembunyikan pekerjaan secara senyap.
- **Angka D satu WO diambil dari nilai terbesar antar baris mesin**, bukan
  penjumlahan. Penjumlahan salah karena setiap mesin memproses qty yang sama;
  WO qty 100 dengan empat step akan terbaca 400. Nilai terbesar juga tetap aman
  ketika planner hanya mengisi sebagian baris.
- **Status yang tampil: `planned` dan `in_progress`.** WO `planned` adalah
  pekerjaan yang belum release — justru sasaran utama alur issue. WO
  `in_progress` tetap tampil agar material tambahan masih bisa di-issue. WO
  `completed` dan `cancelled` tidak tampil.
- **Dua angka, bukan satu.** Baris WO menampilkan "Rencana D" (target hari itu)
  dan "Sudah di-issue". Angka "sudah di-issue" dihitung dari **dokumen issue
  material**, bukan dari hasil produksi, karena APK ini alat pelacak material.
- **WO dengan material tuntas disembunyikan** dari daftar, mengikuti pola papan
  Production Plan yang menyembunyikan baris tuntas.
- **Penghitung "menunggu dijadwalkan"** dihitung dari WO `in_progress` yang
  sudah punya baris plan tetapi tidak punya jadwal hari itu. Ini jaring pengaman
  agar WO yang sudah release tidak hilang tanpa penjelasan.
- **Sisa D tidak terbawa ke hari berikutnya.** Hari berikutnya membaca kolom
  jadwal berikutnya dari plan yang sama; penjadwalan ulang tetap keputusan
  planner di web.
- **Tidak ada perubahan skema database.** Seluruh data sudah tersedia di
  Production Plan, item WO, dan dokumen issue material.
- **Daftar harian tidak dipotong 20 baris.** Pemotongan lama membuat pekerjaan
  hilang dari pandangan tanpa cara memuat halaman berikutnya di APK.

### Kontrak API

`GET /api/work-orders` diperluas, bukan diganti. Parameter lama tetap berlaku.

Parameter tambahan (opsional, untuk pengujian dan pembukaan tanggal lain):
`date` dalam format `Y-m-d`. Bila tidak dikirim, server memakai tanggal WIB.

Field tambahan per WO pada respons:

```
plan_date    : string|null   // tanggal plan yang dipakai
planned_qty  : float         // qty jadwal hari itu
issued_qty   : float         // material yang sudah di-issue
remaining_qty: float         // planned_qty - issued_qty, minimum 0
```

Field lama (`id`, `wo_no`, `status`, `qty`, `planned_date`, `part`) tetap
dikirim agar tidak memutus klien lama. `qty` tetap berarti qty WO penuh.

`meta` pada respons menambah `waiting_for_plan` (jumlah WO yang menunggu
dijadwalkan) dan `waiting_work_orders` (daftar WO-nya), sehingga APK bisa
menampilkan WO mana saja yang menunggu:

```
meta.waiting_work_orders: [
  { id, wo_no, part: { part_number, part_name } | null }
]
```

Field ini diminta oleh modul `tracker-dashboard`; lihat
`docs/prd/tracker-dashboard.md`.

Urutan hasil: alur routing step pertama, lalu nomor WO. Pencarian `search`
diperluas agar mencocokkan nomor WO dan nomor part FG.

## Project Structure

    erp_gci/                          -> Laravel ERP (API)
    erp_gci/app/Http/Controllers/Api/ -> Endpoint APK
    erp_gci/tests/Feature/Api/        -> Feature test API (prior art)
    erp_gci/docs/prd/                 -> PRD dan capability map

    erp_gci_mobile/                   -> APK Material Tracker (Flutter)
    erp_gci_mobile/lib/models/        -> Model respons API
    erp_gci_mobile/lib/repositories/  -> Pemanggil API
    erp_gci_mobile/lib/features/      -> Layar APK
    erp_gci_mobile/test/              -> Test Flutter

## Commands

    Test API:       php artisan test --compact tests/Feature/Api/MaterialIssueApiTest.php
    Test penuh:     php artisan test --compact
    Lint PHP:       vendor/bin/pint --dirty --format agent
    Build web:      npm run build
    Test APK:       flutter test
    Lint APK:       flutter analyze

## Testing Strategy

Fokus pada perilaku yang teramati lewat respons HTTP dan model Flutter, bukan
detail implementasi.

Feature test API (prior art `tests/Feature/Api/MaterialIssueApiTest.php`)
membuktikan:

- WO dengan target hari itu > 0 muncul; target kosong atau 0 tidak muncul.
- Pembacaan kolom berpindah sesuai tanggal: `target_d`, lalu `target_d1`, lalu
  `target_d2`, dan tidak muncul setelah rentangnya lewat.
- Tanggal dihitung memakai zona waktu pabrik, bukan UTC.
- Angka `planned_qty` memakai nilai terbesar antar baris mesin, bukan jumlah.
- `issued_qty` mengikuti dokumen issue material.
- WO dengan material tuntas tidak muncul di daftar.
- `waiting_for_plan` menghitung WO yang menunggu dijadwalkan.
- Urutan hasil mengikuti alur routing lalu nomor WO.
- Pencarian mencocokkan nomor WO dan nomor part FG.
- WO `completed` dan `cancelled` tidak muncul.
- Otorisasi tetap berlaku untuk pengguna tanpa permission.

Test Flutter membuktikan pemetaan field respons baru ke model dan penyaringan
daftar di layar.

## Boundaries

- **Always:** jalankan `php artisan test --compact tests/Feature/Api/MaterialIssueApiTest.php`
  dan `flutter analyze` sebelum menyatakan selesai; jaga `Gate::authorize` di
  setiap endpoint; validasi parameter `date`; pertahankan field respons lama.
- **Ask first:** mengubah skema database; menambah dependency; mengubah alur
  release atau rumus stok; mengubah papan Production Plan di web.
- **Never:** melemahkan otorisasi `issue`/`viewAny`; mengubah data produksi
  sebagai efek samping penyaringan; menghapus test tanpa persetujuan; memakai
  jam perangkat sebagai penentu tanggal.

## Out of Scope

- Menampilkan kolom D+1 dan D+2 di APK.
- Membawa sisa D yang belum selesai ke hari berikutnya secara otomatis.
- Menampilkan daftar per baris mesin di APK.
- Mengubah cara planner mengisi target di papan Production Plan.
- Mengubah alur release, booking tag, atau rumus stok.
- Menambah endpoint baru khusus APK.
- Notifikasi atau pengingat otomatis ke planner.

## Success Criteria

- [ ] WO dengan jadwal hari itu lebih dari nol muncul di respons `GET /api/work-orders`.
- [ ] WO dengan jadwal kosong atau nol tidak muncul.
- [ ] Kolom jadwal yang dibaca berpindah sesuai tanggal rencana.
- [ ] WO tidak muncul lagi setelah melewati `plan_date` + 2 hari.
- [ ] Tanggal ditentukan server memakai zona waktu pabrik, bukan jam perangkat.
- [ ] `planned_qty` memakai nilai terbesar antar baris mesin, bukan penjumlahan.
- [ ] `issued_qty` mengikuti dokumen issue material.
- [ ] WO dengan material tuntas tidak muncul di daftar.
- [ ] `meta.waiting_for_plan` mengisi jumlah WO yang menunggu dijadwalkan.
- [ ] `meta.waiting_work_orders` memuat daftar WO yang menunggu beserta nomor WO dan part FG.
- [ ] Hasil diurutkan mengikuti alur routing lalu nomor WO.
- [ ] Pencarian mencocokkan nomor WO dan nomor part FG.
- [ ] Daftar hari itu tidak terpotong 20 baris.
- [ ] Field respons lama tetap dikirim.
- [ ] Pengguna tanpa permission tetap ditolak.
- [ ] Feature test API, test penuh, Pint, `flutter test`, dan `flutter analyze` lulus.

## Open Questions

1. **Batas `per_page` daftar harian.** PRD ini memutuskan daftar hari itu tidak
   dipotong. Bila suatu hari jumlah WO jauh melebihi perkiraan, perlu diputuskan
   apakah perlu pemuatan bertahap di APK. Belum diperlukan sekarang.
2. **Ambang toleransi nol.** Perbandingan jadwal memakai nilai lebih dari nol.
   Bila muncul target pecahan sangat kecil akibat pembulatan, perlu diputuskan
   ambang toleransi kecil. Belum diperlukan sekarang.
3. **Nasib WO `completed` yang materialnya belum tuntas.** PRD ini
   menyembunyikannya. Bila ternyata ada kasus nyata material kurang pada WO yang
   sudah selesai, perlu aturan tersendiri.
