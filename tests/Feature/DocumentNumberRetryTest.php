<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DocumentNumberRetryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());
    }

    /**
     * Tabrakan disimulasikan seperti request lain yang menyisipkan nomor yang
     * sama di antara `generateWoNo()` dan INSERT: listener `creating` memaksa
     * percobaan pertama memakai nomor milik WO yang sudah ada, sehingga INSERT
     * benar-benar melanggar `work_orders_wo_no_unique`. Percobaan kedua
     * menghasilkan nomor baru dan berhasil.
     */
    public function test_work_order_creation_retries_after_a_duplicate_number(): void
    {
        $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();

        $taken = WorkOrder::generateWoNo();
        WorkOrder::create([
            'wo_no' => $taken,
            'part_id' => $fg->id,
            'qty' => 1,
            'status' => 'planned',
        ]);

        $attempts = 0;
        Event::listen('eloquent.creating: '.WorkOrder::class, function (WorkOrder $workOrder) use (&$attempts, $taken): void {
            $attempts++;

            if ($attempts === 1) {
                $workOrder->wo_no = $taken;
            }
        });

        $this->post(route('work-orders.store'), [
            'part_id' => $fg->id,
            'qty' => 10,
        ])->assertRedirect();

        $created = WorkOrder::query()->latest('id')->firstOrFail();

        expect($attempts)->toBe(2)
            ->and($created->wo_no)->not->toBe($taken)
            ->and(WorkOrder::withTrashed()->where('wo_no', $taken)->count())->toBe(1);
    }
}
