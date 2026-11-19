# Task 12: Daftar penerimaan memuat saat dibuka

**Source:** `tasks/audit-fixes/mobile-audit.md` — C8

**What to build:** Layar daftar penerimaan mesin memuat datanya saat dibuka, dan
membedakan "gagal memuat" dari "belum ada penerimaan".

## Latar

`ReceiptListNotifier.build()` mengembalikan `const ReceiptListState()` — loading
`false`, daftar kosong — dan tidak ada yang memanggil `load()` selain tombol
refresh. `MachineReceiptListScreen` adalah `ConsumerWidget`, jadi tidak ada
`initState`. Akibatnya buka pertama **selalu** "belum ada penerimaan". Selain
itu `state.error != null` jatuh ke cabang kosong yang sama, sehingga timeout
tampil sebagai "tidak ada data".

## Acceptance criteria

- [x] `receiptListProvider` menjadi `NotifierProvider.family` berkunci
      `(machineId, date)`; state mesin A tidak bocor ke mesin B.
- [x] `build()` memulai dengan `loading: true` dan memicu `load()` sekali untuk
      kunci tersebut.
- [x] Cabang `error != null` dirender **sebelum** cabang kosong: pesan + tombol
      coba lagi yang memanggil `load()`.
- [x] SnackBar error sementara tidak lagi menjadi satu-satunya umpan balik.
- [x] Test: buka layar → repository dipanggil tepat sekali tanpa aksi pengguna;
      error → panel error + tombol retry; sukses kosong → teks "belum ada
      penerimaan".

## Verification

- [x] Tests pass: `cd ../erp_gci_mobile && flutter test test/machine_receipt_list_test.dart`
- [x] Full mobile suite: `cd ../erp_gci_mobile && flutter test`
- [x] Analyze: `cd ../erp_gci_mobile && flutter analyze`
- [x] Build: `cd ../erp_gci_mobile && flutter build apk --debug`
- [x] Manual check: konfirmasi satu tag → buka daftar penerimaan → baris
      langsung tampil tanpa menekan refresh

## Blocked by

None — can start immediately.

## Files likely touched

- `lib/providers/production_receipt_provider.dart`
- `lib/features/receipt/machine_receipt_list.dart`
- `test/machine_receipt_list_test.dart` (baru)

## Estimated scope

S — 3 file

## Rollback

Kembalikan provider global dan pemuatan manual. Layar kembali selalu kosong di
buka pertama, seperti sebelum task ini.

## Notes

- Perhatikan pemanggil lain `receiptListProvider`: setelah menjadi `family`,
  setiap `ref.read(receiptListProvider.notifier)` harus diberi argumen kunci.
- Jangan menambah `autoDispose` pada task ini: kembali ke layar setelah
  konfirmasi batch sebaiknya tidak memicu loading penuh lagi. Bila diinginkan,
  putuskan terpisah.
