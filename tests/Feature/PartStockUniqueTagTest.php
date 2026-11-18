<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\PartStock;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartStockUniqueTagTest extends TestCase
{
    use DatabaseTransactions;

    private function makeStock(int $partId, string $tag): PartStock
    {
        return PartStock::create([
            'part_id' => $partId,
            'tag' => $tag,
            'qty' => 5,
            'qty_unit' => 'KGM',
            'received_at' => now(),
        ]);
    }

    public function test_part_stocks_rejects_duplicate_part_and_tag(): void
    {
        $partId = (int) Part::where('part_number', 'AAN30056405')->value('id');
        $tag = 'T-UNIQ-'.Str::upper(Str::random(8));

        $this->makeStock($partId, $tag);

        try {
            DB::transaction(fn () => $this->makeStock($partId, $tag));
            $this->fail('Duplikat (part_id, tag) seharusnya ditolak unique index.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', (string) $exception->getCode());
        }

        $this->assertSame(1, PartStock::where('part_id', $partId)->where('tag', $tag)->count());
    }

    public function test_soft_deleted_stock_does_not_block_the_same_tag(): void
    {
        $partId = (int) Part::where('part_number', 'AAN30056405')->value('id');
        $tag = 'T-UNIQ-'.Str::upper(Str::random(8));

        $this->makeStock($partId, $tag)->delete();

        $this->makeStock($partId, $tag);

        $this->assertSame(1, PartStock::where('part_id', $partId)->where('tag', $tag)->count());
        $this->assertSame(2, PartStock::withTrashed()->where('part_id', $partId)->where('tag', $tag)->count());
    }
}
