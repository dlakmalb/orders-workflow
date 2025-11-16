<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add indexes to improve query performance
        // Note: Skipping indexes that already exist from table creation or unique constraints

        // customers.external_id has unique constraint (creates index automatically)
        // customers.email already has index from table creation

        // products.sku has unique constraint (creates index automatically)

        Schema::table('orders', function (Blueprint $table) {
            // external_order_id has unique constraint (creates index automatically)
            // status and [customer_id, status] already have indexes
            $table->index('placed_at');
        });

        Schema::table('order_items', function (Blueprint $table) {
            // order_id and product_id exist separately, adding composite index
            $table->index(['order_id', 'product_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            // order_id has unique constraint (creates index automatically)
            $table->index(['order_id', 'status']);
            $table->index('provider_ref');
        });

        // refunds.idempotency_key has unique constraint (creates index automatically)

        Schema::table('notification_logs', function (Blueprint $table) {
            // Adding sent_at based indexes (existing indexes use created_at)
            $table->index(['customer_id', 'sent_at']);
            $table->index('sent_at');
        });

        // Add check constraints for data integrity (MySQL 8.0.16+)
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_cents_positive CHECK (price_cents >= 0)');
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_stock_qty_non_negative CHECK (stock_qty >= 0)');
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_cents_non_negative CHECK (total_cents >= 0)');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_unit_price_positive CHECK (unit_price_cents > 0)');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_qty_positive CHECK (qty > 0)');
            DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_positive CHECK (amount_cents > 0)');
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive CHECK (amount_cents > 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop check constraints
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_price_cents_positive');
            DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_stock_qty_non_negative');
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_total_cents_non_negative');
            DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_unit_price_positive');
            DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_qty_positive');
            DB::statement('ALTER TABLE refunds DROP CONSTRAINT IF EXISTS refunds_amount_positive');
            DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_amount_positive');
        }

        // Drop only the indexes we added in up()
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['placed_at']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'product_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'status']);
            $table->dropIndex(['provider_ref']);
        });

        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'sent_at']);
            $table->dropIndex(['sent_at']);
        });
    }
};
