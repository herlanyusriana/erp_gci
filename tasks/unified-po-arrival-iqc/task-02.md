# Task 02: Unifikasi Purchase Order (Lokal & Impor)

**Source:** `docs/prd/unified-po-arrival-iqc.md` — Technical Decisions, Section 1

**What to build:** Memperbarui `PurchaseOrderController` dan view `Incoming/PurchaseOrder/Form.vue` agar mendukung pembuatan PO untuk supplier lokal maupun impor. Menghapus menu dan route `local-pos` dari routing Web dan launcher modul incoming.

## Acceptance criteria

- [ ] `PurchaseOrderController::create` dan `edit` menyajikan seluruh supplier aktif (baik `source = 'local'` maupun `source = 'import'`).
- [ ] Dropdown part pada form PO mendukung part substitute aktif dari supplier lokal maupun impor.
- [ ] Validasi `supplier_id` di `PurchaseOrderController` menerima supplier dengan source `local` maupun `import`.
- [ ] Route `local-pos` dan controller `LocalPoController` dihapus/dideprecate.
- [ ] Tile `Local PO` dihapus dari `resources/js/Pages/Incoming/ModuleLauncher.vue`, hanya menyisakan `Purchase Order`.

## Verification

- [ ] Tests pass: `./vendor/bin/pest tests/Feature/PurchaseOrderTest.php`
- [ ] Build succeeds: `npm run build`
- [ ] Manual check: Form PO dapat menyimpan PO dengan supplier lokal dan supplier impor

## Blocked by

Task 01 — Database Schema & Pembersihan Data Lama

## Files likely touched

- `app/Http/Controllers/PurchaseOrderController.php`
- `resources/js/Pages/Incoming/PurchaseOrder/Form.vue`
- `resources/js/Pages/Incoming/ModuleLauncher.vue`
- `routes/web.php`

## Estimated scope

M — 4 files

## Rollback

Revert commit perubahan `PurchaseOrderController` dan routing.
