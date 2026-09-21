<?php

namespace App\Console\Commands;

use App\Models\WorkOrder;
use App\Services\WoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildProductionPlan extends Command
{
    protected $signature = 'production-plan:rebuild
        {--wo= : ID WO tertentu (opsional)}
        {--date= : Hanya plan pada tanggal ini, format Y-m-d (opsional)}
        {--dry-run : Tampilkan rencana tanpa mengubah data}';

    protected $description = 'Susun ulang baris Production Plan: satu baris per grup mesin (3 huruf pertama nama mesin), input → output, target D/D1/D2 dikosongkan.';

    public function handle(WoService $woService): int
    {
        $workOrders = WorkOrder::query()
            ->whereIn('status', ['planned', 'in_progress'])
            ->whereHas('planItems')
            ->when($this->option('wo'), fn ($q, $id) => $q->whereKey((int) $id))
            ->with(['planItems.plan', 'part'])
            ->get();

        if ($date = $this->option('date')) {
            $workOrders = $workOrders
                ->filter(fn (WorkOrder $wo) => $wo->planItems->contains(
                    fn ($item) => $item->plan?->plan_date?->toDateString() === $date,
                ))
                ->values();
        }

        if ($workOrders->isEmpty()) {
            $this->warn('Tidak ada WO dengan baris plan yang cocok.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        foreach ($workOrders as $workOrder) {
            $planDate = $workOrder->planItems->first()?->plan?->plan_date?->toDateString()
                ?? $workOrder->planned_date?->toDateString()
                ?? now()->toDateString();

            $before = $workOrder->planItems->count();

            if ($dryRun) {
                $this->line(sprintf(
                    '[dry-run] %s (%s) — %d baris → disusun ulang pada %s',
                    $workOrder->wo_no,
                    $workOrder->status,
                    $before,
                    $planDate,
                ));

                continue;
            }

            $after = DB::transaction(function () use ($workOrder, $woService, $planDate) {
                $workOrder->planItems()->delete();

                return $woService->populatePlanItems(
                    $workOrder,
                    $planDate,
                    (int) ($workOrder->updated_by ?? $workOrder->created_by ?? 0),
                );
            });

            $this->info(sprintf(
                '%s (%s) — %d → %d baris pada %s',
                $workOrder->wo_no,
                $workOrder->status,
                $before,
                $after,
                $planDate,
            ));
        }

        if (! $dryRun) {
            $this->warn('Target D/D1/D2 pada baris lama ikut direset — kolom "Sisa Jumlah WO" kembali ke qty WO.');
        }

        return self::SUCCESS;
    }
}
