# Readable product view: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** NWS text reads as paragraphs on a phone (app and public web page), CAP alerts never show XML, and a subscriber can switch a text product back to the layout it was sent in.

**Architecture:** A pure reflow function on the backend, with a word-for-word safety net, is wrapped by one action that decides what each product shows (CAP fields, reflowed text, or the text as sent). The product API and the public View Product page both use that action; the app shows the reading text with an Original layout switch.

**Tech Stack:** Backend (`/Users/jimmy/Documents/WxNotify/wxnotify-nextgen`): Laravel 13, Pest in the dev container (`docker compose exec -T front-end php artisan test --compact <file>`), Pint. Mobile (`/Users/jimmy/Documents/WxNotify/wxnotify-mobile`): NativePHP v4, Pest on the host (`php vendor/bin/pest --compact <file>`), Pint.

**Spec:** `docs/superpowers/specs/2026-11-26-product-reading-view-design.md` (backend repo).

## Global Constraints

- The reading view never adds, drops, reorders or alters a word of a text product. If the words (split on whitespace) of input and output differ in any way, or anything throws, the original text is returned.
- When unsure, a line is left as sent. A missed join is acceptable; a wrong join in a table or code block is not.
- CAP XML never reaches a subscriber's screen or the product API, even for a CAP record with no description, instruction or headline.
- A failure in the reading view never stops a product page or API response.
- CAP: `format` is `cap`, or the text is XML (starts, after whitespace and any leading sequence/header lines, with `<?xml` or contains `<alert xmlns`). Products in the `aviation` family are never reflowed.
- API (`ProductResource`): `text` (text product: as sent; CAP: the readable CAP text), `reading_text`, `has_original` (true only for a text product whose reading text differs from `text`; always false for CAP).
- The app and the web page show `reading_text` by default; the Original layout switch appears only when `has_original`, starts off for every product and is not remembered. The public page loads no scripts, so its switch is CSS-only.
- Nothing stored changes. Alert matching, subjects, push, email and other channel bodies are out of scope.
- Follow `.ai/rules` in both repos; Pint after PHP edits; `php artisan make:` where a command exists. The throwaway spike (`app/Support/SpikeReflow.php` in the mobile repo and its one-line blade change) is removed, not reused as-is.

## Review Focus

- Single-spaced data rows (the `MLLW Categories - ...` / `MHHW Categories - ...` tide rows) stay on separate lines.
- A zone list, UGC block or county list that wraps across lines is never merged into prose.
- A CAP record with only a headline, or with nothing, shows a sentence, never XML or an empty screen.
- A product saved on the phone before this change (no `reading_text`) still opens and shows its text with no switch.
- Windows line endings (`\r\n`) and stray `\r` in real products do not produce doubled or lost lines.

---

### Task 1: The reflow function and its fixtures (backend)

**Work from:** the backend repo.

**Files:**
- Create: `app/Support/ReflowBulletin.php`; `tests/Fixtures/reading-view/{name}.txt` with `{name}.expected.txt` pairs
- Test: `tests/Feature/ReflowBulletinTest.php`

**Interfaces:**
- Produces: `App\Support\ReflowBulletin` with `static reflow(string $text): string` (pure; normalises `\r\n` and `\r` to `\n`; returns the input, line endings normalised, if the safety net trips or anything throws) and `static sameWords(string $a, string $b): bool` (the safety net's comparison).

**Reference material:** a corpus of 3,000 real products as JSON lines (`id`, `fam`, `name`, `cccc`, `text`) and the throwaway implementation are in `/private/tmp/claude-502/-Users-jimmy-Documents-WxNotify-wxnotify-nextgen/a5c12c05-0af5-4f3b-ac59-e77fc1eaa6a3/scratchpad/reflow/` (`corpus.jsonl`, `SpikeReflow.php`, `check.php`). If the folder is gone, export it again from the dev database. Read the spike for its rules; write the class afresh.

- [ ] **Step 1: Build the fixtures.** Pick real products from the corpus (public NWS text; no personal data): a forecast discussion with bullets and `.SECTION...` headers; a warning with `* WHAT...` items, UGC, VTEC and `LAT...LON`; the Coastal Flood product with the tide rows (`kwbc`/`klwx`, a text one, not XML); a product with column tables; a zone-forecast or similar with `.TODAY...` periods; an SPC and a WPC discussion; an aviation product (TAF groups). For each, write the expected reading text **by hand** from the source, deciding every join by eye; do not generate it from your implementation.
- [ ] **Step 2: Write the failing tests:** each fixture reflows to its expected text exactly (dataset over the pairs); for every fixture `sameWords(source, reflow(source))` is true and every source line with 3 or more consecutive inner spaces appears unchanged in the output; the tide rows stay on two lines; a UGC zone block and a county-name list that each wrap over several lines keep their line breaks (include both in the warning fixture); a sentence that mentions several numbers (for example "Highs 50 to 55 with lows 30 to 35 and winds 10 to 20 mph") that was wrapped IS still joined; `\r\n` input gives the same result as `\n`; empty string and a single line are returned unchanged; `sameWords` is false when a word is changed, dropped or reordered; a several-hundred-line input reflows in under 100 ms.
- [ ] **Step 3: Run, expect FAIL.**
- [ ] **Step 4: Implement** per the spec's rules (wrap detection: join only when the next line's first word would not have fitted on the previous line at the NWS width; the layout list including a data-row rule that keeps the tide rows; block starters; indented text continues only a bullet), then the safety net (`sameWords` comparison and a `try/catch (Throwable)` returning the normalised input). Do not edit an expected fixture to match the code; if a fixture is wrong, say why in the report.
- [ ] **Step 5: Run, expect PASS.** Then run the corpus check (adapt `check.php` to the new class): report documents with changed words (must be 0), column lines altered (must be 0) and the short-fragment reduction by family.
- [ ] **Step 6: Pint and commit** (`Reading view: reflow NWS text into paragraphs with a word-for-word safety net`).

---

### Task 2: What each product shows, and the API (backend)

**Work from:** the backend repo.

**Files:**
- Create: `app/Actions/Products/BuildReadingText.php`
- Modify: `app/Http/Resources/Api/V1/ProductResource.php`
- Test: `tests/Feature/BuildReadingTextTest.php`; extend `tests/Feature/ApiProductShowTest.php`

**Interfaces:**
- Consumes: `ReflowBulletin::reflow()` (Task 1).
- Produces: `App\Actions\Products\BuildReadingText` invokable: `__invoke(IngestedProduct $product): array{text: string, reading_text: string, has_original: bool}`. CAP: `text` and `reading_text` are the same readable text: the reflowed description, then (if present) a blank line, the line `Instructions`, and the reflowed instruction; with no description, the headline takes its place; with none of the three, "This alert has no text to show." `has_original` false. Aviation family (`$product->product?->family === ProductFamily::Aviation`): `reading_text` = `text` = as stored, `has_original` false. Other text products: `text` as stored, `reading_text` reflowed, `has_original` = they differ after normalising line endings. Any `Throwable`: for a text product both are the stored text; for CAP the fixed sentence; log only the exception class.

- [ ] **Step 1: Write the failing tests:** each case above (CAP with description and instruction; description only; headline only; nothing; XML text with a non-`cap` format is treated as CAP; in every CAP case neither value contains `<alert` or `<?xml`, including when `description` itself is null and `text` is XML); a text product; an aviation product; an already unwrapped text product gives `has_original` false; a forced failure falls back as described. API: `GET /api/v1/products/{slug}` returns `text`, `reading_text`, `has_original` for a text product (with `text` byte-for-byte as stored) and no XML for a CAP product.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement**, and have `ProductResource` take the three values from the action.
- [ ] **Step 4: Run, expect PASS**, plus `ApiProductShowTest`, `ApiProductsTest`, `ProductResourceTest`.
- [ ] **Step 5: Pint and commit** (`Reading view: reading text in the product API; CAP alerts no longer return XML`).

---

### Task 3: The public View Product page (backend)

**Work from:** the backend repo.

**Files:**
- Modify: `app/Http/Controllers/ViewProductController.php`, `resources/views/view-product/show.blade.php`, `deploy/README.md` (upgrade note)
- Test: extend `tests/Feature/ViewProductPageTest.php`

**Interfaces:**
- Consumes: `BuildReadingText` (Task 2).
- Produces: the page's "Product text" section shows the reading text; when `has_original`, an **Original layout** checkbox with a label that, using CSS only (the page loads no scripts and is cached), shows the as-sent `<pre>` instead; both blocks are escaped with Blade `{{ }}`. CAP pages show only the readable text. Title, meta description, cache headers and the Product details section are unchanged.

- [ ] **Step 1: Write the failing tests:** a text product page contains the joined paragraph and the original lines, has the Original layout control, and the reading block comes first and is the one visible by default (assert the CSS hook the implementation uses); a product with nothing to reflow has no control and one text block; a CAP product page contains the description and no `&lt;alert` / `<alert`; text containing `<script>` is escaped in both blocks; the page still contains no `<script` tag and keeps its Cache-Control header.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** In `deploy/README.md`'s upgrade section add one sentence: product pages are served `Cache-Control: public, max-age=31536000, immutable`, so after upgrading to this release purge any CDN cache of `/view-product/*` (browsers that already hold a page keep it until their copy expires).
- [ ] **Step 4: Run, expect PASS**; run `bash deploy/verify.sh`.
- [ ] **Step 5: Pint and commit** (`Reading view: the public product page shows the reading text with an Original layout switch`).

---

### Task 4: The app's Product screen (mobile)

**Work from:** the mobile repo.

**Files:**
- Delete: `app/Support/SpikeReflow.php` (untracked throwaway) and revert its line in `resources/views/native/product-view.blade.php`
- Modify: `app/NativeComponents/ProductView.php`, `resources/views/native/product-view.blade.php`
- Test: extend `tests/Feature/ProductViewTest.php`

**Interfaces:**
- Consumes: the API fields `text`, `reading_text`, `has_original` (Task 2).
- Produces: `public bool $originalLayout = false` and `public function toggleOriginalLayout(): void` on `ProductView`. The body shows `reading_text` when it is a non-empty string and `originalLayout` is off, else `text`. The switch (a native toggle or checkbox labelled "Original layout", ref `original-layout`) renders above the text only when `has_original === true` and `reading_text` is non-empty.

- [ ] **Step 0:** `git checkout -- resources/views/native/product-view.blade.php && rm -f app/Support/SpikeReflow.php`. Check `git status`: do not stage or revert any other uncommitted change you did not make.
- [ ] **Step 1: Write the failing tests:** the reading text is shown by default and the original is not; turning the switch on shows the original and off shows the reading text; no switch when `has_original` is false; a response without `reading_text`/`has_original` (old backend) shows `text` with no switch; a saved copy from before the change (cache seeded without the new fields) opens offline showing `text`; a freshly opened screen starts with the switch off; a product shown from the placeholder (saved copy) shows the reading text on the first frame.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** Keep the existing font and wrapping classes; theme tokens only.
- [ ] **Step 4: Run, expect PASS**, plus `ProductsScreenTest`, `InboxScreenTest`, `ProductLink` tests, then the full mobile suite.
- [ ] **Step 5: Pint and commit** (`Product screen: show the reading text, with an Original layout switch`).

---

### Task 5: Eyeball review, rules and full runs

- [ ] **Step 1 (backend): Review at least 100 real products by eye.** Run the new reflow over the corpus, write before/after differences for at least 100 products spread across families (weighted toward warnings, advisories and any product with several short lines), and read every joined line for wrong joins. For each kind of wrong join found: add a fixture that fails, fix the rule (leaving lines alone is the safe direction), rerun Task 1's tests and the corpus check, and commit (`Reading view: leave <kind> lines alone`). Report how many were reviewed, what was found and what remains.
- [ ] **Step 2: Project rule** (`record-rule`, glob `app/Support/**`): the reading view's anti-goals, the safety net, that new layout cases are added as fixtures with hand-written expected output, and that CAP never shows XML. Add one sentence to the mobile repo's `.ai/rules/native.md` on `reading_text` / `has_original`.
- [ ] **Step 3: Full runs:** the backend suite in parallel in the container (project memory: 6 processes) and the full mobile suite; report failures by name.
- [ ] **Step 4: Commit rules** in each repo (`Reading view: project rules`). In the final message give the owner: the deploy order (backend first; purge the CDN cache for `/view-product/*`), and a check on the simulator of an AFD, a warning with tide or table rows, and a CAP alert.
