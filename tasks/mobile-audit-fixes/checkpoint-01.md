# Checkpoint 01: setelah Task 01–04 (kontrak loading & OQC)

- [x] `vendor/bin/pest tests/Feature/Api/DeliveryLoadingApiTest.php tests/Feature/Api/DeliveryQcApiTest.php tests/Feature/DeliveryTest.php` lulus — 39 passed / 336 assertions
- [x] Full web suite `vendor/bin/pest` lulus tanpa regresi (baseline 697) — 710 passed / 4123 assertions
- [x] `vendor/bin/pint --dirty --format agent` bersih
- [x] `cd ../erp_gci_mobile && flutter test` lulus (baseline 214, tidak turun) — 232 passed
- [x] `cd ../erp_gci_mobile && flutter analyze` tanpa error/warning — 5 info lama
- [x] `cd ../erp_gci_mobile && flutter build apk --debug` sukses
- [x] Alur end-to-end: dokumen dengan satu FG di dua sequence — pindai satu tag,
      baris kedua **tidak** terkunci, progres memakai angka part
      (bukti: `DeliveryLoadingApiTest` kasus dua baris + widget test
      `loading_part_progress_test.dart`)
- [x] Alur end-to-end: tandai semua troli OK → Selesaikan OQC dari APK → status
      `qc_passed` tanpa membuka web (bukti: `DeliveryQcApiTest` 5 test +
      widget test `loading_oqc_completion_test.dart`; belum dijalankan di
      perangkat fisik)
- [ ] Review manusia sebelum lanjut ke Phase 3

Jika ada yang gagal, perbaiki dulu sebelum melanjutkan.
