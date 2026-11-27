# A readable product view: reflowed text and CAP alerts without XML

## Why

The National Weather Service hard-wraps its text at about 66 to 69 characters. A phone fits about 43, so nearly every line wraps a second time and a forecast discussion reads as ragged long-line, short-line fragments. Separately, many advisories, warnings and watches arrive as CAP XML, which is stored as the product's text and printed as-is on the product page. A subscriber who taps an alert to read it should get something they can read.

## Evidence (throwaway test, 2026-11-26)

A draft reflow was run over the 3,000 latest catalogued products in the dev database and tried live in the app.
- Across 1,596 plain-text products, no word was changed, moved or lost, and no column-spaced line was altered.
- Short orphan fragments on a 43-column screen fell by 72% for forecast discussions, 54% for SPC/WPC, 31% for advisories, 24% for warnings and 23% for watches.
- One wrong join was found: two tide-table rows spaced with single spaces (`MLLW Categories - ...` and `MHHW Categories - ...`) were merged. Others may exist; the automated checks cannot detect a wrong join.
- In the same sample 1,086 of 1,384 advisories, 284 of 395 warnings and 34 of 59 watches were raw CAP XML. In a CAP sample from the whole table, all 329 had a headline, 325 a description and 236 an instruction.

The test code (`app/Support/SpikeReflow.php` in the mobile repo and a one-line change to the product screen) is thrown away; nothing from it is kept as-is.

## Decisions (agreed in conversation, 2026-11-26)

- Join hard-wrapped prose back into paragraphs ("reflow"); leave anything whose layout carries meaning exactly as sent.
- The reflow runs **on the server**. Both versions are sent to the app.
- An **Original layout** switch shows the text exactly as sent.
- The CAP XML problem is fixed in the same work.
- Not chosen: a smaller font, sideways scrolling, forcing landscape, a proportional font.

## Goals

- A text product reads as paragraphs on a phone, in the app and on the public web product page.
- A CAP alert shows readable text, never XML.
- The subscriber can always see a text product exactly as it was sent.

## Non-goals

Hanging indents for bullets, a proportional or resizable font, rendering tables as real tables, changing what is stored, and any change to alert matching, subjects, push, email or other channel bodies.

## Anti-goals (a failure even if it technically works)

- The reading view must never add, drop, reorder or alter a word of the product. This is life-safety text.
- When the rules are unsure, a line is left as sent. A missed join is acceptable; a wrong join in a table or code block is not.
- CAP XML must never reach a subscriber's screen, even for a CAP record with missing fields.
- A failure in the reading view must never stop a product page from showing.

## Constraints

- Backend: `wxnotify-nextgen` (Laravel 13, Pest). App: `wxnotify-mobile` (NativePHP; the product screen is `ProductView`, its view `product-view.blade.php`; viewed products are cached on the phone).
- The public web page (`resources/views/view-product/show.blade.php`) prints `$product->text` in a `<pre>`. The app's `GET /api/v1/products/{slug}` (`ProductResource`) sends the same field.
- `ingested_products` has `format` (`cap` for CAP), `text` (as received), `headline`, `description` and `instruction`.
- Nothing stored changes; the reading view is computed when the page or API response is built.
- Follow `.ai/rules` in both repos.

## Design

### The reading text

One pure function turns a product into its reading text. Two cases:

**CAP products** (`format` is `cap`, or the text is XML): the reading text is built from the stored fields: the description, then the instruction (headed "Instructions"), separated by blank lines; the headline already has its own place on both pages and is used in the text only if there is no description. A CAP description is itself hard-wrapped prose, so it goes through the same reflow. If a CAP record has no description, instruction or headline, the reading text is a short plain sentence saying the alert has no text to show. The XML is never returned.

**Text products:** reflow by these rules, which err towards leaving lines alone:
- A line is joined to the one before it only if the break looks like a wrap (the next line's first word would not have fitted on the previous line at the NWS width), neither line is layout, and the next line does not start something new.
- **Layout, never joined:** lines with column spacing or tabs; sequence number, WMO header and AWIPS ID lines; `&&` and `$$`; UGC and VTEC/H-VTEC codes; `LAT...LON`, `TIME...MOT...LOC` and coordinate rows; issuance time lines; aviation forecast groups (`TAF`, `FM`, `TEMPO`, `PROB`, `BECMG`); dot leaders and rule lines; and **rows of data**, including single-spaced label-and-value rows like the tide lines above.
- **Starts something new:** bullets and numbered items, `.SECTION...` and `...HEADLINE...` lines, `LABEL...` lines (`HAZARD...`), `* ` items.
- Indented text continues only a bullet.
- Products in the **aviation** family are not reflowed at all.

**The safety net:** after reflowing, the function compares the words of the input and output, in order. If they differ in any way, it returns the original text. Any error does the same.

### The API

`ProductResource` returns:
- `text`: for a text product, as sent (unchanged meaning). For a CAP product, the readable CAP text, so **app versions already installed stop showing XML** as soon as the server is updated.
- `reading_text`: the reflowed text (or the readable CAP text).
- `has_original`: true when `text` differs from `reading_text` in layout (so the switch is worth showing); always false for CAP.

### The app

The Product screen shows `reading_text` by default. When `has_original` is true it shows an **Original layout** switch above the text; turning it on shows `text`. The choice applies to the screen being viewed and starts off each time. A product saved on the phone before this change, or a server without the new fields, shows `text` with no switch.

### The web page

The public View Product page shows the reading text, with the same **Original layout** switch when `has_original` is true (a plain toggle in the page, no reload, working without a signed-in user and keeping the page cacheable). A CAP product shows only the readable text. The page's meta description and title are unchanged.

## Approaches considered

- **A. Reflow the prose (chosen).**
- **B. Smaller font:** needs about 9-point text.
- **C. Sideways scrolling:** panning on every line of prose; the Original layout switch covers the case where exact layout matters.
- **D. Landscape:** the app is portrait-only and nobody should have to turn the phone.
- **E. Proportional font:** does not remove hard wraps and breaks tables.
- **Where:** server (chosen: one set of rules, tested against real products, also serves the web page and fixes CAP for installed apps) over in-app (app only).

## Deploy and compatibility

Backend first; installed apps immediately stop showing XML, and keep showing text products as before. The switch and reflow arrive with the next app build.

## Testing

- A fixture set of real NWS products (public domain), chosen to cover: forecast discussions with bullets and sections, warnings with `*` items and code lines, the tide-table case, a product with column tables, an aviation product, SPC and WPC discussions, and CAP alerts with and without a description or instruction. Each has its expected reading text checked in and reviewed by eye.
- Over every fixture: the words are identical and in order, and column-spaced lines survive unchanged.
- The safety net returns the original when a rule would change a word (test with a forced fault).
- API: both fields for text and CAP, never XML. Web: the reading text, the switch only when it applies, no XML for CAP. App: default view, the switch, missing-field fallback.
- Before release, the builder reviews a sample of at least 100 real products' before/after differences by eye for wrong joins, and reports what was found.

## Left to the builder

Class names and placement, the exact data-row rule (it must keep the tide case and not block ordinary sentences that mention several numbers, proven by fixtures), the switch's exact look in the app and on the web.

## Calls made without being asked (open to change)

- For CAP, `text` itself becomes readable, to fix installed apps without a release.
- CAP has no Original layout switch: raw XML helps no subscriber.
- The switch is not remembered between products.
- Aviation products are never reflowed.
- Bullets lose their indent on wrapped lines.
- The reading text is computed per request, not stored.
