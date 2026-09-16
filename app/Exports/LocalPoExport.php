<?php

namespace App\Exports;

use App\Models\IncomingArrivalItem;
use Illuminate\Support\Enumerable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LocalPoExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithColumnWidths
{
    public function collection(): Enumerable
    {
        return IncomingArrivalItem::query()
            ->with(['arrival.supplier', 'part', 'receives'])
            ->whereHas('arrival', fn ($q) => $q->where('is_local', true))
            ->join('incoming_arrivals', 'incoming_arrivals.id', '=', 'incoming_arrival_items.arrival_id')
            ->orderByDesc('incoming_arrivals.invoice_date')
            ->orderBy('incoming_arrivals.invoice_no')
            ->select('incoming_arrival_items.*')
            ->get();
    }

    public function headings(): array
    {
        return [
            'PO No', 'PO Date', 'Supplier', 'Part No', 'Part Name', 'Size',
            'Qty Ordered', 'Unit', 'Price', 'Total Price',
            'Qty Received', 'Remaining', 'Currency',
        ];
    }

    public function map($item): array
    {
        $arrival = $item->arrival;
        $received = (float) $item->receives->sum('qty');

        return [
            $arrival?->invoice_no ?? '',
            optional($arrival?->invoice_date)->format('Y-m-d') ?? '',
            $arrival?->supplier?->supplier_name ?? '',
            $item->part?->part_number ?? '',
            $item->part?->part_name ?? '',
            $item->size ?? '',
            (float) $item->qty_goods,
            $item->unit_goods ?? '',
            (float) ($item->price ?? 0),
            (float) ($item->total_price ?? 0),
            $received,
            max(0, (float) $item->qty_goods - $received),
            $arrival?->currency ?? 'IDR',
        ];
    }

    public function styles(Worksheet $sheet): ?array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 22, 'B' => 14, 'C' => 24, 'D' => 20, 'E' => 28, 'F' => 14,
            'G' => 14, 'H' => 10, 'I' => 14, 'J' => 16, 'K' => 14, 'L' => 14, 'M' => 10,
        ];
    }
}
