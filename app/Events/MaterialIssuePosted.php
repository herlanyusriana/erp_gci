<?php

namespace App\Events;

use App\Models\MaterialIssue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MaterialIssuePosted implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $connection = 'database';

    public function __construct(public readonly array $payload) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('issue-out-monitoring')];
    }

    public function broadcastAs(): string
    {
        return 'material-issue.posted';
    }

    /**
     * @return array{issue: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        return ['issue' => $this->payload];
    }

    /**
     * @return array<string, mixed>
     */
    public static function payloadFromIssue(MaterialIssue $issue): array
    {
        $issue->loadMissing([
            'workOrder:id,wo_no',
            'issuer:id,name',
            'items:id,material_issue_id,tag,qty,uom',
        ]);

        $qtyByUom = [];
        $tags = [];
        foreach ($issue->items as $item) {
            $uom = (string) ($item->uom ?: '—');
            $qtyByUom[$uom] = ($qtyByUom[$uom] ?? 0) + (float) $item->qty;
            if ($item->tag !== null && $item->tag !== '') {
                $tags[$item->tag] = true;
            }
        }

        ksort($qtyByUom);

        return [
            'issue_id' => (int) $issue->id,
            'issue_no' => $issue->issue_no,
            'work_order_id' => (int) $issue->work_order_id,
            'work_order_no' => $issue->workOrder?->wo_no,
            'operator_id' => $issue->issued_by !== null ? (int) $issue->issued_by : null,
            'operator_name' => $issue->issuer?->name,
            'received_by' => $issue->received_by,
            'status' => $issue->status,
            'issue_date' => $issue->issue_date?->toDateString() ?? (string) $issue->issue_date,
            'item_count' => $issue->items->count(),
            'tag_count' => count($tags),
            'qty_by_uom' => $qtyByUom,
        ];
    }
}
