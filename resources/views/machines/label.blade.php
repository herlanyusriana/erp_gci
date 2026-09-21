@php
    $companyName = $companyName ?? 'PT Geum Cheon Indo';
    $payload = [
        'type' => 'machine',
        'machine_id' => (int) $machine->id,
        'machine_code' => (string) $machine->machine_code,
        'machine_name' => (string) $machine->machine_name,
    ];
    $qrSvg = \App\Support\QrSvg::make(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 320, 0);
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Machine Label {{ $machine->machine_code }}</title>
    <style>
        @page { size: 80mm 60mm; margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-print-color-adjust: exact; }
        body { font-family: "Helvetica Neue", Arial, sans-serif; background: #eee; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .card { width: 76mm; height: 56mm; background: #fff; border: 2px solid #000; display: flex; flex-direction: column; overflow: hidden; }
        header { height: 12mm; border-bottom: 2px solid #000; display: flex; align-items: center; justify-content: space-between; padding: 0 3mm; }
        .brand { font-size: 12px; font-weight: 800; letter-spacing: 1px; }
        .kind { font-size: 10px; font-weight: 700; background: #000; color: #fff; padding: 1mm 2mm; border-radius: 1mm; }
        main { flex: 1; display: flex; align-items: center; gap: 3mm; padding: 3mm; }
        .qr { width: 30mm; height: 30mm; flex: 0 0 auto; }
        .qr svg { width: 100%; height: 100%; }
        .info { flex: 1; min-width: 0; }
        .code { font-size: 18px; font-weight: 900; word-break: break-all; }
        .name { font-size: 11px; margin-top: 1mm; word-break: break-word; }
        footer { border-top: 1px solid #000; height: 9mm; display: flex; align-items: center; padding: 0 3mm; font-size: 9px; }
    </style>
</head>
<body>
    <div class="card">
        <header>
            <div class="brand">{{ $companyName }}</div>
            <div class="kind">MACHINE</div>
        </header>
        <main>
            <div class="qr">{!! $qrSvg !!}</div>
            <div class="info">
                <div class="code">{{ $machine->machine_code }}</div>
                <div class="name">{{ $machine->machine_name }}</div>
            </div>
        </main>
        <footer>Scan untuk memilih mesin di Material Tracker</footer>
    </div>

    <script>
        window.addEventListener('load', function () { window.print(); });
    </script>
</body>
</html>
