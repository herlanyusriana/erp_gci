# Checkpoint 04: final, setelah Task 13–17

- [ ] Full web suite: `vendor/bin/pest` lulus tanpa regresi
- [ ] `vendor/bin/pint --dirty --format agent` bersih
- [ ] `node scripts/check-i18n.mjs` lulus
- [ ] `npm run build` lulus
- [ ] Full mobile suite: `cd ../erp_gci_mobile && flutter test` lulus, jumlah test
      **tidak turun** dari baseline (214); test yang dipindah/dihapus di Task 16
      dijelaskan di PR
- [ ] `cd ../erp_gci_mobile && flutter analyze` tanpa error/warning
- [ ] `cd ../erp_gci_mobile && flutter build apk --debug` sukses
- [ ] `grep -rn "issue_screen.dart" ../erp_gci_mobile/lib` → kosong
- [ ] Katalog i18n 277 key × 3 locale, test paritas hijau
- [ ] Form Arrival & Local PO tidak lagi meminta gross weight
- [ ] `resolveTag` mengembalikan `available_qty` dan APK memakainya sebagai batas
- [ ] Delapan temuan Critical di `tasks/audit-fixes/mobile-audit.md` semuanya
      berstatus selesai atau punya alasan tertulis bila ditunda
- [ ] Review manusia sebelum merge

Jika ada yang gagal, perbaiki dulu sebelum merge.
