"""Hit the live service and print real JSON responses for each scenario.

Usage:  python demo_requests.py [base_url]
"""
import io
import json
import sys
import time
from pathlib import Path

import httpx
from PIL import Image

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8077"
CATALOG = Path(__file__).resolve().parents[1] / "public" / "dev-catalog"


def side_by_side(a_path, b_path):
    a = Image.open(a_path).convert("RGB")
    b = Image.open(b_path).convert("RGB")
    h = max(a.height, b.height)
    a = a.resize((int(a.width * h / a.height), h))
    b = b.resize((int(b.width * h / b.height), h))
    canvas = Image.new("RGB", (a.width + b.width, h), (255, 255, 255))
    canvas.paste(a, (0, 0))
    canvas.paste(b, (a.width, 0))
    buf = io.BytesIO()
    canvas.save(buf, format="JPEG")
    return buf.getvalue()


def blank():
    buf = io.BytesIO()
    Image.new("RGB", (640, 480), (210, 210, 210)).save(buf, format="JPEG")
    return buf.getvalue()


def show(title, resp, truncate_landmarks=None):
    print("\n" + "=" * 70)
    print(title, "->", resp.status_code)
    print("=" * 70)
    try:
        data = resp.json()
    except Exception:
        print(resp.text)
        return
    if truncate_landmarks and isinstance(data, dict):
        mp = data.get("main_person")
        if mp and len(mp["landmarks"]) > truncate_landmarks:
            kept = mp["landmarks"][:truncate_landmarks]
            mp["landmarks"] = kept + [f"... (+{33 - truncate_landmarks} more landmarks)"]
    print(json.dumps(data, indent=2, ensure_ascii=False))


def main():
    # Wait for the model to finish loading.
    for _ in range(60):
        try:
            r = httpx.get(f"{BASE}/health", timeout=5)
            if r.status_code == 200 and r.json().get("model_loaded"):
                break
        except Exception:
            pass
        time.sleep(1)

    with httpx.Client(timeout=60) as c:
        show("GET /health", c.get(f"{BASE}/health"))

        full = (CATALOG / "woman-model.jpg").read_bytes()
        show(
            "POST /pose  (full-body single person — showing first 6 of 33 landmarks)",
            c.post(f"{BASE}/pose", files={"image": ("woman-model.jpg", full, "image/jpeg")}),
            truncate_landmarks=6,
        )

        show(
            "POST /pose  (no person — blank image)",
            c.post(f"{BASE}/pose", files={"image": ("blank.jpg", blank(), "image/jpeg")}),
        )

        two = side_by_side(CATALOG / "woman-model.jpg", CATALOG / "selfie.jpg")
        show(
            "POST /pose  (two people — main_person landmarks omitted for brevity)",
            c.post(f"{BASE}/pose", files={"image": ("two.jpg", two, "image/jpeg")}),
            truncate_landmarks=0,
        )

        show(
            "POST /pose  (corrupt bytes)",
            c.post(f"{BASE}/pose", files={"image": ("x.jpg", b"not an image", "image/jpeg")}),
        )

        show("POST /pose  (no file field)", c.post(f"{BASE}/pose"))


if __name__ == "__main__":
    main()
