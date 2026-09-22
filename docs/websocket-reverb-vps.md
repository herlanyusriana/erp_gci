# Reverb Local dan VPS

Panduan ini menjalankan Laravel Reverb untuk dashboard **Issue Out Monitoring**.
Reverb hanya menangani kanal real-time; transaksi release APK tetap diproses
oleh Laravel dan event broadcast dimasukkan ke queue database.

## Local

1. Salin environment dan isi nilai lokal:

```bash
cp .env.example .env
php artisan key:generate
```

2. Pastikan nilai berikut sesuai `.env`:

```dotenv
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=database
REVERB_APP_ID=erp-gci-local
REVERB_APP_KEY=erp-gci-local-key
REVERB_APP_SECRET=erp-gci-local-secret
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

3. Jalankan proses aplikasi, queue worker, Reverb, dan Vite pada terminal
   terpisah:

```bash
php artisan serve
php artisan queue:work --tries=3
php artisan reverb:start
npm run dev
```

4. Uji kesiapan proses:

```bash
php artisan route:list --path=broadcasting
curl -I http://127.0.0.1:8000/up
```

Jangan menggunakan secret lokal pada environment production atau melakukan
commit file `.env`.

## VPS topology

- Nginx menerima HTTPS pada `example.invalid`.
- Laravel HTTP berjalan pada `127.0.0.1:8000`.
- Reverb bind ke `127.0.0.1:8080`; port 8080 tidak dibuka ke internet.
- Queue worker memproses `BroadcastEvent` dari database.
- Browser memakai host publik HTTPS/WSS pada `REVERB_HOST` dan `REVERB_PORT`.

Setiap environment harus memiliki `REVERB_APP_ID`, `REVERB_APP_KEY`, dan
`REVERB_APP_SECRET` sendiri. Nilai di bawah hanya placeholder.

## Nginx WebSocket upgrade

Gunakan sertifikat TLS yang dikelola organisasi/Let's Encrypt. Contoh blok
berikut tidak berisi secret:

```nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

server {
    listen 443 ssl http2;
    server_name example.invalid;

    ssl_certificate     /etc/letsencrypt/live/example.invalid/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.invalid/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }

    location /app/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_read_timeout 60s;
        proxy_send_timeout 60s;
    }
}
```

Sesuaikan `REVERB_HOST`, `REVERB_PORT`, dan `REVERB_SCHEME=https` dengan host
publik. Uji konfigurasi sebelum reload:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

## systemd supervision

Buat unit terpisah agar restart Reverb tidak memengaruhi PHP worker:

```ini
[Unit]
Description=ERP GCI Laravel Reverb
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/erp_gci
ExecStart=/usr/bin/php artisan reverb:start
Restart=always
RestartSec=5
Environment=APP_ENV=production
StandardOutput=append:/var/log/erp-gci/reverb.log
StandardError=append:/var/log/erp-gci/reverb-error.log

[Install]
WantedBy=multi-user.target
```

Worker queue unit minimal:

```ini
[Unit]
Description=ERP GCI Laravel queue worker
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/erp_gci
ExecStart=/usr/bin/php artisan queue:work database --sleep=3 --tries=3 --timeout=90
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Simpan sebagai unit lokal sesuai standar server, lalu:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now erp-gci-reverb erp-gci-worker
sudo systemctl status erp-gci-reverb erp-gci-worker
```

## Firewall, logs, dan health check

- Buka hanya `80/tcp` untuk redirect/ACME dan `443/tcp` untuk aplikasi.
- Jangan membuka `8080/tcp` secara publik.
- Pantau status dan log:

```bash
sudo systemctl status erp-gci-reverb erp-gci-worker
sudo journalctl -u erp-gci-reverb -f
sudo journalctl -u erp-gci-worker -f
tail -f storage/logs/laravel.log
```

- `curl -fsS https://example.invalid/up` harus berhasil.
- Pastikan queue tidak menumpuk pada tabel `jobs` dan event broadcast tidak
  terus gagal.
- Dari browser berizin `stock.issue`, kanal private `issue-out-monitoring`
  harus berhasil di-authorize; user tanpa permission harus ditolak.

## Restart dan rollback aman

Setelah deployment:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo systemctl restart erp-gci-reverb erp-gci-worker
```

Jika koneksi live gagal, periksa urutan: environment aktif, queue worker,
Reverb log, Nginx upgrade header, TLS, dan firewall. Rollback aplikasi ke
release sebelumnya lalu restart kedua unit; jangan menghapus tabel `jobs` atau
memutar ulang issue secara manual tanpa idempotency key. Jika queue sudah
terlanjur berisi event dari release baru, biarkan worker menghabiskannya hanya
setelah kompatibilitas payload dipastikan.
