# Pose Detection Microservice

A small, stateless **CPU-only** FastAPI service for the AI Fitting Room. Given a
photo it returns facts about the people in it: how many, where the main person
is, and their body landmarks. It is a **pure detector** — it makes *no* judgement
about whether a photo is "good enough" for try-on. That policy lives in the
Laravel backend (a later sub-step).

> Sub-step 9.0 of Phase 9. Local development first; server deployment notes are
> at the bottom but not performed yet.

---

## 1. Model choice — MediaPipe Pose Landmarker (Tasks API)

**Chosen: MediaPipe Pose Landmarker** (`pose_landmarker_full`, BlazePose, float16),
running CPU-only via `mediapipe.tasks.python.vision`.

Considered alternatives: classic `mp.solutions.pose`, and MoveNet
(SinglePose/MultiPose Lightning/Thunder).

| Criterion | MediaPipe Pose Landmarker (Tasks) | MoveNet |
|---|---|---|
| Landmarks | **33** incl. ankles, **heels, foot index** | 17 (COCO), ankles but no feet detail |
| Legs / full-height reliability | **Strong** — explicit foot points, good lower-body coverage | Adequate ankles, weaker on occluded legs |
| Multi-person | **Yes** via `num_poses` | Only MultiPose variant; SinglePose can't count |
| CPU speed | **Fast** (designed for mobile/CPU); ~15–60 ms/frame on a desktop core | Lightning fast, Thunder slower |
| Per-landmark confidence | Yes (`visibility`) | Yes (keypoint scores) |

Why it wins for *this* use case:

1. **Legs/feet matter most.** The next sub-steps decide "is the full body
   visible" for smart cropping. The 33-point model gives explicit ankle, heel and
   foot-index landmarks plus better lower-body coverage than MoveNet's 17 points.
2. **It can count people.** The classic `mp.solutions.pose` only ever returns one
   person, which fails the `person_count > 1` requirement. The Tasks API
   `PoseLandmarker` takes `num_poses` (we default to 6), giving both the count and
   the main person's landmarks from one call.
3. **CPU-friendly.** No GPU required; cold model load is ~1 s, inference is tens
   of ms per image on one core.

---

## 2. API contract

### `GET /health`

Liveness + model status. No auth required.

```json
{ "status": "ok", "model_loaded": true, "model": "mediapipe_pose_landmarker_full_v1" }
```

### `POST /pose`

Detect poses in one image.

- **Request: `multipart/form-data`**, one file field named **`image`**.
  - *Why multipart, not base64:* base64 inflates the payload ~33%, forces the
    whole image through the JSON parser, and costs extra memory for encode/decode.
    Multipart is the standard, streaming-friendly transport for binary and is what
    Laravel's HTTP client (`->attach(...)`) sends natively.
- **Auth:** if `POSE_API_TOKEN` is set, send header `X-API-Token: <token>`.
- **Size limit:** rejects bodies larger than `POSE_MAX_UPLOAD_BYTES` (default 15 MiB).

#### Success — `200 OK`

```jsonc
{
  "person_count": 1,                  // 0..N, never an error
  "image_dimensions": { "width": 600, "height": 900 },
  "main_person": {                    // null when person_count == 0
    "bounding_box": { "x": 209.09, "y": 89.54, "width": 163.55, "height": 760.6 },
    "landmarks": [                    // 33 BlazePose points (see names below)
      {
        "name": "nose",
        "x": 298.32, "y": 104.52,     // pixels in the source image
        "x_norm": 0.49719, "y_norm": 0.11613,  // normalized [0,1]
        "z": -0.6092,                 // relative depth (MediaPipe units; neg = closer)
        "confidence": 0.9999          // visibility probability [0,1]
      }
      // ... 32 more
    ]
  },
  "bbox_confidence_threshold": 0.5,   // cutoff used to build bounding boxes
  "model": "mediapipe_pose_landmarker_full_v1"
}
```

- **`main_person`** = the most prominent person, defined as the one with the
  largest **confident** bounding-box area (closest to camera — the natural try-on
  subject).
- **`bounding_box`** is built **only from landmarks whose `confidence >=
  bbox_confidence_threshold`**, then clamped to the image. Points that MediaPipe
  extrapolates off the visible body (e.g. the legs of a waist-up photo, with
  confidence ≈ 0) are therefore *excluded* and cannot inflate the box. (If no
  landmark clears the threshold for a detected person, the box falls back to all
  points so it is never empty.) The threshold is configurable via
  `POSE_BBOX_MIN_CONFIDENCE`.
- **Landmarks** always contains all 33 points; occluded/extrapolated points are
  still returned but with **low `confidence`** (the caller decides what to do with
  them — the service does not drop or judge them).

#### Telling visible body parts from extrapolated ones (for step 9.2)

The service never decides "full height: yes/no". Instead, **`confidence` honestly
reflects whether a point is really seen or guessed**, so the backend can decide:

- *Legs genuinely visible* → ankle/foot `confidence` is **high** (~0.8–0.95) and
  those points sit inside the body region; the `bounding_box` reaches down to the
  feet.
- *Legs off-frame / cropped* → MediaPipe still emits ankle/foot landmarks, but
  with **near-zero `confidence`** (e.g. ~0.01–0.09), and they do **not** affect
  the `bounding_box`.

So a simple, robust rule for "are the legs visible" on the caller side is e.g.
*"`left_ankle` and `right_ankle` confidence both ≥ 0.5"* — no service-side policy
needed.

**Landmark names (index order):**
`nose, left_eye_inner, left_eye, left_eye_outer, right_eye_inner, right_eye,
right_eye_outer, left_ear, right_ear, mouth_left, mouth_right, left_shoulder,
right_shoulder, left_elbow, right_elbow, left_wrist, right_wrist, left_pinky,
right_pinky, left_index, right_index, left_thumb, right_thumb, left_hip,
right_hip, left_knee, right_knee, left_ankle, right_ankle, left_heel, right_heel,
left_foot_index, right_foot_index`.

#### No person — still `200 OK` (not an error)

```json
{ "person_count": 0, "image_dimensions": { "width": 640, "height": 480 },
  "main_person": null, "model": "mediapipe_pose_landmarker_full_v1" }
```

#### Errors

| Status | When | Body |
|---|---|---|
| `400 Bad Request` | empty body, or bytes that can't be decoded as an image | `{ "detail": "Could not decode image data." }` |
| `401 Unauthorized` | auth enabled and token missing/wrong | `{ "detail": "Invalid or missing X-API-Token header." }` |
| `413 Payload Too Large` | upload exceeds the byte limit | `{ "detail": "Image exceeds the N byte limit." }` |
| `422 Unprocessable Entity` | no `image` field in the request | FastAPI validation error |

Interactive docs (Swagger UI) are at `GET /docs` when running.

### `POST /preprocess-image` (Phase 9.5 — input-photo enhancement)

Enhance the **person** photo before try-on: **lighting normalization** (classical
OpenCV — CLAHE + gray-world white balance + auto gamma) and/or **person
segmentation** onto a **solid neutral background** (rembg / U²-Net, CPU). It is
embedded in *this* process rather than a second service so the box runs one Python
process, not two — important on a small GPU-less server where MediaPipe is already
resident.

- **Request: `multipart/form-data`** — file field **`image`** plus form fields:
  - `lighting` — `true|false` (run lighting normalization).
  - `segment` — `true|false` (run segmentation onto the neutral background).
  - `format` — `jpeg|png|webp` output encoding (default `jpeg`).
- **Auth / size limit:** same `X-API-Token` and `POSE_MAX_UPLOAD_BYTES` as `/pose`.
- **Response — `200 OK`:** the enhanced image **bytes** (`image/jpeg|png|webp`),
  **same dimensions as the input** (the backend's crop step reuses pose landmark
  pixel coordinates, so the image is never resized here). Two headers report what
  actually ran: `X-Applied-Lighting` and `X-Applied-Segment` (`true|false`).
- **Best-effort per step:** a step that fails is **skipped** (its header is
  `false`) instead of failing the request, so the backend always gets a usable
  image; its own fail-open policy only has to handle a hard transport/HTTP error.
- The cut-out is composited onto an **opaque** neutral background (never a
  transparent PNG) so the VTON engine receives solid pixels.

Errors mirror `/pose`: `400` undecodable, `401` bad token, `413` too large.

#### Memory (rembg on a small box)

rembg's U²-Net adds a transient ~0.6 GB during inference on top of MediaPipe's
~0.2 GB resident. By default `SEGMENT_KEEP_LOADED=false`, so the model is
**released after every call** and the process returns to the MediaPipe-only level
between generations — the right trade on an 8 GB GPU-less host (detection volume is
low, lead-gated). Measure on the target box with `bench_memory.py` (run it while
watching `free -h`); flip `SEGMENT_KEEP_LOADED=true` only if there is headroom and
the per-call reload latency matters. `SEGMENT_MODEL` can drop to `u2netp` (~5 MB)
if RAM is tight.

---

### `POST /color-analysis` (seasonal colour analysis)

Wraps the [`color-engine`](../services/color-engine/README.md) library, which is
a library and has no HTTP layer of its own. This endpoint is that layer.

```
multipart/form-data
  image  — the photo file, ORIGINAL bytes
  quiz   — JSON object of quiz answers, optional
  debug  — bool, default false
```

```json
{
  "result": { "status": "rejected", "reject_reason": "no_face", "...": "..." },
  "debug_images": { "overlay": "<base64 PNG>", "crop_left_eye": "<base64 PNG>" }
}
```

`result` is the engine's own `AnalysisResult`, **passed through untouched** — the
schema that governs it is `services/color-engine/src/color_engine/docs/contract.schema.json`,
not this service. Reshaping it here would mean two places to change every time
the engine grows a field.

`debug_images` is present only when `debug=true`. A name is **absent** when the
photo did not produce that view (a rejected photo has no zones to draw), so the
object can legitimately be empty. Names: `overlay`, `crop_left_eye`,
`crop_right_eye`, `crop_left_cheek`, `crop_right_cheek`, `crop_forehead`,
`wb_before_after`.

| Status | When |
| --- | --- |
| 200 | Including a photo the engine **refuses** — `status: "rejected"` is a fact about the photograph, not an HTTP error |
| 400 | Empty image, or a malformed/unknown-answer quiz — a broken request |
| 401 | Missing or invalid `X-API-Token`, same gate as `/pose` |
| 413 | Over the **engine's** `max_input_bytes`, so the caller gets the same answer whether the engine is reached over HTTP or imported directly |
| 5xx | A broken environment, never a bad photo |

> **The bytes arrive untouched.** None of `/preprocess-image`'s lighting
> normalisation is applied here, in any form. CLAHE, grey-world white balance and
> gamma correction all rewrite exactly the colours this endpoint exists to
> measure; the engine does its own colour management from the original file, ICC
> profile included, and anything done to the pixels first is measurement error.

**Threading.** Unlike `/pose`, this endpoint is declared `def`, not `async def`:
the analysis is a second or so of CPU-bound work and on the event loop it would
stall every other request, `/health` included. Starlette therefore runs it in the
threadpool, where two requests really can arrive at once — so the engine sits
behind a lock in `app/color.py`. **One engine plus a lock, not one engine per
thread:** each engine holds two MediaPipe graphs, the threadpool is 40 threads
wide by default, and the work is CPU-bound on a box with a handful of cores, so
per-thread engines would multiply the memory by the pool width and buy no
throughput at all. Concurrency of one, flat memory, predictable latency — the
same trade-off `PoseDetector` already makes.

**Models.** The engine's artifacts are found through `COLOR_ENGINE_MODELS_DIR`.
They are loaded once in the lifespan, and a missing one **stops the service at
startup** with a message naming the path — a service that answers `/health` while
it cannot serve a request is worse than one that refuses to come up. Note the
consequence: this service now needs *both* model sets, the pose landmarker and
the colour engine's two.

### `POST /color-rule-check` (what today's thresholds would say)

Re-decides measurements taken earlier against the thresholds as they stand now.
No photograph is involved and none could be: the photos go on a 60-hour clock and
the numbers are all that is left.

```json
{ "samples": { "skin": { "L": 84.831, "...": "..." }, "sclera_parts": { "...": "..." } } }
```

```json
{
  "thresholds": { "sclera_socket_light_ratio": 0.55, "...": "..." },
  "zones": {
    "right_cheek": {
      "replayed": true, "usable": false,
      "reason": "overexposed", "decided_by": "light_and_flat",
      "checks": [{ "name": "light_and_flat", "value": 91.85, "limit": 90.0, "margin": -1.85 }],
      "blind_spots": ["clipped_fraction"]
    }
  }
}
```

**No models, and no switch.** This needs the `color_engine` package and nothing
else — no MediaPipe artifacts, no engine in memory — so it answers on a box that
never installed them. It is deliberately **not** gated on `COLOR_ENGINE_ENABLED`:
that switch exists to stop a deployment loading 20 MB of weights nobody asked
for, and there is nothing here for it to protect.

**The rules are the engine's.** They live in
`services/color-engine/src/color_engine/rules.py`, beside the thresholds they
read. A second implementation on the caller's side would drift, and a
rule-checker that disagrees with the engine tells you about itself and nothing
else. Laravel's `php artisan color:rule-check` is the caller.

`blind_spots` is the part that matters. A rule that read the *pixels* cannot be
re-run: `too_few_pixels`, `eye_too_small`, `too_noisy`, and the clipped-fraction
half of `overexposed`. A zone caught only by one of those replays as clean, and
each zone says which of them it is blind to rather than leaving the caller to
assume none.

| Status | When |
| --- | --- |
| 200 | Always, for a well-formed request — including "this zone cannot be judged" |
| 400 | `samples` is not the engine's `Samples` shape |
| 401 | Missing or invalid `X-API-Token`, same gate as `/pose` |
| 503 | The `color_engine` package is not installed in this environment |

## 3. Real responses (captured from the running service)

See `demo_requests.py` to reproduce. Abbreviated:

- **Full-body photo** → `person_count: 1`, 33 landmarks incl. ankles/feet with
  high confidence, `bounding_box` covering the body, correct `image_dimensions`.
- **Blank image** → `person_count: 0`, `main_person: null` (no crash).
- **Two people side-by-side** → `person_count: 2`, `main_person` = the larger one.
- **Corrupt bytes** → `400 { "detail": "Could not decode image data." }`.
- **No file field** → `422` validation error.

Run it yourself:

```bash
python -m uvicorn app.main:app --port 8077   # terminal 1
python demo_requests.py http://127.0.0.1:8077 # terminal 2
```

---

## 4. Local setup (priority)

Requires Python 3.10+ (tested on 3.10, Windows, CPU-only).

```bash
cd pose-service

# 1) virtual env
python -m venv .venv
# Windows:  .venv\Scripts\activate
# macOS/Linux:  source .venv/bin/activate

# 2) dependencies (pinned), from THIS directory — requirements.txt refers to
#    the colour engine by a relative path that pip resolves against the CWD
pip install -r requirements.txt

#    ...that installs color-engine as a COPY, which is what production wants.
#    For development, install it editable instead so a change in the engine
#    source is picked up without reinstalling:
#      pip install -e ../services/color-engine

# 3) download the model (~9 MB, into ./models, gitignored)
python download_model.py

# 4) config
cp .env.example .env        # defaults are fine for localhost

# 5) run — env-driven (reads POSE_SERVICE_HOST / POSE_SERVICE_PORT from .env)
python run.py
#   or, for auto-reload during development:
#   python -m uvicorn app.main:app --reload --host 127.0.0.1 --port 8000

# 6) check
curl http://127.0.0.1:8000/health
```

### Tests

```bash
pip install -r requirements.txt   # includes pytest + httpx
pytest -v
```

The whole suite needs the colour engine's models as well as the pose model,
because the app refuses to start without either; `tests/conftest.py` skips with a
message naming what is missing rather than erroring. The colour tests also carry
the `requires_color_models` marker.

Tests run against the **real** local model (no network, no external services) and
cover: health, full-body single person (incl. legs), WebP decoding, no-person =
`count 0`, multiple people, corrupt input, empty input, missing field, oversize
upload, and the auth gate (on/off).

### Environment variables

| Var | Default | Meaning |
|---|---|---|
| `POSE_SERVICE_HOST` | `127.0.0.1` | bind address (used by `python run.py`) — keep localhost-only in dev |
| `POSE_SERVICE_PORT` | `8000` | port (used by `python run.py`) |
| `POSE_API_TOKEN` | *(empty)* | if set, required as `X-API-Token`; empty = auth off |
| `POSE_MODEL_PATH` | `models/pose_landmarker_full.task` | model file |
| `POSE_MAX_PERSONS` | `6` | max people to detect (>1 enables multi-count) |
| `POSE_MIN_DETECTION_CONFIDENCE` | `0.5` | detection threshold |
| `POSE_MIN_PRESENCE_CONFIDENCE` | `0.5` | presence threshold |
| `POSE_BBOX_MIN_CONFIDENCE` | `0.5` | landmarks below this are excluded from the bounding box |
| `POSE_MAX_UPLOAD_BYTES` | `15728640` | reject larger uploads |
| `COLOR_ENGINE_MODELS_DIR` | *(empty)* | where the colour engine's artifacts live; required for a non-editable install |
| `COLOR_ENGINE_WHITE_BALANCE_ENABLED` | `false` | the engine's white balance — off in production until its sclera target is calibrated; **set it to `true` locally**, see below |
| `SEGMENT_KEEP_LOADED` | `false` | keep the rembg model resident between calls (false = release after each, saves RAM) |
| `SEGMENT_MODEL` | `u2net` | rembg model: `u2net` (quality) / `u2netp` (light) / `isnet-general-use` |
| `PREPROCESS_BG_COLOR` | `#f0f0f0` | neutral background for the cut-out (`#rrggbb` or `r,g,b`) |
| `PREPROCESS_CLAHE_CLIP` | `2.0` | CLAHE clip limit (local-contrast strength) |
| `PREPROCESS_CLAHE_TILE` | `8` | CLAHE tile grid size |
| `PREPROCESS_WHITE_BALANCE` | `true` | apply gray-world white balance |
| `PREPROCESS_GAMMA` | `true` | apply auto gamma toward `PREPROCESS_GAMMA_TARGET` |
| `PREPROCESS_GAMMA_TARGET` | `0.5` | target mid luminance (0..1) for gamma |
| `PREPROCESS_OUTPUT_QUALITY` | `90` | re-encode quality for jpeg/webp output |

---

## 5. Privacy

- Uploaded bytes are decoded **in memory**, run through the detector, and dropped
  when the request returns. **Nothing is written to disk.**
- **No image content is logged** — only ordinary request lines / errors.
- Stateless: no caches, no temp files, no DB. This complies with the project's
  privacy constraint (the photo is processed ephemerally on our own host; FASHN's
  72-hour retention is a separate concern and not part of this service).

---

## 6. Access / security

- **Local dev:** bind to `127.0.0.1` (the default). The service is then only
  reachable from the same machine; no token needed.
- **The service is never public.** It is called only by the backend.
- **Defence in depth (for a server):** set a strong `POSE_API_TOKEN` *and*
  restrict the network (bind to a private interface / firewall the port). The
  token is compared in constant time.

### 6.1 Reaching the service from the Laravel container (recommendation, not implemented now)

Laravel runs in Docker (`laravel.test`); this pose service runs on the host (or
its own container). Two supported options when we wire it up later:

1. **`host.docker.internal` (simplest for local dev).** Run the pose service on
   the host bound to a port, and from the Laravel container call
   `http://host.docker.internal:8000`. On Linux Docker, add
   `extra_hosts: ["host.docker.internal:host-gateway"]` to the Laravel service in
   `docker-compose.yml`. Set `POSE_SERVICE_URL=http://host.docker.internal:8000`
   in Laravel's `.env`. Note: with `host.docker.internal` the service must bind to
   `0.0.0.0` (not just `127.0.0.1`) so the container can reach it — pair that with
   `POSE_API_TOKEN` so it isn't open.
2. **Shared Docker network (cleaner long-term).** Run the pose service as a
   container on the same compose network; Laravel then calls it by service name,
   e.g. `http://pose:8000`. No host port exposure needed.

Either way Laravel only needs `POSE_SERVICE_URL` and (optionally) `POSE_API_TOKEN`
— both already configurable here, so the binding is a config change, no code
change. **Do not implement the wiring in this sub-step.**

---

## 7. Future server deployment (notes only — not performed)

Target: a Linux VPS (possibly FastPanel, no Docker required).

**Run with a process manager.** Example **systemd** unit
(`/etc/systemd/system/pose.service`):

```ini
[Unit]
Description=AI Fitting Room pose detector
After=network.target

[Service]
User=poseapp
WorkingDirectory=/opt/pose-service
EnvironmentFile=/opt/pose-service/.env
ExecStart=/opt/pose-service/.venv/bin/uvicorn app.main:app \
          --host 127.0.0.1 --port 8000 --workers 1
Restart=always
RestartSec=3
# Hardening
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload && sudo systemctl enable --now pose
```

(**supervisor** is an equally fine alternative if FastPanel uses it.)

- Bind to `127.0.0.1` and let the backend reach it locally, **or** put it behind
  the existing reverse proxy on a private path with `POSE_API_TOKEN` enforced and
  the port firewalled off from the public internet.
- Concurrency: detection is CPU-bound and serialized per worker. Scale by adding
  uvicorn `--workers` (each loads its own model copy → more RAM) rather than
  threads.

### 7.1 Colour analysis on the server (optional)

**Everything in this section is optional.** `COLOR_ENGINE_ENABLED` is `false` by
default; leave it alone and the service deploys, starts and serves `/pose`,
`/preprocess-image` and `/health` exactly as it did before any of this existed.
Nothing below is needed for pose detection.

What it costs to turn on:

1. **The engine's source has to be on the box.** It is a local package in this
   repository, not a release on PyPI, so `services/color-engine/` must sit
   beside `pose-service/` in whatever is deployed. If the deploy copies only
   `pose-service/`, this is the step that fails — and it fails at install time,
   loudly, rather than at runtime.

   ```
   /opt/fitting-room/
     pose-service/
     services/color-engine/
   ```

2. **Install the package into the same venv.** Non-editable in production, so
   the running service holds a copy and a stray edit in the source tree cannot
   change what it computes:

   ```bash
   cd /opt/fitting-room/pose-service
   .venv/bin/pip install ../services/color-engine
   ```

   It brings **no version changes** to the existing pins — mediapipe, numpy,
   opencv and pillow are pinned identically in both projects, by design. Verify
   before you commit to it:

   ```bash
   .venv/bin/pip install --dry-run ../services/color-engine   # expect only additions
   ```

3. **Fetch the engine's own models** (~20 MB, two files, not in git):

   ```bash
   cd /opt/fitting-room/services/color-engine
   /opt/fitting-room/pose-service/.venv/bin/python scripts/fetch_models.py
   ```

   The script verifies each file against a hardcoded sha256 and fails loudly if
   the download does not match. It is the only code in either package that opens
   a socket.

4. **Set the variables** in `pose-service/.env`:

   | Variable | Value on a server |
   | --- | --- |
   | `COLOR_ENGINE_ENABLED` | `true` |
   | `COLOR_ENGINE_MODELS_DIR` | `/opt/fitting-room/services/color-engine/models` — **required** for a non-editable install, because the library's own default path only exists in a source checkout |
   | `COLOR_ENGINE_WHITE_BALANCE_ENABLED` | `false` until the engine's sclera target has been calibrated on a real reference set. See `services/color-engine/README.md`, "Before this ships" |

**Locally, set it to `true`.** The admin colour-analysis page exists to judge
what the engine does, and with the correction off it shows measurements of
whatever light the photo happened to be taken in — honest, but not the thing you
are trying to look at. Every uncalibrated result is labelled as such on the page,
so there is no danger of mistaking one for a finished answer:

```bash
COLOR_ENGINE_ENABLED=true COLOR_ENGINE_WHITE_BALANCE_ENABLED=true COLOR_ENGINE_MODELS_DIR=.../services/color-engine/models   .venv/bin/python -m uvicorn app.main:app --host 0.0.0.0 --port 8000
```

Production is the default, `false`, and stays that way until the calibration is
done.

5. **Restart and check.** `/health` reports the engine under its own field:

   ```bash
   sudo systemctl restart pose
   curl -s http://127.0.0.1:8000/health
   # {"status":"ok","model_loaded":true,"model":"...",
   #  "color_engine":{"available":true,"detail":null}}
   ```

   If `available` is `false`, `detail` says why in one sentence — switched off,
   package missing, models missing — and the same sentence is in the startup log
   and in the body of every `503` from `/color-analysis`. There is no case where
   you have to read the source to find out.

**What happens if you skip all of it.** The service starts, logs one warning
line, and `/color-analysis` answers `503` with the reason. Nothing else changes:
`/pose`, `/preprocess-image` and `/health` never touch the colour engine, and
`/health` still reports `status: "ok"`, because a deployment that never wanted
colour analysis is not degraded.

**Extra resources when it IS on.** Roughly +500 MB resident per worker (a second
pair of MediaPipe graphs) and ~20 MB of disk for the models. On the 2 vCPU / 2 GB
box suggested above, budget for one worker rather than two if you enable it.

**Resource estimate (CPU-only, `pose_landmarker_full`):**

- **RAM:** ~400–700 MB resident per worker (Python + MediaPipe + OpenCV + the
  loaded model). Budget ~1 GB per worker to be safe.
- **CPU:** ~15–60 ms inference per image on one modern core; cold model load ~1 s
  at startup. A single core comfortably handles the expected lead volume; add a
  worker per extra concurrent request you need to serve in parallel.
- **Disk:** the venv (~1–1.5 GB with OpenCV/JAX) plus the ~9 MB model.
- A 2 vCPU / 2 GB box running 1–2 workers is a realistic starting point.

## 8. Known issues

**Inference blocks the event loop on `/pose` and `/preprocess-image`.** Both are
declared `async def`, so FastAPI runs them on the event loop rather than in the
threadpool, and the MediaPipe call inside is blocking. While one detection runs,
every other request to this process waits — including `/health`, which means a
health check can time out purely because somebody uploaded a photo a moment
earlier.

It has not bitten yet because the service runs one worker and traffic is low, and
the lock in `detector.py` means concurrency would be one regardless. The comment
in that file describes the threadpool behaviour it was written for, which is no
longer how the endpoints are declared.

The fix is small — drop the two `async` keywords, as `/color-analysis` already
does — but it changes the concurrency behaviour of the endpoints the try-on flow
depends on, so it belongs in its own change with its own testing, not smuggled
into an unrelated one.
