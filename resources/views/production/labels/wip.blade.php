<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>LABEL WIP {{ $kind === 'ng' ? '(NG) ' : '' }}· {{ $woNo }}</title>
    <style>
        @page { size: 150mm 100mm; margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body {
            font-family: Arial, "Helvetica Neue", Helvetica, sans-serif;
            background: #eee;
            color: #000;
        }
        .sheet-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            padding: 10px 0;
        }
        .label-card {
            width: 148mm;
            height: 98mm;
            background: #fff;
            border: 2px solid #000;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            page-break-after: always;
            break-after: page;
        }
        .label-card:last-child {
            page-break-after: auto;
            break-after: auto;
        }
        .header-bar {
            height: 17mm;
            border-bottom: 2px solid #000;
            display: flex;
        }
        .header-title {
            width: 78%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26pt;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
            border-right: 2px solid #000;
        }
        .header-month {
            width: 22%;
            background: #000;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30pt;
            font-weight: 800;
        }
        .main-body {
            flex: 1;
            display: flex;
            min-height: 0;
        }
        .left-col {
            width: 58%;
            border-right: 2px solid #000;
            padding: 3.5mm 4mm;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .top-group {
            display: flex;
            flex-direction: column;
            gap: 1.5mm;
        }
        .wo-no {
            font-size: 16pt;
            font-weight: 800;
            text-transform: uppercase;
            line-height: 1.15;
        }
        .part-desc {
            font-size: 15pt;
            font-weight: 800;
            text-transform: uppercase;
            line-height: 1.15;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .part-process {
            font-size: 10.5pt;
            font-weight: 400;
            padding-left: 4mm;
            margin-top: 0.5mm;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .batch-invoice {
            font-size: 17pt;
            font-weight: 800;
            text-transform: uppercase;
            margin-top: 1.5mm;
            line-height: 1.15;
        }
        .tag-no {
            font-size: 10.5pt;
            font-weight: 400;
            text-transform: uppercase;
            padding-left: 10mm;
        }
        .personnel-group {
            display: flex;
            flex-direction: column;
            gap: 1mm;
            margin-top: 2mm;
        }
        .person-line {
            font-size: 10.5pt;
            font-weight: 800;
            text-transform: uppercase;
        }
        .date-shift-line {
            font-size: 10pt;
            font-weight: 800;
            text-transform: uppercase;
        }
        .right-col {
            width: 42%;
            display: flex;
            flex-direction: column;
        }
        .qr-cell {
            height: 46%;
            border-bottom: 1.5px solid #000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2mm;
        }
        .qr-cell svg {
            width: 33mm !important;
            height: 33mm !important;
        }
        .qty-header {
            height: 8%;
            border-bottom: 1.5px solid #000;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 9pt;
            font-weight: 700;
            text-transform: uppercase;
        }
        .qty-value {
            height: 38%;
            border-bottom: 1.5px solid #000;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24pt;
            font-weight: 800;
        }
        .location-footer {
            height: 8%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10pt;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .no-print {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #000;
            color: #fff;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            z-index: 999;
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
                <div class="header-bar">
                    <div class="header-title">LABEL WIP{{ $kind === 'ng' ? ' (NG)' : '' }}</div>
                    <div class="header-month">{{ $monthBox ?? '01' }}</div>
                </div>

                <div class="main-body">
                    <div class="left-col">
                        <div class="top-group">
                            <div class="wo-no">{{ $woNo ?? '-' }}</div>
                            <div class="part-desc">{{ $fgPartName ?? $partName ?? '-' }}</div>
                            <div class="part-process">
                                {{ $partNumber ?? '-' }}@if(!empty($stepFraction)) {{ $stepFraction }}@endif - {{ $processName ?? $partName ?? '-' }}
                            </div>
                            <div class="batch-invoice">{{ $invoiceNo ?? '-' }}</div>
                            @if (!empty($supplierName) && ($invoiceCount ?? 0) > 1)
                                <div class="part-process">SUMBER: {{ $supplierName }}</div>
                            @endif
                            <div class="tag-no">TAG # : {{ $tagSeq ?? 1 }}</div>
                        </div>

                        <div class="personnel-group">
                            <div class="person-line">{{ $operatorName ?? '#OPERATOR NAME#' }}</div>
                            <div class="person-line">{{ $foremanName ?? '#FOREMAN NAME#' }}</div>
                        </div>

                        <div class="date-shift-line">
                            {{ $resultDate ?? '-' }} / SHIFT {{ $shift ?? '-' }}@if(!empty($periodStart)) ({{ substr($periodStart, 0, 5) }}-{{ substr($periodEnd, 0, 5) }})@endif
                            @if(!empty($isBackfilled)) · SUSULAN @endif
                        </div>
                    </div>

                    <div class="right-col">
                        <div class="qr-cell">{!! $qr !!}</div>
                        <div class="qty-header">
                            QUANTITY ({{ $uom ?: 'EA' }} - {{ strtoupper($sheet['packing_type']) }})
                        </div>
                        <div class="qty-value">
                            {{ rtrim(rtrim(number_format($sheet['qty'], 4, '.', ','), '0'), '.') }}
                        </div>
                        <div class="location-footer">
                            LABEL {{ $sheet['label_number'] }} / {{ $sheet['total_labels'] }}
                        </div>
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
