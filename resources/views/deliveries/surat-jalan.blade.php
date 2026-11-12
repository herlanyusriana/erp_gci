<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Surat Jalan {{ $delivery->delivery_no }}</title>
    <style>
        @page { size: A4 portrait; margin: 14mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Helvetica Neue", Arial, sans-serif; color: #17202A; font-size: 12px; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #24106C; padding-bottom: 10px; }
        .company { font-size: 18px; font-weight: 800; color: #24106C; }
        .title { font-size: 16px; font-weight: 800; letter-spacing: 1px; text-align: right; }
        .muted { color: #64748B; }
        .meta { display: flex; flex-wrap: wrap; gap: 6px 32px; margin: 14px 0 18px; }
        .meta div { min-width: 180px; }
        .meta .k { font-size: 10px; text-transform: uppercase; letter-spacing: .5px; color: #64748B; }
        .meta .v { font-weight: 600; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #D9E2EC; padding: 6px 8px; text-align: left; vertical-align: top; }
        th { background: #F7F9FC; font-size: 10px; text-transform: uppercase; letter-spacing: .5px; color: #64748B; }
        td.num, th.num { text-align: right; }
        .tag { font-family: "Courier New", monospace; font-size: 11px; }
        .sign { display: flex; justify-content: space-between; margin-top: 44px; }
        .sign div { width: 30%; text-align: center; }
        .sign .line { margin-top: 56px; border-top: 1px solid #17202A; padding-top: 4px; font-weight: 600; }
        .toolbar { text-align: right; margin-bottom: 12px; }
        @media print { .toolbar { display: none; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <button onclick="window.print()">Cetak</button>
    </div>

    <div class="head">
        <div>
            <div class="company">{{ $companyName ?? 'PT. GEUM CHEON INDO' }}</div>
            <div class="muted">Delivery Order / Surat Jalan</div>
        </div>
        <div class="title">
            SURAT JALAN
            <div class="muted" style="font-size:12px;font-weight:600;">{{ $delivery->delivery_no }}</div>
        </div>
    </div>

    <div class="meta">
        <div><div class="k">Tanggal</div><div class="v">{{ $delivery->delivery_date?->format('d/m/Y') }}</div></div>
        <div><div class="k">Customer</div><div class="v">{{ $delivery->customer?->customer_name ?? '-' }}</div></div>
        <div><div class="k">Kode Customer</div><div class="v">{{ $delivery->customer?->customer_code ?? '-' }}</div></div>
        <div><div class="k">Alamat</div><div class="v">{{ $delivery->customer?->address ?? '-' }}</div></div>
        <div><div class="k">No. Truk</div><div class="v">{{ $delivery->truck_no ?? '-' }}</div></div>
        <div><div class="k">Pengemudi</div><div class="v">{{ $delivery->driver_name ?? '-' }}</div></div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:36px;" class="num">#</th>
                <th style="width:150px;">Part No</th>
                <th>Nama Part</th>
                <th style="width:190px;">Tag</th>
                <th style="width:110px;" class="num">Qty</th>
                <th style="width:70px;">Satuan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($delivery->items as $index => $item)
                <tr>
                    <td class="num">{{ $index + 1 }}</td>
                    <td>{{ $item->part?->part_number ?? '-' }}</td>
                    <td>{{ $item->part?->part_name ?? '-' }}</td>
                    <td class="tag">{{ $item->tag ?? '-' }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $item->qty, 4, '.', ','), '0'), '.') }}</td>
                    <td>{{ $item->qty_unit ?? '-' }}</td>
                </tr>
            @endforeach
            @if ($delivery->items->isEmpty())
                <tr>
                    <td colspan="6" style="text-align:center;padding:16px;">Belum ada barang.</td>
                </tr>
            @endif
        </tbody>
    </table>

    @if ($delivery->notes)
        <p style="margin-top:12px;"><span class="muted">Catatan:</span> {{ $delivery->notes }}</p>
    @endif

    <div class="sign">
        <div>
            <div class="line">Pengirim</div>
        </div>
        <div>
            <div class="line">Pengemudi</div>
        </div>
        <div>
            <div class="line">Penerima</div>
        </div>
    </div>

    <script>
        window.addEventListener('load', function () { window.print(); });
    </script>
</body>
</html>