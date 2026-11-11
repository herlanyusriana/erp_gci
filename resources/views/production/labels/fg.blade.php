<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>LABEL FINISH GOOD {{ $kind === 'ng' ? '(NG) ' : '' }}· {{ $woNo }}</title>
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
        .brand {
            width: 16mm; display: flex; align-items: center; justify-content: center;
            font-size: 15pt; font-weight: 900; border-right: 2px solid #000;
        }
        .header-title {
            flex: 1; display: flex; align-items: center; justify-content: center;
            font-size: 24pt; font-weight: 900; letter-spacing: 1.5px; text-transform: uppercase;
        }
        .header-month {
            width: 18mm; background: #000; color: #fff; display: flex; align-items: center;
            justify-content: center; font-size: 24pt; font-weight: 900;
        }

        .body { flex: 1; display: flex; min-height: 0; }
        .fields { width: 62%; border-right: 2px solid #000; display: flex; flex-direction: column; }
        .field { flex: 1; display: flex; align-items: center; border-bottom: 1px solid #000; padding: 0 3.5mm; }
        .field:last-child { border-bottom: none; }
        .field .k {
            width: 34mm; flex-shrink: 0; font-size: 7pt; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.4px; color: #333;
        }
        .field .v {
            font-size: 10pt; font-weight: 800; text-transform: uppercase;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .field.part .v { font-size: 12pt; }

        .side { width: 38%; display: flex; flex-direction: column; }
        .qr-box { height: 48mm; display: flex; align-items: center; justify-content: center; border-bottom: 2px solid #000; padding: 2mm; }
        .qr-box svg { width: 42mm !important; height: 42mm !important; }
        .qty-head {
            height: 6mm; display: flex; align-items: center; justify-content: center;
            border-bottom: 1px solid #000; font-size: 7pt; font-weight: 700; letter-spacing: 0.5px;
        }
        .qty-val {
            flex: 1; display: flex; align-items: center; justify-content: center;
            border-bottom: 1px solid #000; font-size: 22pt; font-weight: 900;
        }
        .counter {
            height: 8mm; display: flex; align-items: center; justify-content: center;
            font-size: 9pt; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase;
        }

        .no-print {
            position: fixed; bottom: 20px; right: 20px; background: #000; color: #fff;
            padding: 12px 24px; border: none; border-radius: 8px; font-weight: 700;
            cursor: pointer; z-index: 999;
        }
        @media print {
            body { background: #fff; }
            .sheet-container { padding: 0; gap: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="sheet-container">
        @foreach ($labelSheets as $sheet)
            <div class="label-card">
                <div class="header">
                    <div class="brand">GL</div>
                    <div class="header-title">LABEL FINISH GOOD{{ $kind === 'ng' ? ' (NG)' : '' }}</div>
                    <div class="header-month">{{ $monthBox ?? '01' }}</div>
                </div>

                <div class="body">
                    <div class="fields">
                        <div class="field part">
                            <span class="k">Part No</span>
                            <span class="v">{{ $partNumber ?? '-' }}</span>
                        </div>
                        <div class="field">
                            <span class="k">Part Name</span>
                            <span class="v">{{ $partName ?? '-' }}</span>
                        </div>
                        <div class="field">
                            <span class="k">Model / Size</span>
                            <span class="v">{{ $model ?: '-' }} / {{ $size ?: '-' }}</span>
                        </div>
                        <div class="field">
                            <span class="k">WO No</span>
                            <span class="v">{{ $woNo ?? '-' }}</span>
                        </div>
                        <div class="field">
                            <span class="k">Invoice</span>
                            <span class="v">{{ $invoiceNo ?? '-' }}</span>
                        </div>
                        <div class="field">
                            <span class="k">Tag</span>
                            <span class="v">{{ $tagNo ?? '-' }}</span>
                        </div>
                        <div class="field">
                            <span class="k">Operator</span>
                            <span class="v">{{ !empty($operatorName) && $operatorName !== '-' ? strtoupper($operatorName) : '-' }}</span>
                        </div>
                        <div class="field">
                            <span class="k">Date / Shift</span>
                            <span class="v">
                                {{ $resultDate ?? '-' }} / {{ $shift ?? '-' }}
                                @if (!empty($isBackfilled)) · SUSULAN @endif
                            </span>
                        </div>
                    </div>

                    <div class="side">
                        <div class="qr-box">{!! $qr !!}</div>
                        <div class="qty-head">QUANTITY ({{ $uom ?: 'EA' }} - {{ strtoupper($sheet['packing_type']) }})</div>
                        <div class="qty-val">
                            {{ rtrim(rtrim(number_format($sheet['qty'], 4, '.', ','), '0'), '.') }}
                        </div>
                        <div class="counter">LABEL {{ $sheet['label_number'] }} / {{ $sheet['total_labels'] }}</div>
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