# CORRAD Laravel CMS — AI Coding Reference

> **This file is the single source of truth for AI-assisted development.**
> All generated code MUST conform to the patterns below. No exceptions.

## Project Identity

| Key | Value |
|-----|-------|
| Name | CORRAD Laravel CMS |
| Backend | Laravel 12, PHP 8.2+, Laravel Sanctum 4.3 |
| Frontend | Vue 3 + TypeScript + Tailwind CSS 4 + Pinia 3 + Vite 7 |
| Database | SQLite (dev), MySQL (prod) |
| Auth | Sanctum session-based SPA auth, RBAC via `Permission` class |
| Commands | `composer setup` (init), `composer dev` (run all), `composer test` (PHPUnit), `composer deploy` (production — see `docs/process/deployment.md`) |

---

## AI Execution Protocol (Anti-Hallucination)

These steps are mandatory for every AI task.

1. **Discover first**: inspect existing code before proposing or editing. Cite concrete files/functions that already exist.
2. **Do not guess**: if a route, class, env var, migration, or API contract is not found, state that explicitly before creating anything.
3. **Reuse before create**: check for existing controllers/services/composables/utilities before adding new ones.
4. **Implement minimally**: make the smallest coherent change that satisfies the request and preserves existing patterns.
5. **Validate before claiming done**: run relevant checks for touched areas (`composer test`, targeted PHPUnit test, build/lint when frontend changes).
6. **Report with evidence**: final summary must include changed files, commands run, validation results, and any assumptions/risks.

Hard requirements:
- Never claim tests/commands passed unless they were actually executed.
- Never fabricate output, stack traces, package versions, or benchmark numbers.
- Never present speculative code as "existing behavior."

---

## Directory Layout

```
app/
  Enums/Permission.php              # RBAC permission constants
  Http/
    Controllers/Api/                 # One controller per resource (11 total)
    Middleware/                       # CamelCaseMiddleware, CheckPermission
    Requests/                        # BaseFormRequest + Store*/Update* per entity
    Traits/                          # ApiResponse, Auditable
  Jobs/                              # CleanExpiredSessions, PruneAuditLogs
  Models/                            # 8 Eloquent models
  Providers/AppServiceProvider.php
  Services/                          # AuditService, SettingService, SlugService
bootstrap/app.php                    # Middleware, exception handling, rate limiting
config/                              # Standard Laravel config files
routes/api.php                       # ALL API route definitions (single file)
database/
  migrations/                        # Timestamped migration files
  seeders/                           # RoleSeeder, UserSeeder, SettingSeeder, CategorySeeder
  factories/
client/                              # Vue 3 SPA (separate package.json)
  src/
    api/                             # client.ts (HTTP util), auth.ts, cms.ts
    components/                      # Reusable Vue components
    composables/                     # useToast, useConfirmDialog, useSidebarCollapse
    config/admin-menu.ts
    layouts/                         # AdminLayout.vue, StorefrontLayout.vue
    router/index.ts                  # All frontend route definitions
    stores/                          # Pinia stores: auth, site, menu, uiTheme
    views/                           # View components (~30+)
    types.ts                         # All TypeScript interfaces
    env.ts                           # API_BASE_URL from Vite env
tests/
  Feature/                           # Integration tests
  Unit/                              # Unit tests
```

**Rule**: Default to this structure. If a task requires a new path outside it, call it out explicitly and only proceed when justified by the task.

---

## Backend Rules

### Controllers

**Location**: `app/Http/Controllers/Api/{Resource}Controller.php`

Every controller MUST:
- `use ApiResponse` trait — never return raw `response()->json()`
- Inject services via constructor promotion
- Accept `page`, `limit`, `q`, `sort_by`, `sort_dir` on list endpoints
- Return pagination meta on list endpoints
- Use Form Requests for store/update (never inline `$request->validate()`)

**Skeleton**:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store{Resource}Request;
use App\Http\Requests\Update{Resource}Request;
use App\Http\Traits\ApiResponse;
use App\Models\{Resource};
use App\Services\SlugService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class {Resource}Controller extends Controller
{
    use ApiResponse;

    public function __construct(
        protected SlugService $slugService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $page    = (int) $request->input('page', 1);
        $limit   = (int) $request->input('limit', 10);
        $q       = $request->input('q');
        $sortBy  = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');

        $query = {Resource}::query();

        if ($q) {
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', "%{$q}%");
            });
        }

        $total = $query->count();
        $rows  = $query->orderBy($sortBy, $sortDir)
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        return $this->sendOk($rows, [
            'page'       => $page,
            'limit'      => $limit,
            'total'      => $total,
            'totalPages' => (int) ceil($total / $limit),
        ]);
    }

    public function store(Store{Resource}Request $request): JsonResponse
    {
        $data = $request->validated();
        $resource = {Resource}::create($data);
        return $this->sendCreated($resource);
    }

    public function show(int $id): JsonResponse
    {
        $resource = {Resource}::find($id);
        if (!$resource) {
            return $this->sendError(404, 'NOT_FOUND', '{Resource} not found');
        }
        return $this->sendOk($resource);
    }

    public function update(Update{Resource}Request $request, int $id): JsonResponse
    {
        $resource = {Resource}::find($id);
        if (!$resource) {
            return $this->sendError(404, 'NOT_FOUND', '{Resource} not found');
        }
        $resource->update($request->validated());
        return $this->sendOk($resource);
    }

    public function destroy(int $id): JsonResponse
    {
        {Resource}::where('id', $id)->delete();
        return $this->sendOk(['success' => true]);
    }
}
```

**Reference**: `app/Http/Controllers/Api/PostController.php`

### Response Methods (ApiResponse trait)

| Method | Usage | HTTP Status |
|--------|-------|-------------|
| `$this->sendOk($data, $meta)` | Success response | 200 |
| `$this->sendCreated($data)` | Resource created | 201 |
| `$this->sendNoContent()` | Deletion with no body | 204 |
| `$this->sendError($status, $code, $message, $details)` | Error response | any |

### Models

**Location**: `app/Models/{Resource}.php`

Every model MUST:
- Declare `$fillable` explicitly — never use `$guarded = []`
- Use `casts()` method (not `$casts` property)
- Use `HasFactory` trait
- Use `Auditable` trait for data models (from `App\Http\Traits\Auditable`)
- Declare `$hidden` for sensitive fields (password, remember_token)
- Use explicit return type annotations on relationships

**Skeleton**:

```php
<?php

namespace App\Models;

use App\Http\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class {Resource} extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'name',
        'slug',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
```

**Reference**: `app/Models/Post.php`

### Form Requests

**Location**: `app/Http/Requests/{Store|Update}{Resource}Request.php`

Every form request MUST:
- Extend `BaseFormRequest` (NOT `FormRequest` directly)
- Return `true` from `authorize()` — auth is handled by route middleware
- Use `exists:{table},{column}` for foreign key validation
- Prefix nullable fields with `nullable|`

**Skeleton**:

```php
<?php

namespace App\Http\Requests;

class Store{Resource}Request extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => 'required|string|min:1',
            'slug'        => 'nullable|string',
            'description' => 'nullable|string',
            'status'      => 'nullable|in:draft,published,archived',
            'parent_id'   => 'nullable|integer|exists:resources,id',
        ];
    }
}
```

**Reference**: `app/Http/Requests/StorePostRequest.php`, `app/Http/Requests/BaseFormRequest.php`

### Services

**Location**: `app/Services/{Name}Service.php`

- Pure PHP classes, no base class
- Inject via constructor in controllers
- Existing services to reuse:
  - `SlugService::uniqueSlug($table, $title, $requestedSlug, $excludeId)` — for all sluggable entities
  - `AuditService::log(...)` / `::logAuth(...)` — for manual audit events
  - `SettingService` — key-value settings with alias support

### Routes

**File**: `routes/api.php` (ALL API routes live here — no other route files for API)

Route groups:
1. **Public** (no auth): `Route::prefix('public')->group(...)`
2. **Auth**: `Route::prefix('auth')->group(...)` with `throttle:login` on login
3. **Protected**: `Route::middleware('auth:sanctum')->group(...)`

For standard CRUD, use `Route::apiResource('{resources}', {Resource}Controller::class)`.
For permission-gated routes, add `->middleware('permission:{module}.{action}')`.

### Middleware

- **CamelCaseMiddleware**: Automatically converts incoming JSON camelCase keys to snake_case and outgoing snake_case to camelCase. Applied globally to the `api` middleware group. **Never manually convert case in controllers or responses.**
- **CheckPermission**: RBAC check. Registered as alias `permission`. Usage: `->middleware('permission:posts.view')`
- Both registered in `bootstrap/app.php`

### Permissions (RBAC)

**File**: `app/Enums/Permission.php`

Pattern: `const {MODULE}_{ACTION} = '{module}.{action}'`

Existing permissions:
```
posts.view, posts.create, posts.edit, posts.delete
pages.view, pages.create, pages.edit, pages.delete
media.view, media.upload, media.delete
users.view, users.create, users.edit, users.delete
roles.view, roles.create, roles.edit, roles.delete
settings.view, settings.edit
menus.view, menus.edit
audit.read
```

When adding a new module: add constants AND register them in `Permission::all()`.

### Jobs

**Location**: `app/Jobs/{JobName}.php`
- Must implement `ShouldQueue`, use `Queueable` trait
- Reference: `app/Jobs/PruneAuditLogs.php`

---

## Frontend Rules

### Vue Components

- MUST use `<script setup lang="ts">` — never Options API
- Admin pages wrap in `<AdminLayout>`, storefront pages in `<StorefrontLayout>`
- Icons from `lucide-vue-next`
- Use composables: `useToast()`, `useConfirmDialog()`, `useSidebarCollapse()`

### API Calls

**All HTTP calls go through `apiRequest<T>()` from `client/src/api/client.ts`.**

- CSRF: Handled automatically (reads `XSRF-TOKEN` cookie)
- Credentials: Always `credentials: "include"` (set in `apiRequest`)
- POST/PUT: `JSON.stringify(input)` for body
- File uploads: Use `FormData` — do NOT set Content-Type manually

**Skeleton** (add to `client/src/api/cms.ts`):

```ts
export async function list{Resources}(params = "") {
  return apiRequest<{ data: {Resource}[]; meta: Record<string, unknown> }>(`/api/{resources}${params}`);
}

export async function get{Resource}(id: number) {
  return apiRequest<{ data: {Resource} }>(`/api/{resources}/${id}`);
}

export async function create{Resource}(input: {Resource}Input) {
  return apiRequest<{ data: {Resource} }>("/api/{resources}", { method: "POST", body: JSON.stringify(input) });
}

export async function update{Resource}(id: number, input: {Resource}Input) {
  return apiRequest<{ data: {Resource} }>(`/api/{resources}/${id}`, { method: "PUT", body: JSON.stringify(input) });
}

export async function delete{Resource}(id: number) {
  return apiRequest<{ data: { success: boolean } }>(`/api/{resources}/${id}`, { method: "DELETE" });
}
```

**Reference**: `client/src/api/cms.ts`

### TypeScript Types

**File**: `client/src/types.ts` — ALL entity types live here.

Pattern:
- `{Resource}Input` — create/update payload (camelCase keys)
- `{Resource}` — full server response (extends Input, adds `id`, `createdAt`, `updatedAt`)
- `PublishStatus = "draft" | "published" | "archived"` — reuse for status fields
- `ApiResponse<T> = { data: T; meta?: Record<string, unknown> }`

**Skeleton**:

```ts
export type {Resource}Input = {
  name: string;
  slug?: string;
  description?: string;
};

export type {Resource} = {Resource}Input & {
  id: number;
  slug: string;
  createdAt: string;
  updatedAt: string;
};
```

### Pinia Stores

**Location**: `client/src/stores/{name}.ts`
- Use `defineStore("name", { state, getters, actions })` — Options syntax
- Reference: `client/src/stores/auth.ts`

### Router

**File**: `client/src/router/index.ts`
- Admin routes: `/admin/{resource}` prefix
- Meta: `requiresAuth: true` for protected pages, `guestOnly: true` for login
- `meta.title` sets document title via `afterEach` hook

---

## API Design Rules

### Response Envelope

**Success (single)**:
```json
{ "data": { "id": 1, "name": "..." } }
```

**Success (list with pagination)**:
```json
{
  "data": [...],
  "meta": { "page": 1, "limit": 10, "total": 50, "totalPages": 5 }
}
```

**Created**: Same as success, HTTP 201.

**Deleted**:
```json
{ "data": { "success": true } }
```

**Error**:
```json
{ "error": { "code": "NOT_FOUND", "message": "Post not found", "details": null } }
```

### Standard Error Codes

| HTTP | Code | Usage |
|------|------|-------|
| 400 | `BAD_REQUEST` | Malformed request |
| 401 | `UNAUTHORIZED` | Not authenticated |
| 403 | `FORBIDDEN` | Missing permission |
| 404 | `NOT_FOUND` | Resource not found |
| 409 | `MEDIA_IN_USE` | Cannot delete referenced media |
| 422 | `VALIDATION_ERROR` | Form validation failed (details = field errors) |
| 429 | `TOO_MANY_REQUESTS` | Rate limit exceeded |
| 500 | `INTERNAL_ERROR` | Server error |

### Pagination Query Params

`?page=1&limit=10&q=search&sort_by=created_at&sort_dir=desc`

### Key Convention

Backend uses `snake_case` internally. Frontend uses `camelCase`. The `CamelCaseMiddleware` converts automatically. Never convert manually on either side.

---

## Security Policies

These rules are mandatory and non-negotiable.

1. **Validation**: Every mutating API endpoint MUST have a Form Request extending `BaseFormRequest`
2. **Authentication**: All protected routes MUST use `auth:sanctum` middleware
3. **Authorization**: Protected routes MUST use `auth:sanctum`. Use `middleware('permission:{module}.{action}')` for permission-gated endpoints and keep values aligned with `Permission::all()`
4. **Audit Logging**: All data models MUST use the `Auditable` trait. Auth events use `AuditService::logAuth()`
5. **Sensitive Fields**: `password`, `remember_token` MUST be in model `$hidden`. Auditable trait auto-filters these
6. **File Uploads**: MUST validate `mimes:` and `max:` size. Store via `Storage::disk('public')`. Sanitize filenames (lowercase, alphanumeric + dashes only)
7. **CSRF**: Sanctum handles CSRF via XSRF-TOKEN cookie. Never disable CSRF protection
8. **Passwords**: Use `hashed` cast in User model. Production bcrypt rounds: 12
9. **Rate Limiting**: Login endpoint uses `throttle:login`. Add rate limiting to sensitive endpoints
10. **No Raw SQL**: Use Eloquent query builder in controllers. Raw DB queries only in services when absolutely necessary
11. **Mass Assignment**: Always use explicit `$fillable` arrays. Never `$guarded = []`
12. **Input Sanitization**: Validate all input server-side. Never trust frontend validation alone

---

## Database Rules

### Migrations

**Location**: `database/migrations/`

- Use `$table->id()` for primary keys (bigint auto-increment)
- Use `$table->timestamps()` for `created_at`/`updated_at`
- Nullable fields: `->nullable()`
- Foreign keys: `$table->foreignId('x_id')->constrained('table_name')->nullOnDelete()`
- Slugs: `$table->string('slug')->unique()` — always unique index
- Status fields: `$table->string('status')->default('draft')` — store as string, not DB enum
- JSON columns: `$table->json('column')->nullable()` — cast as `array` in model
- Add compound indexes for frequent queries: `$table->index(['status', 'created_at'])`

### Seeders

**Location**: `database/seeders/`

- Use `WithoutModelEvents` in `DatabaseSeeder` (or in a specific seeder when intentionally suppressing model events)
- Register in `DatabaseSeeder::run()` call chain
- Existing order: RoleSeeder → UserSeeder → SettingSeeder → CategorySeeder

---

## Testing Requirements

- **Framework**: PHPUnit 11 via `composer test`
- **Database**: SQLite `:memory:` (configured in `phpunit.xml`)
- **Trait**: Use `RefreshDatabase` in all feature tests
- **Auth**: Use `Sanctum::actingAs($user)` for authenticated requests

### Every new API endpoint requires:

1. **Success case** — correct response shape and status code
2. **Validation error case** — 422 with `VALIDATION_ERROR` code
3. **Auth guard case** — 401 when unauthenticated
4. **RBAC case** — 403 when user lacks required permission
5. Assert response matches the API envelope format (`data`, `meta`, `error`)

### Test locations:

- Feature tests: `tests/Feature/{Resource}Test.php`
- Unit tests: `tests/Unit/` — for services and complex logic

---

## Final Response Contract

Every AI completion must include these sections in this order:

1. **Summary** — what was changed and why
2. **Changed Files** — exact paths and key edits
3. **Validation** — commands run and pass/fail outcomes
4. **Assumptions / Risks** — unresolved items, tradeoffs, or follow-up needed

Minimum quality rules:
- If no commands were run, state that explicitly.
- If tests fail, include the failing command and first relevant error.
- Do not hide uncertainty; label assumptions directly.

---

## Forbidden Patterns

These are hard rules. No exceptions. No escape hatches.

| # | DO NOT | Reason |
|---|--------|--------|
| 1 | Use `$guarded = []` on any model | Mass assignment vulnerability |
| 2 | Return raw `response()->json()` from controllers | Breaks standardized envelope — use ApiResponse trait |
| 3 | Extend `FormRequest` directly | Breaks JSON error format — extend `BaseFormRequest` |
| 4 | Manually convert camelCase/snake_case | `CamelCaseMiddleware` handles this automatically |
| 5 | Use Vue Options API (`export default { ... }`) | Project standard is `<script setup lang="ts">` |
| 6 | Create separate axios/fetch instances | Use `apiRequest()` from `client/src/api/client.ts` |
| 7 | Hardcode API URLs in frontend | Use `API_BASE_URL` from `client/src/env.ts` |
| 8 | Skip `Auditable` trait on data models | Enterprise audit compliance requirement |
| 9 | Add permissions without updating `Permission::all()` | RBAC registry becomes inconsistent |
| 10 | Use PHP `enum` for Permission class | It is a plain class with constants (JSON-serializable) |
| 11 | Create API routes outside `routes/api.php` | Single route file policy |
| 12 | Bypass Sanctum middleware on protected endpoints | Authentication requirement |
| 13 | Store passwords as plain text or custom hashing | Use Laravel `hashed` cast only |
| 14 | Install new packages without explicit user instruction | Dependency control policy |
| 15 | Use `$request->validate()` inline in controllers | Use Form Request classes for validation |
| 16 | Create new stores, composables, or utils without checking existing ones first | Avoid duplication |
| 17 | Say "done/tested" without running checks | Creates false confidence and hides regressions |
| 18 | Invent files/classes/routes/env vars not present in repo | Hallucination risk and broken implementation |
| 19 | Skip mentioning assumptions or unresolved risks | Reviewers cannot evaluate correctness safely |

---

## New Module Checklist

When adding a new CRUD module (e.g., "Tags", "Comments"), follow these steps in order:

### Backend

1. **Migration** — `database/migrations/xxxx_create_{table}_table.php` with `id()`, `timestamps()`, indexes
2. **Model** — `app/Models/{Resource}.php` with `$fillable`, `casts()`, `HasFactory`, `Auditable`, relationships
3. **Form Requests** — `app/Http/Requests/Store{Resource}Request.php` + `Update{Resource}Request.php` extending `BaseFormRequest`
4. **Controller** — `app/Http/Controllers/Api/{Resource}Controller.php` with `use ApiResponse`, inject services, CRUD methods
5. **Permissions** — Add constants to `app/Enums/Permission.php` as `{module}.view`, `{module}.create`, `{module}.edit`, `{module}.delete`. Register in `all()`
6. **Routes** — Add to `routes/api.php` inside `auth:sanctum` group. Use `Route::apiResource()` or explicit routes. Add `->middleware('permission:...')` only for permission-gated endpoints
7. **Seeder** (optional) — `database/seeders/{Resource}Seeder.php`, register in `DatabaseSeeder`

### Frontend

8. **Types** — Add `{Resource}Input` and `{Resource}` to `client/src/types.ts`
9. **API Functions** — Add CRUD functions to `client/src/api/cms.ts` using `apiRequest<T>()`
10. **Views** — Create list + editor views in `client/src/views/`, wrap in `AdminLayout`
11. **Router** — Add routes to `client/src/router/index.ts` with `requiresAuth: true` and `meta.title`

### Testing

12. **Tests** — Add `tests/Feature/{Resource}Test.php` covering all 4 required cases (success, validation, auth, RBAC)

---

## Quick Reference: Existing Services

| Service | Method | Purpose |
|---------|--------|---------|
| `SlugService` | `uniqueSlug($table, $title, $slug, $excludeId)` | Generate unique slug |
| `AuditService` | `log($userId, $action, $type, $id, $old, $new)` | Manual audit entry |
| `AuditService` | `logAuth($userId, $action)` | Login/logout audit |
| `SettingService` | `getAll()`, `update($data)`, `get($key)`, `set($key, $val)` | Settings CRUD |

## Quick Reference: Existing Composables

| Composable | Usage |
|------------|-------|
| `useToast()` | `toast.success("Saved")`, `toast.error("Failed")` |
| `useConfirmDialog()` | `confirm({ title, message, onConfirm })` |
| `useSidebarCollapse()` | Sidebar open/close state |
