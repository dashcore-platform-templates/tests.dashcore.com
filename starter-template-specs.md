# Starter Template Specs

What the starter kit provides out of the box. If a feature is listed here, it does not need to be scoped — it already works.

---

## 1. Built-in Screens & Pages

### Public

| Route | Page | What the user can do |
|-------|------|----------------------|
| `GET /` | Welcome | See the app name, sign in, or register |

### Authentication (guest only)

| Route | Page | What the user can do |
|-------|------|----------------------|
| `GET /login` | Login | Enter email + password, toggle "remember me", link to forgot password |
| `GET /register` | Register | Create an account (name, email, password with confirmation) |
| `GET /forgot-password` | Forgot Password | Enter email to receive a password reset link |
| `GET /reset-password/{token}` | Reset Password | Set a new password using a signed token from email |

### Authentication (authenticated only)

| Route | Page | What the user can do |
|-------|------|----------------------|
| `GET /verify-email` | Email Verification | Resend the verification email or log out |
| `GET /verify-email/{id}/{hash}` | Verify Handler | Signed URL that marks the email as verified (no UI — redirects) |
| `GET /confirm-password` | Confirm Password | Re-enter password before sensitive actions |

### Authenticated App

| Route | Page | What the user can do |
|-------|------|----------------------|
| `GET /dashboard` | Dashboard | See system status, see installed modules grouped by category with links |
| `GET /profile` | Profile | Update name and email, change password, delete account |

All authenticated routes require the `auth` and `verified` middleware — unverified users are redirected to the email verification page.

---

## 2. Authentication & Authorization

### Auth Flows

| Flow | Status | Details |
|------|--------|---------|
| Registration | Working | Creates user, fires `Registered` event, auto-logs in, redirects to dashboard |
| Login | Working | Email + password with "remember me", rate-limited to 5 attempts per email+IP |
| Logout | Working | Invalidates session, regenerates CSRF token |
| Password Reset | Working | Sends signed reset link via email, validates token, updates password, fires `PasswordReset` event |
| Email Verification | Working | User model implements `MustVerifyEmail`, signed URL sent via email, can resend |
| Password Confirmation | Working | Session-based confirmation gate for sensitive actions |

### Role System

Three roles defined in `App\Enums\UserRole` (string-backed enum):

| Role | Value | Default | Description |
|------|-------|---------|-------------|
| Owner | `'owner'` | No | Highest privilege |
| Admin | `'admin'` | No | Administrative access |
| Member | `'member'` | Yes | Default role for new users |

The `User` model provides `isOwner()`, `isAdmin()`, and `isMember()` helper methods. The `role` column is cast to the `UserRole` enum.

### Middleware

| Middleware | Behavior |
|------------|----------|
| `EnsureUserIsOwner` | Aborts 403 if user is not an Owner |
| `EnsureUserIsAdmin` | Aborts 403 if user is not an Admin |

These are available but not applied to any routes by default — they're ready to use when needed.

### Gates & Policies

None defined. Only middleware-based role checks exist.

---

## 3. Database Models & Tables

### User

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | Primary key |
| `name` | string | |
| `email` | string | Unique |
| `email_verified_at` | timestamp | Nullable |
| `password` | string | Auto-hashed via cast |
| `role` | string | Default `'member'`, cast to `UserRole` enum |
| `remember_token` | string | |
| `created_at` / `updated_at` | timestamps | |

**Relationships:** None defined (uses `Notifiable` trait from Laravel).

**Factory states:** `->owner()`, `->admin()`, `->unverified()`

### ActivityLog

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | Primary key |
| `user_id` | foreign | Nullable, cascades to null on user delete |
| `action` | string | e.g. "created", "updated", "deleted" |
| `subject_type` | string | Nullable, polymorphic morph type |
| `subject_id` | bigint | Nullable, polymorphic morph ID |
| `properties` | json | Nullable, cast to array |
| `created_at` / `updated_at` | timestamps | |

**Relationships:** `user()` (BelongsTo User), `subject()` (MorphTo)

**Note:** The model, migration, and factory exist but nothing writes to this table yet. It is scaffolding for audit logging.

### Infrastructure Tables

| Table | Purpose |
|-------|---------|
| `password_reset_tokens` | Stores password reset tokens (keyed by email) |
| `sessions` | Database-backed sessions (indexed by user_id and last_activity) |
| `cache` / `cache_locks` | Database-backed cache |
| `jobs` / `job_batches` / `failed_jobs` | Database-backed queue |

### Seeder

`DatabaseSeeder` creates one user: name "Admin", email `admin@example.com`, password `password`, role Owner.

---

## 4. Pre-installed Packages

### PHP (Composer — production)

| Package | Version | What it provides |
|---------|---------|------------------|
| `laravel/framework` | ^13.0 | Core framework |
| `livewire/livewire` | ^4.3 | Reactive server-rendered components |
| `livewire/volt` | ^1.7.0 | Single-file Livewire components (class + template in one file) |
| `resend/resend-laravel` | ^1.2 | Email delivery via Resend API |
| `laravel/tinker` | ^3.0 | REPL for interacting with the app |
| `laravel/nightwatch` | ^1.23 | Application monitoring |

### PHP (Composer — dev only)

| Package | Version | What it provides |
|---------|---------|------------------|
| `laravel/breeze` | ^2.4 | Auth scaffolding (generated the auth views/routes) |
| `laravel/pint` | ^1.27 | PHP code formatter (PSR-12 / Laravel style) |
| `laravel/pail` | ^1.2.5 | Real-time log tailing |
| `phpunit/phpunit` | ^12.5.12 | Test framework |
| `fakerphp/faker` | ^1.23 | Test data generation |
| `mockery/mockery` | ^1.6 | Test mocking |
| `nunomaduro/collision` | ^8.6 | Pretty error reporting in CLI |

### JavaScript (npm — dev only)

| Package | Version | What it provides |
|---------|---------|------------------|
| `tailwindcss` | ^4.0.0 | Utility-first CSS framework |
| `@tailwindcss/vite` | ^4.0.0 | Tailwind integration with Vite |
| `vite` | ^8.0.0 | Frontend build tool with HMR |
| `laravel-vite-plugin` | ^3.0.0 | Laravel + Vite integration |
| `concurrently` | ^9.0.1 | Runs multiple dev processes in parallel |

There are no runtime JavaScript dependencies. Alpine.js is bundled with Livewire.

---

## 5. UI Components & Layout System

### Layouts

| Layout | Tag | Used for |
|--------|-----|----------|
| App | `<x-app-layout>` | Authenticated pages. Includes sticky top navbar with navigation, persisted across Livewire navigations via `@persist`. |
| Guest | `<x-guest-layout>` | Auth pages (login, register, etc.). Centered card on black background with app logo. |

### Reusable Blade Components

| Component | Tag | What it does |
|-----------|-----|--------------|
| Primary Button | `<x-primary-button>` | Accent-colored submit button |
| Secondary Button | `<x-secondary-button>` | Muted button for cancel/secondary actions |
| Danger Button | `<x-danger-button>` | Red submit button for destructive actions |
| Text Input | `<x-text-input>` | Themed text input with focus ring |
| Input Label | `<x-input-label>` | Form field label |
| Input Error | `<x-input-error :messages="$errors->get('field')">` | Renders validation error messages |
| Modal | `<x-modal name="..." :show="...">` | Alpine.js modal with backdrop, focus trap, Escape-to-close. Sizes: sm/md/lg/xl/2xl |
| Action Message | `<x-action-message on="event-name">` | Shows temporary "Saved." confirmation for 2 seconds after a Livewire event |
| Auth Session Status | `<x-auth-session-status :status="session('status')">` | Displays session flash messages |
| Dropdown | `<x-dropdown>` | Alpine.js dropdown with trigger/content slots, configurable alignment and width |

### Design Token System

CSS custom properties defined in `resources/css/app.css`, applied via Tailwind v4:

| Token family | Classes | Purpose |
|--------------|---------|---------|
| Surface | `surface-50` through `surface-950` | Backgrounds, text, borders (slate gray palette) |
| Accent | `accent-400`, `accent-500`, `accent-600` | Interactive elements — buttons, links, focus rings (cyan/blue) |
| Success | `success-500` | Positive status indicators (green) |
| Warning | `warning-500` | Warning indicators (amber) |

### Theme System

- Two modes: `body.theme-dark` (default) and `body.theme-light`
- Stored in `localStorage` under key `app-theme`
- Applied before first paint via inline `<script>` to prevent flash
- Toggle button in the navigation bar

### Utility Classes

| Class | What it does |
|-------|--------------|
| `.card-os` | Glassmorphism card — backdrop blur, semi-transparent background, subtle border |
| `.card-os-glow` | Same as `.card-os` with added glow box-shadow |
| `.pulse-dot` | Animated pulsing dot (opacity + scale keyframes) |
| `.animate-fade-in` | 300ms fade-in animation |
| `.scrollbar-hide` | Hides scrollbars (webkit) |
| `.safe-bottom` | Safe-area padding for notched mobile devices |

### Fonts

- **Body:** DM Sans (loaded from fonts.bunny.net)
- **Code:** JetBrains Mono (loaded from fonts.bunny.net)

---

## 6. Built-in Workflows & Features

| Feature | Status | Details |
|---------|--------|---------|
| Database sessions | Active | Sessions stored in `sessions` table, configured in `.env` |
| Database cache | Active | Cache stored in `cache` table |
| Database queue | Active | Jobs stored in `jobs` table, `composer run dev` starts a queue listener |
| Email verification | Active | Users must verify email before accessing authenticated routes |
| Rate-limited login | Active | 5 attempts per email+IP combination, then locked with cooldown |
| Theme persistence | Active | Dark/light preference persisted in localStorage, no flash on load |
| Activity log table | Schema only | Table and model exist but nothing writes to it |

### What is NOT actively running

- No scheduled tasks (only the default `inspire` command exists in `routes/console.php`)
- No queued jobs defined (the queue infrastructure is ready but no jobs exist)
- No notifications defined
- No events or listeners beyond Laravel's built-in auth events (`Registered`, `PasswordReset`, `Verified`, `Lockout`)
- No file upload handling
- No webhook handling

---

## 7. Navigation System

The sidebar is powered by `App\Services\NavigationRegistry`, a singleton registered in `AppServiceProvider`.

### Default Nav Items

Only **Dashboard** is hardcoded in the navigation template. Everything else comes from packages.

### How New Sections Get Added

Packages register nav items in their service provider's `boot()` method:

```php
$registry = app(\App\Services\NavigationRegistry::class);
$registry->register('Sales', 'Contacts', 'crm.contacts.index', 'users', 1);
```

Parameters: `register(group, label, routeName, heroiconName, sortOrder)`

- Items are grouped into dropdown menus by `group` name
- The nav component only renders items whose named route actually exists (`Route::has()`)
- Items are sorted by `order` within each group (lower = first)
- `hasItems()` lets the dashboard detect whether any modules are installed

### Navigation Behavior

- Sticky top bar with backdrop blur
- Desktop: horizontal bar with dropdown menus per group
- Mobile: hamburger menu with collapsible sections
- Active route highlighting via Alpine.js path tracking
- SPA-like navigation via `wire:navigate`
- User dropdown (profile, logout) on the right side
- Theme toggle button

---

## 8. What Is NOT Included

The following are **not provided** by the starter kit. If a project requires any of these, they must be scoped and built:

- **No multi-tenancy** — single-tenant application, one database, no team/organization model
- **No payment or billing** — no Stripe, no subscription management, no invoicing
- **No API layer** — no `routes/api.php`, no API authentication (Sanctum/Passport), no API resources
- **No admin panel** — role middleware exists but there are no admin-only pages or dashboards
- **No permissions system** — only three fixed roles (Owner/Admin/Member), no granular permissions or policies
- **No file uploads or media management** — no file storage handling, no image processing
- **No notifications** — no notification classes, no in-app notification center, no email templates beyond auth
- **No search** — no full-text search, no search UI
- **No audit logging implementation** — the `activity_logs` table and model exist but nothing writes to them
- **No reporting or analytics** — no charts, no data export, no reporting engine
- **No settings pages** — no app-wide or user-specific settings UI
- **No onboarding flow** — no setup wizard, no first-run experience beyond registration
- **No real-time features** — no WebSockets, no broadcasting, no presence channels
- **No internationalization** — English only, no translation files or locale switching
- **No custom email templates** — uses Laravel's default markdown mail for auth emails
- **No CI/CD configuration** — no GitHub Actions, no deployment scripts
- **No Docker or containerization** — assumes Laravel Herd local development
