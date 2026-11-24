<?php

namespace App\Providers;

use App\Services\Fal\FalTryOnProvider;
use App\Services\Fashn\FashnTryOnProvider;
use App\Services\Fashn\TryOnProvider;
use App\Services\Feed\AmazonPaApiFeedParser;
use App\Services\Feed\DefaultFlatBackgroundVerifier;
use App\Services\Feed\FeedParser;
use App\Services\Feed\FeedSource;
use App\Services\Feed\FlatBackgroundVerifier;
use App\Services\Feed\HttpFeedSource;
use App\Services\Feed\NullFeedParser;
use App\Services\Feed\XmlFeedParser;
use App\Services\Lead\LeadNotifier;
use App\Services\Lead\NullLeadNotifier;
use App\Services\Membership\PlanCatalog;
use App\Services\Photo\DefaultPhotoValidator;
use App\Services\Photo\InterventionPhotoAspectFitter;
use App\Services\Photo\InterventionPhotoCropper;
use App\Services\Photo\InterventionPhotoNormalizer;
use App\Services\Photo\NullImageEnhancer;
use App\Services\Photo\PhotoAspectFitter;
use App\Services\Photo\PhotoCropper;
use App\Services\Photo\PhotoEnhancer;
use App\Services\Photo\PhotoNormalizer;
use App\Services\Photo\PhotoValidator;
use App\Services\Photo\PoseServiceImageEnhancer;
use App\Services\Pose\MediaPipePoseDetector;
use App\Services\Pose\NullPoseDetector;
use App\Services\Pose\PoseDetector;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Apple\Provider as AppleProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Feed import contracts. A real parser is enabled ONLY by an explicit
        // FEED_FORMAT: 'xml' (Google Merchant RSS, what partner networks serve)
        // or 'amazon' (the Product Advertising API shape the Amazon.ae catalog
        // comes in). Every other value — including unset/empty, the default —
        // resolves to NullFeedParser (imports nothing, never fatals), so an
        // unconfigured environment is safe by default. Keep the strict match in
        // lockstep with config/feed.php having no FEED_FORMAT default.
        $this->app->bind(FeedParser::class, fn ($app) => match (config('feed.format')) {
            'xml' => $app->make(XmlFeedParser::class),
            'amazon' => $app->make(AmazonPaApiFeedParser::class),
            default => $app->make(NullFeedParser::class),
        });
        $this->app->bind(FlatBackgroundVerifier::class, DefaultFlatBackgroundVerifier::class);

        // Default feed source: fetch the partner feed over HTTP from
        // config('feed.url'). The dev `feed:seed-local` command rebinds this to a
        // local-file source for its own process only — prod stays HTTP.
        $this->app->bind(FeedSource::class, HttpFeedSource::class);

        // Lead notification seam. Default is a no-op: no external funnel tool is
        // chosen yet, so captured leads stay in our own DB and nothing is sent.
        // Swapping in a real notifier later is just a binding change here.
        $this->app->bind(LeadNotifier::class, NullLeadNotifier::class);

        // Virtual try-on engine. The job depends on the TryOnProvider contract;
        // the concrete client is selected by config('services.tryon.driver') —
        // 'fal' (production default) or 'fashn' (legacy, kept for fallback). Tests
        // fake the HTTP layer; the suite pins the driver to 'fashn' via phpunit.xml.
        $this->app->bind(TryOnProvider::class, fn ($app) => match (config('services.tryon.driver')) {
            'fashn' => $app->make(FashnTryOnProvider::class),
            default => $app->make(FalTryOnProvider::class),
        });

        // Pose detection seam (Phase 9). 'null' selects the no-op stub for tests
        // / environments without the service; anything else (the default) uses
        // the real HTTP client. Not yet wired into the generation flow — that is
        // 9.5. Consumers (9.2/9.4) will depend on the PoseDetector contract.
        // Note: an explicit POSE_DRIVER=null is read back by Laravel's env() as PHP
        // null (it coerces the string "null"), and an empty value as ''. Both mean
        // "no service" and must select the stub — matching the string 'null' alone
        // would silently fall through to the real HTTP client (and only "work" while
        // the service happens to be unreachable via fail-open). So map null/''/'null'.
        $this->app->bind(PoseDetector::class, fn ($app) => match (config('services.pose.driver')) {
            'null', '', null => $app->make(NullPoseDetector::class),
            default => $app->make(MediaPipePoseDetector::class),
        });

        // Input-photo suitability gate (Phase 9.2). Pure decision logic over a
        // PoseResult; not yet wired into the generation flow (that is 9.5).
        $this->app->bind(PhotoValidator::class, DefaultPhotoValidator::class);

        // Input-photo geometry normalization (Phase 9.3): EXIF orientation +
        // aspect-preserving downscale + format. Not yet wired into the flow (9.5).
        $this->app->bind(PhotoNormalizer::class, InterventionPhotoNormalizer::class);

        // Input-photo person-aware cropping (Phase 9.4): crop around the subject
        // from reliable pose landmarks. Not yet wired into the flow (that is 9.5).
        $this->app->bind(PhotoCropper::class, InterventionPhotoCropper::class);

        // Output aspect fit (Phase 9.6): after cropping, fit the photo to the
        // engine's output ratio (config photo.output.aspect) so the Before/After
        // slider shares one frame — no letterbox bars. Pure geometry.
        $this->app->bind(PhotoAspectFitter::class, InterventionPhotoAspectFitter::class);

        // Input-photo appearance enhancement (Phase 9.5): lighting normalization +
        // person segmentation, both run by the pose microservice. The real HTTP
        // enhancer is used only when at least one step is enabled AND the pose
        // service is in play; otherwise the no-op enhancer keeps the pipeline
        // byte-identical to pre-9.5 and makes no network call. Each step is also
        // independently toggled inside the enhancer via config('photo.enhancement').
        $this->app->bind(PhotoEnhancer::class, function ($app) {
            $anyStep = (bool) config('photo.enhancement.lighting.enabled', false)
                || (bool) config('photo.enhancement.segmentation.enabled', false);

            // Same null/''/'null' handling as the pose binding: no service → no-op.
            $poseActive = ! in_array(config('services.pose.driver'), ['null', '', null], true);

            return ($anyStep && $poseActive)
                ? $app->make(PoseServiceImageEnhancer::class)
                : $app->make(NullImageEnhancer::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Throttle lead capture as a guard against form spam: a handful of
        // submissions per minute per IP is plenty for a real user.
        RateLimiter::for('lead', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        // Throttle photo uploads per IP — keeps someone from flooding R2 with junk.
        RateLimiter::for('photo-upload', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // The paywall modal lists the plans, and it is drawn on every page that has overlays.
        View::composer('membership.partials.modals', fn ($view) => $view->with('paywallTiers', app(PlanCatalog::class)->tiers()));

        // A translated string as a JS literal that keeps its letters readable (JsText).
        Blade::directive('jsText', fn (string $expression) => "<?php echo \\App\\Services\\Locale\\JsText::from({$expression}); ?>");

        // Sign in with Apple is not one of Socialite's built-in drivers; the
        // community provider registers itself through this event.
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('apple', AppleProvider::class);
        });
    }
}
