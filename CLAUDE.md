# CLAUDE.md — Atelier Fit (AI Fitting Room)

Instructions for the agent that writes this project. Read the whole file before any task. A phased roadmap (phases 0–8) exists outside this repository — follow it for ordering; this file sets the rules, not the order of tasks. Deferred tasks live in a separate backlog file that is not part of this public copy.

## What this is

A virtual try-on ("fitting room") for a stylist's website, aimed at the UAE market. The site brand is **Atelier Fit** (monogram logo `AF`, favicon `public/favicon.png`). How a try-on works: the user uploads their own photo, picks a garment from the catalogue (imported from a partner feed), leaves a contact (WhatsApp/e-mail), gets a generated look and a buy button. The backend is Laravel, generation goes through an external try-on provider (FASHN API), and leads go to ManyChat.

### Surfaces of the site (as of 2026-08-20)

- `/` — the **sign-in page** (passwordless, since 2026-11-02): a six-digit code sent by e-mail (`App\Services\Otp\OtpService`, mail `App\Mail\LoginCode` over `MAIL_MAILER`; an SMTP relay in production), or "Continue with Google" / "Continue with Apple" through Laravel Socialite (`App\Http\Controllers\Auth\SocialAuthController`, table `social_accounts`). A provider button is drawn only if its `*_CLIENT_ID` is set (`App\Services\Auth\SocialProviders`). The account identity is `users.email` (normalised: trim + lowercase, `App\Services\Otp\EmailAddress`). A signed-in member is sent straight to `/account`. Apple answers with a cross-site POST, so its flow is stateless with a nonce in the provider cookie, and `auth/apple/callback` is excluded from CSRF.
- `/coming-soon` — a pre-launch holding page behind the `FEATURE_COMING_SOON` flag (on top of membership): while it is on, the e-mail from the sign-in field is stored as a lead tagged `waitlist` (no code, no account) and the visitor lands here; addresses in `OTP_TEST_ADDRESSES` take the normal sign-in (with a fixed `OTP_TEST_CODE`, no mail).
- `/pricing` — public plans; `/account/*` — the member area (dashboard, looks, colour type `/account/color` — behind the `COLOR_ANALYSIS_ENABLED` flag on top of membership, wardrobe, capsules, stylist, profile). All of it lives behind the `FEATURE_MEMBERSHIP` flag.
- `/ai-fitting-room` — an **internal** page that drives the try-on pipeline; NOT customer-facing. It lives behind `FEATURE_FITTING_ROOM` (off by default → 404) together with its endpoints. There are no links to it in the UI and none should be added.

The product owner closed the fitting room on purpose; the guest "2+4" funnel is therefore currently unreachable.

---

## Iron constraints (do NOT break them, do NOT "improve" them silently)

These decisions were made deliberately. If a better option seems to exist, do not implement it — ask first.

1. **The try-on engine is the FASHN API only.** Using IDM-VTON, Kolors, OutfitAnyone or any model with a non-commercial / research-only licence is forbidden — the project is commercial. Do not suggest or write code for self-hosting such models on RunPod/Replicate.
2. **The try-on call does not change the background.** That is a separate FASHN background endpoint, +1 call per variant. Do not write code that waits for a background from the try-on call. The background step is switched on only by the `with_background` flag and only when the task explicitly requires it.
3. **Photo privacy — honestly.** The photo goes to a third-party provider (FASHN, kept up to 72 h). Never claim anywhere in code, copy, comments or UI that it is "deleted immediately / after processing". The correct wording is "within 72 hours" (in Ukrainian «протягом 72 годин»). Do not hard-code promises the system does not keep.
4. **Lead timing:** the contact is collected AFTER the photo upload and the garment choice, but BEFORE the result is shown. Do not move lead capture to the first screen.
5. **The buy button is dynamic:** `brand_name` and `shop_url` are read from the product row in the database. No hard-coded shop names in a template.
6. **Generation is asynchronous only.** The FASHN call goes through a queue job: the HTTP endpoint only enqueues the work and returns a signed status link, and waiting for the result happens entirely inside the job (currently by polling FASHN until a terminal status; a webhook is allowed but not implemented). Holding an HTTP request open while waiting for the model is forbidden.

---

## Stack

- PHP 8.2, Laravel
- PostgreSQL (JSONB for the raw feed and FASHN payloads — do not push into separate tables what is naturally JSONB)
- Redis + Laravel Horizon (queues, cache, rate limits)
- Cloudflare R2 through the S3 driver (photo storage)
- FASHN API — the try-on engine
- ManyChat API — leads/messenger
- Front end: Blade + Alpine.js + Dropzone, no bundler — Alpine and Dropzone from a CDN (do NOT introduce an SPA/bundler without an explicit task). Pages: sign-in `/`, member area `/account/*` + `/pricing` (shared layout `resources/views/membership/layout.blade.php`), and the internal `/ai-fitting-room` (its own self-contained template)
- Palette — monochrome, exactly four colours: `#2D2D2D` (text, dark panels, primary buttons), `#FFFFFF` (background, cards, text on dark), `#F0F0F0` (second surface: sidebar, tiles, placeholders; it replaced a warm cream `#FBF8F3` on 2026-10-01 following the Figma desktop frame), `#6B6B6B` (secondary text and accents). Everything else is the same colours with transparency (the greys of the mock-up are defined that way: `#F6F6F6` hints = ink 4%, `#F8F8F8` = ink 3%, `#DDD` borders = ink 16%); the only exceptions are functional (`--danger` red, the green success tick "Photo added" on the phone — an icon from the Figma mock-up, `public/images/lk-mob/photo-added.svg`, chosen by the owner on 2026-10-09; the black Apple Pay button; country flags in the phone selector — emoji). Do not introduce new shades — `AppearanceTest` checks this. The single agreed exception (2026-10-20) is the "Try-on" screen of the phone `/account` (`resources/views/membership/partials/m-tryon.blade.php`, styles in `dashboard.blade.php`): per the design hand-over table, two grey lines are added to the four colours — `--border` `#E6E6E6` (outlines, the line above the tabs) and `--dashed` `#BDBDBD` (the dashed upload card); the two-step version `/account/try-on/step-1`, `/step-2` (`resources/views/membership/tryon/`) uses the same palette; the test allows these two greys only in `dashboard.blade.php` and `membership/tryon/`. `/account/try-on` redirects to `/account`
- Tests: Pest
- CI/CD: GitHub Actions → SSH deploy to a VPS

---

## Structure and data model

The core of the try-on is three tables. Key fields go into columns, raw data into JSONB.

- **products**: `external_id` (unique), `brand_name`, `product_name`, `price_aed`, `image_flat_url`, `shop_url`, `category` (`upper_body`/`lower_body`/`dresses`), `feed_category` (nullable — the category the product has in the feed; the filter chips in the member area are built from it, labels in `config/catalog.php` `category_labels`), `flat_bg_verified` (bool), `raw` (jsonb). Import is an upsert `ON CONFLICT (external_id) DO UPDATE`. Partial index on `flat_bg_verified = true`.
- **leads**: `user_id` (nullable — a lead can be a guest), `channel` (`whatsapp`/`email`), `contact`, `manychat_pushed_at`, `tags`, `ip`.
- **generations**: `lead_id`, `product_id` (+ `product_ids` jsonb and `outfit_step` for outfits), `client_photo_path`, `status` (`queued`/`processing`/`done`/`failed`/`expired`), `fashn_request_id`, `result_urls` (jsonb), `with_background` (bool), `error`, `expires_at`.

Membership (Stage B) added four more:

- **users**: `email` (unique) — the sign-in identity, `email_verified_at`; `name`/`password` are nullable because an account is created from an address, without a password. There are no phone columns any more (the 2026-11-02 migration dropped phone-only accounts).
- **otp_codes**: `email`, `code_hash` (the code is stored only as a hash), `attempts`, `expires_at`, `consumed_at`.
- **social_accounts**: `user_id`, `provider` (`google`/`apple`), `provider_id` (unique together with provider), `email` — the address the provider gave at link time.
- **credit_transactions**: the credit ledger — `user_id`, `generation_id` (nullable), `delta`, `reason` (`welcome`/`spend`/`refund`), unique on `(generation_id, reason)`. The balance is the sum of `delta`; there is no balance column and none should be added.
- **welcome_grants**: `identity_hash` (unique, an HMAC of the normalised e-mail) — the welcome grant is given once per ADDRESS forever, surviving account deletion.

Only products with `flat_bg_verified = true` reach the catalogue (`/api/catalog`).

### Feature flags

`config/features.php`, values from `.env`, all **off** by default — a deploy without an explicit decision does not open unfinished work. A route group is attached to the `feature:<name>` middleware; a disabled feature is a plain 404 (no redirects that betray the existence of routes).

- `FEATURE_MEMBERSHIP` — auth endpoints, `/pricing`, `/account/*`. The `/` route itself (name `login`) is outside the group — the start page must always answer, so the controller judges the flag there: no membership → send to the fitting room, and if that is closed too → 404.
- `FEATURE_FITTING_ROOM` — the `/ai-fitting-room` page and the endpoints that START work (`/api/catalog`, `/api/lead`, `/api/fitting-room/photo`, `/generate`, `/generate-outfit`). The signed status route is deliberately outside the flag: a generation already started must be allowed to finish. The suite runs the pipeline with the flag `true` (set in `phpunit.xml`); the closed state is checked by `FittingRoomAccessTest`.

---

## Commands

```bash
composer install
php artisan migrate
php artisan horizon          # queue workers (generation does not work without it)
php artisan schedule:work    # feed import and cleanup on a schedule
./vendor/bin/pest            # tests
./vendor/bin/pest --coverage # with coverage
```

---

## Testing (mandatory, no exceptions)

Test coverage is a requirement of the product owner, not an option.

- **A task is not done without tests.** Do not mark a step done if the code is not covered and `pest` is not green.
- **Run `pest` before reporting readiness.** Do not claim that something works without running the tests.
- **External services in tests are ALWAYS mocked.** No real FASHN/ManyChat calls and no real R2 uploads from tests — that is money and private data. Use:
  - `Http::fake()` — FASHN, ManyChat
  - `Storage::fake('r2')` — photos
  - `Queue::fake()` or a synchronous run — jobs
- **The FASHN webhook signature** is tested separately: valid → accepted, invalid → rejected. It is an external trust point; treat it as a security boundary.
- Pyramid: unit (feed parser, brand mapper, validators, FASHN request building) → feature (endpoints, jobs, webhooks, rate limits) → a couple of e2e smoke tests on the happy path.
- Target coverage of business logic — not below ~80%.
- Every bug you fix comes with a test that reproduces it.

### Model factories (mandatory convention)

Test data is prepared only through factories — uniformly in every phase.

- **Every Eloquent model ships with its own factory** in `database/factories` (you create a model — in the same step you create its factory).
- **The model uses `HasFactory`** (trait `Illuminate\Database\Eloquent\Factories\HasFactory`).
- **Tests prepare data THROUGH factories** (`Model::factory()->create()/make()`), not through raw `DB::table()->insert()` / `Model::create([...])`. Raw inserts in tests only when the behaviour on "dirty" data that bypasses the model is exactly what is being checked, and this is explicitly justified.
- **Meaningful variations go through factory states**, not by duplicating setup across tests. Add a named state for every significant variant of an entity (for example "verified product", "whatsapp lead", "generation in failed status") and reuse it.
- Factories use Faker (`fake()`); no real external calls from factories.

---

## External integrations — rules

- **Secrets only in `.env`**, accessed through `config()`. Never hard-code or commit FASHN/ManyChat/R2 keys. If a new key is needed, add it to `.env.example` with an empty value and describe it.
- **All external calls go through dedicated service classes** (e.g. `App\Services\Fashn`, `App\Services\ManyChat`), never directly from controllers. That makes them easy to mock and replace.
- **FASHN:** a hard spending cap and error handling are mandatory. A model error → status `failed`, a log entry, a clear state for the user — not a white screen and not an endless spinner.
- **Rate limit:** at most 3 generations per day per IP+e-mail. Remember that this is a flood barrier, not budget protection — the real protection is the lead gate (no contact → no generation) and the cap on the FASHN side.

---

## Interface languages (i18n)

The languages are in `config/locales.php` (currently `uk`, `en`, `ru`, `ar`; Arabic is RTL, and the CSS of the client pages uses logical properties `inset-inline-*`/`margin-inline-*`). The procedure is described in detail in the skill `.claude/skills/translate/SKILL.md`; in short:

- **No text in templates or PHP.** Every visible string is a key in `lang/<locale>/<file>.php`; in Blade `{{ __('file.key') }}`, in Alpine/JS `@jsText(__('file.key'))` (not `@js`: it turns Cyrillic into `\uXXXX`). HTML inside a translation only through a placeholder and `{!! !!}` with `e()` on the inserted values.
- **A new key goes into all languages at once**, with the same `:placeholders`. `TranslationParityTest` checks this (files, keys, placeholders, every key used in code), and `LocalizedPagesTest` checks that an English page has no Cyrillic and that no page shows a raw key.
- **Language selection** — `App\Http\Middleware\SetLocale`: `?lang=` / the switcher `/locale/{code}` → session → `users.locale` → cookie → `Accept-Language` → `APP_LOCALE`. Internal tools (`/admin/color`, `/stylist/color`) are pinned to English through `locale:en`.
- **Do not translate legal texts silently** (consent and Terms: `config/consent.php`, `config/terms.php`): a consent is valid only for the version the person saw. A translation is the owner's decision, shipped as a new version.
- Constraint 3 holds in every language: «протягом 72 годин» / "within 72 hours" / «в течение 72 часов» / «خلال 72 ساعة», never "immediately".

## Code conventions

- Follow standard Laravel practice and PSR-12. Run Pint if the project has it.
- Business logic lives in services/actions and jobs; controllers stay thin.
- Input validation goes through a Form Request, not in the controller body.
- Names, code comments and commit messages are in English. Domain terms from the brief (the `try-on-user` tag, statuses) stay as given.
- Do not pull extra packages into the project for one function — first check whether Laravel has it.

---

## Definition of Done (for every task)

1. The code is implemented and follows the constraints and conventions above.
2. Tests are written, `pest` is green locally.
3. External calls are mocked, no real spending during tests.
4. No violation of the "iron constraints".
5. `.env.example` is updated if new variables appeared.

---

## Working process

- Work through the phases of the roadmap (0 → 8), in order. Phase 5 (several looks + background) is optional — implement it only on explicit request.
- Before a big task, state a short plan; do not start rewriting the architecture without agreement.
- If a task requirement conflicts with the "iron constraints" — stop and ask, do not choose silently.
- Make atomic commits by meaning, each with passing tests.

## CI/CD — merge and deploy gates (contract)

Green tests are a mandatory condition for both merge and deploy. This is a contract that Phase 8 relies on; do not reinvent it.

- **CI:** `.github/workflows/ci.yml`, a job with the fixed name **`tests`** (Postgres + Redis as services, a `pest` run). The name `tests` is the required status check; **do not rename it**, or branch protection breaks.
- **Merge gate:** the `main` branch is protected (branch protection / ruleset): direct push is forbidden, changes only through a PR, required check = `tests`, "branch up to date" on, bypass forbidden even for admins. This is configured with repository-admin rights in Settings (outside the agent's reach).
- **Deploy gate (Phase 8):** the deploy step MUST depend on `tests` — implement it as a job in the same workflow with `needs: tests` and `if: github.ref == 'refs/heads/main' && github.event_name == 'push'`. That makes deploying a commit with red tests technically impossible: the deploy job does not start until `tests` has passed in the same run. (An acceptable alternative is a separate workflow on `workflow_run` from CI with the condition `conclusion == 'success'` and `head_branch == 'main'`.)
- **Transitive protection:** deploy is triggered only by a push to `main`; and `main` is protected by the merge gate above → only green, up-to-date, PR-reviewed commits reach `main`. So no separate "check the tests before deploy" is needed beyond `needs: tests` — rely on this chain.

## What not to do

- Do not connect non-commercial try-on models (see constraint 1).
- Do not promise instant photo deletion in code or UI (constraint 3).
- Do not make synchronous blocking FASHN calls (constraint 6).
- Do not call real FASHN/ManyChat/R2 from tests.
- Do not commit secrets.
- Do not turn the Blade front end into an SPA without a task.
- Do not put links to `/ai-fitting-room` in the customer UI — the page is internal.
- Do not introduce colours outside the palette from the "Stack" section.
- Do not mark a task done without green tests.
