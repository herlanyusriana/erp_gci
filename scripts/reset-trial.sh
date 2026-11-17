#!/usr/bin/env bash
#
# reset-trial.sh — kosongkan data transaksi ERP GCI untuk trial, master tetap.
#
# Urutan: preflight -> config:clear + cek identitas DB -> dry run -> backup ->
# konfirmasi -> maintenance mode + stop service -> reset --force --i-know ->
# start service -> verifikasi -> maintenance off.
#
# Pemakaian:
#   bash scripts/reset-trial.sh --dry-run          # hanya lihat rencana
#   bash scripts/reset-trial.sh                    # interaktif (rekomendasi)
#   bash scripts/reset-trial.sh --keep-sessions    # user & APK tetap login
#   bash scripts/reset-trial.sh --yes --keep-sessions   # tanpa konfirmasi (skrip)
#
# Opsi:
#   --dry-run        Hanya tampilkan rencana; tidak backup, tidak mengubah data.
#   --yes            Lewati konfirmasi ketik RESET (wajib untuk non-interaktif).
#   --keep-sessions  Pertahankan sesi web + token APK (tidak logout).
#   --no-backup      Lewati pg_dump. Hanya boleh bersama --yes.
#   --help           Tampilkan bantuan ini.
#
# Env opsional:
#   APP_DIR     direktori aplikasi   (default: root repo skrip ini)
#   APP_USER    pemilik file Laravel (default: www-data)
#   BACKUP_DIR  folder backup        (default: /var/backups/erp-gci)
#   SERVICES    unit systemd         (default: "erp-gci-worker erp-gci-reverb")

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$(cd -- "$SCRIPT_DIR/.." && pwd)}"
APP_USER="${APP_USER:-www-data}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/erp-gci}"
SERVICES="${SERVICES:-erp-gci-worker erp-gci-reverb}"

DRY_RUN=0
ASSUME_YES=0
KEEP_SESSIONS=0
DO_BACKUP=1

C_RESET=$'\033[0m'
C_BOLD=$'\033[1m'
C_RED=$'\033[31m'
C_GREEN=$'\033[32m'
C_YELLOW=$'\033[33m'
C_CYAN=$'\033[36m'

log()   { printf '%s\n' "$*"; }
step()  { printf '\n%s==> %s%s\n' "$C_BOLD" "$*" "$C_RESET"; }
ok()    { printf '%s  ok%s %s\n' "$C_GREEN" "$C_RESET" "$*"; }
warn()  { printf '%s  !%s  %s\n' "$C_YELLOW" "$C_RESET" "$*"; }
fail()  { printf '%s  x%s  %s\n' "$C_RED" "$C_RESET" "$*" >&2; }
die()   { fail "$*"; exit 1; }

usage() {
    # Cetak blok komentar di kepala skrip (berhenti sebelum kode).
    sed -n '2,/^set -euo pipefail/{/^set -euo pipefail/d;p}' "${BASH_SOURCE[0]}" \
        | sed 's/^# \{0,1\}//'
}

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        --yes|-y) ASSUME_YES=1 ;;
        --keep-sessions) KEEP_SESSIONS=1 ;;
        --no-backup) DO_BACKUP=0 ;;
        --help|-h) usage; exit 0 ;;
        *) die "Opsi tidak dikenal: $arg (pakai --help)" ;;
    esac
done

if [[ "$DO_BACKUP" -eq 0 && "$ASSUME_YES" -eq 0 ]]; then
    die "--no-backup hanya boleh dipakai bersama --yes."
fi

# --- Helper -----------------------------------------------------------------

# Jalankan `php artisan` sebagai pemilik file aplikasi.
artisan() {
    if [[ "$(id -u)" -eq 0 && "$APP_USER" != "root" ]]; then
        sudo -u "$APP_USER" php "$APP_DIR/artisan" "$@"
    else
        php "$APP_DIR/artisan" "$@"
    fi
}

# Sama seperti artisan(), tapi mengubah kegagalan koneksi DB menjadi pesan yang
# bisa ditindaklanjuti alih-alih stack trace Laravel.
artisan_checked() {
    local out status=0
    out="$(artisan "$@" 2>&1)" || status=$?

    if [[ "$status" -ne 0 ]]; then
        # Stack trace Laravel panjang; cukup ekor output untuk konteks.
        printf '%s\n' "$out" | tail -n 6 >&2

        if grep -qiE 'could not find driver|connection refused|could not connect|password authentication failed|does not exist|SQLSTATE' <<<"$out"; then
            fail "Koneksi database gagal. Periksa DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD di $APP_DIR/.env"
            fail 'Uji cepat: psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" -c "select 1"'
        fi

        die "Perintah 'artisan $*' gagal (exit $status)."
    fi

    printf '%s\n' "$out"
}

# Baca nilai dari .env tanpa mengeksekusinya (jangan `source .env`).
# Variabel environment yang sudah diset menang, supaya skrip bisa diarahkan ke
# database lain (mis. uji coba di DB salinan) tanpa menyentuh .env.
env_value() {
    local key="$1" line value
    if [[ -n "${!key:-}" ]]; then
        printf '%s' "${!key}"
        return 0
    fi
    line="$(grep -E "^[[:space:]]*${key}[[:space:]]*=" "$APP_DIR/.env" | tail -n1 || true)"
    [[ -z "$line" ]] && return 1
    value="${line#*=}"
    value="${value%$'\r'}"
    value="${value%\"}"; value="${value#\"}"
    value="${value%\'}"; value="${value#\'}"
    printf '%s' "$value"
}

SERVICES_STOPPED=0
MAINTENANCE_ON=0
restore_services_on_exit() {
    if [[ "$SERVICES_STOPPED" -eq 1 ]]; then
        warn "Mengembalikan service yang sempat dihentikan..."
        # shellcheck disable=SC2086
        sudo systemctl start $SERVICES 2>/dev/null || true
        SERVICES_STOPPED=0
    fi
    if [[ "$MAINTENANCE_ON" -eq 1 ]]; then
        warn "Mematikan maintenance mode..."
        artisan up >/dev/null 2>&1 || true
        MAINTENANCE_ON=0
    fi
}
trap restore_services_on_exit EXIT

# Hitung baris langsung lewat psql. `artisan tinker` tidak dipakai karena psysh
# menulis config ke $HOME/.config/psysh, yang gagal saat skrip jalan sebagai
# www-data (HOME=/var/www tidak writable) — verifikasi jadi gagal padahal reset
# sukses.
verify_counts() {
    local transaction_tables=(purchase_orders incoming_arrivals incoming_receives
        part_stocks work_orders production_results material_issues picking_lists deliveries)
    local master_tables=(parts suppliers customers users)

    if [[ "$DB_CONNECTION" == "pgsql" ]] && command -v psql >/dev/null && [[ -n "$DB_PASSWORD" ]]; then
        local sql="" table
        for table in "${transaction_tables[@]}"; do
            sql+="select '${table}' as label, count(*) as n from ${table} union all "
        done
        sql+="select 'TOTAL transaksi', sum(n) from ("
        local first=1
        for table in "${transaction_tables[@]}"; do
            [[ $first -eq 0 ]] && sql+=" union all "
            sql+="select count(*) as n from ${table}"
            first=0
        done
        sql+=") x union all select '--- MASTER ---', null"
        for table in "${master_tables[@]}"; do
            sql+=" union all select '${table}', count(*) from ${table}"
        done

        local out
        if out="$(PGPASSWORD="$DB_PASSWORD" psql -h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" \
                -U "$DB_USERNAME" -d "$DB_DATABASE" -tAF'|' -c "$sql" 2>&1)"; then
            while IFS='|' read -r label value; do
                [[ -z "$label" ]] && continue
                if [[ "$label" == "--- MASTER ---" ]]; then
                    log "  --- MASTER (harus tetap) ---"
                    continue
                fi
                printf '  %-24s %s\n' "$label" "${value:-}"
            done <<<"$out"

            return 0
        fi

        printf '%s\n' "$out" >&2
        return 1
    fi

    # Fallback: tinker dengan HOME yang writable, untuk DB non-pgsql.
    XDG_CONFIG_HOME="${TMPDIR:-/tmp}" HOME="${TMPDIR:-/tmp}" artisan tinker --execute '
$rows = 0;
foreach (["purchase_orders","incoming_arrivals","incoming_receives","part_stocks","work_orders","production_results","material_issues","picking_lists","deliveries"] as $t) {
    $n = \Illuminate\Support\Facades\DB::table($t)->count();
    $rows += $n;
    echo "  ".str_pad($t, 24).$n."\n";
}
echo "  ".str_pad("TOTAL transaksi", 24).$rows."\n";
echo "  --- MASTER (harus tetap) ---\n";
foreach (["parts","suppliers","customers","users"] as $t) {
    echo "  ".str_pad($t, 24).\Illuminate\Support\Facades\DB::table($t)->count()."\n";
}
'
}

# Database yang benar-benar dipakai Laravel. Bisa berbeda dari .env bila
# bootstrap/cache/config.php masih menyimpan nilai lama; nilai inilah yang
# dipakai backup, truncate, dan verifikasi.
resolved_database() {
    local out
    out="$(XDG_CONFIG_HOME="${TMPDIR:-/tmp}" HOME="${TMPDIR:-/tmp}" artisan_checked tinker --execute \
        'echo config("database.connections.".config("database.default").".database");')"

    printf '%s\n' "$out" | grep -v '^[[:space:]]*$' | tail -n1 | tr -d '[:space:]' || true
}

# --- 1. Preflight -----------------------------------------------------------

step "1/7 Preflight"

[[ -d "$APP_DIR" ]] || die "APP_DIR tidak ditemukan: $APP_DIR"
[[ -f "$APP_DIR/artisan" ]] || die "Bukan direktori Laravel (artisan tidak ada): $APP_DIR"
[[ -f "$APP_DIR/.env" ]] || die ".env tidak ada di $APP_DIR"
command -v php >/dev/null || die "php tidak ditemukan di PATH"

DB_CONNECTION="$(env_value DB_CONNECTION || echo '')"
DB_DATABASE="$(env_value DB_DATABASE || echo '')"
DB_HOST="$(env_value DB_HOST || echo '')"
DB_PORT="$(env_value DB_PORT || echo '5432')"
DB_USERNAME="$(env_value DB_USERNAME || echo '')"
DB_PASSWORD="$(env_value DB_PASSWORD || echo '')"
APP_ENV="$(env_value APP_ENV || echo '')"

[[ -n "$DB_DATABASE" ]] || die "DB_DATABASE kosong di .env"

log "Aplikasi   : $APP_DIR"
log "Environment: ${APP_ENV:-tidak diset}"
log "Database   : ${DB_CONNECTION:-?} / $DB_DATABASE"
log "Pemilik    : $APP_USER"
log "Service    : $SERVICES"

if ! artisan list 2>/dev/null | grep -q 'erp:reset-data'; then
    die "Perintah erp:reset-data belum ada di server ini. Jalankan 'git pull' dulu (butuh commit b37059c atau lebih baru)."
fi
ok "perintah erp:reset-data tersedia"

if [[ "$DB_CONNECTION" != "pgsql" ]]; then
    warn "DB_CONNECTION=$DB_CONNECTION (bukan pgsql). Backup pg_dump akan dilewati."
    DO_BACKUP=0
fi

# Buang config cache dulu supaya artisan, pg_dump, dan psql menunjuk database
# yang sama. Tanpa ini config.php lama bisa mengarahkan truncate ke DB lain.
artisan_checked config:clear >/dev/null
ok "config cache dibersihkan"

RESOLVED_DB="$(resolved_database)"
[[ -n "$RESOLVED_DB" ]] || die "Tidak bisa membaca database dari Laravel. Cek DB_* di $APP_DIR/.env."

if [[ "$RESOLVED_DB" != "$DB_DATABASE" ]]; then
    die "Database tidak cocok: Laravel memakai '$RESOLVED_DB', .env berisi '$DB_DATABASE'. Backup dan truncate harus menunjuk DB yang sama. Hapus $APP_DIR/bootstrap/cache/config.php lalu ulangi."
fi

ok "database terverifikasi: $RESOLVED_DB (Laravel = .env)"

# --- 2. Dry run -------------------------------------------------------------

step "2/7 Dry run (tidak mengubah data)"
artisan_checked erp:reset-data

if [[ "$DRY_RUN" -eq 1 ]]; then
    log ""
    ok "Dry run selesai. Tidak ada data yang dihapus."
    exit 0
fi

# --- 3. Backup --------------------------------------------------------------

BACKUP_FILE=""
if [[ "$DO_BACKUP" -eq 1 ]]; then
    step "3/7 Backup database"
    command -v pg_dump >/dev/null || die "pg_dump tidak ditemukan. Pasang postgresql-client atau pakai --no-backup --yes."

    mkdir -p "$BACKUP_DIR" 2>/dev/null || sudo mkdir -p "$BACKUP_DIR"
    BACKUP_FILE="$BACKUP_DIR/${DB_DATABASE}_$(date +%F_%H%M%S).dump"

    DUMP_HOST="${DB_HOST:-127.0.0.1}"
    DUMP_PORT="${DB_PORT:-5432}"
    # `--no-password`: jangan menggantung menunggu prompt. Tanpa ini, DB_PASSWORD
    # yang kosong/salah membuat skrip diam selamanya alih-alih gagal jelas.
    DUMP_ARGS=(-h "$DUMP_HOST" -p "$DUMP_PORT" -U "$DB_USERNAME" -Fc -d "$DB_DATABASE" --no-password)

    log "Menulis $BACKUP_FILE"
    if [[ -z "$DB_PASSWORD" ]]; then
        die "DB_PASSWORD kosong di .env. Isi dulu, atau pakai --no-backup --yes bila Anda punya backup lain."
    fi

    if ! PGPASSWORD="$DB_PASSWORD" pg_dump "${DUMP_ARGS[@]}" > "$BACKUP_FILE"; then
        rm -f "$BACKUP_FILE"
        die "pg_dump gagal. Cek DB_USERNAME/DB_PASSWORD di .env: kredensial yang sama harus bisa dipakai psql." 
    fi

    [[ -s "$BACKUP_FILE" ]] || die "Backup kosong/gagal: $BACKUP_FILE"
    ok "backup $(du -h "$BACKUP_FILE" | cut -f1) -> $BACKUP_FILE"
else
    step "3/7 Backup database (dilewati)"
    warn "Tanpa backup. Pastikan Anda punya salinan lain sebelum melanjutkan."
fi

# --- 4. Konfirmasi ----------------------------------------------------------

step "4/7 Konfirmasi"

printf '%s' "$C_RED$C_BOLD"
log "PERINGATAN: langkah berikut MENGHAPUS PERMANEN data transaksi:"
log "  Database: $RESOLVED_DB (APP_ENV=${APP_ENV:-tidak diset})"
log "  - PO, kedatangan, penerimaan, stok (part_stocks), WO, produksi,"
log "  - issue material, picking list, pengiriman, serta sesi/cache/job."
log "Master (parts, supplier, customer, BOM, mesin, user, harga) TETAP."
log "Penomoran PK/DO/WO/MI akan mulai lagi dari 0001."
printf '%s' "$C_RESET"
log ""

if [[ "$ASSUME_YES" -eq 0 ]]; then
    [[ -t 0 ]] || die "Tidak ada TTY untuk konfirmasi. Pakai --yes untuk mode non-interaktif."
    printf 'Ketik %sRESET%s untuk melanjutkan: ' "$C_BOLD" "$C_RESET"
    read -r answer
    [[ "$answer" == "RESET" ]] || die "Dibatalkan (jawaban: '$answer')."
    ok "konfirmasi diterima"
else
    warn "Konfirmasi dilewati karena --yes."
fi

# --- 5. Maintenance mode + stop service -------------------------------------

step "5/7 Maintenance mode & hentikan service penulis data"

# php-fpm tetap melayani request kalau hanya worker yang dihentikan; TRUNCATE
# butuh ACCESS EXCLUSIVE di banyak tabel, jadi request akan menggantung.
# Flag diset sebelum `down` supaya trap tetap memanggil `artisan up` bila
# perintah itu gagal di tengah jalan.
MAINTENANCE_ON=1
artisan_checked down --retry=60
ok "maintenance mode aktif (--retry=60)"

if command -v systemctl >/dev/null; then
    # shellcheck disable=SC2086
    if sudo systemctl stop $SERVICES 2>/dev/null; then
        SERVICES_STOPPED=1
        ok "service dihentikan: $SERVICES"
    else
        warn "Gagal/tidak ada unit systemd ($SERVICES). Lanjut tanpa menghentikan apa pun."
    fi
else
    warn "systemctl tidak tersedia. Pastikan queue worker manual sudah dihentikan."
fi

# --- 6. Reset ---------------------------------------------------------------

step "6/7 Reset data transaksi"
# --i-know: skrip ini sudah meminta konfirmasi RESET dan mengaktifkan
# maintenance mode, jadi guard environment di perintah artisan terpenuhi.
RESET_ARGS=(erp:reset-data --force --i-know)
if [[ "$KEEP_SESSIONS" -eq 1 ]]; then
    RESET_ARGS+=(--keep-sessions)
    log "Sesi web & token APK dipertahankan (--keep-sessions)."
else
    log "Sesi web & token APK ikut dihapus; semua user harus login ulang."
fi

artisan_checked "${RESET_ARGS[@]}"
ok "reset selesai"

artisan optimize:clear >/dev/null 2>&1 && ok "cache aplikasi dibersihkan"

# --- 7. Start service + verifikasi ------------------------------------------

step "7/7 Nyalakan service & verifikasi"
if [[ "$SERVICES_STOPPED" -eq 1 ]]; then
    # shellcheck disable=SC2086
    if sudo systemctl start $SERVICES; then
        SERVICES_STOPPED=0
        # shellcheck disable=SC2086
        sudo systemctl is-active $SERVICES | paste -sd' ' - | sed 's/^/  status: /'
        ok "service berjalan kembali"
    else
        fail "Gagal menyalakan service. Jalankan manual: sudo systemctl start $SERVICES"
    fi
else
    warn "Tidak ada service yang dihentikan sebelumnya."
fi

log ""
log "Ringkasan baris (transaksi harus 0, master harus tetap):"
verify_counts || warn "Verifikasi otomatis gagal; jalankan psql manual (perintah di bawah)."

if [[ "$MAINTENANCE_ON" -eq 1 ]]; then
    if artisan up >/dev/null 2>&1; then
        MAINTENANCE_ON=0
        ok "maintenance mode dimatikan"
    else
        fail "Gagal mematikan maintenance mode. Jalankan manual: php $APP_DIR/artisan up"
    fi
fi

log ""
if [[ -n "$BACKUP_FILE" ]]; then
    log "Backup: $BACKUP_FILE"
    log "Restore bila perlu:"
    log "  sudo systemctl stop $SERVICES"
    log "  pg_restore --clean --if-exists -h ${DB_HOST:-127.0.0.1} -U $DB_USERNAME -d $DB_DATABASE $BACKUP_FILE"
    log "  sudo systemctl start $SERVICES"
fi
ok "Selesai."
