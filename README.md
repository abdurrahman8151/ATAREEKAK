# 🚗 Syride — Carpooling Platform API

<div align="center">

![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?style=for-the-badge&logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-10.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![Redis](https://img.shields.io/badge/Redis-7.x-DC382D?style=for-the-badge&logo=redis&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?style=for-the-badge&logo=docker&logoColor=white)
![Firebase](https://img.shields.io/badge/Firebase-FCM-FFCA28?style=for-the-badge&logo=firebase&logoColor=black)

**A production-grade, real-time carpooling backend built with Laravel — featuring JWT authentication, a multi-gateway wallet system, live chat, push notifications, a trust-score engine, and a full staff management portal.**

[API Docs](#api-documentation) · [Getting Started](#getting-started) · [Architecture](#architecture) · [Load Testing](#load-testing) · [Deployment](#deployment)

</div>

---

## Table of Contents

- [Overview](#overview)
- [Key Features](#key-features)
- [Architecture](#architecture)
- [Tech Stack](#tech-stack)
- [Getting Started](#getting-started)
- [Environment Variables](#environment-variables)
- [Database](#database)
- [API Documentation](#api-documentation)
- [Testing](#testing)
- [Load Testing](#load-testing)
- [Deployment](#deployment)
- [Performance Results](#performance-results)

---

## Overview

Syride is a full-featured carpooling platform API designed for scalability and reliability. Drivers post rides, passengers discover and book seats, and the platform manages the full lifecycle — from OTP-verified signup through in-ride chat, payment, and post-ride trust scoring — all in real time.

The backend is engineered for production: MySQL primary/replica replication, Redis-backed caching and queues, Laravel Octane with RoadRunner for high throughput, and a comprehensive k6 load-testing suite that has validated **900+ concurrent users** under sustained load.

---

## Key Features

### 👤 Authentication & Identity
- JWT-based stateless authentication with refresh token rotation
- Multi-channel OTP verification — SMS (TextMe bot), WhatsApp, and Email
- Google OAuth social login
- Token versioning to instantly invalidate all sessions on password change
- Role-separated middleware for passengers/drivers, staff, and admins

### 🚗 Ride Management
- Full ride lifecycle: create → search → book → launch → complete / cancel
- Flexible booking types (instant and pre-scheduled)
- Spatial search with route calculation and Arabic place-name support
- Real-time ride status updates via WebSocket broadcasting

### 💰 Wallet & Payments
- Multi-currency wallet per user with full transaction history
- Payment strategies: **Wallet**, **Cash**, **ePay** (pluggable via Strategy pattern)
- Automated refund engine with configurable policies
- Admin-managed wallet top-up / withdrawal request flow (approval queue)
- Cash ride fee tracking with debt reconciliation

### ⭐ Trust Score Engine
- Configurable scoring policies: ride completion, driver/passenger no-shows, cancellations
- Score tiers (Bronze → Gold → …) that gate platform privileges
- Automated no-show report resolution via scheduled command

### 💬 Real-Time Chat
- Conversation-based messaging between ride participants
- Image and text message types
- Firebase Cloud Messaging (FCM) push notifications
- In-app notification centre with read/unread tracking

### 🛡️ Staff & Admin Portal
- Multi-role employee system (`admin`, `support`, `sycash`, …)
- Staff-scoped JWT with separate refresh token store
- Complaint management with file attachments and status workflow
- Review moderation and user ban management
- Dashboard reporting and CSV export

### 📸 Profile & Verification
- Profile photos with dedicated photo repository
- Document verification workflow (national ID, driver licence)
- Profile interaction service (ratings, comments)

---

## Architecture

Syride follows a **layered, domain-oriented architecture** inside the Laravel monolith, making it straightforward to extract services in the future.

```
┌─────────────────────────────────────────────────────┐
│                   HTTP Layer                        │
│  Controllers → Form Requests → API Resources        │
├─────────────────────────────────────────────────────┤
│                 Application Layer                   │
│  Services  ·  DTOs  ·  Jobs  ·  Listeners          │
├─────────────────────────────────────────────────────┤
│                   Domain Layer                      │
│  Value Objects  ·  Policies  ·  Strategies          │
│  (Payment, Score, Message Types)                    │
├─────────────────────────────────────────────────────┤
│               Infrastructure Layer                  │
│  Repositories  ·  Eloquent Models  ·  Events        │
└─────────────────────────────────────────────────────┘
```

### Design Patterns Used

| Pattern | Where |
|---|---|
| **Repository** | `UserRepository`, `RideRepository`, `ChatRepository`, … — all behind interfaces for easy mocking |
| **Strategy** | `PaymentStrategy` — `CashPaymentStrategy`, `EPayPaymentStrategy`, `WalletPaymentStrategy` resolved by `PaymentStrategyFactory` |
| **Policy** | `ScorePolicyInterface` implementations: `RideCompletionPolicy`, `DriverNoShowPolicy`, `PassengerCancelPolicy`, … resolved by `ScorePolicyFactory` |
| **Value Objects** | `Money`, `PhoneNumber`, `Email`, `Location` — immutable domain primitives |
| **DTO** | `CreateRideDTO`, `BookRideDTO`, `SendEmailOtpDTO` — typed request transport |
| **Observer** | `UserObserver` — model lifecycle hooks decoupled from business logic |
| **Event / Listener** | `RideBooked → SendRideBookedNotification`, `OtpSent → SendOtpNotification`, … |

---

## Tech Stack

| Layer | Technology |
|---|---|
| **Framework** | Laravel 10 |
| **Runtime** | PHP 8.x + RoadRunner (via Laravel Octane) |
| **Primary DB** | MySQL 8.0 (primary + replica) |
| **Cache / Queues** | Redis 7 + Laravel Horizon |
| **Push Notifications** | Firebase FCM |
| **Real-time** | Laravel Echo + WebSocket broadcasting |
| **Auth** | Custom JWT (`JwtService`, `StaffJwtService`) |
| **API Docs** | L5-Swagger (OpenAPI 3.0) |
| **Container** | Docker + Docker Compose |
| **Proxy** | Nginx |
| **CI/CD** | GitHub Actions |
| **Code Quality** | SonarQube |
| **Load Testing** | k6 |

---

## Getting Started

### Prerequisites

- Docker ≥ 24 and Docker Compose v2
- PHP 8.x + Composer (for local development without Docker)
- Node.js 18+ (for Vite asset compilation)

### 1 — Clone & configure

```bash
git clone https://github.com/<your-org>/syride.git
cd syride
cp .env.example .env
```

### 2 — Install dependencies

```bash
composer install --no-dev --optimize-autoloader
npm install && npm run build
```

### 3 — Start the stack

```bash
# Full cluster (app + MySQL primary/replica + Redis + Nginx)
docker compose up -d
```

> The `start-cluster.bat` / `stop-cluster.bat` / `start-syride.ps1` wrappers were
> **removed in RV-07**: they printed seeded admin credentials and passed a real
> third-party API key on the command line. Use `docker compose` directly.

### 4 — Bootstrap the database

```bash
php artisan migrate --force
php artisan db:seed          # seeds admin, system wallet, and demo users
```

> The seeder creates a **system wallet**, a special admin account, and sample drivers/passengers for local testing. See `database/seeders/DatabaseSeeder.php` for the full seed order.

### 5 — Generate secrets

```bash
php artisan jwt:secret          # application JWT secret
php artisan staff:jwt-secret    # separate secret for staff tokens
```

### 6 — Start queue workers

```bash
php artisan horizon             # recommended — dashboard at /horizon
# or plain worker:
php artisan queue:work redis --queue=notifications,default
```

---

## Environment Variables

| Variable | Description |
|---|---|
| `APP_KEY` | Laravel application key (`php artisan key:generate`) |
| `DB_HOST` / `DB_READ_HOST` | MySQL primary / replica hosts |
| `REDIS_HOST` | Redis instance |
| `JWT_SECRET` | User JWT signing secret |
| `STAFF_JWT_SECRET` | Staff JWT signing secret |
| `TEXTME_API_KEY` | SMS OTP gateway (TextMe bot) |
| `WHATSAPP_OTP_*` | WhatsApp OTP credentials |
| `FIREBASE_*` | FCM credentials (or path to `config/firebase-credentials.json`) |
| `EPAY_*` | ePay payment gateway credentials |
| `GOOGLE_CLIENT_ID/SECRET` | Google OAuth |
| `BROADCAST_DRIVER` | `pusher` or `redis` for WebSocket events |

A full annotated `.env.example` is included in the repository.

---

## Database

### Primary / Replica Setup

Syride ships with a MySQL primary + replica configuration under `docker/mysql/`. Write queries are routed to the primary; reads are automatically distributed to the replica via Laravel's `DB::connection('mysql-read')`.

```
mysql-primary (writes)  ──replication──▶  mysql-replica (reads)
```

```bash
# Initialise replication (first-time only)
docker exec -it syride-mysql-primary bash /docker-entrypoint-initdb.d/replica-init.sh
```

### Key Migrations

| Migration | Description |
|---|---|
| `create_users_table` | Core user model with ban fields and token versioning |
| `create_rides_table` | Spatial columns for pickup/dropoff, status enum, route index |
| `create_bookings_table` | Passenger ↔ ride join with payment method and no-show tracking |
| `create_wallets_table` | Per-user balance + cash-ride debt |
| `create_wallet_transactions_table` | Full transaction ledger |
| `create_wallet_requests_table` | Admin-approved top-up/withdrawal queue |
| `create_otps_table` | Multi-type OTP store (phone, email) |
| `create_user_scores_table` | Trust score with tier, cancel rate, no-show count |
| `create_complaints_table` + attachments | Complaint workflow |
| `create_noshow_reports_table` | Driver/passenger no-show records with auto-resolution |
| `create_employees_table` | Staff accounts with role enum |
| `add_performance_indexes_*` | Composite indexes on hot query paths |

---

## API Documentation

Interactive Swagger UI is served at:

```
http://localhost/api/documentation
```

The OpenAPI spec lives at `storage/api-docs/api-docs.json` and is generated from inline annotations in the `app/Docs/` classes:

| Doc class | Covers |
|---|---|
| `AuthDocs` | Signup, login, OTP, Google OAuth, password reset |
| `RideDocs` | Create, search, book, cancel, launch rides |
| `BookingDocs` | Booking lifecycle and cancellation |
| `WalletDocs` | Balance, transactions, top-up requests |
| `ProfileDocs` | Profile update, photo upload, document verification |
| `ChatDocs` | Conversations, messages (text + image) |
| `NotificationDocs` | Notification list, FCM token registration |
| `AdminDocs` | Dashboard, user/driver management, bans, exports |
| `StaffDocs` | Staff auth, complaints, reviews, operations |
| `EmployeeDocs` | Employee CRUD |

To regenerate the spec after changing annotations:

```bash
php artisan l5-swagger:generate
```

---

## Testing

The test suite is split into **unit** and **feature** layers and run via PHPUnit:

```bash
php artisan test                        # all tests
php artisan test --testsuite=Unit       # unit only
php artisan test --testsuite=Feature    # feature only
php artisan test --filter RideTest      # specific test class
```

### Coverage Areas

| Domain | Test file |
|---|---|
| Ride flow (full lifecycle) | `RideControllerFullTest`, `RideTest` |
| Ride search | `RideSearchServiceTest` |
| Wallet & transactions | `WalletTest`, `WalletTransactionServiceTest` |
| Refund policy | `WalletTransactionServiceRefundPolicyTest` |
| OTP verification | `OtpTest`, `TextMeOtpControllerTest` |
| Score service | (ScoreService, ScoreAction policies) |
| Complaints | `ComplaintControllerTest`, `ComplaintRepositoryTest` |
| Staff auth & operations | `StaffAuthControllerTest`, `StaffOperationsControllerTest` |
| Employee management | `EmployeeManagementControllerTest`, `EmployeeManagementServiceTest` |
| Review moderation | `ReviewModerationServiceTest`, `StaffReviewControllerTest` |
| Push notifications | `FcmSenderServiceTest`, `NotificationTest` |
| Document verification | `DocumentControllerTest`, `DocumentVerificationServiceTest` |
| Profile | `ProfileTest` |
| Geocoding | `GeocodingServiceTest`, `ArabicPlaceNameServiceTest` |
| Chat events | `MessageReceivedTest`, `MessageReceivedNotificationTest` |
| Payment strategies | `PaymentResultTest`, `RefundResultTest`, `CashRideFeeServiceTest` |

---

## Load Testing

Syride was stress-tested with **k6** across four infrastructure scenarios to isolate the gains from each optimisation layer:

| Scenario | Config | File |
|---|---|---|
| A — Baseline | No cache, no load balancer | `Scenario1 no cache no lb.js` |
| B — Cache only | Redis cache, single node | `Scenario2 cache no lb.js` |
| C — LB only | Multi-node, no cache | `Scenario3 lb no cache.js` |
| D — Full | Redis cache + load balancer | `Scenario4 cache and lb.js` |

Additional scripts:

```
syride-breakpoint-test.js   — finds the saturation point
syride-spike-only.js        — sudden traffic spike
syride-hammer-test.js       — sustained maximum throughput
syride-capacity-validation-test.js  — verify target SLA
Syride-70pct-stage1.js      — ramp to 70% capacity
Syride-stage1-900vu-confirm.js — 900 VU confirmation run
```

### Running a test

```bash
# Install k6: https://k6.io/docs/get-started/installation/
cd k6-load
k6 run "Scenario4 cache and lb.js"
```

Results are stored in `perf-results/` (`A_baseline.json`, `B_indexes.json`, `C_full.json`).

---

## Deployment

### Docker (recommended)

```bash
# Build production image
docker build -t syride:latest .

# Start with compose
docker compose -f docker-compose.yml up -d
```

The `Dockerfile` uses a multi-stage build. Nginx (`nginx-docker.conf`) fronts RoadRunner.

### GitHub Actions

Two workflows are preconfigured:

| Workflow | Trigger | Action |
|---|---|---|
| `deploy-to-vps.yml` | Push to `main` | SSH deploy to VPS, migrate, restart Octane |
| `sonar.yml` | Pull Request / push | Static analysis via SonarQube |

### Scheduled Commands

Register these in `app/Console/Kernel.php` (or your cron / Horizon scheduler):

| Command | Purpose |
|---|---|
| `otps:cleanup` | Purge expired OTPs |
| `tokens:cleanup` | Purge expired user refresh tokens |
| `staff-tokens:cleanup` | Purge expired staff refresh tokens |
| `noshow-reports:resolve` | Auto-resolve no-show reports past their window |

---

## Performance Results

Three benchmark snapshots track the cumulative impact of each optimisation:

| Run | Description | Stored in |
|---|---|---|
| A | Raw baseline — cold queries, single node | `perf-results/A_baseline.json` |
| B | After adding DB indexes on hot paths | `perf-results/B_indexes.json` |
| C | Indexes + Redis cache + load balancer | `perf-results/C_full.json` |

Key wins: composite indexes on `rides` (status + departure), `bookings` (ride_id + status), and `wallet_transactions` (wallet_id + type); Redis query caching for ride-search results; and RoadRunner eliminating per-request PHP bootstrap overhead.

---

## Project Structure (highlights)

```
app/
├── Console/Commands/          # Artisan commands (cleanup, JWT, test flows)
├── Domain/
│   ├── Payment/Strategies/    # Cash, ePay, Wallet payment strategies
│   ├── Score/Policies/        # Trust-score policy engine
│   └── ValueObjects/          # Money, PhoneNumber, Email, Location
├── DTOs/                      # Typed input transport objects
├── Events/ & Listeners/       # Async event bus
├── Http/
│   ├── Controllers/API/       # RESTful controllers
│   ├── Middleware/            # JWT, OTP verify, admin/staff gates
│   └── Resources/             # API response transformers
├── Interfaces/                # Repository and service contracts
├── Models/                    # Eloquent models
├── Repositories/              # Concrete DB implementations
└── Services/                  # Business logic (Ride, Wallet, Score, …)
database/
├── migrations/                # 50+ ordered migrations
└── seeders/                   # Dev and production seed data
k6-load/                       # Load test scripts
perf-results/                  # Benchmark JSON snapshots
```

---

## License

This project is proprietary software. All rights reserved.