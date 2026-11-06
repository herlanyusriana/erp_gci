<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\Part;
use App\Models\Process;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class WorkOrderProcessBOMTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);
    }

    public function test_work_order_show_renders_assembly_process_with_multiple_materials_and_tpl_line(): void
    {
        $fgPart = Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) = ?', ['fg']))->firstOrFail();
        $matPart1 = Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))->firstOrFail();
        $matPart2 = Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))->where('id', '!=', $matPart1->id)->firstOrFail();

        $processAssy = Process::firstOrCreate(['process_code' => 'TEST_ASSY'], ['process_name' => 'Assembly Full Test']);
        $machineAssy = Machine::firstOrCreate(['machine_code' => 'M_ASSY_01'], ['machine_name' => 'ASSY. TPL COMP BASE 1', 'is_active' => true]);

        $wo = WorkOrder::create([
            'wo_no' => 'WO-TEST-'.uniqid(),
            'part_id' => $fgPart->id,
            'qty' => 100,
            'status' => 'planned',
        ]);

        // Buat 2 item untuk proses assembly yang sama (meniru multi-material assembly)
        WorkOrderItem::create([
            'work_order_id' => $wo->id,
            'sequence' => 1,
            'process_id' => $processAssy->id,
            'machine_id' => $machineAssy->id,
            'parent_part_id' => $fgPart->id,
            'child_part_id' => $matPart1->id,
            'qty_required' => 100,
            'qty_consumed' => 0,
        ]);

        WorkOrderItem::create([
            'work_order_id' => $wo->id,
            'sequence' => 1,
            'process_id' => $processAssy->id,
            'machine_id' => $machineAssy->id,
            'parent_part_id' => $fgPart->id,
            'child_part_id' => $matPart2->id,
            'qty_required' => 200,
            'qty_consumed' => 0,
        ]);

        $res = $this->get(route('work-orders.show', $wo->id))->assertOk();
        $page = $res->original->getData()['page'];
        $items = $page['props']['workOrder']['items'];

        $this->assertCount(2, $items);
        $this->assertSame($matPart1->id, $items[0]['child_part_id']);
        $this->assertSame($matPart2->id, $items[1]['child_part_id']);
    }
}
