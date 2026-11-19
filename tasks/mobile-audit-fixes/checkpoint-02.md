# Checkpoint 02: setelah Task 05–08 (hasil produksi)

- [ ] `vendor/bin/pest tests/Feature/ProductionResultTest.php tests/Feature/Api/ProductionSessionApiTest.php tests/Feature/Api/MaterialIssueApiTest.php` lulus
- [ ] Full web suite `vendor/bin/pest` lulus tanpa regresi
- [ ] `vendor/bin/pint --dirty --format agent` bersih
- [ ] Migration `production_results.idempotency_key` terpasang dan rollback bersih
- [ ] `cd ../erp_gci_mobile && flutter test` lulus
- [ ] `cd ../erp_gci_mobile && flutter analyze` tanpa error/warning
- [ ] `cd ../erp_gci_mobile && flutter build apk --debug` sukses
- [ ] Bukti dedupe: dua POST dengan key sama → `SELECT count(*) FROM production_results WHERE idempotency_key = '…'` = 1
- [ ] Bukti plafon: reject saja (good 0) tersimpan; angka melebihi qty WO ditolak 422
- [ ] Bukti APK: input melebihi sisa memunculkan konfirmasi sebelum request
- [ ] Review manusia sebelum lanjut ke Phase 4

Jika ada yang gagal, perbaiki dulu sebelum melanjutkan.
