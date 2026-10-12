# Sakania

> A full-stack property rental and reservation management platform, built as a
> monorepo with a **Laravel 12 REST API** (backend) and a **React 19 + Vite SPA**
> (frontend), orchestrated with Docker.

---

## Table of Contents

- [Sakania](#sakania)
  - [Table of Contents](#table-of-contents)
  - [1. Project Overview](#1-project-overview)
  - [2. Repository Architecture](#2-repository-architecture)
    - [How the pieces fit together](#how-the-pieces-fit-together)
  - [3. Tech Stack](#3-tech-stack)
    - [Backend (`backend/`)](#backend-backend)
    - [Frontend (`frontend/`)](#frontend-frontend)
    - [Infrastructure](#infrastructure)
  - [4. Key Features](#4-key-features)
  - [5. Getting Started](#5-getting-started)
    - [Prerequisites](#prerequisites)
    - [Option A — Docker (recommended)](#option-a--docker-recommended)
    - [Option B — Local (without Docker)](#option-b--local-without-docker)
    - [Default demo accounts (after seeding)](#default-demo-accounts-after-seeding)
  - [6. Environment Variables](#6-environment-variables)
  - [7. API Overview](#7-api-overview)
  - [8. Testing](#8-testing)
  - [9. Security \& Secrets](#9-security--secrets)
  - [10. Deployment Notes](#10-deployment-notes)

---

## 1. Project Overview

**Sakanai** is a rental property platform where landlords list properties and
tenants discover, book, and pay for them. It covers the full rental lifecycle:
browse → reserve → pay → sign a contract → communicate — with a role-based
back office for administrators.

The platform is organised around **three roles**:

| Role     | Capabilities |
|----------|--------------|
| `tenant` | Browse and search properties, save favourites, create reservations, pay, download contracts/receipts, message owners |
| `owner`  | Manage properties (create/edit/delete), review reservation requests (accept/reject), track payments and contracts |
| `admin`  | Platform-wide dashboard, user management (activate/deactivate/delete), property moderation (approve/reject), reservations/contracts oversight |

**Stack summary:** Laravel (API + business logic) behind an Nginx reverse proxy,
a React single-page application for the UI, MySQL for persistence, Redis for
sessions/cache/queues, and an optional AI chatbot powered by Google Gemini with
Google OAuth sign-in.

---

## 2. Repository Architecture

Sakanai is a **monorepo**: the API, the web client, and the deployment tooling
live in one Git repository at the project root.

```
Project Sakanai/
├── backend/              # Laravel 12 REST API (PHP-FPM)
│   ├── app/              # Controllers, Models, Services, Middleware
│   ├── bootstrap/        # Framework bootstrap (global middleware)
│   ├── config/           # Laravel configuration
│   ├── database/         # Migrations, factories, seeders
│   ├── lib/
│   │   └── boosthis-php/ # Internal performance-monitoring kit (path repo)
│   ├── public/           # Web root (index.php, assets, uploads)
│   ├── routes/           # api.php, web.php, console.php
│   ├── storage/          # Logs, cache, private files (contracts)
│   ├── tests/            # PHPUnit feature tests
│   ├── Dockerfile        # php:8.2-fpm image
│   └── composer.json
│
├── frontend/             # React 19 + Vite single-page application
│   ├── src/
│   │   ├── components/   # UI (ui/, layouts/, admin/, common/)
│   │   ├── contexts/     # AuthContext, FavoritesContext
│   │   ├── pages/        # Admin/, Auth/, Owner/, Public/, Tenant/
│   │   ├── services/     # Axios API clients (one per domain)
│   │   └── utils/
│   ├── public/
│   ├── Dockerfile        # Multi-stage: dev / build / nginx prod
│   └── package.json
│
├── docker-compose.yml    # Service orchestration (mysql, redis, backend, frontend, nginx)
├── nginx.conf            # Reverse proxy: routes /api and / to the right service
├── e2e-workflow.sh       # End-to-end check of the reservation→payment→contract flow
└── .gitignore            # Root ignore rules protecting both tiers
```

### How the pieces fit together

The **root-level deployment files** wire the two application tiers together:

- **`docker-compose.yml`** defines five services: `mysql` (8.0, exposed on host
  `3307`), `redis` (7), `backend` (PHP-FPM on `9000`), `frontend` (Vite dev
  server on `5173`), and `nginx` (public entry point on `80`).
- **`nginx.conf`** is the single entry point. It routes by path:
  - `/api` → **`backend:9000`** via FastCGI (Laravel `public/index.php`).
  - `/` → **`frontend:5173`** (Vite, with WebSocket support for HMR).
  - `/uploads` → static assets; `/uploads/contracts` is explicitly denied
    (`404`) because contracts are private and delivered only through the
    authenticated `GET /api/contracts/{id}/download` endpoint.

```
Browser
   │  http://localhost  (port 80)
   ▼
 Nginx (nginx.conf)
   ├── /api      ──FastCGI──▶  backend  (Laravel / PHP-FPM :9000)  ──▶ MySQL / Redis
   └── /           ──proxy──▶  frontend (React / Vite     :5173)
```

The backend is a stateless JSON API consumed by the frontend through Axios
(`frontend/src/services/*`); the frontend stores the issued Bearer token and
sends it with each request. There is no server-side coupling beyond the REST
contract, so either tier can be deployed or tested independently.

---

## 3. Tech Stack

### Backend (`backend/`)

| Concern | Technology |
|---------|------------|
| Language / Runtime | PHP **^8.2** (PHP-FPM) |
| Framework | Laravel **^12** |
| API auth | Laravel **Sanctum** (Bearer tokens) |
| Social login | Laravel **Socialite** (Google OAuth 2.0) |
| PDF generation | `barryvdh/laravel-dompdf` (contracts, receipts) |
| Database | MySQL 8 (Redis for sessions/cache/queues) |
| AI chatbot | Google **Gemini** API |
| Monitoring | `boosthis/boosthis-php` — internal performance kit (local path repo) |
| Testing | PHPUnit **^11.5** |
| Code style | Laravel **Pint**, **Pail** (log viewer) |

### Frontend (`frontend/`)

| Concern | Technology |
|---------|------------|
| Framework | React **19** (SPA) |
| Build tool | Vite **8** |
| Routing | React Router **7** |
| Styling | Tailwind CSS **4** |
| Components | Radix UI primitives, `class-variance-authority`, `tailwind-merge` |
| Animation | Framer Motion |
| HTTP | Axios |
| Notifications | react-hot-toast |
| Linting | Oxlint |

### Infrastructure

Docker Compose, Nginx (reverse proxy + gzip), MySQL 8, Redis 7.

---

## 4. Key Features

- **Authentication & roles** — email/password registration and login (Sanctum
  tokens, rate-limited), plus Google OAuth (`Continue with Google`) with a
  browser-safe exchange flow.
- **Property catalogue** — public listing, search, and detail pages; owners
  create/edit/delete with image uploads; admins approve or reject listings.
- **Reservations** — tenants request, owners accept/reject, with role-scoped
  dashboards and status tracking.
- **Payments & contracts** — checkout/process flow, success pages, receipts,
  and PDF contracts generated via DomPDF and stored **privately** (served only
  through an authenticated, authorization-checked download endpoint).
- **Messaging** — tenant ↔ owner conversations with unread counts and read
  receipts.
- **Notifications** — per-user notifications with unread counts and mark-as-read.
- **Favourites** — tenants save properties.
- **AI chatbot** — context-aware assistant backed by Google Gemini
  (`app/Services/Chatbot/`).
- **Admin back office** — platform dashboard, user administration, and
  moderation of properties, reservations, contracts, and payments.

---

## 5. Getting Started

### Prerequisites

- **Docker** and **Docker Compose** (recommended path), **or**
- **PHP ≥ 8.2** + Composer, **Node ≥ 20** + npm, **MySQL 8**, and **Redis**
  (manual path).

### Option A — Docker (recommended)

```bash
# 1. From the project root, build and start every service
docker compose up -d --build

# 2. Create the backend environment file (it is intentionally not committed)
cp backend/.env.example backend/.env

# 3. Generate an application key, run migrations, and seed demo data
docker compose exec backend php artisan key:generate
docker compose exec backend php artisan migrate --seed
```

The application is then available at:

| URL | Service |
|-----|---------|
| http://localhost | Frontend (via Nginx) |
| http://localhost/api | REST API (via Nginx) |
| http://localhost:5173 | Vite dev server (HMR, direct) |

Host ports: `80` (nginx), `5173` (frontend), `3307` → MySQL, `6379` (redis).

### Option B — Local (without Docker)

```bash
# Backend
cd backend
composer install
cp .env.example .env
php artisan key:generate
# Point DB_HOST / DB_PORT / REDIS_HOST at your local services, then:
php artisan migrate --seed
php artisan serve            # http://localhost:8000

# Frontend (in a second terminal)
cd frontend
npm install
npm run dev                  # http://localhost:5173
```

### Default demo accounts (after seeding)

| Role   | Email               | Default password |
|--------|---------------------|------------------|
| Admin  | `admin@sakania.ma`  | `admin123` |
| Owner  | `owner@sakania.ma`  | `owner123` |
| Tenant | `tenant@sakania.ma` | `tenant123` |

> These defaults come from the seeder and can be overridden with the
> `DEFAULT_ADMIN_PASSWORD` / `DEFAULT_OWNER_PASSWORD` / `DEFAULT_TENANT_PASSWORD`
> environment variables. **Change them before any production deployment.**

---

## 6. Environment Variables

Configuration is read from `backend/.env` (copied from `backend/.env.example`).
The file is **git-ignored** — never commit real credentials.

| Variable | Purpose |
|----------|---------|
| `APP_KEY` | Laravel encryption key (`php artisan key:generate`) |
| `APP_ENV`, `APP_DEBUG`, `APP_URL` | Application environment |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | MySQL connection |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | Redis (sessions, cache, queues) |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | Drivers (Redis by default) |
| `GEMINI_API_KEY` | Google Gemini key for the chatbot |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` | Google OAuth (Socialite) |
| `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS` | CORS / redirect targets |
| `DEFAULT_ADMIN_PASSWORD`, `DEFAULT_OWNER_PASSWORD`, `DEFAULT_TENANT_PASSWORD` | Seeded demo passwords |
| `BOOSTHIS_DISABLED`, `BOOSTHIS_PROJECT_KEY`, `BOOSTHIS_INSTALL_ID`, `BOOSTHIS_APP_NAME` | Internal monitoring kit |

The frontend reads `VITE_API_URL` from `frontend/.env` (default `/api`, i.e.
same-origin through Nginx).

---

## 7. API Overview

All endpoints are under `/api`. Public routes are open; everything else requires
an `Authorization: Bearer <token>` header (Sanctum) and, for role-specific
prefixes, the matching role gate.

| Group | Method & Path | Access |
|-------|---------------|--------|
| Auth | `POST /register`, `POST /login` | Public (throttled) |
| Auth (Google) | `POST /auth/google/redirect`, `GET /auth/google/callback`, `POST /auth/google/exchange` | Public |
| Auth | `POST /logout`, `GET /user` | Authenticated |
| Profile | `GET/PUT /profile`, `PUT /profile/password`, `DELETE /profile/avatar` | Authenticated |
| Properties | `GET /properties`, `GET /properties/search`, `GET /properties/{id}` | Public |
| Chatbot | `POST /chatbot/send` | Public (throttled) |
| Favourites | `GET /favorites`, `POST/DELETE /favorites/{propertyId}` | Authenticated |
| Messages | `GET /messages`, `GET /messages/unread/count`, `GET /messages/conversations/{id}`, `PUT …/read`, `POST /messages` | Authenticated |
| Notifications | `GET /notifications`, `…/unread/count`, `PUT /notifications/{id}/read`, `PUT /notifications/read-all` | Authenticated |
| Reservations | `POST /reservations`, `PUT /reservations/{id}/cancel` | Tenant |
| Payments | `GET /payments/checkout/{id}`, `POST /payments/process`, `GET /payments/{id}/success`, `GET /payments/{id}/receipt` | Tenant |
| Contracts | `GET /contracts/{id}/download` | Authenticated |
| Tenant area | `/tenant/*` (dashboard, profile, reservations, contracts, payments) | Tenant |
| Owner area | `/owner/*` (dashboard, properties CRUD + stats, reservations accept/reject, contracts, payments) | Owner |
| Admin area | `/admin/*` (dashboard, users, properties moderation, reservations, contracts, payments) | Admin |

A health endpoint `GET /up` is provided by Laravel and is used by the container
entrypoint to warm the opcode cache at boot.

---

## 8. Testing

The backend ships **PHPUnit feature tests** covering the main domains:
authentication (Google OAuth), the reservation→payment→contract workflow,
owner property management, profile, messaging, notifications, and the chatbot.
Tests run against an in-memory SQLite database (`phpunit.xml`).

```bash
cd backend
composer test          # clears config, then runs `php artisan test`
# or directly:
php artisan test
```

An **end-to-end workflow check** against a running stack is available at the
project root:

```bash
./e2e-workflow.sh      # exercises reservation → payment → contract via the live API
```

Frontend linting:

```bash
cd frontend
npm run lint           # oxlint
```

---

## 9. Security & Secrets

- **`backend/.env` and `frontend/.env` are git-ignored.** Only `*.env.example`
  files are tracked. Real secrets (`APP_KEY`, `GEMINI_API_KEY`,
  `GOOGLE_CLIENT_SECRET`, `BOOSTHIS_PROJECT_KEY`, DB passwords) must never be
  committed.
- **Private runtime data is excluded from Git**: `backend/vendor/`,
  `frontend/node_modules/`, `frontend/dist/`, `backend/storage/app/private/**`
  (generated contracts), `backend/bootstrap/cache/*.php`, and local database
  dumps (`*.sqlite`, `*.sql`).
- **Contracts are private by design**: stored outside the public web root and
  served only through an authorized endpoint; the legacy public path is blocked
  at the Nginx layer (`/uploads/contracts → 404`).
- If a secret is ever exposed, **rotate it immediately** — removing it from a
  file is not enough once it has been pushed to history.

See the root `.gitignore` for the complete set of protected paths.

---

## 10. Deployment Notes

- **Production builds**: the `frontend/Dockerfile` is multi-stage
  (`dev` → `build` → `nginx`). The `docker-compose.yml` in this repo runs the
  `dev` target for local development; deploy the `prod` stage (static assets
  served by Nginx) for production.
- **Reverse proxy**: keep the `/api` FastCGI route and the private
  `/uploads/contracts` denial from `nginx.conf` when adapting for production.
- **Backend image**: `php:8.2-fpm` with `pdo_mysql`, `gd`, `exif`, `bcmath`,
  `pcntl`, and `redis` extensions; runs `composer install --no-dev` at build.
- **Monitoring**: the `boosthis/boosthis-php` kit is bundled as a local Composer
  path repository and registers global middleware. It is disabled via
  `BOOSTHIS_DISABLED=true` in the container environment.

---

<p align="center"><sub>Sakanai — property &amp; reservation management platform.</sub></p>
