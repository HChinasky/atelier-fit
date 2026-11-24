# dev-catalog — sample product photos for the file-based catalogue feeds

Placeholder images referenced by the two XML feeds in `database/seeders/fixtures/`.
They are generated grey silhouettes on white, not real product photos, so the
repository ships no third-party imagery.

| Feed | Purpose | Imported by |
|------|---------|-------------|
| `local_catalog_feed.xml`   | messy dev/test fixture | `php artisan feed:seed-local` (dev only) |
| `curated_catalog_feed.xml` | a small hand-curated catalogue | `php artisan catalog:import-file` |

Product names and brands inside the feeds are sample data.

## The rule for a photo here

A photo should be a **flat shot of the garment alone on a plain white background**
— that is what `flat_bg_verified` means and what the try-on engine can dress. A
photo of the garment on a model is the wrong input: the engine receives the
customer's photo plus a picture of a different person, and the result shows the
wrong body.

To turn a usable photo into this shape, remove the background and centre the
garment on white — `rembg` (installed with the pose service) does the cut-out.
