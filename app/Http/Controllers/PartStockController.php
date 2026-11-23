<?php

namespace App\Http\Controllers;

use App\Models\IncomingArrival;
use App\Models\PartStock;
use App\Models\WorkOrderMaterialBooking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PartStockController extends Controller
{
    /** Tipe part yang dipisah ke tab masing-masing. */
    public const TABS = ['material', 'wip', 'fg'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PartStock::class);

        // Stok dipisah ke tiga tab (material, WIP, FG) agar tiap jenis punya
        // kolom yang relevan tanpa tercampur.
        $tab = (string) $request->input('tab', 'material');
        $tab = in_array($tab, self::TABS, true) ? $tab : 'material';

        $arrivalType = (string) $request->input('arrival_type', '');
        $arrivalType = in_array($arrivalType, IncomingArrival::TYPES, true) ? $arrivalType : '';

        $stocks = PartStock::query()
            ->whereHas('part.partType', fn ($q) => match ($tab) {
                'wip' => $q->whereRaw('UPPER(code) = ?', ['WIP']),
                'fg' => $q->whereRaw('UPPER(code) = ?', ['FG']),
                default => $q->whereRaw("UPPER(code) NOT IN ('WIP', 'FG')"),
            })
            ->when($arrivalType !== '', fn ($q) => $q->whereHas(
                'receive.arrivalItem.arrival',
                fn ($a) => $a->where('arrival_type', $arrivalType),
            ))
            ->with([
                'part:id,part_number,part_name,model,part_type_id',
                'part.partType:id,code,name',
                'receive:id,invoice_no,arrival_item_id,location_code,packing_count,packing_type,bundle_unit,net_weight',
                'receive.packings' => fn ($q) => $q
                    ->select('id', 'receive_id', 'packing_no', 'sequence', 'qty', 'uom', 'net_weight')
                    ->orderBy('sequence')
                    ->orderBy('id'),
                'receive.arrivalItem:id,arrival_id',
                // invoice_no ikut: kolom Faktur membaca invoice arrival (dokumen
                // induk), bukan snapshot receive yang bisa basi.
                'receive.arrivalItem.arrival:id,arrival_no,arrival_type,is_local,supplier_id,subcontractor_id,customer_id,invoice_no,delivery_note_no,contract_no',
                'receive.arrivalItem.arrival.supplier:id,supplier_name',
                'receive.arrivalItem.arrival.subcontractor:id,subcontractor_name',
                'receive.arrivalItem.arrival.customer:id,customer_name',
            ])
            // Pencarian dikelompokkan: tanpa pembungkus, `orWhereHas` akan
            // melepas filter tab (material/WIP) saat mencari nomor part.
            ->when($request->input('search'), fn ($q, $s) => $q->where(function ($query) use ($s): void {
                $query->where('tag', 'ilike', "%{$s}%")
                    ->orWhereHas('part', fn ($w) => $w
                        ->where('part_number', 'ilike', "%{$s}%")
                        ->orWhere('part_name', 'ilike', "%{$s}%")
                        ->orWhere('model', 'ilike', "%{$s}%"))
                    ->orWhereHas('receive', fn ($w) => $w
                        ->where('invoice_no', 'ilike', "%{$s}%")
                        ->orWhereHas('arrivalItem.arrival', fn ($a) => $a
                            ->where('invoice_no', 'ilike', "%{$s}%")
                            ->orWhere('delivery_note_no', 'ilike', "%{$s}%")
                            ->orWhere('arrival_no', 'ilike', "%{$s}%")))
                    ->orWhereHas('receive.packings', fn ($w) => $w->where('packing_no', 'ilike', "%{$s}%"));
            }))
            ->orderByDesc('receive_id')
            ->orderBy('part_id')
            ->orderBy('tag')
            ->paginate(100)
            ->withQueryString();

        // Qty yang sedang di-book WO (belum terpakai) per baris stok.
        $bookedByStock = WorkOrderMaterialBooking::query()
            ->whereIn('part_stock_id', $stocks->pluck('id'))
            ->where('status', WorkOrderMaterialBooking::STATUS_BOOKED)
            ->groupBy('part_stock_id')
            ->selectRaw('part_stock_id, SUM(qty) AS total')
            ->pluck('total', 'part_stock_id');

        $stocks->getCollection()->transform(function (PartStock $stock) use ($bookedByStock) {
            $booked = (float) ($bookedByStock[$stock->id] ?? 0);
            $stock->setAttribute('booked_qty', round($booked, 4));
            $stock->setAttribute('avail_qty', round(max(0.0, (float) $stock->qty - $booked), 4));

            return $stock;
        });

        return Inertia::render('Incoming/Stock/Index', [
            'stocks' => $stocks,
            'tab' => $tab,
            'filters' => [
                'search' => $request->input('search'),
                'arrival_type' => $arrivalType !== '' ? $arrivalType : null,
            ],
        ]);
    }
}
