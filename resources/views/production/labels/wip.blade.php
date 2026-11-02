<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Label {{ strtoupper($kind) }} · {{ $woNo }}</title>
    <style>
        @page { size: 100mm 50mm; margin: 0; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            color: #17202a;
        }
        .label {
            width: 100mm;
            height: 50mm;
            padding: 3mm;
            display: flex;
            gap: 3mm;
            border: 1px solid #d9e2ec;
        }
        .body { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .kind {
            font-size: 11pt;
            font-weight: 800;
            letter-spacing: .04em;
            padding: 1mm 2mm;
            display: inline-block;
            border-radius: 1mm;
            margin-bottom: 1.5mm;
        }
        .kind.good { background: #dcfce7; color: #15803d; }
        .kind.ng { background: #fee2e2; color: #b91c1c; }
        .wo { font-size: 11pt; font-weight: 700; margin-bottom: .5mm; }
        .part { font-size: 9pt; color: #17202a; margin-bottom: .5mm; }
        .meta { font-size: 8pt; color: #64748b; line-height: 1.35; }
        .qty {
            font-size: 13pt;
            font-weight: 800;
            margin: .5mm 0;
        }
        .backfill { color: #b45309; font-weight: 700; }
        .qr { width: 26mm; display: flex; align-items: center; justify-content: center; }
        .qr svg { width: 26mm; height: 26mm; }
    </style>
</head>
<body onload="window.print()">
    <div class="label">
        <div class="body">
            <span class="kind {{ $kind }}">{{ strtoupper($kind) }}</span>
            <div class="wo">{{ $woNo }}</div>
            <div class="part">{{ $partNumber }} · {{ $partName }}</div>
            <div class="qty">{{ rtrim(rtrim(number_format($qty, 4, '.', ','), '0'), '.') }} {{ $uom }}</div>
            <div class="meta">
                Shift {{ $shift }} · {{ substr($periodStart, 0, 5) }}-{{ substr($periodEnd, 0, 5) }}<br>
                {{ $resultDate }} · {{ $machineName ?? '—' }}
                @if ($isBackfilled)
                    <br><span class="backfill">SUSULAN</span>
                @endif
            </div>
        </div>
        <div class="qr">{!! $qr !!}</div>
    </div>
</body>
</html>