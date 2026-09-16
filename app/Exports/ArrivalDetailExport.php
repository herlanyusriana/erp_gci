<?php

namespace App\Exports;

use App\Models\IncomingArrival;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Illuminate\Support\Enumerable;

class ArrivalDetailExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithColumnWidths
{
    private IncomingArrival $arrival;

    public function __construct(IncomingArrival $arrival)
    {
        $this->arrival = $arrival;
        $this->arrival->loadMissing(['supplier', 'items.part']);
    }

    public function collection(): Enumerable
    {
        return $this->arrival->items;
    }

    public function headings(): array
    {
        return [
            'Arrival No', 'Invoice No', 'Invoice Date', 'Supplier', 'Vessel',
            'ETD', 'ETA GCI', 'Port of Loading', 'Bill of Lading', 'HS Code',
            'PEN No', 'AJU No', 'Price Term', 'Currency',
            'Part No', 'Part Name', 'Material Group', 'Size',
            'Qty Goods', 'Unit Goods', 'Qty Bundle', 'Unit Bundle',
            'Weight Nett (KG)', 'Weight Gross (KG)', 'Price', 'Total Price',
            'FOC', 'Notes',
        ];
    }

    public function map($item): array
    {
        $arrival = $this->arrival;

        return [
            $arrival->arrival_no,
            $arrival->invoice_no ?? '-',
            optional($arrival->invoice_date)->format('Y-m-d') ?? '-',
            $arrival->supplier?->supplier_name ?? '-',
            $arrival->vessel ?? '-',
            optional($arrival->etd)->format('Y-m-d') ?? '-',
            optional($arrival->eta_gci)->format('Y-m-d') ?? '-',
            $arrival->port_of_loading ?? '-',
            $arrival->bill_of_lading ?? '-',
            $arrival->hs_code ?? '-',
            $arrival->pen_no ?? '-',
            $arrival->aju_no ?? '-',
            $arrival->price_term ?? '-',
            $arrival->currency ?? '-',
            $item->part?->part_number ?? '-',
            $item->part?->part_name ?? '-',
            $item->material_group ?? '-',
            $item->size ?? '-',
            (float) ($item->qty_goods ?? 0),
            strtoupper((string) ($item->unit_goods ?? '-')),
            (float) ($item->qty_bundle ?? 0),
            $item->unit_bundle ?? '-',
            (float) ($item->weight_nett ?? 0),
            (float) ($item->weight_gross ?? 0),
            (float) ($item->price ?? 0),
            (float) ($item->total_price ?? 0),
            $item->is_foc ? 'Y' : 'N',
            $item->notes ?? '-',
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
            'A' => 15, 'B' => 15, 'C' => 12, 'D' => 24, 'E' => 14,
            'F' => 12, 'G' => 12, 'H' => 18, 'I' => 16, 'J' => 14,
            'K' => 14, 'L' => 14, 'M' => 12, 'N' => 10,
            'O' => 20, 'P' => 24, 'Q' => 16, 'R' => 18, 'S' => 14,
            'T' => 12, 'U' => 10, 'V' => 12, 'W' => 12,
            'X' => 14, 'Y' => 14, 'Z' => 12, 'AA' => 12, 'AB' => 4,
            'AC' => 20,
        ];
    }
}