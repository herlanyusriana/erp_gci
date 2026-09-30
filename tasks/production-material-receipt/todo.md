# Task List: Production Material Receipt (APK)

PRD: `docs/prd/production-material-receipt.md`
Plan: `tasks/production-material-receipt/plan.md`

---

### Phase 1: Backend Foundation

- [ ] Task 1: Migrasi tabel `production_material_receipts` + Model Eloquent
- [ ] Task 2: Service `ProductionReceiptService` — logika bisnis
- [ ] Task 3: API Controller + Route — `POST /api/receipts/confirm`, `GET /api/receipts`

### Checkpoint 1: Backend Foundation
- [ ] Migrasi jalan, model dan service berfungsi
- [ ] Test API lulus: confirm sukses, validasi tag/mesin/duplikat
- [ ] Pint lulus, npm build lulus

### Phase 2: Flutter Integration

- [ ] Task 4: Flutter model `ProductionReceipt` + Repository
- [ ] Task 5: Provider `ProductionReceiptNotifier` (Riverpod)
- [ ] Task 6: Screen scan tag → scan mesin → konfirmasi
- [ ] Task 7: Daftar penerimaan per mesin (list screen)

### Checkpoint 2: Complete
- [ ] Backend: 251+ test lulus
- [ ] Flutter: existing + new test lulus
- [ ] `flutter analyze` no issues
- [ ] `npm run build` lulus
- [ ] Manual flow terverifikasi

---

## Archivos

### Backend
- `app/Models/ProductionMaterialReceipt.php`
- `app/Services/ProductionReceiptService.php`
- `app/Http/Controllers/Api/ProductionReceiptController.php`
- `database/migrations/2026_09_29_XXXXXX_create_production_material_receipts_table.php`
- `routes/api.php` (tambah route)
- `tests/Feature/Api/ProductionReceiptApiTest.php`

### Flutter
- `lib/models/production_receipt.dart`
- `lib/repositories/production_receipt_repository.dart`
- `lib/providers/production_receipt_provider.dart`
- `lib/features/production_receipt/confirm_screen.dart`
- `lib/features/production_receipt/machine_receipt_list_screen.dart`
- `test/` — test terkait