<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Commercial Invoice</title>
    <style>
        @page {
            size: A4;
            margin: 0;
        }

        .page-break {
            page-break-after: always;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            padding: 0;
        }

        .doc-toolbar {
            position: sticky;
            top: 0;
            z-index: 20;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            background: #fff;
            border-bottom: 1px solid #cbd5e1;
            padding: 10px 20px;
        }

        .doc-toolbar span {
            font-weight: 600;
            color: #64748b;
        }

        .doc-toolbar .actions {
            display: flex;
            gap: 8px;
        }

        .doc-toolbar a,
        .doc-toolbar button {
            display: inline-block;
            text-decoration: none;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #1a2a3a;
            border-radius: 6px;
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }

        .doc-toolbar button.print {
            background: #0B3A6E;
            border-color: #0B3A6E;
            color: #fff;
        }

        .title {
            text-align: center;
            font-family: Tahoma, Arial, sans-serif;
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 8px;
            text-decoration: none;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .main-table td {
            border: 2px solid #000;
            padding: 5px 7px;
            vertical-align: top;
            font-size: 12px;
        }

        .main-table .num {
            font-weight: bold;
            width: 12px;
        }

        .col-left {
            width: 55%;
        }

        .col-right {
            width: 45%;
        }

        .inner-table {
            width: 100%;
            border-collapse: collapse;
        }

        .inner-table td {
            border: none;
            padding: 2px 0;
            vertical-align: top;
        }

        .section-label {
            font-weight: bold;
            font-size: 10px;
        }

        .company-name {
            font-weight: bold;
        }

        .items-table {
            margin-top: 8px;
        }

        .packing-items-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .packing-items-table td,
        .packing-items-table th {
            padding: 4px;
            font-size: 12px;
            vertical-align: top;
        }

        .packing-items-table th {
            border-bottom: 2px solid #000;
            font-weight: bold;
            text-align: center;
            white-space: normal;
        }

        .packing-items-table th:first-child {
            text-align: left;
        }

        .packing-bundle {
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .packing-desc {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .packing-desc td {
            border: none;
            padding: 0;
            vertical-align: middle;
            line-height: 1.2;
            font-size: inherit;
        }

        .packing-qty-split td {
            padding: 0 4px;
        }

        .packing-total-parts {
            font-weight: bold;
            text-align: center;
            white-space: normal;
            line-height: 1.15;
        }

        .packing-total-parts span {
            display: inline-block;
            margin: 0 6px;
            white-space: nowrap;
        }

        .invoice-qty-split td {
            padding: 0 4px;
        }

        .invoice-qty-compact {
            width: auto;
            margin: 0 auto;
        }

        .invoice-qty-compact td {
            padding: 0 6px;
        }

        .items-table:not(.packing-items-table) td,
        .items-table:not(.packing-items-table) th {
            border: none;
            padding: 5px 6px;
            vertical-align: top;
            font-size: 11px;
        }

        .items-table:not(.packing-items-table) th {
            background: none;
            font-weight: bold;
            text-align: center;
            font-size: 10px;
            border-bottom: none;
        }

        .invoice-items-box {
            border: 2px solid #000;
            margin-top: 8px;
            padding: 0 6px 6px 6px;
        }

        .invoice-items-box .items-table {
            margin-top: 0;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .text-bold {
            font-weight: bold;
        }

        .container-box {
            padding: 6px 0;
            margin-top: 8px;
            font-size: 11px;
        }

        .footer-section {
            margin-top: 8px;
            position: relative;
        }

        .signature-table {
            width: 100%;
        }

        .signature-table td {
            vertical-align: top;
            padding: 0;
        }

        .original-box {
            border: 2px solid #cc0000;
            color: #cc0000;
            font-family: Tahoma, Arial, sans-serif;
            font-size: 24px;
            padding: 6px 18px;
            font-weight: bold;
            display: inline-block;
        }

        .sign-box {
            border: 1px solid #000;
            width: 180px;
            padding: 8px;
            text-align: center;
            float: right;
        }

        .sign-space {
            height: 50px;
        }

        .sign-name {
            border-top: 1px solid #000;
            padding-top: 4px;
            font-weight: bold;
            font-size: 10px;
        }

        .page-box {
            border: 2px solid #000;
            padding: 0;
        }

        .page-box.packing-list {
            font-family: Arial, sans-serif;
            font-size: 12px;
            line-height: 1.25;
            margin: 15px;
            border: none;
            width: auto;
        }

        .page-box.packing-list .title {
            border-bottom: 2px solid #000;
            margin-bottom: 0;
            padding-bottom: 8px;
        }

        .packing-content-wrapper {
            border: 2px solid #000;
            border-top: none;
        }

        .page-box.packing-list .section-label,
        .page-box.packing-list .main-table td,
        .page-box.packing-list .packing-items-table td,
        .page-box.packing-list .packing-items-table th {
            font-size: inherit;
        }

        .commercial-invoice {
            font-family: Arial, sans-serif;
            font-size: 12px;
            line-height: 1.25;
            margin: 30px;
            padding: 0;
            width: auto;
        }

        .commercial-invoice .invoice-container {
            width: 100%;
            margin: 0;
        }

        .commercial-invoice .ci-header {
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            padding: 8px;
            letter-spacing: 3px;
            border-bottom: 2px solid #000;
        }

        .commercial-invoice .ci-content-wrapper {
            border-left: 2px solid #000;
            border-right: 2px solid #000;
            border-bottom: 2px solid #000;
        }

        .commercial-invoice .ci-info-table {
            width: 100%;
            border-collapse: collapse;
        }

        .commercial-invoice .ci-info-table td {
            vertical-align: top;
            padding: 8px;
        }

        .commercial-invoice .ci-info-row td {
            border-bottom: 2px solid #000;
        }

        .commercial-invoice .ci-info-left {
            width: 50%;
            border-right: 2px solid #000;
        }

        .commercial-invoice .ci-info-right {
            width: 50%;
        }

        .commercial-invoice .ci-section-title {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .commercial-invoice .ci-invoice-number {
            font-size: 18px;
            font-weight: bold;
            margin: 5px 0;
        }

        .commercial-invoice .ci-products {
            padding: 10px;
        }

        .commercial-invoice .ci-products table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .commercial-invoice .ci-products th,
        .commercial-invoice .ci-products td {
            padding: 6px;
            border: none;
        }

        .commercial-invoice .ci-products th {
            font-weight: bold;
            text-align: center;
        }

        .commercial-invoice .ci-products thead th {
            border-bottom: 2px solid #000;
        }

        .commercial-invoice .ci-center {
            text-align: center;
        }

        .commercial-invoice .ci-right {
            text-align: right;
        }

        .commercial-invoice .ci-product-title {
            font-weight: bold;
            margin: 10px 0;
            text-align: center;
        }

        .commercial-invoice .ci-total-row td {
            border-top: 2px solid #000;
            font-weight: bold;
        }

        .commercial-invoice .ci-footer-cell {
            padding-top: 20px;
        }

        .commercial-invoice .ci-footer-table td {
            vertical-align: top;
            padding: 0;
        }

        .commercial-invoice .ci-original-stamp,
        .packing-list .ci-original-stamp {
            border: 3px solid #cc0000;
            color: #cc0000;
            padding: 8px 24px;
            font-size: 30px;
            font-weight: bold;
            display: inline-block;
            margin: 20px 0;
            box-sizing: border-box;
            max-width: 100%;
        }

        .commercial-invoice .ci-sign-block,
        .packing-list .ci-sign-block {
            display: inline-block;
            text-align: center;
            max-width: 100%;
        }

        .commercial-invoice .ci-signature-wrap,
        .packing-list .ci-signature-wrap {
            text-align: right;
            min-height: 30px;
            margin-top: 6px;
        }

        .commercial-invoice .ci-signature img,
        .packing-list .ci-signature img {
            max-height: 45px;
            vertical-align: middle;
        }

        .commercial-invoice .ci-sign-top-row,
        .packing-list .ci-sign-top-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 12px;
            min-height: 45px;
        }

        .commercial-invoice .ci-signedby,
        .packing-list .ci-signedby {
            font-size: 10px;
            font-weight: bold;
            text-align: left;
            white-space: nowrap;
        }

        .commercial-invoice .ci-sign-line,
        .packing-list .ci-sign-line {
            border-top: 1px solid #000;
            margin-top: 6px;
        }

        @media print {
            .doc-toolbar {
                display: none;
            }

            body {
                background: #fff;
            }
        }
    </style>
</head>

<body>
    @php
        // Display exactly what user inputs; no auto-combining/guessing.
        $portDisplay = strtoupper(trim((string) ($arrival->port_of_loading ?? '')));
        if ($portDisplay === '') {
            $portDisplay = strtoupper(trim((string) ($arrival->country ?? '')));
        }

        $notesText = trim((string) ($arrival->notes ?? ''));
        $bankAccountText = trim((string) ($arrival->supplier?->bank_account ?? ''));

        $invoiceCurrency = strtoupper(trim((string) ($arrival->currency ?? ''))) ?: 'USD';

        if (!function_exists('format2')) {
            function format2($value): string
            {
                if (is_string($value)) {
                    $value = str_replace(',', '', $value);
                }

                $num = is_numeric($value) ? (float) $value : 0.0;
                $negative = $num < 0;
                $num = abs($num);

                // Round half-up to 2 decimals: >=5 goes up, <5 stays.
                $cents = (int) floor(($num * 100) + 0.5 + 1e-9);
                $result = ($negative ? -1 : 1) * ($cents / 100);

                return number_format($result, 2, '.', ',');
            }
        }

        if (!function_exists('format3_round_half_up')) {
            function format3_round_half_up($value): string
            {
                $num = is_numeric($value) ? (float) $value : 0.0;
                $negative = $num < 0;
                $num = abs($num);

                // Round half-up to 3 decimals: >=5 goes up, <5 stays.
                $milli = (int) floor(($num * 1000) + 0.5 + 1e-9);
                $result = ($negative ? -1 : 1) * ($milli / 1000);

                return number_format($result, 3, '.', ',');
            }
        }
    @endphp
    @php
        $totalBundles = $arrival->items->map(fn($i) => (float) ($i->qty_bundle ?? 0))->sum();
        $totalQtyGoods = $arrival->items->map(fn($i) => (float) ($i->qty_goods ?? 0))->sum();
        $totalNett = $arrival->items->map(fn($i) => (float) ($i->weight_nett ?? 0))->sum();
        $totalGross = $arrival->items->map(fn($i) => (float) ($i->weight_gross ?? 0))->sum();
        $totalAmount = $arrival->items->map(fn($i) => (float) ($i->total_price ?? 0))->sum();
        // 1. Calculate unique HS codes from individual parts for maximum accuracy
        $derivedHsCodes = $arrival->items
            ->pluck('part.hs_code')
            ->filter(fn($code) => trim((string) $code) !== '')
            ->flatMap(fn($code) => collect(preg_split('/[\r\n,;]+/', (string) $code) ?: []))
            ->map(fn($code) => strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->values();

        // 2. Use derived codes, or fallback to stored arrival record if no items exist
        $hsCodesDisplay = $derivedHsCodes->isNotEmpty()
            ? $derivedHsCodes->implode("\n")
            : ($arrival->hs_code ?? '');
        $hasBundleData = $arrival->items->contains(function ($item) {
            return ($item->qty_bundle ?? 0) > 0;
        });
        $marksNoEnd = (int) $arrival->items->map(fn($i) => (int) ($i->qty_bundle ?? 0))->sum();
        if ($marksNoEnd <= 0) {
            $marksNoEnd = $arrival->items->count();
        }
        $bundleUnitDisplay = optional($arrival->items->filter(function ($item) {
            return ($item->qty_bundle ?? 0) > 0 && $item->unit_bundle;
        })->first())->unit_bundle ?? 'BUNDLE';
        $goodsUnitDisplay = strtoupper(optional($arrival->items->filter(function ($item) {
            return !empty($item->unit_goods);
        })->first())->unit_goods ?? 'PCS');
        $weightUnitDisplay = strtoupper(optional($arrival->items->first())->unit_weight ?? 'KGM');

        $weightOnlyGoodsUnits = ['KGM', 'KG'];
        $qtyTotalsByUnit = $arrival->items
            ->groupBy(fn($i) => strtoupper(trim((string) ($i->unit_goods ?? 'PCS'))))
            ->map(fn($items) => (float) $items->sum(fn($i) => (float) ($i->qty_goods ?? 0)))
            ->filter(fn($v) => $v > 0);
        $qtyTotalsNonWeight = $qtyTotalsByUnit->reject(fn($v, $unit) => in_array($unit, $weightOnlyGoodsUnits, true));
        $nettTotalsByUnit = $arrival->items
            ->groupBy(fn($i) => strtoupper(trim((string) ($i->unit_weight ?? 'KGM'))))
            ->map(fn($items) => (float) $items->sum(fn($i) => (float) ($i->weight_nett ?? 0)))
            ->filter(fn($v) => $v > 0);
    @endphp

    @php
        $groupedItems = $arrival->items->groupBy(function ($i) {
            $group = $i->material_group ?? '';
            if (trim($group) !== '') {
                return strtoupper(trim($group));
            }
            return strtoupper(trim($i->display_part_name ?? ''));
        });

        // Signature image source: browser => URL, dompdf => absolute local path.
        $signatureSrc = null;
        if (!empty($arrival->supplier?->signature_path)) {
            $signatureSrc = !empty($browserPrint)
                ? asset('storage/' . $arrival->supplier->signature_path)
                : \Illuminate\Support\Facades\Storage::disk('public')->path($arrival->supplier->signature_path);
        }
    @endphp

    @if (!empty($browserPrint))
        <div class="doc-toolbar">
            <span>Invoice {{ $arrival->invoice_no ?? $arrival->arrival_no }}</span>
            <span class="actions">
                <a href="{{ route('incoming-arrivals.show', $arrival) }}">Kembali</a>
                <button class="print" onclick="window.print()">Cetak</button>
            </span>
        </div>
    @endif

    <div class="commercial-invoice">
        <div class="invoice-container">
            <div class="ci-header">COMMERCIAL INVOICE</div>

            <div class="ci-content-wrapper">
                <table class="ci-info-table">
                    <tr class="ci-info-row">
                        <td class="ci-info-left">
                            <div class="ci-section-title">1. SHIPPER</div>
                            <div>
                                <strong>{{ strtoupper($arrival->supplier?->supplier_name ?? '-') }}</strong><br>
                                {{ strtoupper($arrival->supplier?->address ?? '') }}<br>
                                TEL: {{ $arrival->supplier?->phone ?? '' }}
                            </div>
                        </td>
                        <td class="ci-info-right">
                            <div class="ci-section-title">8. INVOICE NO. & DATE</div>
                            <table style="width:100%; border:none; margin-top:5px;">
                                <tr>
                                    <td style="border:none; padding:0;">
                                        <div class="ci-invoice-number">{{ $arrival->invoice_no }}</div>
                                    </td>
                                    <td style="border:none; padding:0; text-align:right; font-size:11px;">
                                        {{ optional($arrival->invoice_date)->format('d.M.Y') }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr class="ci-info-row">
                        <td class="ci-info-left">
                            <div class="ci-section-title">2. CONSIGNEE</div>
                            <div>
                                <strong>PT. GEUM CHEON INDO</strong><br>
                                JL. RAYA SERANG KM.12 DESA SUKADAMAI RT 01/RW 05 CIKUPA -<br>
                                TANGERANG 15710, INDONESIA<br><br>
                                TEL:62-21-5940-0240 &nbsp; FAX:62-21-5940-1224<br>
                                CNEE'S TAX ID: 002.006.378.0.415.000<br>
                                EMAIL: MENTOSNICE@GMAIL.COM
                            </div>
                        </td>
                        <td class="ci-info-right">
                            <div class="ci-section-title">9. REMITTANCE</div>
                            @if($notesText !== '')
                                <div style="white-space: pre-line; margin-top:10px;">{{ $notesText }}</div>
                            @else
                                <div style="margin-top:10px;">&nbsp;</div>
                            @endif
                        </td>
                    </tr>

                    <tr class="ci-info-row">
                        <td class="ci-info-left">
                            <div class="ci-section-title">3. NOTIFY PARTY</div>
                            @if($arrival->trucking)
                                <div>
                                    <strong>{{ strtoupper($arrival->trucking->company_name) }}</strong><br>
                                    {{ strtoupper($arrival->trucking->address ?? '') }}<br><br>
                                    Tel: {{ $arrival->trucking->phone ?? '-' }}
                                </div>
                            @else
                                <div>-</div>
                            @endif
                        </td>
                        <td class="ci-info-right">
                            <div class="ci-section-title">10. I/C ISSUING BANK</div>
                            <div style="margin-top:10px;">&nbsp;</div>
                        </td>
                    </tr>

                    <tr class="ci-info-row">
                        <td class="ci-info-left">
                            <table style="width:100%; border:none;">
                                <tr>
                                    <td style="border:none; padding:0; width:50%; vertical-align:top;">
                                        <div class="ci-section-title">4. PORT OF LOADING</div>
                                        <div style="margin-top:10px;">{{ $portDisplay }}</div>
                                    </td>
                                    <td style="border:none; padding:0; width:50%; vertical-align:top;">
                                        <div class="ci-section-title">5. FINAL DESTINATION</div>
                                        <div style="margin-top:10px;">JAKARTA, INDONESIA</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                        <td class="ci-info-right" rowspan="2">
                            <div class="ci-section-title">11. REMARK:</div>
                            @if($bankAccountText !== '')
                                <div style="white-space: pre-line; margin-top:10px;">{{ $bankAccountText }}</div>
                            @else
                                <div style="margin-top:10px;">&nbsp;</div>
                            @endif
                        </td>
                    </tr>

                    <tr class="ci-info-row">
                        <td class="ci-info-left">
                            <table style="width:100%; border:none;">
                                <tr>
                                    <td style="border:none; padding:0; width:50%; vertical-align:top;">
                                        <div class="ci-section-title">6. VESSEL</div>
                                        <div style="margin-top:10px; font-weight:bold;">
                                            {{ strtoupper($arrival->vessel ?? '') }}</div>
                                    </td>
                                    <td style="border:none; padding:0; width:50%; vertical-align:top;">
                                        <div class="ci-section-title">7. SAILING ON OR ABOUT</div>
                                        <div style="margin-top:10px; font-weight:bold;">
                                            {{ optional($arrival->etd)->format('d-M-y') }}</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>

                <div class="ci-products">
                    <table>
                        <colgroup>
                            <col style="width:22%;">
                            <col style="width:26%;">
                            <col style="width:13%;">
                            <col style="width:13%;">
                            <col style="width:14%;">
                            <col style="width:12%;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th style="width:22%; text-align:left;">12. MARKS & NO. OF PKGS</th>
                                <th class="ci-center" style="width:26%;">13. DESCRIPTION OF GOODS</th>
                                <th class="ci-center" colspan="2" style="width:26%;">14. QUANTITY</th>
                                <th class="ci-right" style="width:14%; white-space:nowrap;">15. UNIT PRICE</th>
                                <th class="ci-right" style="width:12%; white-space:nowrap;">16. AMOUNT</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>NO: 1-{{ $marksNoEnd }}.</td>
                                <td>&nbsp;</td>
                                <td colspan="2">&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                            </tr>

                            @foreach($groupedItems as $groupKey => $items)
                                <tr>
                                    <td>&nbsp;</td>
                                    <td>
                                        <div class="ci-product-title">
                                            {{ $items->first()->material_group ?: ($items->first()->display_part_name ?? $groupKey) }}
                                        </div>
                                    </td>
                                    <td colspan="2">&nbsp;</td>
                                    <td>&nbsp;</td>
                                    <td>&nbsp;</td>
                                </tr>

                                @foreach($items as $item)
                                    @php
                                        $goodsUnitLabel = strtoupper($item->unit_goods ?? 'PCS');
                                        $unitWeightLabel = strtoupper($item->unit_weight ?? 'KGM');
                                        $showWeightOnly = in_array($goodsUnitLabel, $weightOnlyGoodsUnits, true);
                                        $hasWeight = (float) ($item->weight_nett ?? 0) > 0;

                                        $pricePerGoodsRaw = (float) ($item->qty_goods ?? 0) > 0
                                            ? ((float) ($item->total_price ?? 0)) / (float) $item->qty_goods
                                            : 0;
                                        $pricePerGoods = format3_round_half_up($pricePerGoodsRaw);

                                        $pricePerWeightRaw = (float) ($item->weight_nett ?? 0) > 0
                                            ? ((float) ($item->total_price ?? 0)) / (float) $item->weight_nett
                                            : 0;
                                        $pricePerWeight = format3_round_half_up($pricePerWeightRaw);

                                        $qtyText = $showWeightOnly
                                            ? '-'
                                            : number_format((float) ($item->qty_goods ?? 0), 0) . ' ' . $goodsUnitLabel;
                                        $nettText = $hasWeight
                                            ? number_format((float) ($item->weight_nett ?? 0), 0) . ' ' . $unitWeightLabel
                                            : '-';
                                    @endphp
                                    <tr>
                                        <td>
                                            {{ strtoupper($item->display_part_name) }}
                                            @if ($item->is_foc)
                                                FOC
                                            @endif
                                        </td>
                                        <td class="ci-center">{{ $item->size ?? '' }}</td>
                                        <td class="ci-center" style="white-space:nowrap;">{{ $qtyText }}</td>
                                        <td class="ci-center" style="white-space:nowrap;">{{ $nettText }}</td>
                                        <td class="ci-right" style="white-space:nowrap;">
                                            @if($hasWeight)
                                                {{ $invoiceCurrency }} {{ $pricePerWeight }} /{{ $unitWeightLabel }}
                                            @else
                                                {{ $invoiceCurrency }} {{ $pricePerGoods }} /{{ $goodsUnitLabel }}
                                            @endif
                                        </td>
                                        <td class="ci-right" style="white-space:nowrap;">{{ $invoiceCurrency }} {{ format2($item->total_price) }}
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach

                            <tr class="ci-total-row">
                                <td style="padding-left:0;">TOTAL :</td>
                                <td>&nbsp;</td>
                                <td class="ci-center" style="white-space:nowrap;">
                                    @if($qtyTotalsNonWeight->isEmpty())
                                        -
                                    @else
                                        @foreach($qtyTotalsNonWeight as $unit => $value)
                                            <div style="white-space:nowrap;">{{ number_format((float) $value, 0) }}
                                                {{ strtoupper((string) $unit) }}</div>
                                        @endforeach
                                    @endif
                                </td>
                                <td class="ci-center" style="white-space:nowrap;">
                                    @if($nettTotalsByUnit->isEmpty())
                                        -
                                    @else
                                        @foreach($nettTotalsByUnit as $unit => $value)
                                            <div style="white-space:nowrap;">{{ number_format((float) $value, 0) }}
                                                {{ strtoupper((string) $unit) }}</div>
                                        @endforeach
                                    @endif
                                </td>
                                <td class="ci-right">&nbsp;</td>
                                <td class="ci-right" style="white-space:nowrap;">{{ $invoiceCurrency }}
                                    {{ format2($arrival->items->sum('total_price')) }}</td>
                            </tr>

                            <tr>
                                <td colspan="6" class="ci-footer-cell">
                                    <table class="ci-footer-table" style="width:100%; border:none;">
                                        <tr>
                                            <td style="border:none; padding:0; width:70%;">
                                                <div style="font-weight:bold;">HS CODE :</div>
                                                @if($hsCodesDisplay !== '')
                                                    <div style="white-space: pre-line;">{{ $hsCodesDisplay }}</div>
                                                @else
                                                    <div>-</div>
                                                @endif

                                                <div style="margin-top:14px; font-weight:bold;">BILL OF LADING :
                                                    {{ strtoupper($arrival->bill_of_lading ?? '-') }}</div>
                                                <div style="font-weight:bold;">PRICE TERM :
                                                    {{ strtoupper($arrival->price_term ?? '-') }}</div>

                                                <div style="margin-top:14px; font-weight:bold;">CONTAINERS :</div>
                                                @if($arrival->containers->count())
                                                    @foreach($arrival->containers as $container)
                                                        <div style="white-space:nowrap;">
                                                            {{ $loop->iteration }}.
                                                            {{ strtoupper(trim($container->container_no)) }}
                                                        </div>
                                                    @endforeach
                                                @else
                                                    <div>-</div>
                                                @endif

                                                <div style="margin-top:10px; font-weight:bold;">SEAL CODE :</div>
                                                @php
                                                    $allSeals = $arrival->containers->pluck('seal_code')->filter()->unique()->values();
                                                @endphp
                                                @if($allSeals->count())
                                                    <div>{{ $allSeals->map(fn($s) => strtoupper(trim((string) $s)))->implode(', ') }}
                                                    </div>
                                                @else
                                                    <div>-</div>
                                                @endif
                                            </td>
                                            <td
                                                style="border:none; padding:0; width:30%; text-align:right; vertical-align:top;">
                                                <div style="margin-top:35px;">
                                                    <div class="ci-original-stamp" style="transform: rotate(-6deg);">
                                                        ORIGINAL</div>
                                                </div>

                                                <div class="ci-sign-block" style="margin-top:10px;">
                                                    <div class="ci-sign-top-row">
                                                        <div class="ci-signedby">
                                                            <div>SIGNED BY</div>
                                                            @php $signedBy = strtoupper(trim((string) ($arrival->supplier?->contact_person ?? ''))); @endphp
                                                            <div>{{ $signedBy !== '' ? $signedBy : ' ' }}</div>
                                                        </div>

                                                        <div class="ci-signature-wrap"
                                                            style="margin-top:0; min-height:0;">
                                                            @if($signatureSrc)
                                                                <img class="ci-signature"
                                                                    src="{{ $signatureSrc }}"
                                                                    alt="Signature">
                                                            @else
                                                                <div
                                                                    style="height:20px; width:120px; display:inline-block;">
                                                                </div>
                                                            @endif
                                                        </div>
                                                    </div>

                                                    <div class="ci-sign-line"></div>
                                                </div>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>


    <div class="page-break"></div>
    <div class="page-box packing-list">
        <div class="title">PACKING LIST</div>
        <div class="packing-content-wrapper">

            <table class="main-table">
                {{-- Row 1: Shipper + Invoice No & Date --}}
                <tr>
                    <td class="col-left">
                        <span class="section-label">1.SHIPPER</span><br><br>
                        <span class="company-name">{{ strtoupper($arrival->supplier?->supplier_name ?? '-') }}</span><br>
                        {{ strtoupper($arrival->supplier?->address ?? '') }}<br>
                        TEL:{{ $arrival->supplier?->phone ?? '' }}
                    </td>
                    <td class="col-right">
                        <span class="section-label">8.INVOICE NO. & DATE</span><br><br>
                        <table style="width:100%; border:none;">
                            <tr>
                                <td style="border:none; padding:0; font-weight:bold; font-size:14px;">
                                    {{ $arrival->invoice_no }}</td>
                                <td style="border:none; padding:0; text-align:right;">
                                    {{ optional($arrival->invoice_date)->format('d.M.Y') }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Row 2: Consignee + Remark --}}
                <tr>
                    <td class="col-left">
                        <span class="section-label">2.CONSIGNEE</span><br><br>
                        <span class="company-name">PT.GEUM CHEON INDO</span><br>
                        JL. RAYA SERANG KM.12 DESA SUKADAMAI RT 01/RW 05 CIKUPA -<br>
                        TANGERANG 15710, INDONESIA<br><br>
                        TEL:62-21-5940-0240 &nbsp; FAX:62-21-5940-1224<br>
                        CNEE'S TAX ID: 002.006.378.0.415.000<br>
                        EMAIL: MENTOSNICE@GMAIL.COM
                    </td>
                    <td class="col-right" rowspan="4" style="vertical-align: top;">
                        <span class="section-label">9.REMARK:</span><br><br>
                        @if($notesText !== '')
                            <div style="white-space: pre-line;">{{ $notesText }}</div>
                        @else
                            &nbsp;
                        @endif
                    </td>
                </tr>

                {{-- Row 3: Notify Party --}}
                <tr>
                    <td class="col-left">
                        <span class="section-label">3.NOTIFY PARTY</span><br><br>
                        @if($arrival->trucking)
                            <span class="company-name">{{ strtoupper($arrival->trucking->company_name) }}</span><br>
                            {{ strtoupper($arrival->trucking->address ?? '') }}<br><br>
                            Tel: {{ $arrival->trucking->phone ?? '-' }}
                        @else
                            -
                        @endif
                    </td>
                </tr>

                {{-- Row 4: Port of Loading + Final Destination --}}
                <tr>
                    <td class="col-left">
                        <table style="width:100%; border:none;">
                            <tr>
                                <td style="border:none; padding:0; width:50%;">
                                    <span class="section-label">4.PORT OF LOADING</span><br><br>
                                    &nbsp;&nbsp;{{ $portDisplay }}
                                </td>
                                <td style="border:none; padding:0; width:50%;">
                                    <span class="section-label">5.FINAL DESTINATION</span><br><br>
                                    &nbsp;&nbsp;JAKARTA, INDONESIA
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                {{-- Row 5: Vessel + Sailing Date --}}
                <tr>
                    <td class="col-left">
                        <table style="width:100%; border:none;">
                            <tr>
                                <td style="border:none; padding:0; width:50%;">
                                    <span class="section-label">6.VESSEL</span><br><br>
                                    <strong>&nbsp;&nbsp;{{ strtoupper($arrival->vessel ?? '') }}</strong>
                                </td>
                                <td style="border:none; padding:0; width:50%;">
                                    <span class="section-label">7.SAILING ON OR ABOUT</span><br><br>
                                    <strong>&nbsp;&nbsp;{{ optional($arrival->etd)->format('d-M-y') }}</strong>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

            {{-- Packing List Items Table --}}
            <div class="invoice-items-box">
                <table class="items-table packing-items-table">
                    <colgroup>
                        <col style="width:22%;">
                        <col style="width:26%;">
                        <col style="width:26%;">
                        <col style="width:13%;">
                        <col style="width:13%;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>10.MARKS & NO. OF PKGS</th>
                            <th>11.DESCRIPTION OF GOODS</th>
                            <th>12.QUANTITY</th>
                            <th>13.NET WEIGHT</th>
                            <th>14.GROSS WEIGHT</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Marks row --}}
                        <tr>
                            <td style="vertical-align:top;">
                                NO: 1-{{ $marksNoEnd }}<br>

                            </td>
                            <td colspan="4">&nbsp;</td>
                        </tr>

                        @foreach($groupedItems as $vendorName => $items)
                            {{-- Group header row --}}
                            <tr>
                                <td>&nbsp;</td>
                                <td style="text-align:left; font-weight:bold;">
                                    {{ $vendorName ?: '-' }}
                                </td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                            </tr>

                            {{-- Item rows --}}
                            @foreach($items as $item)
                                <tr>
                                    <td>
                                        {{ strtoupper($item->display_part_name) }}
                                        @if ($item->is_foc)
                                            FOC
                                        @endif
                                    </td>
                                    <td class="text-center" style="white-space:nowrap;">{{ $item->size ?? '' }}</td>
                                    @php
                                        $packageText = (($item->qty_bundle ?? 0) > 0)
                                            ? number_format((float) ($item->qty_bundle ?? 0), 0) . ' ' . strtoupper($item->unit_bundle ?? 'BUNDLE')
                                            : '-';
                                        $goodsUnitLabel = strtoupper($item->unit_goods ?? 'PCS');
                                        $unitWeightLabel = strtoupper($item->unit_weight ?? 'KGM');
                                        $showWeightOnly = in_array($goodsUnitLabel, ['KGM', 'KG'], true);
                                        $goodsText = $showWeightOnly
                                            ? number_format((float) ($item->weight_nett ?? 0), 0) . ' ' . $unitWeightLabel
                                            : number_format((float) ($item->qty_goods ?? 0), 0) . ' ' . $goodsUnitLabel;
                                    @endphp
                                    <td class="text-center packing-bundle" style="white-space:nowrap;">
                                        <table class="packing-desc packing-qty-split">
                                            <colgroup>
                                                <col style="width:50%;">
                                                <col style="width:50%;">
                                            </colgroup>
                                            <tr>
                                                <td class="text-center" style="white-space:nowrap;">{{ $packageText }}</td>
                                                <td class="text-center" style="white-space:nowrap;">{{ $goodsText }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                    <td class="text-center" style="white-space:nowrap;">
                                        {{ number_format($item->weight_nett ?? 0, 0) }} {{ strtoupper($item->unit_weight ?? 'KGM') }}
                                    </td>
                                    <td class="text-center" style="white-space:nowrap;">
                                        {{ number_format($item->weight_gross ?? 0, 0) }}
                                        {{ strtoupper($item->unit_weight ?? 'KGM') }}</td>
                                </tr>
                            @endforeach
                        @endforeach

                        {{-- Total row --}}
                        <tr style="border-top:2px solid #000;">
                            <td class="text-bold">TOTAL :</td>
                            <td>&nbsp;</td>
                            <td class="text-center text-bold packing-bundle" style="white-space:nowrap;">
                                @php
                                    $bundleTotalsByUnit = $arrival->items
                                        ->filter(fn($i) => (float) ($i->qty_bundle ?? 0) > 0)
                                        ->groupBy(fn($i) => strtoupper(trim((string) ($i->unit_bundle ?? 'BUNDLE'))))
                                        ->map(fn($rows) => (float) $rows->sum(fn($i) => (float) ($i->qty_bundle ?? 0)))
                                        ->filter(fn($v) => $v > 0);

                                    $bundleTotalParts = [];
                                    foreach ($bundleTotalsByUnit as $unit => $value) {
                                        $bundleTotalParts[] = number_format((float) $value, 0) . ' ' . strtoupper((string) $unit);
                                    }
                                    if (empty($bundleTotalParts)) {
                                        $bundleTotalParts[] = '-';
                                    }

                                    // Quantity total only shows non-weight qty units (EA/PCS/etc).
                                    // If all goods units are KGM/KG, show the nett weight total here.
                                    $goodsTotalParts = [];
                                    foreach ($qtyTotalsNonWeight as $unit => $value) {
                                        $goodsTotalParts[] = number_format((float) $value, 0) . ' ' . strtoupper((string) $unit);
                                    }
                                    if (empty($goodsTotalParts)) {
                                        foreach ($nettTotalsByUnit as $unit => $value) {
                                            $goodsTotalParts[] = number_format((float) $value, 0) . ' ' . strtoupper((string) $unit);
                                        }
                                    }
                                    if (empty($goodsTotalParts)) {
                                        $goodsTotalParts[] = '-';
                                    }
                                @endphp
                                <div style="overflow: hidden; width: 100%;">
                                    <div style="width: 49%; float: left; text-align: center;"
                                        class="packing-total-parts">
                                        @foreach($bundleTotalParts as $part)
                                            <span>{{ $part }}</span>
                                        @endforeach
                                    </div>
                                    <div style="width: 49%; float: right; text-align: center;"
                                        class="packing-total-parts">
                                        @foreach($goodsTotalParts as $part)
                                            <span>{{ $part }}</span>
                                        @endforeach
                                    </div>
                                </div>
                            </td>
                            <td class="text-center text-bold" style="white-space:nowrap;">
                                @if($nettTotalsByUnit->count() <= 1)
                                    {{ number_format($totalNett, 0) }} {{ $weightUnitDisplay }}
                                @else
                                    @foreach($nettTotalsByUnit as $unit => $value)
                                        <div style="white-space:nowrap;">{{ number_format($value, 0) }} {{ $unit }}</div>
                                    @endforeach
                                @endif
                            </td>
                            <td class="text-center text-bold" style="white-space:nowrap;">
                                @php
                                    $grossTotalsByUnit = $arrival->items
                                        ->groupBy(fn($i) => strtoupper(trim((string) ($i->unit_weight ?? 'KGM'))))
                                        ->map(fn($items) => (float) $items->sum(fn($i) => (float) ($i->weight_gross ?? 0)))
                                        ->filter(fn($v) => $v > 0);
                                @endphp
                                @if($grossTotalsByUnit->count() <= 1)
                                    {{ number_format($totalGross, 0) }} {{ $weightUnitDisplay }}
                                @else
                                    @foreach($grossTotalsByUnit as $unit => $value)
                                        <div style="white-space:nowrap;">{{ number_format($value, 0) }} {{ $unit }}</div>
                                    @endforeach
                                @endif
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- Signature Section (match Commercial Invoice) --}}
            <div class="footer-section">
                <table style="width:100%; border:none;">
                    <tr>
                        <td style="border:none; padding:0; width:70%;">&nbsp;</td>
                        <td style="border:none; padding:0; width:30%; text-align:right; vertical-align:top;">
                            <div style="margin-top:10px;">
                                <div class="ci-original-stamp" style="transform: rotate(-6deg);">ORIGINAL</div>
                            </div>

                            <div class="ci-sign-block" style="margin-top:10px;">
                                <div class="ci-sign-top-row">
                                    <div class="ci-signedby">
                                        <div>SIGNED BY</div>
                                        @php $signedBy = strtoupper(trim((string) ($arrival->supplier?->contact_person ?? ''))); @endphp
                                        <div>{{ $signedBy !== '' ? $signedBy : ' ' }}</div>
                                    </div>

                                    <div class="ci-signature-wrap" style="margin-top:0; min-height:0;">
                                        @if($signatureSrc)
                                            <img class="ci-signature"
                                                src="{{ $signatureSrc }}"
                                                alt="Signature">
                                        @else
                                            <div style="height:20px; width:120px; display:inline-block;"></div>
                                        @endif
                                    </div>
                                </div>

                                <div class="ci-sign-line"></div>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>

        </div>
    </div>
</body>

</html>
