<div align="center">
    <h1>
        📦 Orders Process Workflow<br/>
        <sub><sup><sub>Production-ready asynchronous order management system</sub></sup></sub><br/>
    </h1>

[![CI](https://github.com/dlakmalb/orders-workflow/actions/workflows/ci.yml/badge.svg)](https://github.com/dlakmalb/orders-workflow/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12-red)](https://laravel.com)

</div>

## 📝 Summary

This project is a **production-ready**, fully-tested asynchronous order processing system built with Laravel 12, Redis, and Horizon. It demonstrates enterprise-grade patterns including:

- 🔄 Asynchronous job processing with queue separation
- 🔒 Distributed locking for stock reservation (prevents race conditions)
- 📊 Real-time KPIs with Redis (revenue, order count, AOV, customer leaderboard)
- 💰 Refund management with idempotency
- 🧪 Comprehensive test coverage with factories
- 🐳 Docker-ready with full CI/CD pipeline
- 🏗️ Clean architecture with Services, DTOs, and proper exception handling

---

## 🏛️ Architecture

### System Flow

```
CSV Import → Order Processing → Payment → Notification
     ↓              ↓              ↓           ↓
  Database    Stock Reserve    KPI Update   Log History

Refunds → Validation → Processing → KPI Adjustment
```

### Key Components

- **Services Layer**: Business logic separation (OrderService, StockService, RefundService, KpiService)
- **Jobs**: Asynchronous processing (ProcessOrderJob, PaymentCallbackJob, ProcessRefundJob, OrderProcessedNotification)
- **Models**: Eloquent ORM with proper relationships
- **Exceptions**: Custom domain exceptions for better error handling
- **Factories**: Test data generation for comprehensive testing

---

## 🚀 Workflow Overview

### 1. Order Import (`OrdersImportCommand`)
- Validates CSV header and data
- Streams large files efficiently using `LazyCollection`
- Upserts customers, products, and orders
- Recomputes order totals
- Dispatches `ProcessOrderJob` per order

### 2. Order Processing (`ProcessOrderJob`)
- Groups items by product
- Acquires Redis distributed locks (prevents overselling)
- Uses database `lockForUpdate()` for row-level locking
- Reserves stock atomically
- Dispatches `FakeGatewayChargeJob` (simulated payment gateway)

### 3. Payment Simulation (`FakeGatewayChargeJob`)
- Configurable success rate (default: 90%)
- Simulates payment processing delay
- Dispatches `PaymentCallbackJob` with success/failure

### 4. Payment Callback (`PaymentCallbackJob`)
- **On Success**: Creates/updates payment, marks order `PAID`, updates KPIs
- **On Failure**: Restores stock, marks order `FAILED`, updates KPIs
- Dispatches `OrderProcessedNotification`

### 5. Notifications (`OrderProcessedNotification`)
- Writes notification history to database
- Logs notification details
- Extensible for email/SMS/Slack channels

### 6. Refunds (`ProcessRefundJob`)
- Validates refund amount against order total
- Idempotency via unique `idempotency_key`
- Atomically updates KPIs and leaderboard
- Marks refund `PROCESSED` or `FAILED`

---

## ⚙️ Technology Stack

<p align="left">
  <img src="https://skillicons.dev/icons?i=php,laravel,mysql,redis,docker,github" />
</p>

- **PHP 8.2+** with strict types
- **Laravel 12** with Horizon
- **MySQL 8+** with check constraints
- **Redis** for queues, cache, and KPIs
- **Pest** for testing
- **Docker** for containerization

---

## 🛠️ Setup

### Prerequisites
- PHP 8.2+
- Composer
- MySQL 8+
- Redis
- Node.js & npm (for frontend assets)

### Option 1: Manual Setup

```bash
# 1. Clone repository
git clone https://github.com/dlakmalb/orders-workflow.git
cd orders-workflow

# 2. Install dependencies
composer install
npm install

# 3. Environment setup
cp .env.example .env
php artisan key:generate

# 4. Configure .env
DB_DATABASE=orders_workflow
DB_USERNAME=your_user
DB_PASSWORD=your_pass

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis

# Fake payment configuration
FAKE_PAYMENT_SUCCESS_RATE=90
FAKE_PAYMENT_DELAY=2

# Horizon admin access (production)
HORIZON_ADMINS=admin@example.com

# 5. Run migrations
php artisan migrate

# 6. Build assets
npm run build
```

### Option 2: Docker Setup

```bash
# 1. Clone repository
git clone https://github.com/dlakmalb/orders-workflow.git
cd orders-workflow

# 2. Copy environment file
cp .env.example .env

# 3. Start services
docker-compose up -d

# 4. Run migrations
docker-compose exec app php artisan migrate

# 5. Access application
# Web: http://localhost:8000
# Horizon: http://localhost:8000/horizon
```

---

## 📺 Usage

### Import Orders from CSV

```bash
php artisan orders:import file.csv
```

**CSV Format** (required columns):
```csv
external_order_id,order_placed_at,currency,customer_id,customer_email,customer_name,product_sku,product_name,unit_price_cents,qty
ORD-100045,2025-10-27 16:12:00,EUR,CUST-001,jane@example.com,Jane Doe,SKU-IPH14-BLK-128,iPhone 14 128GB Black,109900,1
```

### Run Queue Workers

```bash
# Option 1: Individual workers
php artisan queue:work --queue=default
php artisan queue:work --queue=notifications
php artisan queue:work --queue=refunds

# Option 2: Horizon (recommended)
php artisan horizon
# Access dashboard: http://your-app.test/horizon
```

### Process Refunds

```bash
# Partial refund
php artisan orders:refund {order_id} {amount_cents} --reason="Customer request"

# Full refund with idempotency key
php artisan orders:refund 42 10000 --reason="Product defect" --key="REF-2025-001"
```

### Development Mode

```bash
# Run all services concurrently
composer dev
# This starts: Laravel server, queue workers, Pail logs, and Vite
```

---

## 📈 KPIs & Monitoring

### Redis Keys

Daily KPIs:
```
kpi:YYYY-MM-DD:revenue_cents
kpi:YYYY-MM-DD:order_count
kpi:YYYY-MM-DD:failed_order_count
kpi:YYYY-MM-DD:avg_order_value_cents
kpi:YYYY-MM-DD:refund_count
kpi:YYYY-MM-DD:refund_amount_cents
```

Customer Leaderboard:
```
leaderboard:customers (sorted set by total spend)
```

### Horizon Dashboard

Access Horizon at `/horizon` to monitor:
- Queue workload and throughput
- Failed jobs
- Job metrics and trends
- Recent jobs

**Security**: In production, access is restricted to authorized admin emails (configure via `HORIZON_ADMINS` env var).

---

## 🧪 Testing

### Run Tests

```bash
# Run all tests
composer test

# Run with coverage
vendor/bin/pest --coverage

# Run specific test suite
vendor/bin/pest tests/Feature/Jobs
vendor/bin/pest tests/Unit
```

### Test Coverage

The project includes comprehensive tests for:
- ✅ Order processing with stock reservation
- ✅ Payment success and failure scenarios
- ✅ Refund validation and processing
- ✅ Notification logging
- ✅ KPI calculations

All tests use **model factories** for clean, maintainable test data.

---

## 🏗️ Code Structure

```
app/
├── Console/Commands/       # Artisan commands
│   ├── OrdersImportCommand.php
│   └── RefundOrderCommand.php
├── Exceptions/Domain/      # Custom exceptions
│   ├── InsufficientStockException.php
│   ├── OrderNotFoundException.php
│   ├── RefundAmountExceededException.php
│   └── ...
├── Jobs/                   # Queue jobs
│   ├── ProcessOrderJob.php
│   ├── FakeGatewayChargeJob.php
│   ├── PaymentCallbackJob.php
│   ├── ProcessRefundJob.php
│   └── OrderProcessedNotification.php
├── Models/                 # Eloquent models
│   ├── Order.php
│   ├── OrderItem.php
│   ├── Customer.php
│   ├── Product.php
│   ├── Payment.php
│   ├── Refund.php
│   └── NotificationLog.php
├── Services/               # Business logic
│   ├── OrderService.php
│   ├── StockService.php
│   ├── RefundService.php
│   └── KpiService.php
└── Providers/
    └── HorizonServiceProvider.php

database/
├── factories/              # Model factories for testing
│   ├── CustomerFactory.php
│   ├── ProductFactory.php
│   ├── OrderFactory.php
│   ├── OrderItemFactory.php
│   ├── PaymentFactory.php
│   └── RefundFactory.php
└── migrations/             # Database schema

tests/
├── Feature/Jobs/           # Job tests
└── Unit/                   # Unit tests
```

---

## 🔒 Security Features

- ✅ Horizon authentication (production-ready)
- ✅ Database check constraints (prevents invalid data)
- ✅ Distributed locks (prevents race conditions)
- ✅ Idempotency keys (prevents duplicate refunds)
- ✅ Row-level locking (atomic stock updates)
- ✅ Input validation (CSV import validation)

---

## 🚀 CI/CD Pipeline

GitHub Actions workflow includes:
- ✅ Automated testing on PHP 8.2 & 8.3
- ✅ Laravel Pint code style checks
- ✅ PHPStan static analysis
- ✅ Security vulnerability scanning
- ✅ Code coverage reporting (Codecov)

See [`.github/workflows/ci.yml`](.github/workflows/ci.yml) for details.

---

## 📊 Database Schema

### Tables

- **customers**: Customer information
- **products**: Product catalog with stock levels
- **orders**: Order records with status tracking
- **order_items**: Line items with calculated subtotals
- **payments**: Payment records with provider details
- **refunds**: Refund requests with idempotency
- **notification_logs**: Notification audit trail

### Indexes

Optimized queries with indexes on:
- Customer external_id and email
- Product SKU
- Order external_order_id and placed_at
- Composite indexes for common query patterns

### Check Constraints

Data integrity enforced at database level:
- Positive prices and quantities
- Non-negative stock levels
- Valid order totals

---

## 🔄 Queue Architecture

### Queue Separation

- **default**: Order processing (high priority)
- **notifications**: Async notifications (medium priority)
- **refunds**: Refund processing (low priority)

### Retry Strategy

- Max attempts: 3
- Backoff: 5 seconds between retries
- WithoutOverlapping middleware prevents duplicate processing

### Horizon Configuration

- **Local**: 3 max processes
- **Production**: 10 max processes with auto-scaling

---

## 🌟 Best Practices Implemented

1. ✅ **SOLID Principles**: Services handle single responsibilities
2. ✅ **Repository Pattern**: Data access abstraction (via Services)
3. ✅ **Factory Pattern**: Consistent test data generation
4. ✅ **Exception Handling**: Custom domain exceptions
5. ✅ **Database Transactions**: Atomic operations
6. ✅ **Distributed Locking**: Race condition prevention
7. ✅ **Queue Separation**: Performance optimization
8. ✅ **Idempotency**: Duplicate request handling
9. ✅ **Logging**: Comprehensive audit trail
10. ✅ **Testing**: High coverage with isolated tests

---

## 📖 License

This project is open-sourced software licensed under the [MIT license](LICENSE).

---

## 🤝 Contributing

Contributions are welcome! Please follow these steps:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Run tests (`composer test`)
4. Run code style checks (`vendor/bin/pint`)
5. Commit your changes (`git commit -m 'Add amazing feature'`)
6. Push to the branch (`git push origin feature/amazing-feature`)
7. Open a Pull Request

---

## 📧 Support

For issues or questions:
- Open an [issue](https://github.com/dlakmalb/orders-workflow/issues)
- Contact: [dlakmalb@github.com](mailto:dlakmalb@github.com)

---

<div align="center">
Made with ❤️ using Laravel
</div>
