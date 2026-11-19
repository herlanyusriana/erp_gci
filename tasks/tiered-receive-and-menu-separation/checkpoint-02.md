# Checkpoint 02: Final Verification against PRD Success Criteria

- [x] Di halaman Detail Arrival (`Arrival/Show.vue`), tidak ada tombol "Terima" atau tabel tag receive.
- [x] Tombol "Terima" di menu Receive (`/receive`) tidak lagi melempar user ke halaman Arrival, melainkan masuk ke workspace Receive khusus.
- [x] Form Receive memiliki input berurutan: Bundle $\rightarrow$ Packing Type $\rightarrow$ Isi per Packing $\rightarrow$ Satuan (UOM).
- [ ] Total kuantitas barang otomatis terhitung dari perkalian Bundle $\times$ Packing $\times$ Isi, dan tetap bisa disesuaikan manual.
- [x] Kolom net weight dan gross weight berlabel dan ber-satuan mutlak KGM.
- [x] Label QR tag mencantumkan rincian kemasan bertingkat.
- [x] File test `tests/Feature/TieredReceiveTest.php` dan seluruh test Pest lulus hijau (`vendor/bin/pest`).
- [x] `npm run build` dan `vendor/bin/pint --format agent` sukses tanpa error.
