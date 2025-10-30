<div align="center">
    <h1>
        📦 Orders Process Workflow<br/>
        <sub><sup><sub>End-to-end asynchronous order management system</sub></sup></sub><br/>
    </h1>
</div>
<br/>

## 📝 Summary

This project demonstrates asynchronous order processing with Laravel, Redis and Horizon.
- CSV import → queued order processing
- Stock reservation with concurrency control
- Simulated payment + callback (PAID/FAILED)
- Queued notifications with history table
- Refunds (partial/full) processed asynchronously
- Real-time KPIs (revenue / order count / AOV) + customer leaderboard in Redis

## 🚀 Workflow Overview
1. Import orders `OrdersImportCommand`
    * Validates CSV header.
    * Record customers/products/orders details.
    * Recomputes order totals.
    * Dispatches `ProcessOrderJob` per order.
2. Order processing `ProcessOrderJob`
    * Groups items by product.
    * Acquires per-product Redis locks (avoid oversell).
    * DB transaction + `lockForUpdate()` to check+decrement stock.
    * Dispatches `FakeGatewayChargeJob`.
3. Payment simulation `FakeGatewayChargeJob`
    * This waits sometime and dispatches `PaymentCallbackJob` with success/failure.
4. Finalize / Rollback `PaymentCallbackJob`
    * On success → creates/updates payment, marks order `PAID`.
    * On failure → restores stock, marks order `FAILED`.
    * Updates KPIs/leaderboard via `KpiService`.
    * Dispatches `OrderProcessedNotification` and logs to `notification_logs`.
5. Notifications `OrderProcessedNotification`
    * writes a history row with `order_id`, `customer_id`, `status`, `total_cents`, `channel`, `payload`, `sent_at`.
6. Refunds (Partial or Full) `ProcessRefundJob`
    * Validates amount vs order total.
    * Idempotency via unique idempotency_key (optional) and status checks.
    * Updates KPIs/leaderboard immediately via `KpiService::recordRefund()`.
    * Marks refund `PROCESSED` (or `FAILED`)

## ⚙️ Technology Stack

- **PHP 8.2+**
- **Laravel 12**
- **MySQL 8+**
- **Laravel Horizon**
- **Redis** (queues, cache, session, KPIs/leaderboard)

<p align="left">
  <a href="https://skillicons.dev">
    <img src="https://skillicons.dev/icons?i=php,laravel,mysql,redis" />
  </a>
</p>

## 🛠️ Manual Setup (Local)
1. Clone the repository<br/>
```
git clone https://github.com/dlakmalb/orders-workflow.git
cd orders-workflow
```
2. Install dependencies<br/>
```
composer install
```
3. Environment setup<br/>
```
cp .env.example .env
php artisan key:generate
```
4. Update `.env` to use Redis for everything
```
DB_DATABASE=your_db
DB_USERNAME=your_user
DB_PASSWORD=your_pass

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
```
5. Migrate the database
```
php artisan migrate
```

## 📁 Sample CSV
Place a file at `orders_workflow/file.csv`.
Minimal columns (headers required):
```
external_order_id
order_placed_at
currency
customer_id
customer_email
customer_name
product_sku
product_name
unit_price_cents
qty
```

## 📺 Required Commands
1. Import Orders (streamed & queued)
```
php artisan orders:import file.csv
```
2. Start Queue Workers
```
# Core processing (default queue)
php artisan queue:work

# Notifications queue
php artisan queue:work --queue=notifications

# Refunds queue
php artisan queue:work --queue=refunds
```
3. Horizon (queue dashboard)
```
php artisan horizon
# visit http://orders-workflow.test/horizon
```
4. Refunds (Partial or Full)
```
php artisan orders:refund {order_id} {amount_cents} [--reason="text"] [--key="unique-id"]
# example:
php artisan orders:refund 42 5000 --reason="Partial refund" --key="GW-REF-0001"

```

## 📈 KPIs & Leaderboard (Redis)
Daily KPIs (string keys)
```
kpi:YYYY-MM-DD:revenue_cents
kpi:YYYY-MM-DD:order_count
kpi:YYYY-MM-DD:avg_order_value_cents
```

Leaderboard (sorted set)
```
leaderboard:customers
```
