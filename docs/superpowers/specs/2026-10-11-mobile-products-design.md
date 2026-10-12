# Mobile Products (the Products tab) — Design

Source: owner request, 2026-10-11. A new Products tab in the mobile app, between Map and Settings: search the archive of ingested weather products, filter by product type, issuing office (WFO) and time period, see recent searches, and open a result's View Product page, as the Alerts screen does.

## Goal

A subscriber can find a weather product (a forecast discussion, a warning, an outlook) without waiting for an alert: type part of its name or AWIPS ID, optionally narrow by type, office and period, and tap a result to read it.

## Decisions (user-approved, from brainstorming)

- **Search text matches titles and identifiers only:** the catalog product name, the AWIPS ID (prefix) and the WMO header. Not the headline (it would need a trigram index and a new Postgres extension, and for warnings it repeats the product name) and not the bulletin body (a full-text index over a large column, to be considered once the archive size is known). Rejected: full-text now; titles now plus body "as a second step" (that is a later slice, not a promise).
- **Recents are recent searches, kept on the phone only:** the search text plus the filters used. Rejected: recently opened products; both lists; server-side history (the server learns nothing about what a subscriber searches).
- **Time period is presets:** past hour, 24 hours, 7 days, 30 days. Off means all time. Rejected: custom date range (a later addition if wanted).
- **The screen opens with the search box, the filters and the recent searches only.** Results appear once the subscriber searches or sets a filter. Rejected: showing the latest products by default (a heavier query on every open for a list nobody asked for).
- **Search runs on submit,** not on every keystroke: one request per search, and no character loss from live text sync (seen on iOS in the channel screens).

## Backend

Two read-only endpoints in the existing `/api/v1` app-token group (`auth:sanctum`, `ability:app`, `ExtendAppTokenExpiry`, `throttle:app-api`), returning Eloquent API Resources. The products are public data (their View Product pages need no login), so nothing is scoped to the user, but the endpoints stay behind the app token. The query lives in an action, `SearchProducts` (`app/Actions/Products/`).

### `GET /api/v1/products`

Query parameters, all optional and combined with AND:

| Parameter | Meaning |
| --- | --- |
| `q` | Search text, at least 2 characters after trimming (else 422). A catalog product with a word in its name that starts with it (not a substring: `tor` finds Tornado, not Thunderstorm), or an AWIPS ID or WMO header (`TTAAII CCCC`, any prefix of it) that starts with it; all case-insensitive (the pipeline stores these codes in lowercase). Spaces typed in the text are ignored for the AWIPS ID match ("AFD MOB" finds `afdmob`). `%` and `_` in the text are literal. A matched catalog product whose `code` is a 3-character AWIPS category (`TAF`, `AFD` ...) also matches the unclassified rows (`product_id` null) whose AWIPS ID starts with it, because the archive classifies only some products. |
| `type` | A catalog product id (`products.id`): rows with that `product_id`; for a product whose `code` is a 3-character AWIPS category (`TAF`, `AFM` ...), also the unclassified rows (`product_id` null) whose AWIPS ID starts with the code. A VTEC-style code (`TO.W`) matches by `product_id` only. |
| `wfo` | An office code as `GET /nws-offices` lists it (`MOB`). Matches the product's issuing office, `cccc` (stored lowercase): the code with a leading `K` or `P` (`KMOB`, `PHFO`), plus the known exception `SJU` → `TJSJ`; plan Task 1 checks the full office list against the archive's `cccc` values for others. An unknown code is a 422. |
| `period` | `hour`, `day`, `week` or `month`: products received in the last 1 hour, 24 hours, 7 days or 30 days. |
| `before` | The cursor from the previous page. |
| `limit` | 1 to 50, default 25. |

Results are ordered by `received_at` descending, then id descending; the cursor carries both so pages are stable when times tie (as `ListAppAlerts` does). Test products (`is_test`) are excluded in production, as `ViewProductController` does. Each row:

```
{ id, title, office, issued_at, source, product_id, type_label, view_url }
```

`title` is the catalog name, else the AWIPS ID, else the WMO header (`TTAAII`), else "Weather product", as the product page does. `office` is `cccc`. `issued_at` is the header's issue time (`GenerateViewSlug::issuedAt()`), else `received_at`, as an ISO 8601 string. `type_label` is the catalog product's category label (null without a catalog entry). `view_url` is the product's `viewUrl()` (null when it has no slug). The response never carries the bulletin text, ugc codes, polygons or any other column. `meta` has `next_cursor`.

### `GET /api/v1/product-types`

The filter choices: catalog products that are `active` or `retired` (a retired product can still have archived rows), grouped by `ProductCategory` in the dashboard's section order: `{category, label, products: [{id, name, code}]}`. Drafts are left out.

### Limits and cost

`q` needs two characters, the page is capped at 50 and the matches use only the catalog (a small table), the AWIPS ID and WMO header columns (`awipsid` is indexed; the WMO header match is an equality or prefix on `ttaaii`, `cccc`), `product_id`, `cccc` and `received_at` (indexed). The plan begins by measuring the query on the dev archive (the size is not known yet) and adds an index only if a combination is slow.

## App (`mobile/`)

### Screens and behaviour

- **Bottom bar:** Alerts · Map · Products · Settings. Products goes to `/products` (`ProductsScreen`), a tab root. Its icon is a magnifying glass (an Android and an iOS icon from the generated enums).
- **Search box and filters:** a text input with a Search button (and the keyboard's Search key) at the top; below it three selects, Type, Office and Period, each "Any" by default, plus a "Clear filters" link once any is set. Office uses `GET /nws-offices`; Type uses `GET /product-types`; Period uses the four presets. The Type and Office lists load when the screen opens and are kept for the visit.
- **When it searches:** when the trimmed text has at least 2 characters or any filter is set. Submitting the text runs the search, and changing any filter runs it at once, so a filter alone (for example every AFD from MOB) works. Changing the text without submitting does nothing. Clearing the text and every filter returns to the opening state.
- **Opening state:** the search box, the filters, and "Recent searches".
- **Results:** below the box, a native list, newest first; a row shows the title, the office and the issue time ("KMOB · 2 hours ago"). A "Show more" button under the rows loads the next page (the screen is one scrolling page, so the search box and filters stay above the rows). Tapping a row with a `view_url` opens it in the in-app browser (`Browser::inApp`), like an alert; a row without one is not tappable.
- **Recent searches:** the last 10 distinct searches (text, type, office, period), newest first, labelled like "AFD · MOB · Past 7 days" (a search with no text and a type reads as the type's name). A search is saved when it runs and succeeds. Tapping one refills the box and filters and runs it. A Clear link empties the list. A search already in the list moves to the top.
- **States:** a loading indicator while searching; "No products match" for an empty result, with the filters left as they are; "You're offline. Reconnect to search." offline (the recents still show; results are not cached); a failure shows a message with Try again; 401 signs out as everywhere.
- **Cache:** `LocalCache` keeps the recent searches (and nothing else of this feature), wiped at sign-out with everything else. Results and the filter lists are never cached.

### Safety

Search text is sent as a query parameter to the WxNotify API only (never to a third party), is never logged by the app, and is never put in a deep link. Titles and offices are shown as native text.

## Testing

- **Backend** (`SearchProducts` and the endpoints): each filter alone and combined; `q` shorter than 2 characters (422); matching by catalog name, AWIPS ID prefix and WMO header; `%` and `_` treated literally; the office-code match (`MOB` finds `KMOB`, `HFO` finds `PHFO`, `SJU` finds `TJSJ`; a code that is not a known office is 422); every period; newest first with a stable cursor when times tie and a `limit` cap; test products hidden in production and shown otherwise; a product with no catalog entry or no slug (title fallback, null `view_url`); no bulletin text or other columns in the payload; `product-types` groups active and retired and leaves drafts; the app-token ability and a 401; the throttle.
- **App:** `ProductsScreen` states (opening, results, empty, error, offline, 401); the search triggers (Search button, a filter change, filters alone, clearing); paging; a row opening its page and a row without a link; the recent searches (saved on success, de-duplicated to the top, capped at 10, tapped to re-run, Clear, the sign-out wipe); the tab bar test updated to four tabs; a render check for the new elements; `native:validate` clean.
- **Device:** the Android emulator and the iOS simulator against the dev server (a text search, each filter, recents, opening a result, offline).

## Rules to record

In `.ai/rules/api.md`: `GET /products` returns only public product fields (never the bulletin text), requires at least 2 characters, caps the page, and is not logged with its query. In `.ai/rules/actions.md`: `SearchProducts` owns the product search query and mirrors `ViewProductController`'s production test-product rule.

## Out of scope

Searching the bulletin body or headlines; saved or favourite searches; recently opened products; server-side search history; a custom date range; filtering by family or category directly (only by a product); region or location filters; pushing a result into a rule or channel; offline results.

## Risks to settle in the plan

- **Archive size and query cost:** unmeasured (the dev database was unreachable at brainstorming time). Task 1 of the plan measures each filter combination on dev data and decides whether an index is needed.
- **Long select lists:** the Type list is the whole catalog and Office has about 120 entries. If the native select is awkward at that length on a device, the fallback is a pushed picker screen for each.
- **Office-code mapping:** `cccc` is the four-letter issuing centre; not every `cccc` is a forecast office (national centres such as `KWNS` have no office code and are found without the Office filter). The WFO filter is "issued by this office", not "about this area".
