<?php

use App\Http\Controllers\Admin\ColorAnalysisController;
use App\Http\Controllers\Auth\ComingSoonController;
use App\Http\Controllers\Auth\EmailAuthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\FittingRoomController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Membership\AvatarController;
use App\Http\Controllers\Membership\ColorController;
use App\Http\Controllers\Membership\FavoriteController;
use App\Http\Controllers\Membership\LookDownloadController;
use App\Http\Controllers\Membership\OnboardingController;
use App\Http\Controllers\Membership\ShopRedirectController;
use App\Http\Controllers\Membership\TryOnController;
use App\Http\Controllers\Membership\WardrobeController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\Stylist\ColorLabelController;
use Illuminate\Support\Facades\Route;

// The landing page is the sign-in page (e-mail code, Google, Apple). Kept outside the
// membership flag group so "/" always answers: the controller sends visitors
// to the guest fitting room while membership is off, and members to their
// account when a session is already there.
Route::get('/', [LoginController::class, 'show'])->name('login');

// How to have your data deleted. Public and outside every feature flag: Meta
// requires a reachable "data deletion instructions" URL for the WhatsApp app,
// and the instructions must hold whatever state the site is in.
Route::view('/data-deletion', 'legal.data-deletion', ['withoutOverlays' => true])
    ->name('data-deletion');

// Legal documents as HTML pages (the PDFs in public/legal are served by the web
// server directly). Public, outside every flag — Google's OAuth verification
// needs a reachable, readable privacy policy page.
Route::get('/legal/{document}', [\App\Http\Controllers\LegalDocumentController::class, 'show'])
    ->where('document', '[a-z-]+')
    ->name('legal.show');

// Virtual fitting room — an INTERNAL testing surface for the try-on pipeline,
// not a client-facing page. It lives behind FEATURE_FITTING_ROOM (off by
// default) and 404s while the flag is off, together with its API endpoints
// (see routes/api.php). Nothing on the site links to it.
Route::get('/ai-fitting-room', [FittingRoomController::class, 'show'])
    ->middleware('feature:fitting_room')
    ->name('fitting-room');

// Internal colour-analysis tool. Two gates, and they answer different
// questions: the feature flag decides whether these routes exist at all (a
// disabled feature is a plain 404 for everyone, admins included), and the role
// decides who gets past them once they do. Nothing on the site links here.
Route::middleware(['feature:color_analysis', 'auth', 'admin', 'locale:en'])
    ->prefix('/admin/color')
    ->name('admin.color.')
    ->group(function () {
        Route::get('/', [ColorAnalysisController::class, 'index'])->name('index');
        Route::post('/', [ColorAnalysisController::class, 'store'])->name('store');
        Route::get('/{profile}', [ColorAnalysisController::class, 'show'])
            ->whereNumber('profile')
            ->name('show');
        // The ONLY way a stored photo or rendered view reaches a browser.
        Route::get('/{profile}/image/{kind}', [ColorAnalysisController::class, 'image'])
            ->whereNumber('profile')
            // Underscores: the engine's view names are crop_left_eye and friends.
            ->where('kind', '[a-z_]+')
            ->name('image');
    });

// Colour labelling. Its own area rather than a page inside /admin/color,
// because the two answer to different roles: an administrator uploads and reads
// measurements, a stylist labels and needs nothing else. Two surfaces make that
// a fact about the routes rather than a promise in a template. Same feature
// flag, so the whole thing is a 404 while colour analysis is off.
Route::middleware(['feature:color_analysis', 'auth', 'stylist', 'locale:en'])
    ->prefix('/stylist/color')
    ->name('stylist.color.')
    ->group(function () {
        Route::get('/', [ColorLabelController::class, 'index'])->name('index');
        Route::get('/guide', [ColorLabelController::class, 'guide'])->name('guide');
        Route::get('/{profile}', [ColorLabelController::class, 'show'])
            ->whereNumber('profile')
            ->name('show');
        Route::post('/{profile}', [ColorLabelController::class, 'store'])
            ->whereNumber('profile')
            ->name('store');
        // The ONLY way a stored photo reaches a stylist's browser.
        Route::get('/{profile}/image/{kind}', [ColorLabelController::class, 'image'])
            ->whereNumber('profile')
            ->where('kind', '[a-z_]+')
            ->name('image');
    });

// The language switcher. Outside every feature flag: whatever page is open,
// its language can be changed.
Route::get('/locale/{locale}', LocaleController::class)
    ->where('locale', '[a-z]{2}')
    ->name('locale.switch');

// Infrastructure health probe: verifies Postgres + Redis connectivity.
Route::get('/health', [HealthController::class, 'show'])->name('health');

// Membership & Account. The whole area (pages AND auth endpoints) lives
// behind FEATURE_MEMBERSHIP and 404s while it is off, leaving the guest
// fitting-room flow as the only user-facing surface. /pricing stays public;
// /account/* requires a session (guests are sent to the sign-in page "/" —
// see bootstrap/app.php).
Route::middleware('feature:membership')->group(function () {
    Route::get('/pricing', [MembershipController::class, 'pricing'])
        ->name('membership.pricing');

    // Passwordless e-mail code auth. The OTP core carries its own layered
    // limits; the route throttles are just an outer flood guard.
    Route::post('/auth/email/send', [EmailAuthController::class, 'send'])
        ->middleware('throttle:10,1')
        ->name('auth.email.send');
    Route::post('/auth/email/verify', [EmailAuthController::class, 'verify'])
        ->middleware('throttle:20,1')
        ->name('auth.email.verify');

    // Google / Apple (Socialite). A provider without credentials 404s here,
    // the same as a closed feature. Apple answers with a POST (form_post).
    Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
        ->whereIn('provider', ['google', 'apple'])
        ->name('auth.social.redirect');
    Route::match(['get', 'post'], '/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
        ->whereIn('provider', ['google', 'apple'])
        ->name('auth.social.callback');

    Route::post('/logout', LogoutController::class)
        ->middleware('auth')
        ->name('auth.logout');

    // Pre-launch curtain: where the sign-in field leads while we are not open
    // (FEATURE_COMING_SOON). A plain 404 otherwise, like every closed flag.
    Route::get('/coming-soon', [ComingSoonController::class, 'show'])
        ->middleware('feature:coming_soon')
        ->name('coming-soon');

    Route::prefix('/account')->middleware('auth')->name('membership.account.')->group(function () {
        Route::get('/', [MembershipController::class, 'dashboard'])->name('dashboard');
        Route::get('/looks', [MembershipController::class, 'looks'])->name('looks');
        // The one-screen "Примірка" lives on the phone's dashboard now; the old address leads there.
        Route::get('/try-on', [MembershipController::class, 'tryOn'])->name('try-on');
        // The screen's two-step version (the design hand-over, step2-select.html),
        // for review. Step 1 takes the photo into the session; step 2 needs it there.
        Route::get('/try-on/step-1', [TryOnController::class, 'upload'])->name('try-on.step1');
        Route::post('/try-on/photo', [TryOnController::class, 'storePhoto'])
            ->middleware('throttle:photo-upload')->name('try-on.photo.store');
        Route::get('/try-on/photo', [TryOnController::class, 'photo'])->name('try-on.photo');
        Route::get('/try-on/step-2', [TryOnController::class, 'select'])->name('try-on.step2');
        // "Зберегти образ": the member's own finished look as a file.
        Route::get('/looks/{generation}/download', LookDownloadController::class)
            ->whereNumber('generation')->name('looks.download');
        Route::get('/wardrobe', [MembershipController::class, 'wardrobe'])->name('wardrobe');
        // "Видалити цю добірку" / the per-piece close: hidden for good, not erased.
        Route::delete('/wardrobe/looks/{generation}', [WardrobeController::class, 'hideLook'])
            ->whereNumber('generation')->name('wardrobe.looks.hide');
        Route::delete('/wardrobe/looks/{generation}/pieces/{product}', [WardrobeController::class, 'hidePiece'])
            ->whereNumber(['generation', 'product'])->name('wardrobe.pieces.hide');
        // The style quiz: offered once after sign-up, editable from the profile.
        Route::get('/welcome', [OnboardingController::class, 'show'])->name('onboarding');
        Route::post('/welcome', [OnboardingController::class, 'store'])->name('onboarding.store');
        Route::post('/welcome/skip', [OnboardingController::class, 'skip'])->name('onboarding.skip');
        // A buy button: counts the click, then leaves for the product's own shop_url.
        Route::get('/shop/{product}', ShopRedirectController::class)->whereNumber('product')->name('shop');
        // The colour type needs the engine as well as the area: without
        // COLOR_ANALYSIS_ENABLED these are plain 404s, and the nav item is
        // not drawn (AccountShell).
        Route::middleware('feature:color_analysis')->group(function () {
            Route::get('/color', [ColorController::class, 'index'])->name('color');
            Route::post('/color', [ColorController::class, 'store'])->name('color.store');
            Route::get('/color/{profile}', [ColorController::class, 'show'])
                ->whereNumber('profile')->name('color.show');
            Route::get('/color/{profile}/photo', [ColorController::class, 'photo'])
                ->whereNumber('profile')->name('color.photo');
        });
        Route::get('/capsules', [MembershipController::class, 'capsules'])->name('capsules');
        Route::get('/capsules/{occasion}', [MembershipController::class, 'capsule'])
            ->whereIn('occasion', (array) config('capsules.occasions'))->name('capsule');
        Route::get('/vip', [MembershipController::class, 'vip'])->name('vip');
        Route::get('/settings', [MembershipController::class, 'settings'])->name('settings');
        // "Обране": one list behind every heart on the account.
        Route::post('/favorites/{product}', [FavoriteController::class, 'store'])
            ->whereNumber('product')->middleware('throttle:120,1')->name('favorites.store');
        Route::delete('/favorites/{product}', [FavoriteController::class, 'destroy'])
            ->whereNumber('product')->middleware('throttle:120,1')->name('favorites.destroy');
        // The profile picture: the member's own, shown, replaced or removed.
        Route::get('/avatar', [AvatarController::class, 'show'])->name('avatar');
        Route::post('/avatar', [AvatarController::class, 'update'])
            ->middleware('throttle:10,1')->name('avatar.update');
        Route::delete('/avatar', [AvatarController::class, 'destroy'])->name('avatar.destroy');
    });
});
