@php
    use App\Support\UomCatalog;

    $resolvedTag = $receive->tag ?: '-';
    $invoiceNo = $arrival?->invoice_no ?? '-';
    $partName = $part?->part_name ?? '-';

    $sizeText = trim((string) ($arrivalItem?->size ?: $part?->size));
    $sizeText = ($sizeText === '' || $sizeText === '-') ? null : $sizeText;

    $weightUnit = UomCatalog::WEIGHT;
    $num = fn (float $n): string => number_format($n, fmod($n, 1.0) === 0.0 ? 0 : 2);

    // 1 tag = 1 bundle. Bundle memuat beberapa packing (sub-unit fisik).
    $bundleCount = (float) ($receive->bundle_count ?: ($receive->bundle_qty ?: 1));
    $bundleUnit = strtoupper((string) ($receive->bundle_unit ?: 'BUNDLE'));
    $bundleQtyText = $weightBasis
        ? $num((float) ($receive->net_weight ?? $receive->qty ?? 0)).' '.$weightUnit
        : $num((float) ($receive->qty ?? 0)).' '.$goodsUnit;

    $packings = $receive->packings->sortBy('sequence')->values();

    $packingCount = $packings->isNotEmpty()
        ? $packings->count()
        : (int) ($receive->packing_count ?? 0);
    $packingType = strtoupper((string) ($receive->packing_type ?? ''));
    if ($packingType === '') {
        // Data lama / payload tanpa jenis kemasan: pakai istilah netral.
        $packingType = 'PACKAGES';
    }

    $packingWeights = $packings
        ->map(fn ($p) => $weightBasis
            ? ($p->net_weight !== null ? (float) $p->net_weight : null)
            : ($p->qty !== null ? (float) $p->qty : null))
        ->all();

    // Satu nilai untuk semua packing → baris ringkas "@ 1.380 KGM".
    // Bila beratnya berbeda, setiap packing ditulis sendiri agar tidak menyesatkan.
    $packingUnit = $weightBasis
        ? $weightUnit
        : (string) ($packings->first()?->uom ?? $goodsUnit);
    $uniformWeight = $packingWeights !== []
        && ! in_array(null, $packingWeights, true)
        && count(array_unique(array_map(fn ($w) => (string) round((float) $w, 4), $packingWeights))) === 1;

    $packingLines = [];
    $packingNumbers = '';
    if ($packingCount > 0) {
        $head = trim($packingCount.' '.$packingType);
        if ($packings->isNotEmpty() && $uniformWeight) {
            // Ringkas seperti label fisik: "2 COIL @ 1.380 KGM", nomor packing
            // tetap dicetak di bawahnya agar bisa dicocokkan dengan stiker coil.
            $packingLines[] = $head.' @ '.$num((float) $packingWeights[0]).' '.$packingUnit;
            $packingNumbers = $packings->map(fn ($p) => (string) $p->packing_no)->implode(' · ');
        } elseif ($packings->isNotEmpty()) {
            // Berat per packing berbeda: tulis masing-masing, jangan dirata-ratakan.
            $packingLines[] = $head;
            foreach ($packings as $index => $packing) {
                $perUnit = $packingWeights[$index] ?? null;
                if ($perUnit !== null) {
                    $packingLines[] = $packing->packing_no.' — '.$num((float) $perUnit).' '.$packingUnit;
                }
            }
        } else {
            $packingLines[] = $head.' @ '.$num((float) ($receive->packing_qty ?? 0)).' '.$packingUnit;
        }
    }

    $monthBox = str_pad((string) ($monthNumber ?? 1), 2, '0', STR_PAD_LEFT);
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Material Label Card</title>
    <style>
        @page { size: 150mm 100mm; margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body { font-family: Arial, "Helvetica Neue", Helvetica, sans-serif; background: #eee; color: #000; display: flex; justify-content: center; align-items: center; min-height: 100vh; }

        .label-outer { width: 148mm; height: 98mm; background: #fff; border: 2.5px solid #000; display: flex; flex-direction: column; overflow: hidden; }

        /* HEADER */
        header { height: 20mm; border-bottom: 2.5px solid #000; display: flex; align-items: stretch; }
        .brand { width: 20mm; display: flex; align-items: center; justify-content: center; border-right: 2.5px solid #000; }
        .brand img { width: 14mm; height: 14mm; object-fit: contain; }
        .h-title { flex: 1; display: flex; align-items: center; padding-left: 6mm; font-size: 23px; font-weight: 900; letter-spacing: 1.5px; }
        .month-chip { width: 26mm; background: #000; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 30px; font-weight: 900; }

        /* BODY */
        main { display: flex; flex: 1; min-height: 0; }
        .data { width: 62%; border-right: 2.5px solid #000; display: flex; flex-direction: column; padding: 3mm 5mm 3mm 4mm; }
        .side { width: 38%; display: flex; flex-direction: column; }

        .part-name { font-size: 12pt; font-weight: 900; text-transform: uppercase; line-height: 1.15; }
        .part-size { font-size: 8.5pt; font-weight: 600; text-transform: uppercase; color: #222; margin-top: 0.5mm; }
        /* Tag = identitas fisik bundle yang diketik operator, jadi paling menonjol.
           Invoice hanya rujukan dokumen, ditulis kecil agar tidak terbaca sebagai tag. */
        .tag-line { font-size: 19pt; font-weight: 900; text-transform: uppercase; margin-top: 4mm; letter-spacing: 0.3px; line-height: 1.1; }
        .invoice { font-size: 8.5pt; font-weight: 700; text-transform: uppercase; color: #333; margin-top: 1mm; }

        .packaging { margin-top: 3.5mm; }
        .packaging .line { font-size: 9.5pt; font-weight: 800; text-transform: uppercase; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .packaging .line + .line { margin-top: 1mm; }
        .packaging .bundle { display: flex; gap: 5mm; }
        .packaging .bundle .qty { margin-left: auto; }
        .packing-nos { margin-top: 1.2mm; font-size: 7.5pt; font-weight: 700; color: #222; line-height: 1.25; word-break: break-word; }

        .label-date { margin-top: auto; font-size: 9.5pt; font-weight: 700; }

        /* QR */
        .qr-section { height: 48mm; display: flex; align-items: center; justify-content: center; border-bottom: 2.5px solid #000; padding: 2mm; }
        .qr-section svg { width: 42mm !important; height: 42mm !important; }

        /* IQC */
        .iqc-section { flex: 1; display: flex; flex-direction: column; }
        .iqc-head { text-align: center; border-bottom: 1.5px solid #000; font-size: 10pt; font-weight: 900; letter-spacing: 1.2px; padding: 1.5mm 0; }
        .iqc-boxes { flex: 1; display: flex; }
        .iqc-box { flex: 1; display: flex; align-items: flex-end; justify-content: center; padding-bottom: 1.5mm; font-size: 8pt; font-weight: 700; color: #333; }
        .iqc-box:first-child { border-right: 1.5px solid #000; }

        .no-print { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); background: #000; color: #fff; padding: 10px 22px; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; }
        .no-print:hover { background: #333; }

        @media print {
            body { background: #fff; display: block; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="label-outer">
        <header>
            <div class="brand"><img src="{{ asset('images/logo-mark.png') }}" alt=""></div>
            <div class="h-title">LABEL MATERIAL</div>
            <div class="month-chip">{{ $monthBox }}</div>
        </header>

        <main>
            <section class="data">
                <div class="part-name">{{ $partName }}</div>
                @if ($sizeText)
                    <div class="part-size">{{ $sizeText }}</div>
                @endif

                <div class="tag-line">TAG # : {{ $resolvedTag }}</div>
                <div class="invoice">INV : {{ $invoiceNo }}</div>

                <div class="packaging">
                    <div class="line bundle">
                        <span>{{ $num($bundleCount) }} {{ $bundleUnit }}</span>
                        <span class="qty">{{ $bundleQtyText }}</span>
                    </div>
                    @foreach ($packingLines as $line)
                        <div class="line">{{ $line }}</div>
                    @endforeach
                    @if ($packingNumbers !== '')
                        <div class="packing-nos">{{ $packingNumbers }}</div>
                    @endif
                </div>

                <div class="label-date">{{ $receive->ata_date?->format('d-M-y') ?? '-' }}</div>
            </section>

            <section class="side">
                <div class="qr-section">
                    {!! $qrSvg ?? '' !!}
                </div>
                <div class="iqc-section">
                    <div class="iqc-head">IQC APPROVAL</div>
                    <div class="iqc-boxes">
                        <div class="iqc-box">STAMP</div>
                        <div class="iqc-box">TTD</div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <button class="no-print" onclick="window.print()">CETAK LABEL</button>

    <script>
        window.addEventListener('load', function () { window.print(); });
    </script>
</body>
</html>
