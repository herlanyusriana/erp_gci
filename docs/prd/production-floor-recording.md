# PRD: Production Floor Recording (WIP per WO + Periode per Jam)

Module id: `production-floor-recording`
Capability map: `docs/prd/production-floor-recording.md` (dokumen ini)

## Problem Statement

Operator produksi mencatat hasil kerja di lantai, tetapi sistem saat ini tidak
menyediakan cara untuk melaporkan Good/NG per periode jam dengan identitas WIP
yang jelas. WIP yang lahir di mesin 1 harus bisa dikenali dan dipakai di mesin
berikutnya tanpa harus menempel barcode pada setiap unit — karena biaya kertas
dan waktu tempel di lantai terlalu mahal.

Akibatnya, saat ini sisa WIP di mesin tidak tercatat rapi, pemakaian WIP antar
mesin tidak bisa ditelusuri, dan laporan hasil produksi tidak mencerminkan
kapan barang benar-benar dibuat.

## Solution

APK mendapat alur kerja produksi yang berjalan dalam satu sesi per mesin:

1. Operator memilih mesin (scan atau pilih), lalu sistem menampilkan part hasil
   sesuai Production Plan.
2. Operator memilih shift (1/2/3), memilih periode dari dropdown, lalu
   menginput Good serta NG untuk periode itu. Periode yang sudah lewat tetap
   bisa dipilih untuk laporan susulan.
3. Setiap laporan periode menyediakan **satu label** untuk Good dan **satu
   label** untuk NG. Label dicetak dari **Web oleh petugas gudang**, bukan dari
   APK, lalu diantar ke mesin.
4. Identitas WIP mengikuti **nomor WO**, bukan nomor batch terpisah. Beberapa
   periode dalam WO yang sama menambah qty WIP yang sama.
5. Saat mesin berikutnya memakai WIP, sistem memotong stok secara **FIFO
   otomatis**. Jika di mesin itu ada lebih dari satu WO yang menyediakan part
   yang sama, APK meminta konfirmasi WO mana yang dipakai.
6. Jika label belum diantar ke mesin, WIP tetap boleh dipakai, tetapi APK
   menandainya secara visual.

## User Stories

1. Sebagai operator mesin, saya ingin memilih mesin lewat scan atau daftar,
   supaya saya tidak salah mencatat hasil ke mesin lain.
2. Sebagai operator mesin, saya ingin melihat part hasil yang sudah terisi dari
   Production Plan, supaya saya tidak perlu mengetik ulang identitas part.
3. Sebagai operator mesin, saya ingin memilih shift 1/2/3 yang sedang berjalan,
   supaya laporan hasil tercatat pada shift yang benar.
4. Sebagai operator mesin, saya ingin memilih periode dari dropdown lalu
   menginput Good dan NG, supaya hasil kerja tercatat per jam dan laporan
   susulan tetap bisa diisi.
5. Sebagai operator mesin, saya ingin diminta melapor saat periode hampir
   berakhir, supaya saya tidak lupa mencatat.
6. Sebagai petugas gudang, saya ingin melihat daftar laporan periode yang belum
   dicetak labelnya, supaya saya tahu label mana yang harus dicetak.
7. Sebagai petugas gudang, saya ingin mencetak label Good dan NG secara terpisah
   dari Web, supaya barang cacat tidak tercampur dengan barang lolos.
8. Sebagai petugas gudang, saya ingin menandai label sudah diantar, supaya
   status WIP di mesin akurat.
9. Sebagai operator mesin, saya ingin WIP dikenali lewat nomor WO, supaya saya
   tidak perlu membuat nomor batch baru untuk setiap periode.
10. Sebagai operator mesin, saya ingin beberapa periode dalam WO yang sama
    menambah qty WIP yang sama, supaya total WIP per WO selalu bisa dibaca.
11. Sebagai operator mesin, saya ingin sistem memotong WIP secara FIFO otomatis
    saat saya memakai WIP, supaya saya tidak perlu memilih batch secara manual.
12. Sebagai operator mesin, saya ingin dimintai konfirmasi WO ketika ada lebih
    dari satu WO dengan part yang sama di mesin, supaya WIP tidak terpotong dari
    WO yang salah.
13. Sebagai operator mesin, saya ingin melihat peringatan bila label WIP belum
    diantar, supaya saya tahu ada risiko barang belum sampai.
14. Sebagai operator mesin, saya ingin tetap bisa melanjutkan pekerjaan meski
    label belum diantar, supaya produksi tidak berhenti karena urusan
    administratif.
15. Sebagai supervisor, saya ingin melihat WIP per WO beserta qty dan lokasi
    raknya, supaya saya bisa menelusuri asal barang saat ada keluhan kualitas.
16. Sebagai supervisor, saya ingin melihat hasil Good/NG per periode per mesin,
    supaya saya bisa menilai performa mesin per jam, bukan hanya per hari.
17. Sebagai operator mesin, saya ingin memilih periode yang sudah lewat dari
    dropdown dan mengisinya, supaya laporan tidak hilang ketika mesin sempat
    berhenti atau operator lupa melapor.
18. Sebagai pengguna dengan permission terbatas, saya ingin alur ini tidak
    memberi akses tambahan, supaya batas otorisasi tetap utuh.

## Objective

Menyediakan pencatatan produksi di lantai yang mencerminkan kenyataan: hasil
dilaporkan per periode jam, WIP dikenali per WO tanpa barcode per unit, dan
pemakaian WIP antar mesin tercatat tanpa menghambat operator.

Pengguna: operator mesin dan operator gudang pengguna APK Material Tracker.

Ukuran keberhasilan: operator dapat melaporkan Good/NG per jam dalam satu sesi
mesin, setiap laporan menghasilkan label Good dan NG, dan WIP dari mesin
sebelumnya dapat dipakai di mesin berikutnya dengan jejak WO yang jelas.

## Technical Decisions

### Identitas WIP mengikuti WO

- **Satu WO = satu identitas WIP.** Tidak ada tabel batch baru dan tidak ada
  nomor batch per periode. Beberapa laporan periode dalam WO yang sama menambah
  `qty` WIP pada identitas yang sama (kunci: WO + part hasil).
- Konsekuensi: sistem tidak dapat membedakan WIP yang dibuat jam 08:30 dari yang
  dibuat jam 09:30. Ini keputusan yang diterima secara sadar karena biaya
  pelacakan per periode dinilai lebih besar daripada manfaatnya.
- Jejak waktu tetap tersimpan di `production_results` per periode, sehingga
  pertanyaan "kapan WIP ini dibuat" masih bisa dijawab dari laporan periode.

### Label: Good dan NG terpisah

- Setiap laporan periode menghasilkan **dua label opsional**: satu untuk Good,
  satu untuk NG.
- Isi label: nomor WO, part number, qty, jam periode, shift, dan jenis
  (Good/NG). Ditambah QR berisi identitas WO + part + jenis.
- Label **tidak** memuat nomor batch, karena identitas WIP adalah WO.

### Alur cetak: APK melapor → Web mencetak → gudang mengantar

- APK **tidak** mencetak label. APK hanya menyimpan laporan periode.
- Label dicetak dari **Web** oleh petugas gudang, sesuai laporan yang masuk dari
  APK. Web menampilkan daftar laporan yang belum dicetak.
- Setelah dicetak, petugas gudang mengantar label ke mesin.
- **Jeda antara "WIP dilaporkan" dan "label sampai di mesin" adalah keadaan
  normal**, bukan error.

Alasan memindahkan cetak ke Web: printer berada di gudang, dan gudang yang
mengantar label. Satu tempat cetak menghindari kebutuhan printer di tiap mesin
sekaligus memberi gudang kendali atas antrean label.

### WIP tetap tersedia selama label belum diantar

- Stok WIP dianggap tersedia begitu periode dilaporkan, meski label belum
  diantar.
- APK menandai WIP tersebut sebagai **"label belum diantar"** secara visual.
- Produksi **tidak diblokir** oleh status label. Alasan: mesin tidak boleh
  berhenti karena urusan administrasi label.

### Konsumsi WIP: FIFO otomatis dengan konfirmasi saat ambigu

- Saat operator melaporkan hasil pada mesin yang mengonsumsi WIP, sistem memotong
  WIP secara **FIFO** (tertua lebih dulu) tanpa meminta operator memilih.
- **Pengaman:** bila di mesin tersebut terdapat lebih dari satu WO yang
  menyediakan part WIP yang sama, APK menampilkan konfirmasi singkat berisi
  daftar WO kandidat. Operator memilih satu. Bila hanya ada satu kandidat, FIFO
  berjalan tanpa pertanyaan.
- Alasan pengaman: FIFO murni bisa memotong WIP dari WO yang salah ketika
  beberapa WO dengan part sama berada di mesin yang sama.

### Periode pelaporan: dropdown, bukan jam berjalan

- Operator **memilih periode dari dropdown**, bukan mengikuti jam berjalan.
  Pilihan periode diturunkan dari shift yang dipilih (mis. Shift 1:
  07:30–08:30, 08:30–09:30, dst.).
- Alasan: laporan susulan harus bisa diisi kapan saja. Dengan jam berjalan,
  operator yang lupa melapor satu periode kehilangan kemampuan mengisinya.
- Shift: 1 / 2 / 3, mulai 07:30. Shift diturunkan dari jam sistem sebagai nilai
  awal dropdown, tetapi operator dapat mengubahnya.
- Periode yang dipilih tidak boleh melebihi jam berjalan (tidak ada laporan
  untuk periode yang belum terjadi).
- Laporan periode yang diisi setelah periodenya lewat **ditandai sebagai
  susulan**, agar supervisor bisa membedakan laporan tepat waktu dari laporan
  mundur.
- Alarm 5 menit sebelum periode berakhir tetap dipertahankan sebagai **pengingat
  untuk mengisi dropdown**, bukan sebagai penentu periode.

### Lokasi

- Field `Location` pada label dan WIP berarti **lokasi rak penyimpanan WIP**,
  bukan lokasi mesin.
- Mesin sudah memiliki identitas sendiri (nama, kode), sehingga tidak
  diduplikasi ke field lokasi.
- Lokasi bersifat opsional; WIP tanpa lokasi tetap sah.

### Sesi mesin di APK

Urutan input dalam satu sesi:

```
1. Pilih mesin (scan QR mesin atau pilih dari daftar)
2. Part hasil (terisi otomatis dari Production Plan, dapat dikonfirmasi)
3. Shift (1 / 2 / 3)
4. Pilih periode dari dropdown (Good + NG untuk periode itu)
5. Simpan laporan
```

Mesin dan part dipilih lebih dulu agar laporan bisa divalidasi terhadap
Production Plan sebelum disimpan. APK tidak mencetak label; pencetakan dilakukan
di Web oleh petugas gudang.

### Skema data yang terdampak

`production_results` saat ini menyimpan `result_date` (tanggal) dan `shift`.
Pencatatan per jam membutuhkan penanda periode jam pada baris hasil produksi.
Bentuk yang diusulkan (menggantikan atau melengkapi `result_date`):

```
period_start    time     -- awal periode, mis. 08:30
period_end      time     -- akhir periode, mis. 09:30
shift           string   -- "1" | "2" | "3"
is_backfilled   boolean  -- true bila diisi setelah periodenya lewat
label_status    enum     -- pending | printed | delivered | none
```

`label_status` menopang alur cetak di gudang dan peringatan "label belum
diantar" di mesin, tanpa pernah memblokir penyimpanan hasil produksi:

- `pending`   — laporan masuk, label belum dicetak (muncul di antrean Web)
- `printed`   — sudah dicetak petugas gudang, belum diantar
- `delivered` — sudah diantar ke mesin
- `none`      — laporan tanpa label (mis. qty 0 pada sisi itu)

### Kontrak API yang berubah

- Konteks sesi produksi (mesin + part + shift + periode berjalan) untuk APK.
- Simpan laporan periode: Good, NG, shift, period_start, period_end.
- Daftar periode yang tersedia untuk sebuah shift (untuk dropdown APK).
- Daftar laporan periode yang menunggu dicetak (untuk halaman cetak di Web).
- Aksi cetak label di Web: WO, part, jenis (good/ng), qty, periode, shift, lokasi.
- Aksi tandai label terkirim (delivered).
- Daftar kandidat WIP saat konsumsi ambigu (lebih dari satu WO dengan part sama).

## Project Structure

Tidak ada perubahan struktur direktori. Perubahan pada file yang sudah ada:

    app/Http/Controllers/Api/       -> endpoint sesi & laporan periode
    app/Services/                   -> logika periode, FIFO WIP, status label
    database/migrations/            -> kolom periode & label_status
    lib/features/production_result/ -> layar input per periode
    lib/features/result/            -> label & konfirmasi WIP
    resources/views/                -> template cetak label Good/NG

## Commands

    Test (ERP):   php artisan test
    Lint (ERP):   vendor/bin/pint
    Build (ERP):  npm run build
    Test (APK):   flutter test
    Analyze:      flutter analyze
    Build (APK):  flutter build apk --release

## Testing Strategy

Test perilaku eksternal, bukan detail implementasi.

- **Backend (Pest, feature test):** periode dihitung dari jam sistem dengan
  batas shift 07:30; beberapa periode dalam satu WO menambah qty WIP yang sama;
  FIFO memotong WIP tertua; konfirmasi diminta hanya saat ada lebih dari satu WO
  kandidat; laporan periode terlewat tetap diterima dengan penanda; label_status
  tidak pernah memblokir penyimpanan hasil.
- **APK (flutter test):** sesi mesin mengisi part dari plan; shift dapat diubah;
  input Good/NG per periode tersimpan; peringatan "label belum diantar" muncul
  tanpa menonaktifkan tombol lanjut; dialog konfirmasi WO hanya muncul saat
  ambigu.
- **Prior art:** `tests/Feature/ProductionResultTest.php`,
  `tests/Feature/MachineMaterialConsumptionTest.php`,
  `test/issue_tag_suggestion_test.dart`.
- Tidak ada target cakupan angka; setiap perilaku baru wajib punya minimal satu
  test yang gagal sebelum implementasi.

## Boundaries

- **Always:** jalankan test sebelum commit; validasi qty Good/NG di server; catat
  shift dan periode pada setiap laporan; pertahankan jejak audit konsumsi WIP.
- **Ask first:** perubahan skema `production_results`; penambahan dependensi
  cetak; perubahan format label yang sudah dipakai.
- **Never:** memblokir penyimpanan hasil karena status label; menghapus riwayat
  konsumsi WIP; menyimpan laporan tanpa penanda periode.

## Out of Scope

- Barcode atau label per unit WIP (keputusan: terlalu mahal).
- Nomor batch WIP terpisah dari nomor WO.
- Pelacakan WIP per jam sebagai entitas tersendiri.
- Cetak label dari APK (pencetakan hanya di Web oleh petugas gudang).
- Integrasi langsung ke printer tanpa perantara gudang.
- Perhitungan biaya produksi atau OEE.
- Perubahan pada alur release material dari gudang (sudah selesai sebelumnya).

## Success Criteria

- [ ] Operator dapat menyelesaikan satu sesi mesin: pilih mesin, konfirmasi
      part, pilih shift, pilih periode dari dropdown, input Good/NG, simpan.
- [ ] Setiap laporan periode menyimpan shift dan penanda periode jam.
- [ ] Periode dapat dipilih dari dropdown, termasuk periode yang sudah lewat.
- [ ] Laporan yang diisi setelah periodenya lewat ditandai sebagai susulan.
- [ ] Dropdown tidak menawarkan periode yang belum terjadi.
- [ ] Beberapa periode dalam satu WO menambah qty WIP pada identitas WO yang
      sama, bukan membuat batch baru.
- [ ] Konsumsi WIP memotong FIFO tanpa pertanyaan saat hanya ada satu WO
      kandidat.
- [ ] Dialog konfirmasi WO muncul saat ada lebih dari satu WO dengan part WIP
      yang sama di mesin tersebut.
- [ ] Petugas gudang dapat melihat laporan yang belum dicetak dari Web.
- [ ] Label Good dan NG dapat dicetak terpisah dari Web dengan isi: WO, part,
      qty, jam, shift, jenis.
- [ ] Petugas gudang dapat menandai label sudah diantar.
- [ ] WIP dengan label belum diantar tetap dapat dipakai, dengan penanda visual
      di APK.
- [ ] Tidak ada penyimpanan hasil produksi yang ditolak karena status label.

## Open Questions

1. **Merek dan model printer label di gudang** menentukan apakah Web dapat
   mengirim cetak langsung (raw/ESC-POS) atau tetap membuka halaman cetak
   browser seperti label yang sudah ada. Perlu dicek di lapangan.
2. **Batas shift 2 dan 3.** Shift 1 mulai 07:30, tetapi jam mulai shift 2 dan 3
   belum ditetapkan. Perlu angka pasti agar dropdown periode benar.
3. **Periode lintas shift.** Bila satu periode jam melewati batas shift (mis.
   14:30 saat shift berganti), apakah periode itu milik shift lama atau shift
   baru?
4. **Retensi label NG.** Apakah label NG perlu diarsipkan lebih lama daripada
   label Good untuk keperluan audit kualitas.
5. **Batas waktu laporan susulan.** Apakah ada batas berapa lama ke belakang
   operator boleh mengisi periode lewat, atau bebas selama shift masih berjalan?
