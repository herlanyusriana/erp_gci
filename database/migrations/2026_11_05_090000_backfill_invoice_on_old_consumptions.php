<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Isi balik invoice pada konsumsi lama.
 *
 * Sebelum perbaikan, konsumsi hanya menyimpan part_stock_id/receipt, tanpa
 * invoice. Datanya tidak hilang: penerimaan material di mesin masih menyimpan
 * invoice & supplier, dan bisa disambungkan lewat receipt_id maupun part_stock_id.
 *
 * Migrasi ini idempoten: hanya mengisi baris yang invoice-nya masih kosong.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Jalur 1: konsumsi punya production_material_receipt_id (paling akurat).
        DB::statement('
            UPDATE work_order_consumptions c
            SET invoice_no = r.invoice,
                supplier_name = r.supplier,
                source_tag = COALESCE(c.source_tag, r.tag)
            FROM production_material_receipts r
            WHERE c.production_material_receipt_id = r.id
              AND c.invoice_no IS NULL
              AND r.invoice IS NOT NULL
              AND c.deleted_at IS NULL
              AND c.reversed_by IS NULL
        ');

        // Jalur 2: konsumsi hanya punya part_stock_id (mis. konsumsi WIP internal).
        // Ambil satu receipt yang cocok untuk stok itu.
        DB::statement('
            UPDATE work_order_consumptions c
            SET invoice_no = src.invoice,
                supplier_name = src.supplier,
                source_tag = COALESCE(c.source_tag, src.tag)
            FROM (
                SELECT DISTINCT ON (part_stock_id) part_stock_id, invoice, supplier, tag
                FROM production_material_receipts
                WHERE invoice IS NOT NULL
                ORDER BY part_stock_id, id
            ) src
            WHERE c.part_stock_id = src.part_stock_id
              AND c.invoice_no IS NULL
        ');

        // Jalur 3: stok yang dipakai lahir dari penerimaan gudang langsung.
        DB::statement('
            UPDATE work_order_consumptions c
            SET invoice_no = src.invoice_no,
                supplier_name = COALESCE(c.supplier_name, src.supplier_name)
            FROM (
                SELECT ps.id AS part_stock_id,
                       r.invoice_no,
                       s.supplier_name
                FROM part_stocks ps
                JOIN incoming_receives r ON r.id = ps.receive_id
                LEFT JOIN incoming_arrival_items ai ON ai.id = r.arrival_item_id
                LEFT JOIN incoming_arrivals a ON a.id = ai.arrival_id
                LEFT JOIN suppliers s ON s.id = a.supplier_id
                WHERE r.invoice_no IS NOT NULL
            ) src
            WHERE c.part_stock_id = src.part_stock_id
              AND c.invoice_no IS NULL
        ');
    }

    public function down(): void
    {
        // Sengaja tidak mengosongkan: data hasil backfill tetap valid.
    }
};
