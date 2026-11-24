---
name: translate
description: Translate the fitting-room interface or add a new interface language. Use when adding or changing any user-visible text, when a page shows a raw key or the wrong language, when asked to translate copy (uk/en/ar/…), or when adding a language to config/locales.php.
---

# Translating the Atelier Fit interface

The site speaks the languages in `config/locales.php`: `uk` (the owner's copy,
written first), `en`, `ru` (translated from the Ukrainian) and `ar` (right-to-left,
translated from the English). `ru` and `ar` were machine-assisted on 2026-11-07
and still want a native speaker's read, Arabic season names especially.

## Where text lives

| What | Where |
| --- | --- |
| Words on every page (back, close, privacy line, credits) | `lang/<l>/common.php` |
| Sign-in page, e-mail and code steps, server answers | `login.php` |
| Account shell (nav, top line) | `account.php` |
| Guest gate, paywall, checkout overlays | `modals.php` |
| Dashboard, phone try-on, loader | `dashboard.php`, `tryon_steps.php` |
| Wardrobe / capsules / style quiz | `wardrobe.php`, `capsules.php`, `onboarding.php` |
| Looks, stylist, pricing, profile | `pages.php` |
| Plan cards and tiers | `plans.php` (names and prices stay in `MembershipController`) |
| Catalog chips | `catalog.php` (feed word → key in `config/catalog.php`) |
| Form Request and endpoint messages | `requests.php`, `generation.php`, `outfit.php`, `photo_validation.php` |
| Mail | `mail.php` |
| Laravel's own messages | `validation.php` (field names in `attributes`), `auth.php`, `passwords.php`, `pagination.php` |
| Framework errors as JSON (401/403/404/419/429/500/503) and the error page | `errors.php`, via `App\Exceptions\LocalizedErrors` |
| Framework strings keyed by their English text (error-page titles, "(and :count more errors)") | `lang/<l>.json` — none for `en` |
| Seasons and colour analysis | `seasons.php`, `color_*.php` |

## Rules

1. **No literal text in templates or PHP.** Blade: `{{ __('file.key') }}`.
   Alpine / inline JS: `@jsText(__('file.key'))` — never `@js(__())`, which
   writes every non-Latin letter as `\uXXXX`. A placeholder in JS:
   `@jsText(__('dashboard.compare_look')).replace(':n', i + 1)`.
2. **HTML inside a translation** only through a placeholder, rendered with
   `{!! __('x', ['link' => '<a href="'.e($url).'">'.e(__('y')).'</a>']) !!}`.
   The translation itself never holds tags, except a `<br>` the design draws.
3. **Plurals** with `trans_choice()`: `uk` and `ru` have three forms
   (`кредит|кредита|кредитов`), `en` two, `ar` six — zero|one|two|few (3–10)|many
   (11–99)|other. Every form that prints the number keeps `:count`. In JS
   (`tryon_steps.items`, Intl.PluralRules) a missing category falls back to `other`.
4. **Every key in every language, same `:placeholders`.** Add the key to all
   `lang/<l>/` files in the same change.
5. **Tone.** Ukrainian addresses the member as «ти» in new copy (the owner's
   older copy has some «ви» — leave it as written). **Never gendered past forms**
   for the reader («ти використала», «увійшла»): rephrase neutrally
   («вже використано», «вхід виконано»). Russian the same: «ты», no «готова»,
   «использовала». English: plain, warm, British spelling (colour, favourites,
   jewellery). Arabic: Modern Standard; second person cannot avoid gender, so use
   the generic masculine imperative (اختر) and nominal forms (اختيار) where they read well. Do not change approved Ukrainian copy while
   moving it into `lang/` — move it word for word.
6. **Privacy (CLAUDE.md constraint 3)** in every language: «протягом 72 годин» /
   «within 72 hours» / «в течение 72 часов» / «خلال 72 ساعة». Never «одразу»,
   «сразу», «immediately», «فورًا».
7. **Legal text is not translated silently.** `config/consent.php` and
   `config/terms.php` are versioned: consent is valid only for the wording the
   person saw. Translating them is the owner's decision and needs a new version.
8. **Data is not translated.** Brand and product names, prices, feed text come
   from the database as they are.
9. Quotes follow the language: «…» in Ukrainian, Russian and Arabic, “…” in English.
10. **A Form Request words every message a visitor can trigger** in its
    `messages()` (`requests.php`); the `validation.attributes` names are only
    the safety net. The front-end shows a JSON `message` as it is, so test a
    new endpoint in `LocalizedErrorsTest`'s "bad requests" dataset.

## Glossary

| uk | en |
| --- | --- |
| примірка | try-on |
| образ | look |
| річ / речі | piece / pieces |
| гардероб | wardrobe |
| Обране | Favourites |
| капсула | capsule |
| кольоротип | colour type |
| сезон | season |
| тариф | plan |
| членство | membership |
| кредит | credit |
| стилістка | stylist |
| Згенерувати образ | Generate look |

## How the language is chosen

`App\Http\Middleware\SetLocale` (web and api groups): `?lang=<code>` or the
switcher `/locale/{code}` (a choice: kept in session, a year-long cookie and
`users.locale`) → session → the member's `users.locale` → cookie →
`Accept-Language` → `APP_LOCALE`. At sign-in `Locales::syncAtLogin()` moves a
choice made on the device onto the account, or the account's onto the session.
`/admin/color` and `/stylist/color` are pinned to English (`locale:en`).
The switcher is `resources/views/partials/locale-switcher.blade.php`.

## Adding a language

1. Copy `lang/en/` to `lang/<code>/` and translate every value (keep keys and
   `:placeholders`; plural strings get the language's own number of forms).
2. Add `'<code>' => ['name' => '<name in itself>', 'short' => 'XX', 'dir' => 'ltr'|'rtl']`
   to `config/locales.php`.
3. Right-to-left: `<html dir>` follows `dir`. Client CSS uses logical properties
   (`inset-inline-*`, `margin-inline-*`, `padding-inline-*`, `text-align: start`) —
   write new CSS that way. Physical on purpose: centring (`left: 50%` + translate),
   the before/after sliders and the photo carousel (kept `direction: ltr`). The
   `[dir="rtl"]` block at the end of `membership/layout.blade.php` and
   `membership/tryon/layout.blade.php` flips arrows and drops letter-spacing for
   Arabic (it breaks joining). Look at the phone and desktop layouts in a browser.
4. Update `TranslationParityTest`'s `supports …` expectation and add the
   language to the datasets in `LocalizedPagesTest` (its non-Latin check is
   written for English; give the new language its own leak check).
5. Run `docker compose exec -T laravel.test ./vendor/bin/pest`.

## Before calling a translation done

- `TranslationParityTest` and `LocalizedPagesTest` are green, and the full suite.
- No literal user-visible text left in what you touched:
  grep the template for Cyrillic / English outside `{{ }}`.
- Read the page in each language in a browser at phone and desktop width:
  longer English or Arabic strings must not break buttons or pills.
