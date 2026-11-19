# Checkpoint 01: setelah Task 01–04 (kontrak loading & OQC)

- [ ] `vendor/bin/pest tests/Feature/Api/DeliveryLoadingApiTest.php tests/Feature/Api/DeliveryQcApiTest.php tests/Feature/DeliveryTest.php` lulus
- [ ] Full web suite `vendor/bin/pest` lulus tanpa regresi (baseline 697)
- [ ] `vendor/bin/pint --dirty --format agent` bersih
- [ ] `cd ../erp_gci_mobile && flutter test` lulus (baseline 214, tidak turun)
- [ ] `cd ../erp_gci_mobile && flutter analyze` tanpa error/warning
- [ ] `cd ../erp_gci_mobile && flutter build apk --debug` sukses
- [ ] Alur end-to-end: dokumen dengan satu FG di dua sequence — pindai satu tag,
      baris kedua **tidak** terkunci, progres memakai angka part
- [ ] Alur end-to-end: tandai semua troli OK → Selesaikan OQC dari APK → status
      `qc_passed` tanpa membuka web
- [ ] Review manusia sebelum lanjut ke Phase 3

Jika ada yang gagal, perbaiki dulu sebelum melanjutkan.
