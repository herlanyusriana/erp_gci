# Task 13: A2 — selaraskan permission `resolveTag`

**Source:** `tasks/audit-fixes/mobile-audit.md` — A2

**What to build:** `POST /api/stock-tags/resolve` bisa dipakai oleh semua peran
yang memang berhak menjalankan alur yang membutuhkannya, bukan hanya pemegang
`stock.issue`.

## Latar

`resolveTag()` memakai `abort_unless($user->hasPermission('stock.issue'))`,
sedangkan alur yang memanggilnya dijaga oleh
`permission:stock.issue,work_order.update,production.update` (rute `issue-plan`).
Operator yang boleh merilis/menerima material tetapi tidak memegang
`stock.issue` bisa membuka konteks release lalu gagal total di langkah scan —
tanpa penjelasan yang berguna.

Endpoint ini mengungkap stok, harga, invoice, dan supplier, jadi **tidak**
dibuka untuk semua user; yang diperbaiki adalah keselarasan dengan alur yang
sah.

## Acceptance criteria

- [ ] `resolveTag` memakai `hasAnyPermission(['stock.issue', 'production.update', 'work_order.update'])`,
      mengikuti pola `WipBoardApiController` baris 24–26.
- [ ] User tanpa ketiga permission tetap mendapat 403 dengan pesan yang sama.
- [ ] Endpoint penerimaan produksi (`/receipts/resolve`, `/receipts/confirm`)
      tetap mensyaratkan `stock.issue` — tidak ikut dilonggarkan.
- [ ] Test: user dengan `production.update` saja bisa resolve; user tanpa
      ketiganya 403; user `stock.issue` tetap bisa.

## Verification

- [ ] Tests pass: `vendor/bin/pest tests/Feature/Api/MaterialIssueApiTest.php tests/Feature/Api/ProductionReceiptApiTest.php`
- [ ] No regressions: `vendor/bin/pest tests/Feature/RolePermissionTest.php`
- [ ] Lint: `vendor/bin/pint --dirty --format agent`
- [ ] Manual check: `php artisan tinker --execute 'optional(\App\Models\User::where("email","production@geumcheon.local")->first())->hasAnyPermission(["production.update","work_order.update"]);'`

## Blocked by

None — can start immediately.

## Files likely touched

- `app/Http/Controllers/Api/MaterialIssueApiController.php`
- `tests/Feature/Api/MaterialIssueApiTest.php`

## Estimated scope

XS — 2 file

## Rollback

Kembalikan satu baris `abort_unless` ke `hasPermission('stock.issue')`.

## Notes

- Jangan menyamakan ini dengan membuka endpoint tanpa syarat apa pun. Perubahan
  yang benar adalah "tiga permission yang sudah menjaga alur tulis", bukan
  "semua user login".
