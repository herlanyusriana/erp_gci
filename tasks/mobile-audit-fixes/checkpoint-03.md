# Checkpoint 03: setelah Task 09–12 (kehilangan data operator)

- [ ] `cd ../erp_gci_mobile && flutter test test/guided_issue_draft_test.dart test/guided_issue_defer_test.dart test/issue_state_per_wo_test.dart test/machine_receipt_list_test.dart` lulus
- [ ] Full mobile suite `cd ../erp_gci_mobile && flutter test` lulus (tidak turun dari 214)
- [ ] `cd ../erp_gci_mobile && flutter analyze` tanpa error/warning
- [ ] `cd ../erp_gci_mobile && flutter build apk --debug` sukses
- [ ] Bukti: scan 2 tag → back → dialog konfirmasi muncul, bukan keluar diam-diam
- [ ] Bukti: "Sisa Menyusul" dengan 2 tag tersisa → dialog menyebut 2 tag
- [ ] Bukti: release WO-A gagal → buka WO-B → material WO-B tampil dan tidak ada
      tombol yang bisa merilis WO-A
- [ ] Bukti: buka daftar penerimaan mesin → baris langsung tampil tanpa refresh
- [ ] Review manusia dengan operator (atau simulasi peran operator) sebelum
      lanjut ke Phase 5

Jika ada yang gagal, perbaiki dulu sebelum melanjutkan.
