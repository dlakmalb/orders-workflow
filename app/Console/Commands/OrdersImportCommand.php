<?php

namespace App\Console\Commands;

use App\Jobs\ProcessOrderJob;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;

class OrdersImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:import {path : CSV file path}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import orders CSV and enqueue processing jobs';

    private array $ordersResetThisRun = [];

    private array $ordersToEnqueue = [];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $path = $this->argument('path');

        if (! is_readable($path)) {
            $this->error("File not readable: {$path}");

            return self::FAILURE;
        }

        $stream = fopen($path, 'rb');

        // Process CSV in a memory-efficient way using LazyCollection.
        // Read one row at a time, validate, and import.
        $rows = LazyCollection::make(function () use ($stream) {
            $header = null;
            $lineNo = 0;

            while (($rowData = fgetcsv($stream)) !== false) {
                $lineNo++;

                if ($lineNo === 1) {
                    $header = $this->validateAndNormalizeHeader($rowData);

                    if (! $header) {
                        break;
                    }

                    continue;
                }

                // Map row to associative array.
                yield $this->mapRow($header, $rowData, $lineNo);
            }

            fclose($stream);
        });

        $importedCount = 0;
        $skippedCount = 0;

        foreach ($rows as $row) {
            if ($row === null) {
                $skippedCount++;

                continue;
            }

            try {
                $this->importRow($row);
                $importedCount++;
            } catch (\Throwable $e) {
                Log::error('Import error at row', ['row' => $row, 'exception' => $e]);

                $this->warn("Row import failed (line {$row['_line']}). See logs.");
                $skippedCount++;
            }
        }

        // Finalize: recompute totals for each order.
        $this->finalizeOrders();

        $this->info("Import complete. Imported {$importedCount} rows, skipped {$skippedCount}.");
        $this->info('Queued processing for '.count($this->ordersToEnqueue).' orders.');

        return self::SUCCESS;
    }

    private function validateAndNormalizeHeader(array $header): ?array
    {
        $header = array_map(fn ($h) => trim(mb_strtolower($h)), $header);

        $required = [
            'external_order_id',
            'order_placed_at',
            'currency',
            'customer_id',
            'customer_email',
            'customer_name',
            'product_sku',
            'product_name',
            'unit_price_cents',
            'qty',
        ];

        $missing = array_values(array_diff($required, $header));

        if ($missing) {
            $this->error('Missing required columns: '.implode(', ', $missing));

            return null;
        }

        return $header;
    }

    private function mapRow(array $header, array $data, int $lineNo): ?array
    {
        // If the row has fewer cells than headers, skip it.
        if (count($data) < count($header)) {
            $this->warn("Line {$lineNo}: column count mismatch (got ".count($data).' expected '.count($header).') Skipping.');

            return null;
        }

        $assoc = array_combine($header, $data);
        $assoc['_line'] = $lineNo;

        return $assoc;
    }

    private function importRow(array $row): void
    {
        $externalOrderId = trim((string) $row['external_order_id']);
        $currency = trim((string) $row['currency']);
        $customerExternalId = trim((string) $row['customer_id']);
        $customerEmail = trim((string) $row['customer_email']);
        $customerName = trim((string) $row['customer_name']);
        $sku = trim((string) $row['product_sku']);
        $productName = trim((string) $row['product_name']);

        $unitPriceCents = (int) $row['unit_price_cents'];
        $qty = (int) $row['qty'];

        if ($unitPriceCents < 0 || $qty < 1) {
            $this->warn("Line {$row['_line']}: invalid money/qty; skipping.");

            return;
        }

        try {
            $placedAt = CarbonImmutable::parse($row['order_placed_at']);
        } catch (\Throwable $e) {
            $this->warn("Line {$row['_line']}: invalid order_placed_at; skipping.");

            return;
        }

        DB::transaction(function () use (
            $externalOrderId,
            $currency,
            $customerExternalId,
            $customerEmail,
            $customerName,
            $sku,
            $productName,
            $unitPriceCents,
            $qty,
            $placedAt,
        ) {
            // Upsert customer by external_id.
            $customer = $this->upsertCustomer($customerExternalId, $customerEmail, $customerName);

            // Upsert product by SKU.
            $product = $this->upsertProduct($sku, $productName, $unitPriceCents);

            // Upsert order by external_order_id.
            $order = $this->upsertOrder($externalOrderId, $customer->id, $currency, $placedAt);

            $this->resetOrderItemsOnce($order->id);
            $this->addOrderItem($order->id, $product->id, $unitPriceCents, $qty);
        });
    }

    private function upsertCustomer(string $externalId, string $email, string $name): Customer
    {
        return Customer::updateOrCreate(
            ['external_id' => $externalId],
            ['email' => $email, 'name' => $name]
        );
    }

    private function upsertProduct(string $sku, string $name, int $priceCents): Product
    {
        return Product::updateOrCreate(
            ['sku' => $sku],
            [
                'name' => $name,
                'price_cents' => $priceCents,
                'stock_qty' => 50,
            ]
        );
    }

    private function upsertOrder(
        string $externalOrderId,
        int $customerId,
        string $currency,
        CarbonImmutable $placedAt
    ): Order {
        return Order::updateOrCreate(
            ['external_order_id' => $externalOrderId],
            [
                'customer_id' => $customerId,
                'currency' => $currency,
                'placed_at' => $placedAt,
            ]
        );
    }

    /**
     * Delete existing items for an order once per import run, and mark for enqueue.
     */
    private function resetOrderItemsOnce(int $orderId): void
    {
        if (! isset($this->ordersResetThisRun[$orderId])) {
            OrderItem::where('order_id', $orderId)->delete();

            $this->ordersResetThisRun[$orderId] = true;
            $this->ordersToEnqueue[$orderId] = true;
        }
    }

    private function addOrderItem(
        int $orderId,
        int $productId,
        int $unitPriceCents,
        int $qty
    ): void {
        OrderItem::create([
            'order_id' => $orderId,
            'product_id' => $productId,
            'unit_price_cents' => $unitPriceCents,
            'qty' => $qty,
        ]);
    }

    private function finalizeOrders(): void
    {
        $orderIds = array_keys($this->ordersToEnqueue);

        if (empty($orderIds)) {
            Log::info('No orders to finalize.');

            return;
        }

        // Recompute totals in chunks to avoid giant IN() lists
        collect($orderIds)->chunk(500)->each(function (Collection $chunk) {
            $ids = $chunk->all();

            // Recompute totals per order
            $sums = OrderItem::query()
                ->selectRaw('order_id, SUM(subtotal_cents) AS total')
                ->whereIn('order_id', $ids)
                ->groupBy('order_id')
                ->pluck('total', 'order_id'); // [order_id => total]

            foreach ($ids as $orderId) {
                $total = (int) ($sums[$orderId] ?? 0);

                Order::whereKey($orderId)->update(['total_cents' => $total]);

                // Enqueue processing job once per order
                ProcessOrderJob::dispatch($orderId);
            }
        });
    }
}
