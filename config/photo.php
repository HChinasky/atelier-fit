<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Input-photo validation (Phase 9.2)
    |--------------------------------------------------------------------------
    |
    | Thresholds for the pose-based photo gate. All tunable so rules are not
    | hardcoded magic numbers. The validator is a pure decision: go/no-go +
    | reason; it never calls the pose service or FASHN.
    |
    */

    'validation' => [
        // Minimum landmark visibility/confidence to treat a point as reliably
        // seen. Same meaning as the pose service's bbox_confidence_threshold.
        // "Full height" is judged by ankle CONFIDENCE against this, NOT by the
        // mere presence of an ankle landmark — MediaPipe extrapolates off-frame
        // ankles with ~0.02 confidence on waist-up shots.
        'landmark_confidence' => (float) env('PHOTO_LANDMARK_MIN_CONFIDENCE', 0.5),

        // Minimum horizontal shoulder separation as a fraction of image width.
        // In a profile/edge-on pose the shoulders collapse together, so a small
        // separation signals "not frontal".
        'min_shoulder_separation_ratio' => (float) env('PHOTO_MIN_SHOULDER_SEPARATION', 0.05),

        // Catalog categories that require the FULL body to be visible (legs /
        // ankles). Everything else only requires a visible torso, so a waist-up
        // photo is acceptable for a top but not for a dress or trousers.
        'full_body_categories' => ['dresses', 'lower_body'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Geometry normalization (Phase 9.3)
    |--------------------------------------------------------------------------
    |
    | Pure geometry applied before FASHN: fix EXIF orientation, cap the size
    | (downscale only, aspect ratio preserved — never stretch/upscale), and
    | encode to a target format. No ML, no cropping (that is 9.4). All values
    | tunable so nothing is a hardcoded magic number.
    |
    */

    'normalization' => [
        // Image backend: 'gd' (default, always present) or 'imagick'.
        'driver' => env('PHOTO_IMAGE_DRIVER', 'gd'),

        // Longest-side cap in pixels. Larger photos are scaled DOWN to fit within
        // a max_dimension x max_dimension box, preserving aspect ratio. Smaller
        // photos are left untouched (no upscaling).
        'max_dimension' => (int) env('PHOTO_MAX_DIMENSION', 2000),

        // Target output format: 'jpeg' | 'png' | 'webp'.
        'format' => env('PHOTO_TARGET_FORMAT', 'jpeg'),

        // Quality for lossy formats (jpeg/webp), 1–100.
        'quality' => (int) env('PHOTO_IMAGE_QUALITY', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Person-aware cropping (Phase 9.4)
    |--------------------------------------------------------------------------
    |
    | Crop the (normalized) photo around the person using pose landmarks so FASHN
    | gets a well-framed subject. Pure geometry (Intervention), no ML, no
    | distortion — only ever crops within the image. The crop box is built from
    | RELIABLE landmarks (confident AND in-frame); off-frame points are ignored so
    | they cannot stretch the box to the edge. Output is encoded with the
    | normalization driver/format/quality above. All values tunable.
    |
    */

    'cropping' => [
        // Minimum landmark confidence for a point to anchor the crop box. Same
        // meaning as validation.landmark_confidence; points below it are ignored.
        'landmark_confidence' => (float) env('PHOTO_CROP_MIN_CONFIDENCE', 0.5),

        // Catalog categories whose crop must include the FULL body down to the
        // feet. Mirrors validation.full_body_categories. This is the CATALOG view
        // (dresses/lower_body/upper_body), NOT the FASHN-mapped one.
        'full_body_categories' => ['dresses', 'lower_body'],

        // Margins added around the detected body before cropping. Clamped to the
        // image, so padding never escapes the photo.
        //   'crown'  — upward head allowance as a fraction of the face->shoulder
        //              span. MediaPipe gives no crown/hair point, so the top edge
        //              must reach above the topmost face landmark to keep the head
        //              whole; scaling with head size avoids cutting the crown.
        //   'top'    — minimum top margin as a fraction of body height (a floor the
        //              crown allowance can only exceed, for degenerate head spans).
        //   'bottom' — bottom margin as a fraction of body height.
        //   'side'   — left/right margin as a fraction of body width, so the crop
        //              keeps natural air instead of collapsing to a narrow column.
        'padding' => [
            'crown' => (float) env('PHOTO_CROP_PAD_CROWN', 1.1),
            'top' => (float) env('PHOTO_CROP_PAD_TOP', 0.08),
            'bottom' => (float) env('PHOTO_CROP_PAD_BOTTOM', 0.05),
            'side' => (float) env('PHOTO_CROP_PAD_SIDE', 0.5),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Appearance enhancement (Phase 9.5)
    |--------------------------------------------------------------------------
    |
    | Lighting normalization and person segmentation onto a neutral background,
    | run by the pose microservice (POST /preprocess-image). Applied AFTER
    | validation and BEFORE cropping; dimension-preserving so the crop geometry
    | stays valid. Each step is an independent ON/OFF flag, so either can be
    | rolled back via a single env var without a code change. Defaults: lighting ON,
    | segmentation OFF (not needed yet — flip PREPROCESS_SEGMENT=true to enable);
    | the test suite forces both OFF via phpunit.xml so the pre-9.5 path is the
    | default under test. The image-processing params (rembg model, neutral colour,
    | CLAHE/white-balance/gamma) live with the pose service's own env, not here —
    | this side only decides WHETHER each step runs and the call budget.
    |
    */

    'enhancement' => [
        'lighting' => [
            // Even out exposure/contrast/white balance of the input photo.
            'enabled' => filter_var(env('PREPROCESS_LIGHTING', true), FILTER_VALIDATE_BOOLEAN),
        ],

        'segmentation' => [
            // Cut the person out and place them on a neutral studio background so
            // the VTON engine is not distracted by a busy background.
            // OFF by default for now (not needed yet); flip PREPROCESS_SEGMENT=true
            // to re-enable without a code change.
            'enabled' => filter_var(env('PREPROCESS_SEGMENT', false), FILTER_VALIDATE_BOOLEAN),
        ],

        // HTTP timeout (seconds) for the enhancement call. Longer than pose
        // (services.pose.timeout) because rembg adds a couple of CPU seconds.
        'timeout' => (int) env('PREPROCESS_HTTP_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Output aspect fit (Phase 9.6)
    |--------------------------------------------------------------------------
    |
    | The VTON engine returns the look at a fixed aspect (fal: 3:4; FASHN tryon-max
    | preserves the input aspect). The leg-aware crop hugs the person, which can be
    | much narrower than that, so the Before/After slider letterboxes with bars.
    |
    | After cropping we fit the photo to this target aspect — widening the person's
    | region with the photo's REAL surrounding pixels (studio margins) and padding
    | with a neutral colour only where the photo runs out. So the image we send (and
    | the "Before" we show) matches the generated result: no bars, nothing cropped.
    |
    | 'aspect' empty disables the step (the crop is sent as-is). 'W:H' (e.g. 3:4),
    | 'W/H', or a decimal are accepted. Default 3:4 — the engine's output ratio and
    | the typical fashion portrait.
    |
    */

    'output' => [
        'aspect' => env('PHOTO_OUTPUT_ASPECT', '3:4'),
        'pad_color' => env('PHOTO_OUTPUT_PAD_COLOR', '#f0f0f0'),
    ],

];
