<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Label Pengiriman {{ $delivery->delivery_no }}</title>
    <style>
        @page { size: 150mm 100mm; margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body { font-family: Arial, "Helvetica Neue", Helvetica, sans-serif; background: #eee; color: #000; }

        .sheet-container { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 10px 0; }

        .label-card {
            width: 148mm; height: 98mm; background: #fff; border: 2px solid #000;
            display: flex; flex-direction: column; overflow: hidden;
            page-break-after: always; break-after: page;
        }
        .label-card:last-child { page-break-after: auto; break-after: auto; }

        .header { height: 16mm; border-bottom: 2px solid #000; display: flex; align-items: stretch; }
        .brand { width: 16mm; display: flex; align-items: center; justify-content: center; font-size: 15pt; font-weight: 900; border-right: 2px solid #000; }
        .header-title { flex: 1; display: flex; align-items: center; justify-content: center; font-size: 22pt; font-weight: 900; letter-spacing: 1.5px; text-transform: uppercase; }
        .header-seq { width: 20mm; background: #000; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 16pt; font-weight: 900; }

        .body { flex: 1; display: flex; min-height: 0; }
        .fields { width: 62%; border-right: 2px solid #000; display: flex; flex-direction: column; }
        .field { flex: 1; display: flex; align-items: center; border-bottom: 1px solid #000; padding: 0 3.5mm; }
        .field:last-child { border-bottom: none; }
        .field .k { width: 26mm; flex-shrink: 0; font-size: 7pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; color: #333; }
        .field .v { font-size: 10pt; font-weight: 800; text-transform: uppercase; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .field.part .v { font-size: 12pt; }

        .side { width: 38%; display: flex; flex-direction: column; }
        .qr-box { height: 48mm; display: flex; align-items: center; justify-content: center; border-bottom: 2px solid #000; padding: 2mm; }
        .qr-box svg { width: 42mm !important; height: 42mm !important; }
        .qty-head { height: 6mm; display: flex; align-items: center; justify-content: center; border-bottom: 1px solid #000; font-size: 7pt; font-weight: 700; letter-spacing: 0.5px; }
        .qty-val { flex: 1; display: flex; align-items: center; justify-content: center; border-bottom: 1px solid #000; font-size: 22pt; font-weight: 900; }
        .counter { height: 8mm; display: flex; align-items: center; justify-content: center; font-size: 9pt; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase; }

        .no-print { position: fixed; bottom: 20px; right: 20px; background: #000; color: #fff; padding: 12px 24px; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; z-index: 999; }
        @media print { body { background: #fff; } .sheet-container { padding: 0; gap: 0; } .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="sheet-container">
        @foreach ($labels as $index => $item)
            <div class="label-card">
                <div class="header">
                    <div class="brand">GL</div>
                    <div class="header-title">LABEL PENGIRIMAN</div>
                    <div class="header-seq">{{ $index + 1 }}/{{ count($labels) }}</div>
                </div>

                <div class="body">
                    <div class="fields">
                        <div class="field part">
                            <span class="k">Part No</span>
                            <span class="v">{{ $item['part_number'] ?? '-' }}</span>
                        </div>
                        <div class="field">
                            <span class="k">Part Name</span>
                            <span class="v">{{ $item['part_name'] ?? '-' }}</span>
                        </div>
                        <div class="field">
                            <span class="k">Tag</span>
                            <span class="v">{{ $item['tag'] !== '' ? $item['tag'] : '-' }}</span>
                        </div>
                        <div class="field">
                            <span class="k">Customer</span>
                            <span class="v">{{ $delivery->customer?->customer_name ?? '-' }}</span>
                        </div>
                        <div class="field">
                            <span class="k">No. DO</span>
                            <span class="v">{{ $delivery->delivery_no }}</span>
                        </div>
                        <div class="field">
                            <span class="k">Tanggal</span>
                            <span class="v">{{ $delivery->delivery_date?->format('d/m/Y') ?? '-' }}</span>
                        </div>
                    </div>

                    <div class="side">
                        <div class="qr-box">{!! \App\Support\QrSvg::make((string) json_encode([
                            'type' => 'delivery',
                            'delivery_id' => (int) $delivery->id,
                            'delivery_no' => (string) $delivery->delivery_no,
                            'part_stock_id' => (int) $item['part_stock_id'],
                            'tag' => (string) $item['tag'],
                            'part_id' => (int) $item['part_id'],
                            'qty' => (float) $item['qty'],
                        ]), 340, 0) !!}</div>
                        <div class="qty-head">QTY ({{ strtoupper((string) ($item['qty_unit'] ?? 'PCS')) }})</div>
                        <div class="qty-val">{{ rtrim(rtrim(number_format((float) $item['qty'], 4, '.', ','), '0'), '.') }}</div>
                        <div class="counter">LABEL {{ $index + 1 }} / {{ count($labels) }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <button class="no-print" onclick="window.print()">CETAK LABEL</button>
    <script>
        window.addEventListener('load', function () { window.print(); });
    </script>
</body>
</html>
