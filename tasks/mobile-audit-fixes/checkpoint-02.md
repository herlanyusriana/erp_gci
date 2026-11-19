# Checkpoint 02: setelah Task 05–08 (hasil produksi)

- [x] `vendor/bin/pest tests/Feature/ProductionResultTest.php tests/Feature/Api/ProductionSessionApiTest.php tests/Feature/Api/MaterialIssueApiTest.php` lulus — 18 + 13 + 78 passed
- [x] Full web suite `vendor/bin/pest` lulus tanpa regresi — 710 passed / 4123 assertions
- [x] `vendor/bin/pint --dirty --format agent` bersih
- [x] Migration `production_results.idempotency_key` terpasang dan rollback bersih
- [x] `cd ../erp_gci_mobile && flutter test` lulus — 232 passed
- [x] `cd ../erp_gci_mobile && flutter analyze` tanpa error/warning — 5 info lama
- [x] `cd ../erp_gci_mobile && flutter build apk --debug` sukses
- [x] Bukti dedupe: dua POST dengan key sama → satu baris dan satu output WIP (`test_api_report_with_the_same_key_creates_one_row_and_one_output`)
- [x] Bukti plafon: reject saja (good 0) tersimpan; angka melebihi qty WO ditolak 422
      (`test_reject_only_report_is_saved_consumes_material_and_can_be_reversed`,
      `test_report_rejects_good_plus_reject_above_the_work_order_qty`)
- [x] Bukti APK: input melebihi sisa memunculkan konfirmasi sebelum request
      (`production_result_qty_guard_test.dart`)
- [ ] Review manusia sebelum lanjut ke Phase 4

Jika ada yang gagal, perbaiki dulu sebelum melanjutkan.
