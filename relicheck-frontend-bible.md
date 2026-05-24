---
title: "The ReliCheck Frontend Bible"
subtitle: "A complete reference for the marketing site"
date: "Generated 2026-05-19, currently Phase 165. Peer to the app bible (relicheck-bible.pdf)."
documentclass: article
fontsize: 11pt
geometry: margin=1in
toc: true
toc-depth: 2
numbersections: false
colorlinks: true
linkcolor: black
---

\newpage

# Overview

This bible covers the ReliCheck marketing site: the static HTML pages a visitor sees at `relichecksurvey.com` before they reach the application. It is a peer to the app bible, which covers `app.html` and the API surface behind it. The two documents do not overlap; if a question is about the in-app dashboards, the SSI math, or the analytics tabs, the app bible is the answer.

The marketing site is hand-edited HTML. There is no static site generator, no bundler, no build step. Every page is a self-contained HTML file with a small JS-injected nav at the top, a small JS-injected footer at the bottom, a single shared stylesheet, and a per-page `<style>` block for anything specific to that page. Cache busting is a query string (`?v=20260516-style`) on the stylesheet link.

Hosting is IONOS shared. Deploy is FileZilla against the document root. Database lives at `dbs15641829`, but the marketing site itself has no database dependency; everything it serves is flat HTML, CSS, JS, and images.

# Page inventory

As of Phase 165 the project root holds roughly 60 customer-facing HTML pages, plus subdirectories for samples, case studies, blog posts, help, dashboards, and a stale preview tree.

## Project root

**Marketing and product pages.** `index.html`, `overview.html`, `pricing.html`, `compare.html`, `about.html`, `404.html`, `blog.html`, `case-studies.html`, `samples.html`, `templates.html`, `suites.html`, `developers.html`, `accreditation.html`, `businesses.html`, `marketing.html`, `customer-feedback.html`, `education.html`, `hr-teams.html`, `research.html`, `program-evaluation.html`, `mixed-methods.html`, `360-surveys.html`, `tests-overview.html`.

**Resource and guide pages.** `methodology.html`, `reliability-guide.html`, `validity-guide.html`, `ai-features.html`, `help.html`, `survey-design-guide.html`, `survey-strength-index.html`, `pre-publish-check.html`, `import-data.html`, `import-survey.html`.

**Competitor pages.** `relicheck-vs-qualtrics.html`, `relicheck-vs-surveymonkey.html`.

**Legal.** `privacy.html`, `terms.html`.

**Auth and admin shells.** `login.html`, `signup.html`, `reset.html`, `accept-invite.html`, `accept-org-invite.html`, `accept-staff-invite.html`, `admin.html`, `admin-login.html`, `admin-reset.html`, `admin-setup-owner.html`, `admin-email.html`, `admin-check.html`, `admin-smoke.html`, `dashboard.html`.

**Stragglers and redirects.** `course-evaluation.html`, `research-evaluation.html`, `take.html`, `take-test.html`, `app.html` (the application itself, covered by the app bible).

**Drafts (must not deploy).** `index-draft.html`, `index-draft2.html`, `index-draft3.html`, `index-draft4.html`. These are working copies that ship to the live root if uploaded indiscriminately. Strip them out of any FileZilla upload set or move them into a non-deployed folder before shipping.

## /samples (6 files)

Self-contained sample report pages showing what a published ReliCheck dashboard looks like for a given use case. `course-evaluation.html`, `customer-feedback.html`, `hr-engagement.html` (added 2026-05-15), `hybrid-engagement.html`, `suite-rollup.html`, `interactive-course-evaluation.html`. The last one is the only JS-driven sample; the others are static report mocks.

## /case-studies (5 files)

`education-mba-program.html`, `hr.html`, `research.html`, `customer-feedback.html`, `mixed-methods.html`. Each one is the long-read detail behind the case-study teasers on the audience pages.

## /posts (8 files)

`reliability-101-in-five-minutes.html`, `reading-your-first-relicheck-report.html`, `what-a-reliability-number-tells-you.html`, `five-survey-design-mistakes.html`, `practical-ai-in-survey-work.html`, `validating-a-new-survey-scale.html`, `behind-the-mba-program-case-study.html`, `running-a-360-in-15-minutes.html`. The Learning Center on the homepage features three of these; the rest are reachable via `blog.html`.

## /help (8 files)

`getting-started.html`, `building-surveys.html`, `distributing.html`, `analyzing-reporting.html`, `exports-integrations.html`, `account-billing.html`, `privacy-compliance.html`, `troubleshooting.html`. Reached via the help center landing page (`help.html`).

## /dashboards (3 files)

Static dashboard mocks: `data-analysis.html`, `survey-assessment.html`, `test-analytics.html`. Not interactive; used as visual references and screenshot sources.

## /preview (7 files, stale)

Preview-era copies of `index/compare/pricing/methodology/reliability-guide/education/app.html` that still link the legacy `styles.css`. Nothing in the live nav or footer points at `/preview/`. Safe to ignore as a reader; should be deleted as a cleanup pass.

# Shared chrome

Two JS files inject the global nav and footer. Every page that uses them carries the same two-line include pattern.

## /assets/js/relicheck-nav.js

A 505-line self-contained IIFE. On load it detects the active route, injects a `<style>` block with the nav's styling, then injects `<header class="rc-nav">` into the `<div id="relicheck-nav"></div>` slot.

The rendered nav is a thin sticky white bar containing the ReliCheck logo (linked to `/logo-brand.svg`), a centered horizontal link list on desktop, and a hamburger drawer on mobile.

Top-level items, left to right:

| Item       | Type            | Contents                                                                                            |
|------------|-----------------|-----------------------------------------------------------------------------------------------------|
| Platform   | Mega panel      | Left panel: four product cards. Right side: Platform Features / Analysis Tools / Popular Templates. |
| Solutions  | Mega panel      | Suites / By Use Case / By Team.                                                                     |
| Templates  | Mega panel      | Three column list of survey templates.                                                              |
| Resources  | Mega panel      | Three column list of guides and learning content.                                                   |
| Pricing    | Direct link     | Goes to `pricing.html`.                                                                             |
| Sign in    | Right side      | Ghost-styled link to `login.html`.                                                                  |
| Start free | Right side CTA  | Orange filled button to `signup.html`.                                                              |

The mobile drawer mirrors the same data as a vertical accordion. All icons are inline SVG; the file ships no external image dependencies.

## /footer.js

A 75-line script. Injects `<footer class="site-footer">` into `<div id="footer-mount"></div>`.

Five columns: brand block (with tagline and white logo), Product, Solutions, Learn, Company. The Learn column carries the seven SEO-anchor links (Methodology, Reliability guide, Validity guide, AI features, Compare, Help, Blog). The bottom strip carries `(c) 2026 ReliCheck Survey. All rights reserved.` plus the brand line `Made for teams that need to explain their numbers.`

Every footer link uses an absolute path (`href="/index.html"`, `href="/overview.html"`, etc.) so the footer renders correctly regardless of page depth.

## Include pattern

Every page that uses the new chrome carries exactly these two pairs, at the top of body and after `</main>`:

```html
<div id="relicheck-nav"></div>
<script src="/assets/js/relicheck-nav.js" defer></script>

...

<div id="footer-mount"></div>
<script src="/footer.js" defer></script>
```

The script srcs are absolute (`/assets/...` and `/footer.js`) so the same line resolves correctly from any depth.

## Nav backlog

Two nav systems currently coexist in the source tree. The new injected nav (above) is used on 45 pages: all root marketing pages, both new audience pages, both new samples, all three dashboard mocks. An older inline `<header class="site-header">` block is still rendered on 30 pages: every page under `/help/`, every page under `/posts/`, every page under `/case-studies/`, four of the six samples (course-evaluation, customer-feedback, hybrid-engagement, suite-rollup), and the entire `/preview/` tree.

Until the backlog is cleared, any change to `relicheck-nav.js` shows up on roughly half the site. Migrating a page off the legacy nav is a four-line edit: delete the inline `<header class="site-header">` block, delete the matching inline nav script at the bottom, paste in the two-line nav include and the two-line footer include.

\newpage

# CSS system

## styles-2026.css

2,250 lines. Header comment at lines 1 through 14 names it "ReliCheck preview design system (2026 redesign)" and documents three container scales: `--container-app: 1440px`, `--container-content: 1280px`, `--container-survey: 820px`, plus five named breakpoints. The file itself has no version comment; cache busting is via the query string in every page's link tag (`?v=20260516-style`).

The full design system lives in `:root` between lines 17 and 132:

- **Containers and spacing scale.** `--container-app`, `--container-content`, `--container-survey`, plus a 1 to 32 spacing scale (`--s-1`, `--s-2`, etc.) and a clamped section rhythm (`--section-y` clamps 56 to 112 px).
- **Type scale.** Fluid type from `--text-xs` (14 px floor) through `--text-6xl`. The 14 px floor is the site-wide minimum since 2026-05-09; nothing below that ships.
- **Surfaces and ink.** `--bg`, `--bg-soft`, `--bg-muted` for backgrounds; `--ink-3` through `--ink-9` for type ramp; `--line` for borders.
- **Brand palette.** Navy (`--navy`, `--navy-2`, `--navy-3`), coral accent (`--accent: #e85d3a`, `--accent-hover`, `--accent-soft`, `--accent-tint`), teal and gold secondary, plus state colors (good, warn, bad).
- **Radii, shadows, motion.** `--r-sm` through `--r-pill`, `--shadow-xs` through `--shadow-xl`, ease curves (`--ease`), and three motion durations.
- **Type families.** `--font-sans: Inter`, `--font-display: Fraunces`, `--font-mono`.

## Major class families

| Family                       | Use                                                                  |
|------------------------------|----------------------------------------------------------------------|
| `.container-content` and friends | Top-level page width container. Sister classes for app and survey widths. |
| `.section`, `.section-soft`, `.section-tight`, `.section-ink` | Page section wrappers with vertical rhythm. |
| `.kicker`, `.lede`, `.section-head` | Small-caps eyebrow, hero lede paragraph, section header block. |
| `.btn`, `.btn-primary`, `.btn-outline`, `.btn-ghost`, `.btn-ink`, `.btn-lg`, `.btn-sm` | Button variants. |
| `.hero`, `.hero-grid`, `.hero-actions`, `.hero-visual` | Hero block on most pages. |
| `.cards-2`, `.cards-3`, `.card`, `.feature-card`, `.feat-card` | Card grids in two and three columns. |
| `.ssi2-*` (header, ring, tiles, breakdown) | The Survey Strength Index showcase block on the homepage and audience pages. |
| `.rssi-*`                    | Reliability SSI mini block used on resource pages. |
| `.cta-band`                  | Closing CTA strip used at the end of audience and resource pages. |
| `.cs-teaser-card`            | Case-study teaser linking to a long-read case study. |
| `.import-paths`, `.import-path-card` | The four-tile entry pattern on import pages. |
| `.site-header`, `.brand`, `.nav-primary`, `.nav-drawer` | Legacy nav classes used by the 30 pages still on inline nav. |
| `.site-footer`, `.footer-grid`, `.footer-bottom` | Footer chrome (rendered by `footer.js`). |
| `.prose`, `.callout`, `.article-header`, `.article-toc` | Resource and blog-post body chrome. |
| `.app`, `.app-side`, `.app-main`, `.app-topbar`, `.panel`, `.metric-row` | App-shell mocks for the dashboard pages. |

## Legacy /styles.css

Still present at the project root. Linked only from the seven preview pages under `/preview/`. The live site has fully migrated to `styles-2026.css`. The earlier rule that named six pages on `styles-2026.css` is stale; the count is now every live page.

\newpage

# Page types and conventions

## Homepage

`index.html`. Ten `<section>` blocks. Each section follows the same skeleton: `.container-content` wrapper, a `.section-head` with a `.kicker` eyebrow and an `<h2>`, then the content block (`.jobs3`, `.edu-list`, `.report-frame`, `.cards-3`, etc.). The hero uses `.hero` and `.hero-grid` instead of `.section-head`.

Current headline pattern, in render order:

1. Hero. "Survey numbers you can defend, in language anyone can read."
2. ReliCheck Survey Strength Index. "One score. Six domains. Clear next steps."
3. Three jobs. "Three jobs. One workspace."
4. Sample report (full bleed). "This is what a defended survey actually looks like."
5. Built for. "Five audiences. One credibility report."
6. Four questions. "The ones reviewers and decision-makers ask first."
7. AI that explains, not decides. "The statistics stay transparent. AI does the translation."
8. Founder credibility (anonymized). "For the moments when survey results carry real consequences."
9. Learning center. "The reliability questions everyone eventually has to answer."
10. Final CTA. "When your numbers get questioned, ReliCheck helps you answer."

The page is the canonical entry point and the single most important file in the marketing tree.

## Audience pages

`education.html`, `hr-teams.html`, `customer-feedback.html`, `research.html`, `program-evaluation.html`, `marketing.html`, `businesses.html`, `360-surveys.html`, `mixed-methods.html`. All share the same skeleton:

- `<section class="solution-hero">` with `.solution-hero-grid` and a hero image.
- One or two `<section>` blocks with `.feat-grid` of `<article class="feat-card">` blocks. Each card carries a `.tag` chip and a short headline plus paragraph.
- A `.cards-3` row of feature cards for templates, distribution, or analysis specifics.
- A nine-card deep analytics grid answering the buyer questions that audience cares about.
- A `.cs-teaser-card` linking to the matching case study under `/case-studies/`.
- A `.cta-band` closing band.

When adding a new audience page, copy `hr-teams.html` as the canonical model.

## Sample reports

`/samples/*.html`. Each is a self-contained report mock with a deep page-local `<style>` block that overrides the global ink and accent variables for a more report-like presentation. Static samples ship the footer-only script. The interactive sample (`interactive-course-evaluation.html`) is the only one with real client-side logic; it carries eight `<script>` blocks driving filter and interaction behavior.

Two of the six samples (hr-engagement, interactive-course-evaluation) are on the new nav include. The other four are still on the legacy inline `<header>` and are part of the nav backlog.

## Resource and guide pages

Resource pages (`methodology.html`, `reliability-guide.html`, `validity-guide.html`, `ai-features.html`, `compare.html`, `help.html`, `blog.html`, `about.html`, `samples.html`, `overview.html`, `pricing.html`, `import-data.html`, `import-survey.html`, `survey-strength-index.html`, `pre-publish-check.html`, `survey-design-guide.html`, `tests-overview.html`, `templates.html`, `suites.html`, `case-studies.html`) are long-form pages with their own typographic chrome (`.prose`, `.article-header`, `.article-toc`, `.callout`). Many include a sticky table of contents on desktop.

## Auth and admin shells

`login.html`, `signup.html`, `reset.html`, `accept-*.html`, `admin*.html`, `dashboard.html`. Form-heavy. The `dashboard.html` page is a thin shell that loads the app. These pages live outside the marketing voice; do not apply the marketing copy rules to them.

\newpage

# Image and asset conventions

Images live at the project root, not under `/assets/`. The only thing under `/assets/` is `relicheck-nav.js`.

Active marketing images: `hero-product.jpg`, `img-overview-mobile.jpg`, `img-hrteams-hero.jpg`, `img-education-hero.jpg`, `auth-hero.jpg`, `education.png`, `relicheck-coffee-hero.png`, `guy_coffee_shop-hero.png`, `relicheck_and_AI.png`, `relicheck ipad home.png`, `relicheck phone school.png`. The last two filenames contain spaces; URL-encode them in any new link rather than swapping the asset.

Branding assets: `favicon.svg`, `logo-brand.svg` (dark on light, used in the top nav), `logo-brand-white.svg` (white on dark, used in the footer). Raster fallbacks `logo-brand.jpg` and `logo-brand.png` exist in the root if a context cannot render SVG.

A backup file (`.bak-img-education-hero.jpg`) sits in the root from an earlier swap. Dead weight; safe to remove on the next cleanup pass.

# Link and asset rules

**Internal page links use relative paths without a leading slash.** Example from the homepage: `href="samples/course-evaluation.html"`, `href="education.html"`. This works because every linked page sits at the same depth (root). When adding a link from the homepage or an audience page, mirror this convention.

**Scripts use absolute paths.** `/assets/js/relicheck-nav.js` and `/footer.js`. This is deliberate so depth does not matter; the same include works whether the page lives at the root or under `/samples/`.

**Stylesheets use relative paths.** Root pages do `href="styles-2026.css?v=..."`. Nested pages do `href="../styles-2026.css?v=..."`. This is the one convention where depth matters; the include script does not adjust for nesting.

**Footer links use absolute paths.** The 26 hrefs inside `footer.js` are all absolute, so the footer renders correctly from any depth. Mirror the absolute path convention if you ever extend the footer.

# Trademark and voice rules

## Trademark

The Unicode trademark glyph is emitted as the HTML entity `&trade;`. Current usage:

- `index.html`: six occurrences, all on "Survey Strength Index" in the hero, meta description, SSI section kicker, SSI card title, and meta share descriptions.
- `survey-strength-index.html`: 23 occurrences across the canonical product page.
- `pre-publish-check.html`: two occurrences.
- `businesses.html`: one occurrence.

The mark has not been swept across other marketing pages (`methodology.html`, `ai-features.html`, `education.html`, audience pages other than businesses). If a future pass wants every visible mention of "Survey Strength Index" to carry the mark, that is a single grep-and-edit; the convention is `&trade;` directly after the noun phrase with no space.

## Voice rules

- **No em-dashes** in any marketing copy. Replace with commas, periods, parentheses, semicolons, or colons depending on context. Three live marketing pages still leak em-dashes in body or meta: `about.html` (three in meta), `program-evaluation.html` (one in body), `relicheck-vs-qualtrics.html` (one in body). Worth a sweep (TODO, still pending as of Phase 165).
- **The em-dashes in `pricing.html` are intentional.** They are no-value glyphs inside the pricing comparison matrix, not punctuation. Leave them, or swap them for `n/a` if a future pass wants to remove the dash entirely.
- **No anaphora.** Avoid consecutive sentences that begin with the same word unless the repetition is clearly earned.
- **No personification of abstractions.** Products acting (the app "shows", "scores", "tells") are fine; rooms and feelings with human-like agency are not.
- **Title separator is a middle dot (`·`).** Used in 29 `<title>` tags as the page-title separator. UTF-8 charset is declared on every page so the glyph renders cleanly.
- **ReliCheck Intelligence is a proper noun.** When referring to the AI feature, use `ReliCheck Intelligence`. Never write generic `AI` in body copy or UI labels. The short form `Intelligence` is allowed in inline labels (e.g., `Intelligence draft`, `Intelligence rewrites`, `Generate with Intelligence`). This rule applies to marketing pages, in-app copy, help articles, and any new asset.
- **App 2.0 in spoken/written copy.** The file is `app-2026.html`, but every user-facing mention reads "App 2.0". Treat `app-2026` as an internal label only.

## Terminology drift

The product brand is now consistently "Survey Strength Index". The earlier name "Survey Strength Score" still appears in ten places that are part of the backlog: `import-data.html` (two), `methodology.html` (four including a section H2), and four `/help/*` files (sidebar links). A future pass should normalize these to "Survey Strength Index". TODO, still pending as of Phase 165.

\newpage

# Build and deploy

There is no build step. Edit the HTML, save, upload.

## Deploy via FileZilla

The marketing site ships to IONOS via FileZilla against the document root. The same convention as the app side: replace files in place, no Git deploy, no CI. Upload only the files you changed plus their static dependencies.

Cache busting on the stylesheet is the query string in every page's `<link>` tag (`?v=20260516-style`). If `styles-2026.css` changes, bump the date stamp in every page that references it and upload both the stylesheet and the pages.

## Files that must not deploy

- `index-draft.html`, `index-draft2.html`, `index-draft3.html`, `index-draft4.html`. Working copies of the homepage. Reachable at `/index-draft.html`, etc. if uploaded.
- `.bak-img-education-hero.jpg`. Image backup file.
- The `/preview/` tree. Seven stale copies of key pages on the legacy `styles.css`. Nothing in the live nav references them; deploying them would expose old-design URLs to a crawler.

The cleanest practice is to keep these out of the upload set, not to delete them from the project.

## Asset paths on the live server

Document root is `relichecksurvey.com/`. Every absolute path in the source resolves against the document root (`/footer.js`, `/assets/js/relicheck-nav.js`, the footer's `/index.html`, etc.). The marketing domain is `relichecksurvey.com` even though the project folder is named `relicheck`.

## Build handoff format (hard rule)

Every build delivery to the owner follows the same three sections, in this order:

1. **Files to upload via FileZilla.** Repo-relative paths only (e.g., `index.html`, `assets/js/relicheck-nav.js`). No absolute `/Volumes/...` paths. No remote URLs. No "do not upload" callouts.
2. **SQL schema (inline copy-paste).** If the build touches the database, paste the full SQL in a fenced code block in the reply, starting with `USE dbs15641829;`. Marketing-only builds skip this section entirely rather than leaving an empty header.
3. **Server-side verification steps.** A numbered smoke-test checklist using visible UI, FileZilla columns, phpMyAdmin SQL, or Network-tab observation. Never ask the owner to paste JavaScript in the DevTools Console.

## Instructional copy rules

- **Use on-screen labels verbatim** in any step-by-step instruction. Copy headings, button text, and tab names exactly as they render. No paraphrasing.
- **App 2.0** in conversation and copy. `app-2026` is the internal/file label only.

# Per-page playbook

When making a change of any size, the same checklist applies. Run through it in this order.

1. **Identify the page type.** Homepage, audience page, sample report, resource page, auth shell. Each has a canonical model file documented above.
2. **Confirm which chrome the page uses.** Either the new injected nav (`<div id="relicheck-nav"></div>`) or the legacy inline `<header class="site-header">` block. Edits to the wrong chrome have no effect on the live page.
3. **Use the existing classes from `styles-2026.css` whenever possible.** Page-local `<style>` blocks should hold only what is genuinely unique to that page.
4. **Sweep voice and trademark.** No em-dashes. `&trade;` on the canonical product names if the surrounding copy uses them. ASCII-safe source.
5. **Update the cache buster** if the change touches `styles-2026.css`.
6. **Verify links resolve.** Internal hrefs are relative; spot-check that every target file exists.
7. **Upload only the changed files.** No batch deploys.

# Known drift and backlog

A frontend reader should know what is in process before assuming the live site is the source of truth.

- **Two nav systems coexist.** Forty-five pages on the new injected nav; thirty on the legacy inline nav. Every page under `/help/`, `/posts/`, `/case-studies/`, plus four of six samples and the full `/preview/` tree, are still on the legacy chrome. Migrating each is a four-line edit per file.
- **TODO: `Survey Strength Score` cleanup still pending.** As of Phase 165, the earlier name still appears in ten places: `import-data.html` (two), `methodology.html` (four including a section H2), and four `/help/*` files (sidebar links). Should be normalized to "Survey Strength Index".
- **`&trade;` is on four files**, not site-wide. Consistent treatment would be a single sweep.
- **TODO: em-dash leaks still pending** as of Phase 165 in `about.html` (three, meta), `program-evaluation.html` (one, body), `relicheck-vs-qualtrics.html` (one, body). The dashes in `pricing.html` are intentional comparison glyphs.
- **`/preview/` is a buried trap.** Stale copies of seven pages still link `styles.css`. Nothing in the live nav references them; recommend deletion on the next cleanup pass.
- **Four `index-draft*.html` files** live next to `index.html`. Not linked from anywhere but reachable at crawlable URLs if uploaded. Keep them out of the upload set.
- **Audience pages share `hero-product.jpg`** for OG share previews. If social sharing matters for SEO, each audience page should set its own og:image.
- **`mixed-methods.html` will need a marketing pass** once the Mixed-Methods Studio (Phases 155 through 165) exits beta. The current page predates the Studio and does not reflect the 20-step workflow, the Intelligence-powered rewrites, or the new exports.
- **"AI" sweep on marketing pages.** The Phase 156 rebrand only renamed strings inside `app-2026.html`. Marketing pages (`ai-features.html`, audience pages, etc.) still use generic "AI" in many places. A sweep to align with the proper-noun rule is open work.

# Useful references

- **App bible.** `relicheck-bible.pdf` and `relicheck-bible.md` at the project root. Covers `app.html`, the analytics tabs, the Stats library, the AI narrator pattern, the API surface, and the schema migrations.
- **Email system spec.** `email-system-specification.md` at the root. Covers the 11 departmental addresses, the dispatcher, and the templates.
- **Repositioning strategy.** `relicheck_repositioning_strategy.md` at the root. The brand and positioning brief that drove the recent homepage rewrites.
- **Memory.** `feedback_*.md` files in the memory directory carry the voice rules, no-em-dash rule, font-size floor, three-strikes rule, and other operating guidance.

End of document. Future bible reader: trust the source first, this document second. When the bible disagrees with the code, the code wins; flag the drift and update this file rather than reverse-engineering around it.
