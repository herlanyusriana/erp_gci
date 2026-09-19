<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Material Issue {{ $issue->issue_no }}</title>
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
        th, td { border: 1px solid #D9E2EC; padding: 6px 8px; text-align: left; }
        th { background: #F7F9FC; font-size: 10px; text-transform: uppercase; letter-spacing: .5px; color: #64748B; }
        td.num, th.num { text-align: right; }
        tfoot td { font-weight: 700; background: #F7F9FC; }
        .sign { display: flex; justify-content: space-between; margin-top: 48px; }
        .sign div { width: 40%; text-align: center; }
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
            <div class="company">{{ $companyName }}</div>
            <div class="muted">Material Issue to Production</div>
        </div>
        <div class="title">
            BON PENGELUARAN MATERIAL
            <div class="muted" style="font-size:12px;font-weight:600;">{{ $issue->issue_no }}</div>
        </div>
    </div>

    <div class="meta">
        <div><div class="k">Tanggal</div><div class="v">{{ $issue->issue_date?->format('d/m/Y') }}</div></div>
        <div><div class="k">Work Order</div><div class="v">{{ $issue->workOrder?->wo_no ?? '-' }}</div></div>
        <div><div class="k">Part FG</div><div class="v">{{ $issue->workOrder?->part?->part_number }} · {{ $issue->workOrder?->part?->part_name }}</div></div>
        <div><div class="k">Dikeluarkan oleh</div><div class="v">{{ $issue->issuer?->name ?? '-' }}</div></div>
        <div><div class="k">Diterima oleh</div><div class="v">{{ $issue->received_by ?? '-' }}</div></div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:34px;">#</th>
                <th>Part</th>
                <th>Tag</th>
                <th>Invoice</th>
                <th class="num">Qty</th>
                <th>Satuan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($issue->items as $i => $item)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $item->part?->part_number }} · {{ $item->part?->part_name }}</td>
                    <td>{{ $item->tag ?? '-' }}</td>
                    <td>{{ $item->invoice ?? '-' }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $item->qty, 4, '.', ''), '0'), '.') }}</td>
                    <td>{{ $item->uom ?? '-' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" class="num">TOTAL</td>
                <td class="num">{{ rtrim(rtrim(number_format((float) $issue->items->sum('qty'), 4, '.', ''), '0'), '.') }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <div class="sign">
        <div>
            <div class="line">Dikeluarkan</div>
        </div>
        <div>
            <div class="line">Diterima</div>
        </div>
    </div>

    <script>
        window.addEventListener('load', function () { window.print(); });
    </script>
</body>
</html>
