<?php

namespace Tests\Feature;

use App\Models\IncomingArrival;
use App\Models\Part;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class NumberingAfterSoftDeleteTest extends TestCase
{
    use DatabaseTransactions;

    public function test_arrival_no_does_not_reuse_soft_deleted_number(): void
    {
        $arrival = IncomingArrival::create([
            'arrival_no' => IncomingArrival::generateArrivalNo(),
            'is_local' => false,
            'status' => 'draft',
        ]);

        $first = $arrival->arrival_no;
        $arrival->delete();

        $this->assertNotSame($first, IncomingArrival::generateArrivalNo());
    }

    public function test_transaction_no_does_not_reuse_soft_deleted_number(): void
    {
        $date = now()->toDateString();
        $transactionNo = IncomingArrival::generateTransactionNo($date);

        $arrival = IncomingArrival::create([
            'arrival_no' => IncomingArrival::generateArrivalNo(),
            'transaction_no' => $transactionNo,
            'is_local' => false,
            'status' => 'draft',
        ]);
        $arrival->delete();

        $this->assertNotSame($transactionNo, IncomingArrival::generateTransactionNo($date));
    }

    public function test_wo_no_does_not_reuse_soft_deleted_number(): void
    {
        $workOrder = WorkOrder::create([
            'wo_no' => WorkOrder::generateWoNo(),
            'part_id' => Part::query()->value('id'),
            'qty' => 1,
            'status' => 'planned',
        ]);

        $first = $workOrder->wo_no;
        $workOrder->delete();

        $this->assertNotSame($first, WorkOrder::generateWoNo());
    }
}
