# DashCore Starter

> **What this app is for — and what it is not for — is in [`PURPOSE.md`](PURPOSE.md). Read it first.**

Base template for DashCore client applications. Every client app is scaffolded from this template via the DashCore Portal, then customized with installed packages and optional client-specific code.

## What's Included

- **Laravel 13** with PHP 8.3+
- **Authentication** — Laravel Breeze with Livewire (login, register, password reset, email verification, profile management)
- **Design system** — Tailwind CSS v4, dark/light theme toggle, DM Sans + JetBrains Mono fonts, glassmorphism card styles
- **NavigationRegistry** — Singleton service that packages use to register sidebar navigation items dynamically
- **Livewire 3 + Volt** — Class-based and single-file reactive components
- **PostgreSQL** configured by default
- **Dashboard page** — Shows system status and links to installed package features
- **Database-backed sessions, cache, and queue**

## Quick Start

```bash
# Clone and install
git clone git@github.com:dashcoretech/{client-slug}.git ~/Herd/{client-slug}
cd ~/Herd/{client-slug}
composer install
npm install

# Environment
cp .env.example .env
php artisan key:generate --no-interaction

# Database (PostgreSQL)
createdb {client-slug}
# Update .env: DB_DATABASE={client-slug}
php artisan migrate --no-interaction

# Run
composer run dev
```

Or use the one-liner setup script:

```bash
composer run setup
```

## Installing Packages

DashCore modules are Composer packages hosted as private GitHub repos. To install one:

```bash
# 1. Register the package's GitHub repo as a VCS source
composer config repositories.dashcore-{slug} vcs https://github.com/dashcoretech/dashcore-{slug}

# 2. Require the package
composer require dashcore/{slug}:@dev --no-interaction

# 3. Run migrations (package migrations load automatically)
php artisan migrate --no-interaction
```

Available packages:

| Package | Composer Name | What It Adds |
|---------|--------------|--------------|
| CRM | `dashcore/crm` | Contacts, deals, pipeline board, activities |

More packages are added as they're built. The DashCore Portal's Package Manager handles install/uninstall automatically.

## How Packages Register Navigation

Packages use the `NavigationRegistry` singleton to add items to the sidebar. When a package is installed, its service provider calls:

```php
$registry = app(\App\Services\NavigationRegistry::class);
$registry->register('Group Name', 'Label', 'route.name', 'heroicon-name', $order);
```

The navigation component in `resources/views/livewire/layout/navigation.blade.php` reads from the registry and renders grouped dropdown menus. No manual configuration needed — install a package and its nav items appear automatically.

## Directory Structure

```
├── app/
│   ├── Http/Controllers/       # Minimal — most pages are Livewire
│   ├── Livewire/               # Class-based Livewire components
│   │   ├── Actions/            # Invokable actions (Logout)
│   │   └── Forms/              # Form objects (LoginForm)
│   ├── Models/                 # Eloquent models (User)
│   ├── Providers/              # AppServiceProvider, VoltServiceProvider
│   ├── Services/               # NavigationRegistry
│   └── View/Components/        # Layout components (AppLayout, GuestLayout)
├── config/                     # Laravel config files
├── database/
│   ├── factories/
│   ├── migrations/             # Base migrations (users, cache, jobs)
│   └── seeders/
├── resources/
│   ├── css/app.css             # Tailwind v4 theme (surface, accent, status colors)
│   ├── js/app.js
│   └── views/
│       ├── layouts/            # app.blade.php (auth), guest.blade.php (public)
│       ├── components/         # Reusable Blade components
│       ├── livewire/
│       │   ├── layout/         # navigation.blade.php (sidebar)
│       │   ├── pages/auth/     # Auth pages (Volt single-file)
│       │   └── profile/        # Profile forms
│       ├── dashboard.blade.php
│       └── welcome.blade.php
├── routes/
│   ├── web.php                 # App routes (dashboard, profile)
│   └── auth.php                # Auth routes (login, register, etc.)
└── tests/
```

## Design System

The theme uses CSS custom properties defined in `resources/css/app.css`:

- **Surface colors** (`surface-50` through `surface-950`) — Slate-based grays for backgrounds and text
- **Accent colors** (`accent-400/500/600`) — Cyan/blue for interactive elements
- **Status colors** — `success-500` (green), `warning-500` (amber)
- **Fonts** — DM Sans (body), JetBrains Mono (code)
- **Theme toggle** — Dark (default) / Light, stored in `localStorage`, applied before first paint to prevent flash
- **Glassmorphism** — `.card-os` class for frosted glass card style

The theme is applied via `body.theme-dark` / `body.theme-light` CSS classes. The navigation component includes a toggle button.
