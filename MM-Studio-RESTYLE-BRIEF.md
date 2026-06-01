# MM Studio — Restyle Brief for Claude Code

Read this fully before editing. This is a **restyle and light feature port onto an existing, working app that lives in the same repo as the donaldeastonbrooks.com website.** The current build already has the dataset connected and all calculation code in place. That code is the source of truth and must keep working.

There are two source-of-truth rules in this job, and they are equally important:

1. **The website's existing style is the source of truth for all visual design.** The studio must inherit the site's look, not adopt a new one. Same fonts, same colors, same header treatment, same spacing rhythm. The studio is part of the site and must feel like it.
2. **The existing app's data and calculation layer is the source of truth for all numbers.** Do not rebuild the app. Do not touch the data wiring or the statistics.

## On style: inherit, do not recreate

Because the studio lives in the same repo as the website and shares components, you must **reuse the site's existing design system directly**, not retype it.

- Find where the site defines its design tokens: the Tailwind config, the global CSS variables, any theme file, and the shared layout and header components. Use those exact tokens and components. Import them; do not copy their values into a new file.
- The studio's fonts, color palette, header, and chrome all come from the site. If the site uses a shared `<Header>`, `<Layout>`, or token set, the studio uses the same ones.
- If a studio-specific need has no existing token (see strand tags below), add it to the **existing** token source so it stays in one place, rather than starting a parallel system.
- Do not introduce fonts or colors that are not already in the site's system, except the strand-tag colors, and tune even those to sit inside the site's palette.

**The reference file `relicheck-mm-studio-fused-shell.html` is a LAYOUT reference only.** Copy its *structure* (the document-first page surface, the six-stage tab spine, the alphabetical rail with tags, the Report Draft inspector). Do **not** copy its fonts or colors. It uses Newsreader, Hanken Grotesk, purple, and orange as placeholders; replace all of that with the site's real typefaces and palette. If the reference and the site disagree on a font or a color, the site wins, every time.

## On data: leave it alone

Dataset connection, descriptive output, frequencies, group summaries, inferential math, qualitative coding logic. If a change would alter what a number is or how it is computed, stop and ask. This brief changes layout and adds one thread. It does not change what anything computes.

## Before you change anything

1. Run the app and confirm it works as-is.
2. Create a branch (for example `restyle/document-first`).
3. Map two things and report back before editing:
   - **The site's design system:** where tokens, fonts, theme, and shared layout/header components live, and how the existing site pages consume them.
   - **The studio's current structure:** where the shell, stage tabs, rail, and page content live, and where the dataset and calculations are wired.
   Confirm the surface you will touch (layout, chrome) versus the core you will not (data, math).

## What to port (structure + one thread, using the site's style)

### 1. Document-first layout, in the site's styling
Give each page the Pages-style structure from the reference: an uppercase eyebrow (`Stage · question`), a large title in the **site's** display face, a description paragraph, a data-context strip (`250 rows · 32 variables`), then the existing content wrapped in cards. Wrap the existing output; do not regenerate it. All type and color come from the site's tokens.

### 2. The six-stage spine and alphabetical rail
Stages as top tabs (Overview, Instrument Quality, Descriptive Analysis, Inferential Analysis, Interpretation, Reporting), each with its plain-language question. The left rail lists each stage's sub-pages alphabetically, opening on the natural first page. Active states use the site's existing accent color, not a new one.

### 3. Strand tags (the one new visual element)
The rail items carry strand tags so the strands read as equals and quantitative is never the silent default:
- QUAN, QUAL, and MM are peers; tag every analysis page by strand. MM is for genuinely mixed pages (Item/Theme Summaries, Qual → Quant, Joint Displays, Convergence & Divergence, Meta-inferences).
- RSSI is a source marker, not a strand; style it neutrally.
- Helper and synthesis pages (Recommended Analyses, Overview/Interpretation/Reporting pages) get no tag.
Pick the four tag colors from or harmonized with the site's palette. Add them to the site's token source. Do not import the reference's teal/rose/purple if they clash with the site.

### 4. The Report Draft thread (add if missing)
A Save to Report action on each analysis page, and a Report Draft inspector on the right listing saved analyses grouped by stage, feeding the Report Builder's Findings section, with removal from either place. Style it in the site's system. Use the site's existing primary or call-to-action color for the Save button rather than inventing one. Keep it independent from any existing promote-to-integration / joint-display action; they are two separate threads.

## What NOT to do
- Do not create a separate design system or token file for the studio.
- Do not use the reference file's fonts or colors.
- Do not replace the dataset or re-derive any statistic.
- Do not change the RSSI boundary: RSSI is instrument credibility only, shown read-only in Instrument Quality, never computing descriptive or inferential output.

## Voice for any copy you generate
No em dashes. No personification (data does not "tell" or "reveal"). No unintentional anaphora. Formal academic register. APA stats style (no leading zero on r and p, t with df).

## Suggested order
1. Confirm the site's token/theme/component source, wire the studio to consume it, confirm nothing breaks.
2. Restyle shell chrome (toolbar, tabs, rail) using the site's components and tokens.
3. Wrap page content in the document-first surface, one stage at a time, starting with Overview, in the site's styling.
4. Strand tags on the rail, colors added to the site's token source.
5. Report Draft thread.
6. Walk every stage and confirm all existing calculations still render correctly, and that the studio visually matches the rest of the site side by side.

Work in small commits, one numbered step per commit. After step 1, open a site page and a studio page side by side and confirm they read as the same product before going further.
