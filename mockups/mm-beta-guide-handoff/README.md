# Mixed-Methods Studio · Beta User Guide — Design Handoff

This folder is the design handoff package for the **Mixed-Methods Studio beta user guide**. The closed beta launches with a small cohort of dissertation-level researchers and mixed-methods faculty. The guide is what they read once before they start using the studio for real research.

The existing files (working, but unpolished) live at:

- `mm-beta-guide.html` — public HTML page at `relichecksurvey.com/mm-beta-guide.html`
- `mm-beta-guide.pdf` — downloadable PDF, currently auto-generated from ReportLab

Both files work end-to-end. The brand mark, color palette, and content are in place. **What we need from Design:** make it look like a polished product deliverable, not a developer's first draft.

---

## What the guide needs to do

A dissertation candidate or a mixed-methods professor opens this guide once before they touch the studio. They are not novices to research methods, but they may be new to software-assisted mixed-methods. The guide should:

1. Make them feel they have been admitted to something small and well-thought-out (closed beta, real work welcome, your feedback shapes it)
2. Show them the three-phase workflow at a glance
3. Walk them through each phase with a screenshot
4. Give them citation language they can drop into a methods section
5. Tell them how to send feedback and where to go if something breaks

The guide should *not* feel like a corporate marketing page. The tone is direct, technical when needed, never breathless.

---

## Files in this handoff

- `README.md` — this file
- `mm-studio-demo-engagement.csv` — synthetic workplace-engagement dataset (51 rows, 5 columns) used to populate the studio for screenshots. Bring your own data if you want a different domain.
- `screenshots-needed.md` — exact screenshots the guide needs, with the views and URLs to reach them
- `brand-tokens.md` — colors, type, spacing extracted from the existing site
- `current-guide-structure.md` — section-by-section content map so you know what each section is for
- `voice-rules.md` — voice / copy rules to keep consistent with the rest of the ReliCheck site

The current source files are next to this folder:

- `../../mm-beta-guide.html`
- `../../mm-beta-guide.pdf`
- `../../logo-brand.svg`
- `../../logo-brand-white.svg`

---

## Brand foundations (already established)

- **Logo:** `logo-brand.svg` (dark version) and `logo-brand-white.svg` (white version on dark backgrounds)
- **Type:** Inter for UI/body, Fraunces (serif) for display
- **Primary color (deep purple):** `#1e1b4b` (Studio brand)
- **Mid purple:** `#312e81`
- **Light purple:** `#4338ca`
- **Accent (warm orange, ReliCheck primary):** `#e85d3a`
- **Ink scale:** `#1a1f2b` to `#8892a6`
- **Soft blue panel bg:** `#f3f6fb` with border `#d6def0`
- **Soft callout bg (yellow):** `#fff8e6` with left border `#d9a800`

See `brand-tokens.md` for the full token set.

---

## What good looks like

Think of this as a one-week onboarding doc you would hand to a new dissertation advisee. Confident, plain, useful. Not "Welcome aboard!" enthusiasm; not enterprise-software stiffness.

The PDF should print well on US Letter, ~6 pages, with a real cover treatment instead of the current colored band. Print-friendly: no full-bleed gradients unless we are willing to pay for the ink.

The HTML should match the rest of the site's reading experience, with the same nav, footer, and type rhythm.
