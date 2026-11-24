# color-engine

Seasonal colour analysis (the 12-season system) from a face photo.

This is a **library**, not a service. It has no HTTP layer and never will: the
plan is for `pose-service` to import it and expose `POST /color-analysis`, and
for Laravel to talk only to that HTTP contract. Laravel never imports this code.

**Status: stage 3, draft.** The engine reads a photo correctly, finds the face,
builds the measurement zones, samples them in CIELAB, estimates the illuminant
and adapts the samples to D65, reports how far those measurements drift between
lighting conditions, and now computes the three seasonal axes and scores the 12
seasons. Garment matching is still declared in the contract and stubbed in code
— see [What is a stub](#what-is-a-stub).

> **Not ready for production, twice over.**
>
> `sclera_target_ab` has never been measured on a real set — see
> [Before this ships](#before-this-ships).
>
> And **the classifier has never been checked against a face**. Every anchor
> point, weight and prototype in `data/classifier.json` was reasoned from the
> structure of the 12-season system and from where skin, hair and eyes sit in
> CIELAB. Not one of them was fitted to, or tested against, a photograph a
> stylist had labelled, because no such set exists yet. The result carries
> `classifier.draft: true` for exactly this reason, and every consumer must say
> so to the user. See [The classifier](#the-classifier).

## Install

Python 3.10–3.12 ([why](#python-and-mediapipe-versions)), [uv](https://docs.astral.sh/uv/):

```bash
cd services/color-engine
uv venv --python 3.10
uv pip install -e ".[dev]"
```

Then fetch the MediaPipe models (~20 MB, gitignored):

```bash
python scripts/fetch_models.py
```

`scripts/fetch_models.py` downloads two artifacts from Google's official
`storage.googleapis.com/mediapipe-models` URLs, both pinned to an explicit model
version rather than `latest`, and verifies each against a sha256 hardcoded in the
script. **Those hashes were computed from the first download** — if Google ever
republishes a different file at the same URL the script fails loudly rather than
quietly changing everybody's colour readings. It is the only code in the package
that touches the network.

| File | What it does |
| --- | --- |
| `face_landmarker.task` | 478 landmarks: 468 face mesh + 2×5 iris |
| `selfie_multiclass_256x256.tflite` | per-pixel classes: background, hair, body-skin, face-skin, clothes, others |

## Use

```python
from color_engine import Engine, EngineConfig

with Engine(EngineConfig()) as engine:  # loads both models once
    result = engine.analyze(photo_bytes)  # -> AnalysisResult
    print(result.to_json(indent=2))
```

**One `Engine` per process or worker.** MediaPipe Tasks objects are not
thread-safe. `analyze` takes an internal lock so a stray thread cannot corrupt
anything, but that serialises work — it is a safety net, not a concurrency
story. There is deliberately no module-level singleton: importing this package
must never load 20 MB of models.

Expected problems with a photo are **not exceptions**. They come back as
`status="rejected"` with a `reject_reason`:

```json
{"status": "rejected", "reject_reason": "no_face", "algo_version": "0.1.0", ...}
```

Exceptions mean the environment is broken (missing model file, bad config) and
are for operators, not users.

### Wiring it into pose-service

Not done yet, and deliberately not started here. When it is, it is five lines —
build the engine once at startup, reuse it per request:

```python
from color_engine import Engine, EngineConfig

engine = Engine(EngineConfig())  # module scope: one per worker


@app.post("/color-analysis")
def color_analysis(photo: UploadFile) -> dict:
    return engine.analyze(photo.file.read()).to_dict()
```

Because pose-service already runs MediaPipe single-threaded behind a lock, the
same deployment shape applies unchanged.

## CLI

```bash
color-engine analyze photo.jpg                       # indented JSON on stdout
color-engine analyze photo.jpg --json                # one compact line
color-engine analyze photo.jpg --quiz quiz.json

color-engine report ./photos --out reports/2026-09-16/
color-engine report ./photos --landmark-labels zones # index numbers on the face
color-engine report ./photos --landmark-labels all   # all 478, to find a better point
color-engine report ./set --by-person                # drift across lighting + summary.json

color-engine palettes draft --out season_palettes.json  # the DRAFT season palettes
```

`report` runs every image in a directory and writes **one** self-contained HTML
file: the original, the image after EXIF + ICC conversion, the zone polygons and
masks drawn over the face, **zoomed close-ups of each cheek, the forehead and
each eye**, a colour swatch and CIELAB numbers per zone, the landmark indices
each zone was built from, sclera quality, the reject reason and per-step
timings, with a summary table on top.

The close-ups matter: a mask defect around an eye is invisible at full-frame
scale.

That report is how the landmark index sets get checked — see
[Landmark indices](#landmark-indices).

### `--by-person`: stability across lighting

The instrument that matters before stage 2. It needs no seasonal labels, only
`person_id` and `lighting` — from `labels.json` (see
[The reference set](#the-reference-set)) or, failing that, straight off the
[filenames](#naming-the-photos):

```bash
color-engine report "$COLOR_ENGINE_FIXTURES_DIR" --by-person --out reports/baseline/
```

For each person it measures every photo's skin, hair and iris against that
person's **daylight** shot in CIEDE2000 — falling back to the person's own
median when there is no daylight frame, and never electing a rejected photo as
the reference.

Every number is reported **twice, before and after** the illuminant was divided
out, so the correction can be credited or blamed rather than assumed. That is
the number stage 2 is judged by. Per lighting condition it reports the median
and p90 of both, which method white balance used on each photo, and how many
photos were lost to `color_cast`.

It also prints the one measurement that comes back into the code:

> Measured sclera a\*b\* across the N daylight frames, uncorrected: **a\* x,
> b\* y**.

Under daylight the light is close to D65, so what the sclera measures there is
close to the sclera's own colour — and that is what
[`sclera_target_ab`](#the-sclera-is-not-white) should be set to instead of
(0, 0). Measured **uncorrected** on purpose: reading it off a corrected sample
would be circular, since the correction puts it on the current target by
construction.

Alongside the HTML it writes `summary.json` with all of it. Re-run the same set
after a change and the two files compare directly.

> `report` is the only thing in this package that writes an image to disk.
> Its output embeds the photos, so `reports/` is gitignored and a report should
> not outlive the review it was made for.

## Configuration

Every threshold lives in `config.py` with a comment explaining what the number
means, and every one is overridable from the environment with the
`COLOR_ENGINE_` prefix:

| Variable | Default | Meaning |
| --- | --- | --- |
| `COLOR_ENGINE_MODELS_DIR` | `services/color-engine/models` | Where the `.task`/`.tflite` files live |
| `COLOR_ENGINE_MAX_INPUT_BYTES` | `15728640` | Reject the upload before decoding (15 MiB) |
| `COLOR_ENGINE_MAX_SIDE` | `1600` | Longest side; bigger photos are downscaled with Lanczos |
| `COLOR_ENGINE_MIN_SHORT_SIDE` | `480` | Below this → `low_resolution` |
| `COLOR_ENGINE_MIN_FACE_FRAME_RATIO` | `0.15` | Face box vs frame; below this → `face_too_small` |
| `COLOR_ENGINE_MIN_FACE_WIDTH_PX` | `240` | Face width in the **original** pixels; below this → `face_too_small` |
| `COLOR_ENGINE_MIN_IRIS_DIAMETER_PX` | `20.0` | Iris diameter in the **original** pixels; below this the eye zones report `eye_too_small` |
| `COLOR_ENGINE_MAX_YAW_DEG` / `_PITCH_` / `_ROLL_` | `22` / `30` / `25` | Above these → `face_not_frontal` |
| `COLOR_ENGINE_HAIR_BELOW_CHIN_RATIO` | `0.0` | How far below the chin (in face heights) the hair zone may reach; stops a dark top being measured as hair |
| `COLOR_ENGINE_ZONE_POLYGON_SHRINK` | `0.85` | Cheek/forehead polygons pulled toward their centroid |
| `COLOR_ENGINE_L_PERCENTILE_LOW` / `_HIGH` | `10` / `90` | L\* tails dropped per zone (shadows, highlights) |
| `COLOR_ENGINE_CHROMA_MAD_K` | `3.0` | Skin chroma outlier cutoff, in robust sigmas |
| `COLOR_ENGINE_SCLERA_SPECULAR_L` | `96.0` | L\* above which a sclera pixel is a candidate catchlight |
| `COLOR_ENGINE_SCLERA_L_PERCENTILE_LOW` | `55.0` | Keep only the upper part of the sclera's L\* distribution |
| `COLOR_ENGINE_SCLERA_MAX_AB_DISTANCE` | `15.0` | a\*b\* distance from the sclera's own colour beyond which a pixel is lid, lash or liner |
| `COLOR_ENGINE_SCLERA_MAX_AB_SPREAD` | `6.0` | Above this a\*b\* spread the sclera sample is not one surface |
| `COLOR_ENGINE_SCLERA_EYE_AGREEMENT_MAX_DAB` | `6.0` | How far the two eyes may disagree in a\*b\* |
| `COLOR_ENGINE_SCLERA_OVER_SKIN_MIN_L` | `5.0` | The sclera must be this many L\* above the skin of the same face |
| `COLOR_ENGINE_SCLERA_TARGET_AB` | `[0.0, 0.0]` | The a\*b\* a healthy sclera is corrected *to* — **uncalibrated** |
| `COLOR_ENGINE_SCLERA_TARGET_CALIBRATED` | `false` | Has that been measured on a real set? Published as `white_balance.target_calibrated` |
| `COLOR_ENGINE_WHITE_BALANCE_ENABLED` | `false` | Master switch for the whole step. **Off by default** — see [Before this ships](#before-this-ships) |
| `COLOR_ENGINE_SCENE_MINKOWSKI_P` | `6.0` | p of the shades-of-grey estimate over the background |
| `COLOR_ENGINE_SCENE_MIN_PIXELS` | `2000` | Background pixels needed before the fallback means anything |
| `COLOR_ENGINE_SCENE_UNIFORM_AB_SPREAD` | `6.0` | Below this the background is one surface, not a scene |
| `COLOR_ENGINE_SCENE_UNIFORM_MAX_CHROMA` | `12.0` | …and if it is also this chromatic, the estimate is refused |
| `COLOR_ENGINE_MAX_CORRECTION_DUV` | `0.10` | Beyond this Δu'v' from D65 → `color_cast` instead of a correction |
| `COLOR_ENGINE_CAST_CONSENSUS_MIN_CHROMA` | `18.0` | With no estimate, how saturated *every* zone must be to *suspect* a cast |
| `COLOR_ENGINE_LOW_LIGHT_SKIN_L` | `32.0` | Median skin L\* that *raises* the underexposure question |
| `COLOR_ENGINE_LOW_LIGHT_SCLERA_L_OK` | `50.0` | …and the sclera L\* that settles it in the photo's favour |
| `COLOR_ENGINE_LOW_LIGHT_SCENE_L_OK` | `35.0` | …or the scene L\* that does |
| `COLOR_ENGINE_SCLERA_ERODE_RATIO` | `0.12` | Sclera erosion, as a share of the eye opening's **height** |
| `COLOR_ENGINE_MIN_ZONE_PIXELS` | `200` | Skin/hair zones below this are `insufficient` |
| `COLOR_ENGINE_FIXTURES_DIR` | unset | Reference photo set for the marked tests |

The full list is the field set of `EngineConfig`; the table above is the part
worth tuning.

## How it works

```
load → detect → zones → sample → white_balance → quality → features → classify
```

Each step is a plain function in its own module taking explicit arguments and
returning a typed object. No dict is passed end to end. `engine.py` only
sequences them and times them.

**load** (`io.py`) — applies EXIF orientation, converts the embedded ICC profile
to sRGB with `PIL.ImageCms` at perceptual intent, records the source profile, and
downscales to `max_side`. Getting this wrong is not a rounding error: reading a
Display P3 iPhone photo as sRGB pushes every hue outward and would drag the
whole seasonal call warm.

**features** (`features.py`) and **classify** (`classify.py`) — the three axes
and the season. Both read every number they use out of `data/classifier.json`;
see [The classifier](#the-classifier).

**detect** (`detect.py`) — FaceLandmarker with `num_faces=2` (so "more than one
face" is a thing we can *say*, not something we silently pick a winner from) plus
the multiclass segmenter. Head pose comes from the facial transformation matrix
when available, decomposed to yaw/pitch/roll; without it we fall back to nose-to-
face-side asymmetry. Size is checked twice — see
[How much resolution is enough](#how-much-resolution-is-enough).

**zones** (`zones.py`) — cheek and forehead polygons from named landmark sets,
**intersected with the segmenter's face-skin class**. That intersection is what
makes hand-picked indices safe: hair over the forehead, glasses frames and beard
are all a different class and drop out. Iris is an annulus from the iris
landmarks (outer edge shaved, pupil disc removed); sclera is the eye opening
minus the iris, eroded off the lid margins. Morphology kernels are sized as a
fraction of the *face*, never in raw pixels, so the same photo at 800px and
1600px yields the same zones.

**white_balance** (`white_balance.py`) — estimate the illuminant and adapt every
sample to D65. See [White balance](#white-balance).

**quality** (`quality.py`) — the reject reasons that need a measurement rather
than a detection. See [Quality gate](#quality-gate).

**sample** (`sampling.py`) — sRGB → XYZ → CIELAB (D65, 2°) through
`colour-science`; we never hand-roll colour maths. Per zone: drop the L\* tails
(specular highlights read as the light source's colour, not the skin's), and for
skin additionally drop chroma outliers by median+MAD (a lipstick smear has
skin-like lightness, so only the chroma filter catches it). Report the median
L\*a\*b\*, C\*, h°, pixel count and L\* IQR. The IQR is there for the confidence
score later. This module never imports MediaPipe — its input is an image and a
mask, which is what makes it testable against synthetic swatches and reusable
for garments.

`samples.skin` is **not** a pooled median over the union of the three sub-zones.
The forehead polygon is several times the area of either cheek, so pooling makes
"skin colour" mean "forehead colour" — and the forehead is the oiliest, most
sun-exposed and most strongly lit of the three. Each sub-zone is measured on its
own and `skin` is the median of the three medians, so the cheeks, which carry the
undertone, get their say. How far apart the forehead and the cheeks actually sit
is a measurement for the reference set — the report prints all three sub-zones
per photo, which is where that number will come from.

## How much resolution is enough

`min_face_frame_ratio` asks what *share of the frame* the face fills. That is
not the same question as how much detail there is: a 640×480 snapshot of a face
filling the frame passes any ratio you like while carrying a fraction of the
detail of the same composition shot on a phone, and what a colour measurement
depends on is how many sensor pixels landed on the skin and on the eye.

So there are two absolute floors as well, both counted **at source resolution**:

| Floor | What happens below it |
| --- | --- |
| `min_face_width_px` (240) | `face_too_small`. The face carries too little real detail whatever share of the frame it fills |
| `min_iris_diameter_px` (20) | **Not** a rejection. The iris and sclera zones of that eye report `eye_too_small`, and the skin analysis carries on — a photo taken a little too far away still measures skin perfectly well |

The iris floor is the binding one in practice: the iris is about a twelfth of a
face's width, so it runs out of pixels long before the cheeks do. It is applied
per eye; the pooled `iris` and `sclera` zones only fall when *both* eyes are
below it.

**Source resolution, and there is no upscaling anywhere.** `io._downscale` only
ever shrinks, and only past `max_side`; a photo smaller than that is passed
through untouched and one below `min_short_side` is rejected rather than
enlarged. `LoadedImage.source_scale` carries original-pixels-per-analysed-pixel
(≥ 1 by construction) through to `detect` and `zones`, so the floors judge the
camera rather than our own downscale. Two enlargements exist in the codebase and
neither touches a measured pixel: the segmenter's 256×256 class mask is resized
up with nearest-neighbour (class ids, never interpolated), and the report's zone
close-ups are enlarged for the eye, also nearest-neighbour.

That matters because an upscale *invents* pixels, and inventions change the
answer: the same crop upscaled to two different sizes gave sclera medians that
were clearly apart. See
[Why there are no numbers here](#why-there-are-no-numbers-here).

Both numbers are **preliminary**.

## The iris

The first live frame measured the iris at L\* 17.7 and C\* 1.0 on light eyes: a
near-neutral near-black, which is the colour of a *pupil*. Three things were in
the sample that are not the iris, and the mask now takes all three out.

* **The pupil**, with room for it to be **dilated**. Indoors a pupil is much
  wider than in daylight, so an inner radius tuned on a bright photo puts black
  into the sample of every dim one. `iris_inner_ratio` is 0.55 of the measured
  iris radius for that reason.
* **The limbal ring** — the dark band at the edge of every iris. It is not the
  eye's colour, and it is dark enough to drag a median a long way.
  `iris_outer_ratio` is 0.80.
* **Whatever the eyelid is covering.** On most open eyes the lid crosses the top
  of the iris, and the pixels under it are lid, lash, or the shadow they cast.
  The annulus is clipped to the eye opening, pulled in by `iris_opening_shrink`.

The annulus is drawn from a **distance field**, not from two `cv2.circle` calls,
because those take an integer radius: rounding both edges of a ring this thin
changes its area by more than a tenth between a photo at 800px and the same one
at 1600. Nothing else in `zones.py` is thin enough for that to show.

Catchlights are removed the way they are on the sclera — small connected blobs,
bright, and less coloured than what surrounds them — because a glint is a
picture of the lamp sitting in the middle of the thing being measured, and on a
dark iris it survives a percentile trim.

**Nothing is cut for being dark.** A brown iris *is* dark, all of it, and a floor
tuned so a blue eye reads well would leave a brown one with nothing to measure.
The only trim is a percentile inside the zone, which adapts to whatever eye it is
given.

The numbers above are **preliminary** and were chosen from anatomy, not measured:
they want checking against the reference set.

## White balance

Without this step a warm indoor bulb reads as warm skin and every photo drifts
autumn. The step answers one question — *what colour was the light* — and then
adapts every sample to D65, so two photos of the same face under different lamps
can be compared at all.

**Skin is never a reference, in any form.** Correcting the light against skin
would absorb the undertone the whole analysis exists to measure: the estimate
would eat the signal. Skin appears here only as the thing the sclera has to be
brighter than, and as one corroborating voice when there is no estimate at all.

### Where the estimate comes from

| Method | Reference | When |
| --- | --- | --- |
| `sclera` | The white of the eye | Whenever the sclera is usable — the only near-neutral surface a face reliably carries |
| `scene` | Shades of grey over the segmenter's **background** class | When the sclera is not. Clothes are excluded: one saturated dress fills half the frame and drags a grey-world estimate to its complement |
| `none` | — | Neither is trustworthy. The samples are reported as measured and the confidence is knocked down |

### Is the sclera usable?

Every test is **relative**, because the absolute ones measure the wrong thing.
Lightness tracks exposure, not the colour of the light: an underexposed frame
has a dark but perfectly usable sclera, and what we want out of it is a
chromaticity.

The order matters. **Each eye is judged on its own first** — enough pixels,
tight enough in a\*b\* to be one surface, above the sanity floor, lighter than
its own iris, and neither too far below nor as coloured as the skin of the same
face — and only the eyes that survive that are compared with each other. One bad mask therefore does not take a good eye down
with it: the survivor is used and the estimate is flagged `single_eye`, with no
inter-eye check to fail.

| Reason | Per | Test |
| --- | --- | --- |
| `eye_too_small` | eye | The iris was below `min_iris_diameter_px` in the original frame, so the zone was never built |
| `too_few_pixels` | eye | The mask was below the floor, **or** selection inside it left too little |
| `too_noisy` | eye | The sample is too wide in a\*b\* to be one surface |
| `too_dark` | eye | A sanity floor far below any plausible sclera. Catches a mask that landed on a lash line |
| `hair_occluded` | hair | Accessories cover more than `max_accessory_over_head` of the head. Headphones, a hat, a band |
| `not_brighter_than_iris` | eye | Not `sclera_over_iris_min_l` above the iris of the **same eye**. The strong test, and the only one entirely internal to the eye |
| `darker_than_skin` | eye | Too far below the skin. With the eye's own iris measured, "too far" is stated as shade — the sclera may read down to `sclera_socket_light_ratio` of the skin's luminance, never stricter than `sclera_under_skin_max_l`. Without an iris, the constant alone. Catches lid shadow without demanding that the sclera out-read fair skin |
| `more_chromatic_than_skin` | eye | C\* above `sclera_chroma_ratio` of the skin's (floored at `sclera_chroma_floor`). What catches a sample that landed on lid skin |
| `eyes_disagree` | pair | Two eyes that each passed their own checks sit more than `sclera_eye_agreement_max_dab` apart in a\*b\*. **Both are dropped** |

A constant tolerance in L\* is not a constant amount of shade. `L* + 16` goes as
the cube root of luminance, so a flat 6 L\* is a shadow of 0.67 under skin at
L\* 32 and one of 0.83 under skin at L\* 85 — the test was at its strictest
exactly where the eye socket sits furthest below the cheek, and a frame with
skin at L\* 84.8 had both eyes refused as lid shadow while each sclera cleared
its own iris (L\* 32.7, L\* 38.2) by forty L\*. Stating the allowance as a ratio
and converting it makes one claim about anatomy instead of a different implied
claim at every skin tone. It is loosened only where the iris test is there to
carry the weight — with no iris measured the constant is the whole of the
evidence outside the eye and stays as it was.

Every skin tone is loosened, deep skin included: at 0.55 the floor moves from
L\* 26.0 to L\* 23.3 on skin at L\* 32, and from L\* 78.8 to L\* 66.6 on the
frame above. `sclera_under_skin_max_l` survives as a floor that in practice
binds only below about L\* 17. What does not move on deep skin is the verdicts:
lid shadow there is caught by the iris test and the chroma test, and neither
changed.

Every per-eye reason above is reached **before anything is pooled**, and
`samples.sclera` — which is the contract — is built from the pixels the passing
eyes kept, never by measuring the union of the two masks again. Measuring the
union would put pixels from an eye we had just called unusable into the number a
consumer reads, and re-admit everything each eye's own filtering threw out. One
passing eye means `samples.sclera` *is* that eye's sample; none means the zone is
reported absent with the reason.

The iris has no per-eye checks beyond geometry — the generic percentile trim is
all it gets — so there is nothing for it to exclude that `zones` has not already
excluded. If it ever grows one, it needs the same treatment.

#### Measured, and measured *with*

Two different questions, and the contract answers them in two places:

* **`samples`** — what was measured. A zone is absent here only when nothing was
  measured: no mask, or too little left inside it. A zone that produced numbers
  keeps them **whatever the verdict on it** — a blown cheek, a sclera that read
  as lid shadow, an eye at the ceiling.
* **`zones`** — what may be measured *with*. This is where a rejection lives.

They used to be cleared together, and the cost was not cosmetic. `darker_than_skin`
does not say *how much* darker: with the numbers gone, a stored profile could not
tell a threshold that missed by 1 L\* from one that missed by 30, and there is no
reviewing a rule nobody can see the margin on. Every saved profile is now a case
the current thresholds can be replayed against — see
[Replaying the rules](#replaying-the-rules).

Nothing downstream changed. The aggregates and the classifier read the verdict,
never the presence of numbers: `samples.skin` is still pooled from the sub-zones
that passed, `samples.iris` and `samples.sclera` still from the eyes that did.

`eyes_disagree` is the strong test and is deliberately unforgiving. Both eyes
see the same light; if two masks that each look fine on their own disagree about
its colour, one of them is not on sclera, and nothing in a single frame says
which. Averaging them would be picking a number off two readings knowing one is
wrong.

The same reasoning reshaped **pixel selection** inside the zone. Lightness does
the separating — the sclera is the brightest thing in the eye opening under
every illuminant — and colour is used only as a *distance* from the bright set's
own a\*b\*. An absolute chroma cap cannot work: under a tungsten bulb a healthy
sclera measures C\* 60 while the dark lid beside it measures C\* 25, because
CIELAB compresses chroma at low lightness. A cap tuned by a window discards the
whole zone under a bulb — exactly when the cast is strongest.

#### Replaying the rules

`rules.py` re-decides a stored measurement against the thresholds as they stand
now. It is pure: numbers in, verdicts out, no photograph and no file. It exists
because every threshold here is marked PRELIMINARY, and the only honest way to
move one is to see what it would have done to the faces already collected —
re-shooting them is not an option, because the photographs go on a 60-hour clock.

```python
from color_engine import EngineConfig, replay_rules

for zone, replay in replay_rules(stored_samples, EngineConfig()).items():
    print(zone, replay.usable, replay.decided_by, [c.margin for c in replay.checks])
```

Per zone it answers three things: would it pass now, which check decided it, and
by how much — the margin is the number the exercise is for, because "rejected"
is not reviewable while "rejected by 0.4" and "rejected by 18" are different
problems.

**What it cannot answer, and says so.** A rule that read the *pixels* cannot be
re-run at all: `too_few_pixels`, `eye_too_small`, `too_noisy`, and the
clipped-fraction half of `overexposed`. A zone caught only by one of those
replays as clean, so every replay carries `blind_spots` naming which of them it
is blind to. It never claims to reproduce `zones`; it re-decides the part of
`zones` that is arithmetic.

It is served over HTTP as pose-service's `POST /color-rule-check` — which needs
this package but none of its models — and driven from the main app by
`php artisan color:rule-check`.

### The sclera is not white

A healthy sclera is slightly warm. Correcting it to exact neutral would push
every photo cool by that amount — a *systematic* error on the one axis this
product is sold on. So the correction takes the sclera to
`sclera_target_ab`, not to (0, 0).

That value is **(0, 0) today and is expected to change.** The stability report
prints the median a\*b\* of the sclera across the set's `daylight` frames, and
that measurement is what the target becomes. Until then every corrected result
carries a systematic cool bias the size of the real sclera's warmth, and says so
— see [Before this ships](#before-this-ships).

### Before this ships

**The engine does not go to production until `sclera_target_ab` has been
measured on the reference set.** Correcting the sclera to exact neutral, which a
healthy sclera is not, biases every photo cool by the same amount — and a
systematic error on the warm/cool axis is a systematic error on the seasonal
call, which is the entire product.

Two things make that state visible and reversible:

| | |
| --- | --- |
| `white_balance.target_calibrated` | In the contract, on **every** result including the ones that corrected nothing. `false` says the target is still a guess and the result is provisional |
| `COLOR_ENGINE_WHITE_BALANCE_ENABLED` | Master switch, **`false` by default**. Off reports every sample exactly as measured and lowers the confidence — no correction rather than an uncalibrated one. A caller who has not thought about calibration gets an honest answer instead of a confident wrong one |

`COLOR_ENGINE_SCLERA_TARGET_CALIBRATED` defaults to `false` and is what fills
that contract field. Set it to `true` **in the same change** that sets a
measured `sclera_target_ab`, never before.

Working on the engine means turning the switch on explicitly:

```bash
export COLOR_ENGINE_WHITE_BALANCE_ENABLED=true   # development only
```

The test suite does the same through its `config` fixture — a suite that left it
off would be testing the switch rather than the step.

`color-engine report --by-person` switches it on **for itself**, whatever the
environment says: that mode is an instrument rather than a window, it exists to
measure what the correction does to a set, and a before/after with nothing in
the "after" column measures nothing. The HTML says so where it shows those
numbers — what they show is what the correction *would* do, not what a
deployment with the switch off returns.

A plain `report` does **not**. It runs the configuration it was given, so what
the HTML shows is what a caller would actually get back; switching the
correction on behind their back would make the report a picture of a different
engine.

### When the scene is not usable

* **too few pixels** once clipped and shadowed ones are dropped. A blown window
  is a picture of the sensor's ceiling, not of the light; a dark corner is a
  picture of the noise floor;
* **one flat coloured surface.** A plain wall gives a confident estimate of *the
  wall's* colour, and from a single frame there is no way to tell a white wall
  under an orange lamp from an orange wall under a white one. Defined as an
  a\*b\* spread below `scene_uniform_ab_spread` **and** a median chroma above
  `scene_uniform_max_chroma` — a plain *neutral* wall is the good case and is
  kept; it is only the combination that is ambiguous.

### CAT16, and Δu'v'

The adaptation is **CAT16**, not CAT02. CAT16 is the CIE's own correction of
CAT02 (Li et al. 2017, carried into CIECAM16), and what it fixes — negative cone
responses and the instability that follows for saturated colours and extreme
illuminants — is exactly the regime this code hands it, because the photos that
need white balance most are the ones shot under a tungsten bulb.

`correction_strength` is the Euclidean distance between the estimated illuminant
and D65 in **CIE 1976 u'v'**, not a temperature difference. CCT collapses the
green–magenta axis — a fluorescent tube and a daylight lamp can share a CCT and
look nothing alike — and kelvin are not perceptually even, so "500 K" means one
thing at 3000 K and another at 8000 K. u'v' is near-uniform, is a plain distance
in both directions at once, and is what LED binning is specified in.

| Illuminant | Δu'v' from D65 |
| --- | --- |
| D75 | 0.011 |
| D50 | 0.023 |
| FL2 (cool white fluorescent) | 0.039 |
| FL11 | 0.043 |
| A (tungsten, 2856 K) | 0.081 |
| A coloured wash at xy (0.55, 0.40) | 0.148 |

`max_correction_duv` is **0.10**: above a tungsten bulb, which is a room lamp we
do want to correct, and below a sodium lamp or a stage light, which cannot be
corrected honestly because whole regions of the spectrum are simply not in the
frame. Those are rejected as `color_cast` instead.

### A suspicion is not a measurement

When there is **no** estimate at all, a consensus heuristic asks whether every
zone with a colour — sclera, skin, background — is strongly coloured and
pointing the same way. That reading **never refuses a photo.** It sets
`suspicion: cast_suspected` in the debug artifacts and multiplies the confidence
by `confidence_factor_cast_suspected`, and that is all.

The reason is specific: a warm-toned person in front of a beige wall reads
exactly like a warm room, and a refusal here would fall on warm colour types
systematically. That is a bias baked into the product, not a quality gate.

`color_cast` stays a refusal only where there is an actual illuminant estimate
and it sits further than `max_correction_duv` from D65.

`estimated_cct` is McCamy's approximation, reported only when it lands in a
plausible 1000–25000 K. It is a description, never the gate.

### What this does not do

* It corrects **the samples**, not the image. Adapting six median XYZ values is
  the same von Kries transform as adapting several million pixels and costs
  nothing. The pixels are touched only for the report's before/after preview,
  and only when `debug=True`.
* A **coloured wall and a coloured light are the same photograph.** Where the
  scene is the only reference and it is one flat chromatic surface, this code
  declines to guess.
* The thresholds throughout are **preliminary**, set from first principles and
  from synthetic casts. They are expected to move once the reference set exists;
  nothing in the code depends on a particular number.

## Quality gate

| Reason | State |
| --- | --- |
| `insufficient_skin` | No skin measurement, nothing to analyse |
| `color_cast` | Only from a *measured* illuminant too far off to undo — never from the consensus suspicion. See [A suspicion is not a measurement](#a-suspicion-is-not-a-measurement) |
| `low_light` | See below |
| `makeup_filter` | **Deliberately never** |

### `low_light`: a dark photo, or a dark-skinned person?

Deep skin under good light measures exactly like light skin in the dark.
Rejecting the first as `low_light` would refuse someone's photo because of their
complexion, which is not a bug this ships with.

So a dark reading only *raises* the question — median skin L\* under
`low_light_skin_l`, or more than `low_light_max_shadow_fraction` of the skin
mask in shadow, or cheeks noisier than `low_light_max_skin_l_iqr`. The answer
comes from somewhere the person's complexion cannot reach:

1. **the sclera.** Its reflectance barely varies between people, so a sclera
   above `low_light_sclera_l_ok` means the light was there, whatever the skin
   measures. This is the better answer and is consulted first;
2. **the scene** behind them, above `low_light_scene_l_ok`. Weaker — a bright
   wall and a dark room are a plausible pair — but unrelated to the person;
3. **neither.** The photo is kept and the confidence multiplied by
   `confidence_factor_exposure_unknown`. Refusing on a signal we cannot
   interpret is the one outcome that is certainly wrong.

Only when the skin reads dark *and* the rest of the frame reads dark too is the
photo refused. The report prints every one of those numbers next to the verdict:
a rejection the reviewer cannot audit is a rejection nobody can fix.

`makeup_filter` stays in the enum as a contract value and is not implemented.
There is no reliable way to detect a beauty filter or heavy foundation from one
frame: every heuristic that flags smoothed skin also flags good light, a soft
lens and young skin, and the cost of a false positive is refusing a paying
customer's photo for something she did not do. The defence at this stage is the
instruction to the user and the question in the quiz.

## Debug images as an API

The overlay, the zone close-ups and the white-balance before/after are not the
report's private business — `pose-service` serves them over HTTP, and reaching
into `report.builder` for them would have made a private module part of two
contracts at once.

```python
from color_engine import Engine, EngineConfig, render_debug_images

with Engine(EngineConfig()) as engine:
    result = engine.analyze(photo_bytes, debug=True)

images = render_debug_images(result, result.debug)  # {name: PNG bytes}
```

| Name | What it is |
| --- | --- |
| `overlay` | The measured frame with every zone mask and polygon drawn on it |
| `crop_left_eye`, `crop_right_eye` | Zoomed windows on each eye — where a sclera mask defect is actually visible |
| `crop_left_cheek`, `crop_right_cheek`, `crop_forehead` | The same for the skin sub-zones |
| `wb_before_after` | The frame as measured beside the frame adapted to D65, in one image |

Left and right are the **subject's**, as everywhere else here.

A name is **absent** when the photo did not produce it: a photo rejected before
the zones were built has no overlay and no crops, and one white balance did not
correct has no before/after. Never assume a key is there.

PNG, not JPEG: these pictures exist to show exactly which pixels a mask covered,
and JPEG's ringing around a hard mask edge is the artefact that would be mistaken
for a mask defect. `debug_images()` returns the same views as arrays for callers
that want to encode them themselves — the HTML report uses that, sizing them down
to JPEG to keep one self-contained file to a sane weight. One rendering path, two
encodings.

### `source_preview`

The uploaded photo, decoded — and the one view that exists for a browser rather
than for a reviewer. A phone sends HEIC and no browser renders it, so a page
serving the stored file shows a broken image where the photo should be.

The decode happens here because here it has *already* happened: `source_preview`
is the frame after EXIF rotation and the ICC conversion to sRGB, which is to say
the pixels the engine measured. A preview generated anywhere else would be a
thumbnail of the file, and a preview that disagreed with the measurement would
be worse than none — it is the thing a reviewer checks the numbers against.

It is present whenever the photo decoded at all, including on every refusal. A
refused frame is exactly when somebody needs to see what was sent.

## Landmark indices

The index sets in `zones.py` are a **first pass** and are expected to need
correction. They are documented inline, drawn into the HTML report, and nothing
downstream assumes they are exact — each skin polygon is the convex hull of its
landmark set, shrunk toward its centroid, then intersected with the face-skin
class and run through outlier rejection.

Left/right follow **MediaPipe's own naming**, which is subject-relative: the
"left eye" is the person's left eye and appears on the right side of a frontal
photo.

To check them, run `color-engine report` over a folder of faces and look at the
overlay. Confidence at this point:

The report prints, per zone, the constant name, the exact indices, the point
count and the resulting mask area as a share of the face box. Run it with
`--landmark-labels zones` to see those numbers drawn on the face itself, or
`--landmark-labels all` to hunt for a better point than the one in use, then
edit the constant in `zones.py`.

| Set | Confidence | Note |
| --- | --- | --- |
| Iris (468–472, 473–477) | high | Fixed, documented MediaPipe indices; the annulus lands on the iris |
| Iris annulus | medium | Reshaped after the first live frame — see [The iris](#the-iris). Ratios unverified against a real set |
| Eye opening | medium-high | `FACEMESH_*_EYE`, the lid *margins* — deliberately not the socket ring one row out, which runs along the crease |
| Forehead | medium | Lands between brows and hairline; the hairline edge leans on the face-skin intersection |
| Cheeks | medium | Moved onto the apple of the cheek; unverified against a real set |

Two zones were reshaped after stage 1:

* **Sclera.** The mask was measuring the lash line. It now keeps only the wedges
  *beside* the iris, measured along the eye's own axis — on an open eye the iris
  is nearly as tall as the fissure, so the bands above and below it are lid, not
  sclera. Pixel selection is its own rule, not the generic percentile trim
  (which drops the top 10% of L\* — precisely the signal here): lightness picks
  the bright set, catchlights come out, and colour is used only as a distance
  from that set's own a\*b\*. See [White balance](#white-balance).
* **Cheeks.** They reached into the hollow under the zygomatic arch, which is
  concave and therefore shaded. Swapping 123 for 119 (mirror 352 → 348) moved
  them onto the cheekbone.

**No before/after numbers are quoted for either.** The only face this repository
ever had is a catalogue shot whose head is a few dozen pixels wide; the readings
taken from it came from an upscaled crop, and [A1](#why-there-are-no-numbers-here)
showed the sclera median moves with the upscale factor alone. Those numbers
measured the crop, not the zone. The reference set is where the real ones come
from.

Sclera quality (pixels before/after, share dropped, catchlight pixels, median
L\*/C\*, base chroma, a\*b\* spread and a verdict with its reason) is reported
per photo, per eye. It is **not** in the contract — `LabSample` does not move —
it lives in the debug artifacts and the HTML, and `white_balance` consults it
before using the sclera as a white reference.

### Why there are no numbers here

Nothing in this repository is an eligible photo. `public/dev-catalog/` holds
full-body catalogue shots — the face fails `min_face_frame_ratio`, and the
directory is gitignored, so those files are not versioned and not reproducible
anyway.

Measurements taken from them were taken from a **crop, upscaled** to fill a
frame. That is not the same photograph: an upscale invents pixels, and the same
source crop upscaled to two different sizes gave sclera medians that differed
noticeably — from the resampling alone, with nothing else changed. A number like
that measures the resampler.

So: no cropping mode in the CLI, no numbers from crops in this document, and the
only reference is the set at `COLOR_ENGINE_FIXTURES_DIR`, shot as described under
[Shooting the calibration set](#shooting-the-calibration-set) with the face
filling the frame at native resolution.

## What is a stub

| Module | State | What lands there |
| --- | --- | --- |
| `garment.py` | raises | Measure a product photo on the same scale and match it to the palette |

The contract in `models.py` already declares every field these will fill; they
serialise as `null` until then. `docs/contract.schema.json` is generated from the
models and a test fails if the two drift apart.

## The classifier

> **Nothing in this section has been validated against real faces.** Read it as
> a description of what the code does, never as a claim that it is right. The
> numbers were chosen so that the *shape* of the thing is arguable; somebody has
> to shoot a labelled set before any of them mean anything. `classifier.draft`
> in the contract is `true` and stays `true` until that happens.

### Everything that decides a season is in a file

`src/color_engine/data/classifier.json`. Anchor points, weights, prototypes, the
cost of a missing zone, the confidence curve — all of it. `features.py` and
`classify.py` contain arithmetic and nothing else.

The split is not cosmetic. It falls here:

* **Data** says how much a feature counts and where its anchors sit. Re-weighting
  an axis, moving a prototype, making dyed hair cheaper — a JSON diff a stylist
  can read and argue with.
* **Code** says how to get a number off a face. A feature nobody has measured
  before — the hue of the lips, say — needs an extractor in `features.py`,
  because no JSON file can invent a measurement.

Changing the data means changing `version` with it. `tests/test_classifier.py`
keeps a version → SHA-256 map and fails when the file moves under a version that
did not, so an edit that changes what everybody's season is cannot ship stamped
`draft-1`. The hash is over the bytes rather than the parsed object, because
reformatting is also a change somebody should have to look at — with line
endings normalised first, since this repo stores LF and checks out through
`core.autocrlf`, and CRLF-versus-LF is a fact about the checkout rather than an
edit anybody made.

The classifier runs on whatever samples the engine decided to publish —
corrected when white balance was applied, as measured when it was not. With
correction off, every hue angle still carries the colour of the room, so the
result is provisional in a second, larger way.

### The three axes

Each is a weighted mean of features, each feature mapped through its own anchor
points — a triple saying which raw value means −1, which means 0 and which means
+1, piecewise-linear between them and **clamped** at both ends. Clamped, because
hair at L\* 8 is not more than as deep as hair at L\* 20, and an unclamped
feature would drown the others in the mean.

| Axis | Features, heaviest first | Why in that order |
| --- | --- | --- |
| `temperature` −1 cool … +1 warm | skin h° (0.45), skin b\* (0.25), hair b\* (0.15), iris b\* (0.15), **each scaled by its own zone's chroma** | Skin is the largest, highest-chroma zone on a face. Its hue angle carries the undertone: rosy puts a\* ahead of b\* and pulls the angle down, golden pushes it up. Skin b\* corroborates rather than adds — the same evidence read a second way, hence half the weight. Hair and iris use b\* and not hue: dark hair is near-neutral, and a hue angle off a near-neutral a\*b\* is the direction of the noise. |
| `depth` −1 light … +1 deep | hair L\* (0.5), skin L\* (0.3), iris L\* (0.2) | Hair is the widest range of lightness a person has, platinum to black; skin spans far less. Skin is also the reading most easily moved by exposure, which the quality gate can bound but not remove. |
| `chroma` −1 soft … +1 bright | contrast (0.4), iris C\* (0.35), skin C\* (0.25) | "Bright" in this system is as much about the distance between a person's own colours as about saturation — a soft type is one whose hair, skin and eyes sit close together. The eye is the most saturated thing on most faces; skin chroma varies less between people, so it moves the axis less. |

**Weights are scaled by how much colour the zone actually has.** Hue is an
angle, and the angle of a near-neutral sample is the direction of its noise, so
a fixed weight treats a guess and a measurement as equals. Each temperature
feature's weight is multiplied by a curve on its own zone's C\* (0.3 at C\* 6,
rising to 1.0 at C\* 20) and the survivors are renormalised. On the first
classified frame that moved hair from 15% of the axis to 23%, because the skin
came back at C\* 9.9 and natural red hair at C\* 23.8 — and the hair was much
the better evidence.

One curve for every zone, so nothing here knows what a redhead is: the same
arithmetic demotes washed-out hair on a face whose skin is strongly coloured.
The floor is 0.3 rather than 0, because a near-neutral zone still says something
with the sign of its b\*, and a hard zero would make the axis jump as C\*
crossed a threshold. Dyed hair is still excluded outright — reliability is about
how much colour a zone has, and dye is about whether the colour is the person's.

`contrast` is its own 0..1 figure: ΔL\* hair↔skin (0.65) and ΔL\* iris↔sclera
(0.35), each through its own anchors. Hair against skin is the contrast a
stylist reads first and is the whole of what "high contrast" usually means; iris
against the white of the same eye is what separates a dark-eyed light blonde
from a light-eyed one, and it is absent whenever the sclera could not be
measured, so it carries the lesser weight. The contract also reports all three
raw L\* distances beside the normalised value, so the number can be checked
against the samples table by eye.

### When a zone is missing

A face routinely arrives with no usable sclera, or with hair that has been dyed.
Treating the absent zone as average would produce a confident answer built on a
zone nobody measured. Instead:

| Situation | What drops out | Confidence × |
| --- | --- | --- |
| `natural_hair: false` | hair from `temperature`, `depth` **and** `contrast` | 0.75 |
| hair not measured | the same, for a different reason | 0.80 |
| iris not measured | iris from all three axes and from `contrast` | 0.85 |
| sclera not measured | the iris↔sclera contrast only | 0.95 |
| skin too neutral (C\* < 3) | `skin_hue` only — the rest of skin still counts | — |

The surviving weights renormalise, so dropping hair from `depth` does not drag
depth towards zero: it hands hair's half to skin and iris in the proportion they
already stood in. Every exclusion is recorded with its reason and reaches the
debug artifacts. A missing zone is charged **once**, not once per axis that
happened to mention it — otherwise the penalty would measure the shape of the
data file rather than the photograph.

Dyed hair is excluded from `contrast` as well as from the two axes, which is a
judgement call and worth disagreeing with: dyed dark hair really does contrast
with the skin in the photograph. It is excluded because the axes describe the
person, and keeping the dye in one third of the classifier while removing it
from the other two would mean a third of the answer believes the colourist.

An axis with nothing left is `null`, never `0`. Zero is a claim — "perfectly
neutral". Null is the truth.

### The quiz is a nudge

`metal`, `veins` and `tanning` move `temperature` and nothing else. The answered
questions are averaged, scaled by `max_shift` (0.25), and the result **cannot
cross zero**: a cool face with three warm answers lands at 0 at the very most.
Somebody who has been told for years that they are "a winter" answers like a
winter, so a quiz that could outvote the photograph would be measuring the
belief instead of the face.

### The 12 prototypes

Each season is one point in the (temperature, depth, chroma) cube: **1.0 on its
leading axis, 0.5 on its secondary, 0 on the third**. That is what the system's
own vocabulary says they are — a light spring is light first and warm second, a
deep winter is deep first and cool second.

| | leading | secondary |
| --- | --- | --- |
| `light_spring` / `light_summer` | depth −1 | temperature +1 / −1 |
| `deep_autumn` / `deep_winter` | depth +1 | temperature +1 / −1 |
| `true_spring` / `true_autumn` | temperature +1 | chroma +1 / −1 |
| `true_winter` / `true_summer` | temperature −1 | chroma +1 / −1 |
| `bright_spring` / `bright_winter` | chroma +1 | temperature +1 / −1 |
| `soft_autumn` / `soft_summer` | chroma −1 | temperature +1 / −1 |

Distance to a prototype is weighted RMS, each season weighting the axes by what
it is named for: leading 1.0, secondary 0.6, third 0.2. The third is nearly
ignored but not quite — at exactly zero, two prototypes on the same plane would
tie to the last decimal, and a tie is the one answer that helps nobody. Only the
axes that exist are summed, and the divisor is the weight actually used, which
is what lets a face with no usable depth still be ranked: the seasons that
differ only in depth come out exactly level, and the margin reports that as low
confidence, correctly.

Distances become scores through a softmax at temperature 0.28, so all 12 scores
sum to 1. Softmax rather than "closest wins" because a face sitting exactly
between two neighbours *should* come back as a near-tie between them — that is
what it is, and the tie is what makes the confidence come out low. The top two
reach the contract; all 12 reach the debug artifacts.

### Confidence

```
confidence = f(top1 − top2) × (missing-zone penalties) × (photo quality)
```

`f` is piecewise-linear through `(0, 0.15) (0.05, 0.35) (0.15, 0.7) (0.35, 1.0)`.
A dead heat is 0.15 and not 0: the season is still the best available reading,
and the flag is there to say how much of one it is. The photo-quality term is
everything the earlier stages already concluded — white balance, exposure, even
lighting — arriving as one number, because the alternative is four modules each
holding a piece of the definition of confidence.

Below `min_confidence_for_season` (0.4) the season is **still returned**, with
`classifier.low_confidence: true` beside it. Low confidence is a label on the
answer, not a refusal to give one.

The whole multiplication is written into the debug artifacts, so a confidence of
0.31 can always be taken apart into the four things that made it 0.31.

### Preliminary numbers

Every threshold in `data/classifier.json` is preliminary, and the file says so in
its own `notes`. Each carries a `why` in the file itself, because a threshold
whose reasoning was never written down is a threshold nobody can revise.

## Draft palettes

> **Nobody chose these colours.** They are *generated* from the season
> prototypes, not picked by somebody who does this for a living, and not copied
> from anybody else's palette. They exist so the page has something real to
> render and so a stylist has something concrete to disagree with. The file says
> `draft: true` and every consumer must repeat it.

```bash
color-engine palettes draft --out ../../database/seeders/data/season_palettes.json
```

Four groups per season, six colours each: `neutrals`, `base` and `accents` are
recommended, `avoid` is not. The rules are in `src/color_engine/data/palette.json`
and, as with the classifier, changing them means changing `generator_version`;
a test holds the hash.

### How a colour is built

Everything happens in LCh, from the same prototype point the classifier scores
against:

* **temperature chooses which hues the season may have at all.** Not a rotation.
  Turning six evenly spaced hues by 25° does not move a golden ochre out of a
  winter palette — it moves it to a slightly different ochre, and `deep_winter`
  came out holding `#A5896B` and `#A87B45`. Each temperature has a set of
  allowed arcs, and the six hues are spread evenly along whatever is left. A
  warm season has no blue-violet to pick; a cool season has no golden yellow.

  | | may draw from | may not |
  | --- | --- | --- |
  | warm | 15–95 warm red through golden yellow, 125–160 warm green, 175–212 teal, 304–334 red-violet (plum, aubergine) | 255–300 blue-violet, 340–15 cool raspberry |
  | cool | 98–130 lemon and yellow-green, 138–178 cool green, 185–240 teal, 255–330 blue through violet, 333–372 raspberry and fuchsia | 40–95 orange through golden yellow |
  | cool **and deep** | the cool arcs with lemon and yellow-green removed | also 95–135 |

  The last row is there because a hue band is not warm or cool on its own.
  Lemon reads cool while it is light; take it down to the L\* a deep season sits
  at and it is olive, which is warm. Only the cool stance gets that rule — olive
  and moss are what a deep autumn is *for*.

  A season whose **leading** axis is temperature gets the strict bands; where
  temperature is only secondary they open a few degrees at each edge, because
  the season is named for its depth or its clarity and the warm/cool call is a
  modifier rather than the point.
* **depth moves L\*.** Centre 58, ±22: a fully light season centres at 80, a
  fully deep one at 36.
* **chroma moves C\*.** Centre 38, ±20: bright centres at 58, soft at 18. The
  group then scales it — neutrals 0.18, base 0.55, accents 1.0.

Lightness also follows hue within a group, peaking at yellow and troughing at
blue. That is not decoration: sRGB has no dark saturated yellow and no light
saturated violet, so a flat L\* across the circle would send the gamut step to
work on half the palette.

The lightness excursion is **narrowed to fit** rather than clamped. Clamping
collapsed a light season's two lightest colours into the same pure white, and a
group of six that is really a group of five is a bug. Narrowing keeps all six
distinct, and a light palette that is narrow in lightness is not a defect —
that is what a light season is.

### sRGB gamut: compressed, not dropped

A colour outside sRGB has its **chroma compressed** until it fits, keeping L\*
and hue. The alternative — dropping it — was rejected for two reasons: it would
leave holes at exactly the hues where a season is most saturated, so a bright
winter would lose its blue; and it would make the group sizes depend on the
season. A compressed colour is still the right hue at the right lightness, which
is what a garment is matched on. Hue is never moved: a colour that shifted hue to
stay in gamut would be a different colour wearing the right name.

The largest in-gamut fraction is found by bisection with a fixed step count,
not by a loop that stops when it is close enough. Every compressed colour
carries `"compressed": true`, so the page can say which numbers the monitor
could not keep.

About 44 of the 288 colours are compressed, nearly all of them in the light and
bright seasons, where the request runs past what sRGB has.

### The LAB beside a colour is that colour

Stored from the rounded 8-bit value rather than from the request, so the hex and
the numbers next to it on the page are the same colour. Storing the pre-rounding
LAB would be storing a colour nobody can display.

### `avoid`

The `accents` group of the prototype with its **leading axis flipped, and its
temperature with it**. Not all three: the diametrically opposite season is a
different claim, and what a deep winter should avoid is light colours, not light
*warm* ones. Temperature as well, because temperature now decides which hues a
season may have at all — an avoid group drawn from the same bands would be the
right colours at the wrong lightness, which is not what drains a face. Every
season here has temperature as its leading or secondary characteristic, so there
is always one to flip; when temperature *is* the leading axis the two flips are
the same one.

Identical parameters on purpose. The group showing what not to wear should
differ from the recommended one in exactly one thing, the axis the season is
named for, or it is showing something else.

One consequence worth knowing: a season's `avoid` group is often another
season's `accents`, exactly. That is the construction being honest rather than a
collision.

### Deterministic

The same rules always write the same bytes — no randomness, fixed-step
bisection, seasons sorted into a stable order, LF line endings and a trailing
newline whatever the platform. The output is committed and seeded, and a
regeneration that drifted by one bit would be a diff nobody could review.

## Tests

```bash
pytest
ruff check . && ruff format --check .
mypy
```

Two kinds of test:

* **Always run.** Colour maths, image decoding, zone geometry, the contract, the
  fixtures loader. No photos and no models needed — the ICC test builds a real
  Display P3 profile in memory (`tests/support/icc.py`) and checks the converted
  pixel against an independent `colour-science` computation.
* **Marked and skipped otherwise.** `requires_models` needs
  `scripts/fetch_models.py` to have run; `requires_fixtures` needs
  `COLOR_ENGINE_FIXTURES_DIR`.

### Synthetic casts

`tests/test_synthetic_cast.py` casts a known light over known colours and asks
for them back. Two levels, proving different things.

**On samples**, always run. A plausible skin and sclera in CIELAB, adapted to
each illuminant, handed to the estimator. The skin comes back where it started:

| Illuminant | Δu'v' from D65 | Skin ΔE2000 before | after |
| --- | --- | --- | --- |
| D75 | 0.011 | 3.40 | 0.000 |
| D50 | 0.023 | 5.95 | 0.000 |
| FL2 | 0.039 | 9.02 | 0.000 |
| FL11 | 0.043 | 9.61 | 0.000 |
| A (tungsten) | 0.081 | 15.60 | 0.000 |
| Wash at xy (0.55, 0.40) | 0.148 | — | rejected `color_cast` |

Those zeroes are **not** an accuracy result. The cast is applied with the same
transform the estimator inverts, so this is a closed loop: it proves the
algebra — that the von Kries reasoning, the CAT16 matrices and the target
chromaticity compose into an identity — and nothing else.

**On images**, with `COLOR_ENGINE_FIXTURES_DIR` set. The set's real `daylight`
frames, re-lit in linear RGB and put through the whole pipeline, skin compared
to the original before and after. This exercises detection, zones, sclera
selection and the scene fallback together on real pixels. The test asserts the
direction only — a correction that left the frames further from where they
started than the cast put them would mean the estimate is wrong — and prints
each frame's numbers. There is nothing to quote here yet: the only face this
repository has ever had is an upscaled crop of a catalogue shot, and a number
measured on that measures the resampler — see
[Why there are no numbers here](#why-there-are-no-numbers-here).

> **A synthetic cast is not real light.** Laid over an already processed JPEG it
> is not what a bulb does to a sensor: the camera's own auto white balance has
> already moved the image, the tone curve has already clipped something, and a
> real room mixes two illuminants and bounces both off coloured walls. Passing
> these tests says the code does what it says. It does **not** say the estimate
> survives a real photograph. Only the reference set can say that.

There are **no ΔE thresholds** in the stability test. It checks the report
builds and the numbers serialise; what counts as good drift is a question for
the real set, not for a number invented here.

### The reference set

Real faces are never committed here. The stylist's reference photos live outside
the repository at `COLOR_ENGINE_FIXTURES_DIR`, alongside a `labels.json`:

```json
{
  "p001_daylight.jpg": {
    "person_id": "p001",
    "lighting": "daylight",
    "season": "deep_winter",
    "season_alt": "true_winter",
    "undertone": "cool",
    "depth": "deep",
    "chroma": "bright",
    "natural_hair": true,
    "makeup": "none",
    "stylist_confidence": "high",
    "notes": ""
  }
}
```

| Field | Values | |
| --- | --- | --- |
| `person_id` | any string | **required** — one person, several lightings |
| `lighting` | `daylight` · `indoor_warm` · `indoor_cool` · `flash` · `mixed` | |
| `season`, `season_alt` | one of the 12 slugs | optional |
| `undertone` | `warm` · `cool` · `neutral` | optional |
| `depth` | `light` · `medium` · `deep` | optional |
| `chroma` | `bright` · `soft` | optional |
| `natural_hair` | `true` / `false` | optional |
| `makeup` | `none` · `light` · `full` | optional |
| `stylist_confidence` | `high` · `medium` · `low` | optional |
| `notes` | free text | optional |

`season` is deliberately optional: a photo with no verdict still drives the
[stability report](#--by-person-stability-across-lighting), which only asks how
far the measurements drift between conditions.

`color_engine/fixtures.py` loads and validates the file and reports **every**
problem at once, naming each offending entry — the person fixing it is a stylist
editing JSON in a text editor, not a developer reading a traceback.

#### Naming the photos

`labels.json` is **optional**. The two fields `--by-person` actually needs are
already in the filename if the set follows one convention:

```
<person_id>_<lighting>[_<n>].<ext>

p001_daylight.jpg      p001_indoor_warm.heic     p002_flash_2.JPG
```

`<lighting>` is one of the five values in the table above; `<person_id>` is
anything before it; `_<n>` is an optional counter for a second shot under the
same condition. The extension and its case do not matter.

So a set can be shot and measured the same afternoon, before anybody opens a
text editor, and the stylist's verdicts can be added later without renaming a
thing.

| Situation | What happens |
| --- | --- |
| No `labels.json` | Everything is grouped from the filenames |
| Photo is in `labels.json` | The file wins; the filename is only checked against it |
| The two disagree | A **warning** in the report, naming both readings — the file is still used |
| Neither places the photo | It is **not analysed at all** — no person means no drift number to put it in, so it appears only in the skipped list, with the reason, in the report and on stderr |
| `labels.json` is present but malformed | Hard error, as before. Optional means "may be absent", not "may be wrong" |

Both the warnings and the not-grouped list go into `summary.json` under
`labels` and into the HTML, not just onto the terminal: six months later the
report is the only artifact left, and *which photos were not in these numbers*
is the first question it has to answer.

If **every** file in the directory is skipped, `report` writes no HTML and exits
`1` — after printing the same list to the terminal. "No images found" on its own
leaves the person guessing which of their files the tool disagreed with.

### What to ask somebody to do before they take the photo

In daylight, facing a window, no flash. And, because each of these has already
cost a measurement:

* **No headphones, hat, hairband or hood.** The segmenter classes them as
  accessories, the hair mask runs up to their edge, and what it gets wrong there
  is dark and near-neutral — which is to say it looks like hair. One frame
  measured hair at L\* 12.2, C\* 3.0, which was the plastic. Hair carries half
  the depth axis.
* **Hair back off the forehead and cheeks**, so there is skin to measure.
* **No glasses**, which sit across the eye zones.
* **Not in direct sun, and not under a hard lamp.** A blown highlight is not a
  bright colour, it is a missing one — see [Quality gate](#quality-gate).
* **Light from the front, not the side.** Half a face in shadow is refused.
* **No make-up and no beauty filter**, which are a picture of a decision rather
  than of a person.

### Shooting the calibration set

Name the files `<person_id>_<lighting>[_<n>].<ext>` as described under
[Naming the photos](#naming-the-photos) and `--by-person` needs nothing else.

* no make-up, hair pulled back off the forehead, no glasses;
* face straight on, filling most of the frame; the same camera (main or front)
  for every condition, not a mix;
* conditions: by a window in daylight, out of direct sun; a warm room lamp; cool
  white light if available; the phone's flash;
* do **not** edit the files, and do not send them through a messenger — those
  recompress and strip the ICC profile. Originals only;
* written consent from every participant to use their photos for calibration.

## Privacy

This package handles photographs of faces, under UAE PDPL.

* The library **never** writes an image to disk and **never** logs one — not
  bytes, not base64, not a path. Logs carry statuses, step timings, reject
  reasons and numbers.
* Debug artifacts exist only in memory, only when `analyze(debug=True)`.
* The single exception is the CLI `report` command, which is explicit, writes
  into gitignored `reports/`, and says so when it runs.
* No telemetry, no external calls. `scripts/fetch_models.py` is the only code
  here that opens a socket.

## Python and MediaPipe versions

Pinned to match `pose-service`, because the plan is for both to live in one
environment:

| | pose-service | color-engine |
| --- | --- | --- |
| Python | 3.10.0 | `>=3.10,<3.13` |
| mediapipe | `0.10.21` | `0.10.21` |
| numpy | `1.26.4` (`<2` for MediaPipe) | `>=1.26,<2` |
| opencv | `opencv-python-headless==4.10.0.84` | same |
| pillow | `11.1.0` | `11.1.0` |

No conflicts: every shared dependency is on the same pin. pose-service uses the
MediaPipe **Tasks** API (`vision.PoseLandmarker`), not legacy `mp.solutions`, so
this package matches it.

## Known environment risks

**Two OpenCV packages in one venv.** `mediapipe` depends on
`opencv-contrib-python`, so installing this package (or pose-service) pulls in
*both* it and the `opencv-python-headless` we pin. They install into the same
`cv2` namespace and the last one to land wins the import — in practice
`opencv-contrib-python`, the GUI build, which is what `import cv2` resolves to
today.

pose-service already has exactly this pairing in its venv, so nothing here made
it worse. What it means in practice: on a bare Linux server the GUI build wants
`libGL.so.1` (and `libglib`), which a slim image does not carry, and the import
fails with `ImportError: libGL.so.1: cannot open shared object file`. Install
`libgl1` / `libglib2.0-0`, or pin the dependency out, whichever the deployment
prefers. **Not changed here, and pose-service is not to be touched for it** — it
is recorded so it is not a surprise at deploy time.

**MPO and HDR gain maps.** Pillow reports `MPO`, not `JPEG`, for any JPEG
carrying more than one image, which includes ordinary photos from current
phones where the extra frame is an HDR gain map. Rejecting MPO would reject a
plain iPhone shot, so it is accepted and frame 0 — the primary image — is what
gets measured. Seeking to it explicitly also reloads the metadata, so the ICC
profile and EXIF orientation honoured are the primary frame's.
