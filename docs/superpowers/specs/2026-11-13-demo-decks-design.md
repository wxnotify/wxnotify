# Demo decks: design

Date: 2026-11-13. Status: draft for owner approval.

## Intent

The owner demonstrates the next-gen WxNotify system to other people and wants a short deck (at most 10 slides) that explains what WxNotify is and what it does, with screenshots of the subscriber and admin dashboards. The audience is usually technical (software engineers); sometimes it will be less technical. The goal for the technical audience, in the owner's words: they should see that it is a well-engineered system.

So there are two decks built from the same verified facts and the same screenshots, chosen per audience.

## Goals

- **Deck A, for engineers (10 slides).** One alert is followed from the weather feeds to the subscriber's phone. Each stop shows one concrete engineering decision and, where the product shows it, a real screenshot.
- **Deck C, for a broader audience (8 slides).** A plain-language product tour: what it does, how to use it, and why to trust it, with the same screenshots and no jargon.
- Every number or technical claim on a slide is checked against the repository or the running system when the deck is built.
- Screenshots come from the dev system's real data and are cropped or blurred where they show personal details.

## Non-goals

- A third "quality pillars" deck (considered, see Approaches).
- Marketing copy, pricing, roadmaps or anything the product does not do today.
- Video, live-demo scripts or speaker handouts beyond short speaker notes.

## Anti-goals

- A deck that reads as a sales pitch to the engineering audience.
- A claim that is aspirational or unverified. If it is not true today it is not on a slide.
- Screenshots that expose a real person's email, name or location, or any credential.

## Approaches considered

- **A. Follow one alert through the system.** Each slide is one stop on the path (feed, ingest and de-duplication, matching, queues, delivery, the phone). Concrete, has a narrative spine, and screenshots fit naturally. Chosen for the engineering audience.
- **B. Quality pillars** (reliability, correctness, operability, security, shipping). The most direct match to "well-engineered" but abstract and light on screens. Not built; two of its ideas (how it is tested, how it ships) close Deck A.
- **C. Product tour with callouts.** Easy to follow, reads as a pitch for engineers. Chosen as the second deck for less technical audiences, with the engineering callouts removed.

The owner picked A and C as two decks.

## Deck A: engineers (10 slides)

| # | Slide | Evidence on the slide |
| --- | --- | --- |
| 1 | WxNotify: weather feeds in, alerts people trust out | Subscriber dashboard screenshot |
| 2 | Why alerts are hard: latency, duplicate products, geo-matching, the cost of a wrong or repeated send | Short list, no screenshot |
| 3 | System map: feeds, ingest, match, queue, deliver, live | One diagram |
| 4 | Ingest: separate feed containers, the same product from two feeds de-duplicated | Admin: Pipeline Control and Ingested Products |
| 5 | Match: spatial matching in PostGIS, per-product rules and places | Subscriber: My Alerts, My Locations, Map |
| 6 | Queues: delivery runs ahead of everything else, no automatic retry of a billed send, long jobs isolated, timeouts below the retry window | Admin: Delivery Log; queue diagram |
| 7 | Delivery: 22 channel types behind one registry, SSRF guards on user-supplied URLs, secrets encrypted at rest | Subscriber: Notification Channels |
| 8 | Live and observable: Reverb streaming with a polling fallback, heartbeats and health checks | Subscriber: live Products; Admin: Application Health |
| 9 | Quality: over 3,600 Pest tests in 341 files, guard tests for known traps, an end-to-end run before release, the tested image is the published image | Numbers; a test-run line |
| 10 | Ships anywhere: one image for every PHP role, one server or four, open source (GPL-3.0-or-later), how to try it | Self-hosting quick start |

## Deck C: broader audience (8 slides)

1. WxNotify (title; subscriber dashboard).
2. What it does: weather alerts that reach you, wherever you are.
3. How it works in three steps: choose what you care about, we watch the feeds, you get alerted.
4. Your dashboard (screenshot: dashboard with charts and recent alerts).
5. Rules and places (screenshots: My Alerts, My Locations).
6. Alerts where you are: the channel list, with the live Products and Map shown beside it (screenshots).
7. Reliable by design, in plain words: we check ourselves, it is monitored, it keeps working if one part fails (Application Health screenshot).
8. Get started: hosted or run it yourself.

## Verified facts available today

Counted in the repository on 2026-11-13: 3,664 `test(` and `it(` cases in 341 Pest test files (datasets multiply these, so "over 3,600 tests" is safe); 22 channel types registered; 153 database migrations; 585 application classes; 53 API route declarations. These are re-counted when the deck is built; any claim about queue behaviour, de-duplication or security guards is read from the code or its tests, not from memory.

## Screenshot plan

Subscriber (dev system): Dashboard, Map, Products (live), My Alerts, My Locations, Notification Channels, Alert Templates. Admin (dev system): Dashboard, Pipeline Control, Ingested Products, Delivery Log, Application Health, Product Storage, Setup checklist. Captured at desktop width (the browser pane must be at least 1280 px wide; narrow panes render the mobile layout). Each image is checked for personal details (emails, names, locations) and cropped or blurred before use. The dev system's address and credentials never appear in a deck or in this repository.

## Format and constraints

Both decks are built as private slide artifacts that export to PowerPoint or PDF, one artifact per deck, with short speaker notes per slide. Dark theme to match the product. No more than 10 slides each.

## Decisions made without the owner (change if wrong)

- Deck C has 8 slides, not 10, so that it stays short for a non-technical audience.
- The deck says "22 channel types" and "over 3,600 tests", rounded down so they stay true as the code grows.
- The mobile app is not shown unless the owner wants it; it needs a separate simulator capture.

## Left to the builder

Visual layout, the diagram drawings, exact wording and speaker notes, which screenshot crops are used, and how each claim is phrased so that it is both true and short.
