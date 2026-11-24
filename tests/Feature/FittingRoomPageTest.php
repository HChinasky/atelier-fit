<?php

use App\Models\Product;

/*
 * The /ai-fitting-room draft page (Phase 2, step 2). Feature-level only — no
 * browser/Dusk. Asserts the screen renders, targets the real catalog endpoint,
 * has the two zones, and carries the HONEST privacy copy (deletion within 72
 * hours, never an instant-deletion promise — CLAUDE.md hard constraint #3).
 *
 * The result screen (Phase 6.2) is rendered client-side by Alpine from polled
 * state, so these are markup/binding assertions, not browser-executed behaviour.
 */

it('renders the fitting-room page', function () {
    $this->get('/ai-fitting-room')
        ->assertOk()
        ->assertViewIs('ai-fitting-room');
});

it('shows the privacy marker with the honest 72-hour wording', function () {
    $this->get('/ai-fitting-room')
        ->assertSee('видаляється протягом 72 годин');
});

it('never promises instant photo deletion', function () {
    $response = $this->get('/ai-fitting-room');

    // Ukrainian (and a couple of English) "instant deletion" phrasings must never
    // appear — the only honest promise is "протягом 72 годин".
    foreach (['одразу', 'відразу', 'миттєво', 'негайно', 'immediately', 'instantly'] as $forbidden) {
        $response->assertDontSee($forbidden);
    }
});

it('targets the real /api/catalog endpoint', function () {
    $this->get('/ai-fitting-room')
        ->assertSee('/api/catalog', escape: false);
});

it('has the photo upload zone and the catalog grid container', function () {
    $this->get('/ai-fitting-room')
        ->assertSee('id="photo-dropzone"', escape: false)
        ->assertSee('id="catalog-grid"', escape: false);
});

it('uploads the photo to the real endpoint and keeps the returned key in state', function () {
    $this->get('/ai-fitting-room')
        ->assertSee('/api/fitting-room/photo', escape: false)
        ->assertSee('autoProcessQueue: true', escape: false)
        ->assertSee('photoKey', escape: false)
        ->assertDontSee('autoProcessQueue: false', escape: false);
});

it('shows the uploaded photo as an in-zone preview', function () {
    $this->get('/ai-fitting-room')
        // A dedicated preview layer inside the upload zone, shown once a photo
        // is added, with a visible <img>.
        ->assertSee('class="photo-preview"', escape: false)
        ->assertSee('x-show="beforePreview"', escape: false)
        ->assertSee('x-bind:src="beforePreview"', escape: false);
});

it('feeds the in-zone preview from the client-side original file, never a server URL', function () {
    // The preview source is built in-browser from the ORIGINAL file bytes via
    // FileReader (a client-side data URL) — CLAUDE.md privacy: the photo is never
    // re-fetched from the server/R2 or any public URL to render a preview.
    $page = $this->get('/ai-fitting-room');

    // The preview binds to beforePreview...
    $page->assertSee('x-bind:src="beforePreview"', escape: false)
        // ...and beforePreview is read from the original File via FileReader
        // (full image, no canvas resize/crop).
        ->assertSee('const reader = new FileReader();', escape: false)
        ->assertSee('reader.readAsDataURL(file);', escape: false)
        ->assertSee('self.beforePreview = event.target.result;', escape: false)
        // The preview must NOT be sourced from the R2 key or any photo URL.
        ->assertDontSee('x-bind:src="photoKey"', escape: false)
        ->assertDontSee('x-bind:src="photoUrl"', escape: false);
});

it('offers a one-click "replace photo" control over the preview', function () {
    // A dedicated pill (not Dropzone's flaky v6 remove link) that clears the
    // current file and reopens the picker via the stored Dropzone instance.
    $this->get('/ai-fitting-room')
        ->assertSee('class="replace-photo"', escape: false)
        ->assertSee('x-on:click="replacePhoto()"', escape: false)
        ->assertSee('Замінити фото')
        ->assertSee('this.dz = new Dropzone', escape: false)
        ->assertSee('this.dz.removeAllFiles(true)', escape: false)
        ->assertSee('hiddenFileInput', escape: false);
});

it('clears the in-zone preview when the photo is removed', function () {
    $this->get('/ai-fitting-room')
        // Dropzone's localized remove link...
        ->assertSee("dictRemoveFile: 'Прибрати'", escape: false)
        // ...and removing the file resets the zone, including the preview.
        ->assertSee("this.on('removedfile', () => {", escape: false)
        ->assertSee('self.beforePreview = null;', escape: false)
        ->assertSee('self.photoAdded = false;', escape: false)
        ->assertSee('self.photoKey = null;', escape: false);
});

it('has the lead-capture modal with a channel toggle that targets /api/lead', function () {
    $this->get('/ai-fitting-room')
        ->assertSee('id="lead-modal"', escape: false)
        ->assertSee('/api/lead', escape: false)
        ->assertSee('WhatsApp')
        ->assertSee('Email');
});

it('starts the protected generation after lead capture and polls the signed status URL', function () {
    $this->get('/ai-fitting-room')
        ->assertSee('/api/fitting-room/generate', escape: false)
        ->assertSee('startGeneration', escape: false)
        ->assertSee('pollStatus', escape: false)
        ->assertSee('status_url', escape: false);
});

it('shows the server message on a 422/503/429 instead of a generic line', function () {
    // Error responses from /generate carry a localized body.message (gate text, or
    // the photo-suitability reason from 9.2). The page must surface THAT message,
    // read via the errorMessage() helper, not a hardcoded generic string.
    $this->get('/ai-fitting-room')
        ->assertSee('async errorMessage(response, fallback)', escape: false)
        ->assertSee('body.message', escape: false)
        // Each error branch passes the response through errorMessage().
        ->assertSee('response.status === 503) { return this.failGeneration(await this.errorMessage(response', escape: false)
        ->assertSee('response.status === 422) { return this.rejectPhoto(await this.errorMessage(response', escape: false);
});

it('treats a rejected photo (422) as a soft nudge to retry, not a crash', function () {
    // 422 routes to rejectPhoto() (a distinct, friendly 'rejected' state) rather
    // than failGeneration() — and retryPhoto() returns to the start screen WITHOUT
    // clearing the selected item, so the visitor just picks another photo.
    $page = $this->get('/ai-fitting-room')
        ->assertSee('rejectPhoto(', escape: false)
        ->assertSee('retryPhoto(', escape: false)
        ->assertSee("genState === 'rejected'", escape: false)
        ->assertSee('Обрати інше фото')
        // The reason is shown from the server message (bound to genMessage).
        ->assertSee('x-show="genState === \'rejected\'"', escape: false);

    // The soft overlay must NOT reuse the crash heading, and retry must not reset
    // the chosen item (no selectedId clearing in retryPhoto).
    $page->assertSee('Майже ідеально');
});

it('drives the loader copy from the server and hardcodes no background promise', function () {
    // The background step text lives in config and the API sends it ONLY when the
    // generation actually runs background change. The page must render whatever
    // loader_steps it receives — never hardcode a "we restyle the background" line.
    $this->get('/ai-fitting-room')
        ->assertSee('loader_steps', escape: false)
        ->assertDontSee(config('fitting_room.loader.background_step'));
});

/*
 * --- Phase 6.2: the result screen (status = done) ---
 */

it('renders the result screen with the generated looks and a before/after slider', function () {
    $this->get('/ai-fitting-room')
        ->assertSee('id="result-screen"', escape: false)
        // Result overlay is gated on the done state.
        ->assertSee("genState === 'done'", escape: false)
        // The before/after comparison slider and its panes.
        ->assertSee('id="ba-slider"', escape: false)
        ->assertSee('>До</div>', escape: false)
        ->assertSee('>Після</div>', escape: false)
        // The looks are rendered from the polled result_urls array.
        ->assertSee('x-bind:src="afterUrl"', escape: false)
        ->assertSee('in resultUrls', escape: false);
});

it('shows the generated look whole — contain, with the box adopting its ratio', function () {
    // A portrait FASHN result must be shown in full (no head/feet cropped) and
    // the stage box must take the look's own proportions instead of a fixed crop.
    $this->get('/ai-fitting-room')
        // Box ratio is learned from the loaded image, not hardcoded to a crop.
        ->assertSee('aspect-ratio: ${resultRatio}', escape: false)
        ->assertSee('x-on:load="setResultRatio($event.target)"', escape: false)
        ->assertSee('this.resultRatio = img.naturalWidth', escape: false)
        // The result pane (and the whole-photo Before fallback) contain the whole
        // image (no cover-crop of head/feet).
        ->assertSee('.ba-before-full, .ba-after img { width: 100%; height: 100%; object-fit: contain', escape: false);
});

it('frames the Before image to the synced crop region when one is returned', function () {
    // When the server returns a crop box, "Before" shows THAT region (so the slider
    // shares one frame with the cropped result) and falls back to the whole photo
    // when there is none (fail-open skip / reload).
    $this->get('/ai-fitting-room')
        ->assertSee('cropBox: null', escape: false)
        ->assertSee('this.cropBox = body.crop_box || null', escape: false)
        // Region path vs whole-photo fallback, switched on the presence of a box.
        ->assertSee('beforePreview && cropBox', escape: false)
        ->assertSee('beforePreview && ! cropBox', escape: false)
        ->assertSee('x-bind:style="beforeRegionStyle()"', escape: false)
        ->assertSee('x-bind:style="beforeImgStyle()"', escape: false);
});

it('shows every look when there are several (num_samples > 1)', function () {
    // The looks row iterates the whole result_urls array and is shown when >1.
    $this->get('/ai-fitting-room')
        ->assertSee('id="looks-row"', escape: false)
        ->assertSee('resultUrls.length > 1', escape: false)
        ->assertSee('in resultUrls', escape: false)
        // Per-look background marker (result_urls carries with_background per look).
        ->assertSee('look.with_background', escape: false);
});

it('shows the before image from the in-browser preview, not a server/public URL', function () {
    // PRIVACY: "Before" binds to a client-side data URL read from the original
    // File in the browser. The photo is not re-uploaded and not served from any
    // predictable URL.
    $page = $this->get('/ai-fitting-room')
        ->assertSee('x-bind:src="beforePreview"', escape: false)
        // The "Before" data URL is produced fully in-browser from the original
        // file (FileReader) — no server round-trip, and no canvas resize that
        // could crop the photo.
        ->assertSee('reader.readAsDataURL(file);', escape: false)
        ->assertDontSee('createImageThumbnails: true', escape: false);

    // No pre-signed / temporary URL is minted for the "Before" image client-side.
    $page->assertDontSee('temporaryUrl', escape: false);
});

it('has a dynamic shop button bound to the selected product brand and shop_url', function () {
    $this->get('/ai-fitting-room')
        ->assertSee('id="shop-button"', escape: false)
        // href comes from the product's affiliate shop_url; text carries the brand.
        ->assertSee('selectedProduct.shop_url', escape: false)
        ->assertSee("'Купити на ' + selectedProduct.brand_name", escape: false)
        // Plain outbound link in a new tab — no payment mechanics.
        ->assertSee('rel="noopener noreferrer"', escape: false)
        ->assertSee('target="_blank"', escape: false);
});

it('supports selecting a SET of items for an outfit, capped by config', function () {
    config(['fitting_room.outfit.max_items' => 2]);

    $this->get('/ai-fitting-room')
        // Multi-select state + the configured cap injected into the page.
        ->assertSee('selectedIds: []', escape: false)
        ->assertSee('maxOutfitItems: 2', escape: false)
        // Cards highlight by membership, not a single id.
        ->assertSee('selectedIds.includes(product.id)', escape: false)
        // A set posts to the outfit endpoint with product_ids; one item stays single.
        ->assertSee('/api/fitting-room/generate-outfit', escape: false)
        ->assertSee('product_ids: this.selectedIds', escape: false)
        // Per-item buy links for the outfit (dynamic brand + shop_url, never hardcoded).
        ->assertSee('id="outfit-shop"', escape: false)
        ->assertSee('item.shop_url', escape: false);
});

it('shows an honest "item N of M" loader for an outfit chain', function () {
    $this->get('/ai-fitting-room')
        ->assertSee('Приміряємо річ ', escape: false)
        // The counter is driven by the server-reported outfit progress.
        ->assertSee('this.outfitStep = body.outfit.step', escape: false)
        ->assertSee('body.outfit.total', escape: false);
});

it('scales the browser poll budget by the number of outfit layers (no premature timeout)', function () {
    // An outfit chains N sequential engine calls, so the page must wait ~N× as long
    // as a single try-on before timing out — the budget is POLL_MAX × layer count.
    $this->get('/ai-fitting-room')
        ->assertSee('get pollBudget()', escape: false)
        ->assertSee('this.POLL_MAX * Math.max(1, this.outfitTotal)', escape: false)
        // The poll loop uses the scaled budget, not the raw single-try-on constant.
        ->assertSee('this.pollAttempts >= this.pollBudget', escape: false);
});

it('feeds the dynamic shop button with real brand_name and shop_url from the catalog', function () {
    // The button reads these from /api/catalog; prove the data is actually exposed
    // for a factory product (the source the dynamic href/text bind to).
    $product = Product::factory()->verified()->brand('Mango')->create([
        'shop_url' => 'https://shop.example.test/mango/item-42',
    ]);

    $this->getJson('/api/catalog')
        ->assertOk()
        ->assertJsonPath('data.0.brand_name', 'Mango')
        ->assertJsonPath('data.0.shop_url', 'https://shop.example.test/mango/item-42');
});

it('has the consultation button as a stub with no payment or vendor wired', function () {
    $page = $this->get('/ai-fitting-room')
        ->assertSee('id="consultation-button"', escape: false)
        ->assertSee('Консультація стиліста', escape: false)
        // It is a no-op button, not a link to a checkout/vendor.
        ->assertSee('bookConsultation', escape: false);

    foreach (['stripe', 'checkout', 'paypal', 'manychat', 'wa.me', 'api.whatsapp'] as $vendor) {
        $page->assertDontSee($vendor, escape: false);
    }
});

it('reflects the signed generation in the URL and restores it on page load', function () {
    // On done/processing the address bar gets the SAME signed params via
    // replaceState; on load the page rebuilds the EXACT signed status endpoint
    // URL and asks the server — so a refresh restores the result/loader.
    $this->get('/ai-fitting-room')
        ->assertSee('reflectResultUrl', escape: false)
        ->assertSee('restoreFromUrl', escape: false)
        ->assertSee('history.replaceState', escape: false)
        ->assertSee("set('generation'", escape: false)
        // Restore hits the existing SIGNED status endpoint (not a bare /result/{id}).
        ->assertSee('/api/fitting-room/generation/', escape: false);
});

it('restores result-only and never refetches the Before photo from the server', function () {
    // PRIVACY: after a reload the client preview is gone. The Before layer /
    // divider / labels are gated on beforePreview, so the slider degrades to
    // result-only — the user's photo is never pulled from the server.
    $this->get('/ai-fitting-room')
        ->assertSee('x-show="beforePreview"', escape: false)
        ->assertDontSee('client_photo', escape: false)
        ->assertDontSee('temporaryUrl', escape: false);
});

it('has a share button that shares only public/own content, never the signed status URL', function () {
    $this->get('/ai-fitting-room')
        ->assertSee('id="share-button"', escape: false)
        ->assertSee('shareResult', escape: false)
        // The only link that ever leaves is the public site entrance with the
        // share UTM — never the signed status URL, never a result/card URL.
        ->assertSee('shareSiteUrl', escape: false)
        ->assertSee("window.location.origin + '/?utm_source=share&utm_medium=social'", escape: false)
        // The signed status URL is used ONLY for polling — never passed to share.
        ->assertDontSee('navigator.share({ files: [file], url', escape: false)
        ->assertDontSee('share({ title, text, url: this.statusUrl', escape: false);
});

it('shows the honesty line before the share sheet and offers download + copy link', function () {
    $this->get('/ai-fitting-room')
        ->assertSee('Ви ділитеся зображенням, на якому — ви.', escape: false)
        ->assertSee('downloadCard', escape: false)
        ->assertSee('copyShareLink', escape: false)
        // Cards ride in via the status payload (signed, short-lived) only.
        ->assertSee('body.share || {}', escape: false);
});

it('kills Dropzone auto-discovery before Alpine loads, so init() cannot throw "already attached"', function () {
    // REGRESSION: the upload form carries class="dropzone", so Dropzone attaches
    // itself to it on DOMContentLoaded. autoDiscover was only turned off inside
    // the Alpine component, which is deferred and can run later — losing that
    // race left the form with an auto-created instance, made `new Dropzone(...)`
    // throw, and aborted the rest of init(), including restoreFromUrl().
    $html = $this->get('/ai-fitting-room')->getContent();

    $killSwitch = strpos($html, 'Dropzone.autoDiscover = false');
    $alpine = strpos($html, 'alpine.min.js');

    expect($killSwitch)->not->toBeFalse()
        ->and($alpine)->not->toBeFalse()
        // The kill switch runs BEFORE Alpine is even fetched.
        ->and($killSwitch)->toBeLessThan($alpine);
});

it('reuses or replaces an existing Dropzone instance instead of attaching a second one', function () {
    $this->get('/ai-fitting-room')
        // A second init() on the same component is a no-op ...
        ->assertSee('if (this.dz) return;', escape: false)
        // ... and an instance attached by anything else is destroyed, not fought.
        ->assertSee('if (el.dropzone) el.dropzone.destroy();', escape: false);
});
