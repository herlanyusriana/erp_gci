<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Purchase Order Confirmation {{ $purchaseOrder->po_no }}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: Verdana, "DejaVu Sans", Geneva, sans-serif;
    font-size: 12px;
    color: #222;
    background: #e9e9e9;
    padding: 16px;
  }
  .toolbar { max-width: 297mm; margin: 0 auto 10px; text-align: right; }
  .toolbar button {
    padding: 8px 16px; font-size: 13px; cursor: pointer;
    background: #333; color: #fff; border: none; border-radius: 4px;
    font-family: Arial, sans-serif;
  }
  .page {
    background: #fff;
    width: 297mm;
    max-width: 100%;
    min-height: 210mm;
    margin: 0 auto;
    padding: 8mm 10mm;
    box-shadow: 0 0 6px rgba(0,0,0,.25);
    overflow-x: auto;
  }
  .inner { min-width: 900px; }

  /* ===== Header ===== */
  .head { position: relative; height: 62px; display: flex; align-items: center; }
  .logo-big { height: 52px; width: auto; object-fit: contain; }
  .vendor-tag {
    position: absolute; left: 58%; bottom: 3px;
    font-size: 9px; font-weight: bold;
  }
  .rule { border-top: 2px solid #333; }

  .addr-row { display: flex; margin-top: 4px; }
  .addr-left { width: 58%; font-size: 10px; font-weight: bold; line-height: 1.35; padding-left: 2px; }
  .addr-right { font-size: 11px; font-weight: bold; line-height: 1.35; padding-top: 6px; }
  .addr-right .phone { margin-top: 14px; }

  /* ===== Title ===== */
  h1.title {
    font-size: 17px; font-weight: normal; color: #333;
    margin: 30px 0 22px 2px;
  }

  /* ===== Reference ===== */
  .ref { display: grid; grid-template-columns: 29% 24% 1fr; margin: 0 0 30px 2px; }
  .ref b { display: block; font-weight: bold; font-size: 13px; }
  .ref span { display: block; margin-top: 12px; font-size: 13px; }

  /* ===== Items ===== */
  table.items { width: 100%; border-collapse: collapse; table-layout: fixed; }
  table.items thead th {
    text-align: left; font-size: 12px; font-weight: bold;
    border-bottom: 3px solid #333; padding: 0 2px 2px;
    height: 24px; vertical-align: bottom;
  }
  table.items thead th.unit { text-align: right; padding-right: 14px; }
  table.items td { padding: 12px 2px 0; font-size: 12px; vertical-align: top; }
  .money { display: flex; justify-content: space-between; padding-right: 8px; white-space: nowrap; }
  .net { padding-left: 10px; white-space: nowrap; }
  .qty-total { font-weight: bold; padding-top: 10px; }

  /* ===== Totals ===== */
  .totals { margin-top: 26px; margin-left: 52.5%; }
  .totals .row { display: grid; grid-template-columns: 1fr 38%; align-items: center; height: 40px; }
  .totals .line { border-top: 3px solid #333; }
  .totals .label { font-weight: bold; font-size: 13px; padding-left: 8px; }
  .totals .val { font-size: 12px; padding-left: 10px; white-space: nowrap; }

  @page { size: A4 landscape; margin: 8mm; }
  @media print {
    body { background: #fff; padding: 0; }
    .page { box-shadow: none; padding: 0; width: auto; min-height: auto; overflow: visible; }
    .toolbar { display: none; }
  }
</style>
</head>
<body>

<div class="toolbar"><button onclick="window.print()">Cetak PO</button></div>

<div class="page"><div class="inner">

  <div class="head">
    <img src="{{ asset('images/logo-big.jpg') }}" alt="{{ $companyName }} - All of the Metal Manufacture" class="logo-big">
    <div class="vendor-tag">{{ strtoupper($companyName) }}</div>
  </div>
  <div class="rule"></div>

  <div class="addr-row">
    <div class="addr-left">
      Shipping address:<br>
      {{ $companyName }}<br>
      Jl. Raya Serang km 12,5 Kec. Cikupa<br>
      Tangerang<br>
      Indonesia<br>
      Telp. Fax.
    </div>
    <div class="addr-right">
      {{ $purchaseOrder->supplier?->supplier_name ?? '—' }}<br>
      {!! nl2br(e($purchaseOrder->supplier?->address ?? '')) !!}
      @if($purchaseOrder->supplier?->phone)
        <div class="phone">{{ $purchaseOrder->supplier->phone }}</div>
      @endif
    </div>
  </div>

  <h1 class="title">Purchase Order Confirmation {{ $purchaseOrder->po_no }}</h1>

  <div class="ref">
    <div><b>Our Order Reference:</b><span>{{ $purchaseOrder->po_no }}</span></div>
    <div><b>Order Date:</b><span>{{ $purchaseOrder->po_date ? \Carbon\Carbon::parse($purchaseOrder->po_date)->format('d M Y') : '—' }}</span></div>
    <div><b>Validate By:</b><span>{{ $purchaseOrder->creator?->name ?? '—' }}</span></div>
  </div>

  <table class="items">
    <colgroup>
      <col style="width:36.6%">
      <col style="width:14.8%">
      <col style="width:15.5%">
      <col style="width:13.7%">
      <col style="width:19.4%">
    </colgroup>
    <thead>
      <tr>
        <th>No. Description</th>
        <th>Date Req.</th>
        <th>Qty</th>
        <th class="unit">Unit Price</th>
        <th>Net Price</th>
      </tr>
    </thead>
    <tbody>
      @forelse($purchaseOrder->items as $index => $item)
        @php
          $itemPrice = (float) ($item->price ?? 0);
          $itemQty = (float) $item->qty;
          $itemNet = $itemQty * $itemPrice;
          $desc = trim(($item->part?->part_number ?? '') . ' ' . ($item->part?->part_name ?? ''));
          if (!empty($item->size)) {
            $desc .= ($desc !== '' ? ' ' : '') . $item->size;
          }
        @endphp
        <tr>
          <td>{{ $index + 1 }}. {{ $desc ?: '—' }}</td>
          <td>{{ $purchaseOrder->expected_date ? \Carbon\Carbon::parse($purchaseOrder->expected_date)->format('d M Y') : '—' }}</td>
          <td>{{ number_format($itemQty, 2) }} {{ $item->unit ?? '' }}</td>
          <td><div class="money"><span>{{ $currency }}</span><span>{{ number_format($itemPrice, 2) }}</span></div></td>
          <td class="net">{{ $currency }} {{ number_format($itemNet, 2) }}</td>
        </tr>
      @empty
        <tr>
          <td colspan="5" style="text-align: center; padding: 20px 0; color: #888;">No items in this purchase order.</td>
        </tr>
      @endforelse
      @if($purchaseOrder->items->isNotEmpty())
        <tr>
          <td></td><td></td>
          <td class="qty-total">{{ number_format($totalQty, 2) }}</td>
          <td></td><td></td>
        </tr>
      @endif
    </tbody>
  </table>

  <div class="totals">
    <div class="line"></div>
    <div class="row"><div class="label">Total Without Taxes</div><div class="val">{{ $currency }} {{ number_format($totalAmount, 2) }}</div></div>
    <div class="row"><div class="label">Additional</div><div class="val">{{ $currency }} 0.00</div></div>
    <div class="row"><div class="label">Discount</div><div class="val">{{ $currency }} 0.00</div></div>
    <div class="row"><div class="label">PPh</div><div class="val">{{ $currency }} 0.00</div></div>
    <div class="row"><div class="label">Taxes</div><div class="val">{{ $currency }} 0.00</div></div>
    <div class="line"></div>
    <div class="row"><div class="label">Biaya Kirim</div><div class="val">{{ $currency }} 0.00</div></div>
    <div class="line"></div>
    <div class="row"><div class="label"></div><div class="val">{{ $currency }} {{ number_format($totalAmount, 2) }}</div></div>
  </div>

</div></div>

</body>
</html>
