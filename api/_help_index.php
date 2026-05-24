<?php
// Phase 150: shared Help-article index loader.
//
// Single source of truth for the in-app Help Center. Mirrors the
// __HELP_INDEX array that powers the public help.html marketing page.
// Stored inline rather than in the database because the catalog is
// tiny (37 articles), changes rarely, and ships with the build.
//
// Each entry:
//   t  - category title (display)
//   s  - category slug (matches /help/<s>.html on the marketing site)
//   q  - article question (display title)
//   a  - one-paragraph answer (search target + result snippet)
//   h  - heading anchor inside the marketing /help/<s>.html page

declare(strict_types=1);

function help_index(): array
{
    return [
        ['t' => 'Getting started', 's' => 'getting-started',
            'q' => 'How do I create my first survey?',
            'a' => 'Sign in, click New survey, then pick either a blank survey or one of the validated templates. Add Likert, single-choice, multi-select, or open-ended items, set your scale anchors, then publish to get a share link.',
            'h' => 'how-do-i-create-my-first-survey'],
        ['t' => 'Getting started', 's' => 'getting-started',
            'q' => "What's the fastest way to get started?",
            'a' => 'Start from a template. The Course Evaluation, Employee Engagement, and Customer Satisfaction templates each ship with reviewed items and the right scale defaults, so you can launch in a few minutes.',
            'h' => 'what-s-the-fastest-way-to-get-started'],
        ['t' => 'Getting started', 's' => 'getting-started',
            'q' => 'Do respondents need an account?',
            'a' => 'No. Respondents open your share link, fill out the survey, and submit. No sign-up, no app, no friction. The link can be public or restricted by email allowlist depending on your settings.',
            'h' => 'do-respondents-need-an-account'],
        ['t' => 'Getting started', 's' => 'getting-started',
            'q' => 'What devices does ReliCheck support?',
            'a' => 'Any modern browser. The respondent experience is mobile-first, so phone, tablet, and desktop all work cleanly. The author and analytics dashboards are designed for desktop and tablet.',
            'h' => 'what-devices-does-relicheck-support'],

        ['t' => 'Building surveys', 's' => 'building-surveys',
            'q' => 'What question types are available?',
            'a' => 'Likert (5- or 7-point agreement or frequency), single-choice (radio), multi-select (checkbox), and open-ended (free text). Each type has its own settings for required-vs-optional, reverse-scoring, and skip logic.',
            'h' => 'what-question-types-are-available'],
        ['t' => 'Building surveys', 's' => 'building-surveys',
            'q' => 'How do I use skip logic?',
            'a' => 'On the question editor, click Skip logic, pick the trigger (a previous answer), and choose what happens (skip to a question, end the survey, or show a follow-up). Logic is visible from the survey overview so you can audit the flow.',
            'h' => 'how-do-i-use-skip-logic'],
        ['t' => 'Building surveys', 's' => 'building-surveys',
            'q' => 'Can I import a survey from somewhere else?',
            'a' => 'Yes. Datasets uploaded as CSV or XLSX become a ReliCheck survey automatically: column headers become items, rows become responses. The mapping wizard lets you mark each column as Likert, single, multi, or open before importing.',
            'h' => 'can-i-import-a-survey-from-somewhere-else'],
        ['t' => 'Building surveys', 's' => 'building-surveys',
            'q' => 'How does AI question review work?',
            'a' => 'The AI reviewer flags double-barreled, leading, vague, and double-negative items as you write them. It does not change your wording; it suggests revisions you can accept, reject, or edit. The review is described in detail on the AI features page.',
            'h' => 'how-does-ai-question-review-work'],
        ['t' => 'Building surveys', 's' => 'building-surveys',
            'q' => 'Can I brand the survey?',
            'a' => 'Yes. Add your logo, accent color, and a custom thank-you message in survey settings. Business plans also support a custom domain (e.g. survey.yourorg.com).',
            'h' => 'can-i-brand-the-survey'],

        ['t' => 'Distributing surveys', 's' => 'distributing',
            'q' => 'What distribution channels are supported?',
            'a' => 'Public link, email invitations, QR code (auto-generated for posters and slides), and embed code for your own website. Each channel can have its own response cap and access window.',
            'h' => 'what-distribution-channels-are-supported'],
        ['t' => 'Distributing surveys', 's' => 'distributing',
            'q' => 'Can I restrict who can respond?',
            'a' => 'Yes. Restrict by email allowlist, single-use tokens, or domain (e.g. only addresses ending in @yourschool.edu). Combine with response caps to control sample size precisely.',
            'h' => 'can-i-restrict-who-can-respond'],
        ['t' => 'Distributing surveys', 's' => 'distributing',
            'q' => 'Can the same person respond twice?',
            'a' => 'By default, yes (anonymous responses are not deduplicated). Turn on single-use tokens or require sign-in to enforce one response per person.',
            'h' => 'can-the-same-person-respond-twice'],

        ['t' => 'Analyzing and reporting', 's' => 'analyzing-reporting',
            'q' => 'How are reliability statistics computed?',
            'a' => "Cronbach's alpha, split-half, KMO, item-total correlations, and missing-data summaries are computed per scale on complete-case respondents. Full formulas live on the methodology page.",
            'h' => 'how-are-reliability-statistics-computed'],
        ['t' => 'Analyzing and reporting', 's' => 'analyzing-reporting',
            'q' => 'What does an alpha of 0.83 mean?',
            'a' => '0.83 is in the strong band (>= 0.80). The items in that scale are measuring one thing consistently. Read the reliability guide for thresholds, confidence intervals, and the caveats every researcher should know.',
            'h' => 'what-does-an-alpha-of-0-83-mean'],
        ['t' => 'Analyzing and reporting', 's' => 'analyzing-reporting',
            'q' => 'What is item-total correlation?',
            'a' => 'The correlation between an individual item and the rest of the scale (the corrected version excludes the item from the total). An item below 0.20 is a weak contributor and worth reviewing.',
            'h' => 'what-is-item-total-correlation'],
        ['t' => 'Analyzing and reporting', 's' => 'analyzing-reporting',
            'q' => 'How does AI theme extraction work?',
            'a' => 'Open-ended responses are clustered into 3 to 8 themes with counts and example quotes. Themes are generated on demand and never stored without your approval. The model never sees response IDs or any other PII.',
            'h' => 'how-does-ai-theme-extraction-work'],
        ['t' => 'Analyzing and reporting', 's' => 'analyzing-reporting',
            'q' => 'Can I see results in real time?',
            'a' => 'Yes. Reliability statistics, item flags, and theme summaries refresh as new responses arrive. Statistics requiring a minimum sample (alpha needs at least 30 complete-case respondents) display a count instead until the threshold is met.',
            'h' => 'can-i-see-results-in-real-time'],

        ['t' => 'Exports and integrations', 's' => 'exports-integrations',
            'q' => 'How do I export to SPSS?',
            'a' => "Open the survey's Reports tab, choose Export, and select SPSS .sav. Variable and value labels, plus reverse-scoring, are preserved automatically.",
            'h' => 'how-do-i-export-to-spss'],
        ['t' => 'Exports and integrations', 's' => 'exports-integrations',
            'q' => 'What other export formats are available?',
            'a' => 'PDF report, Word document, Excel workbook, raw CSV, SPSS .sav, R-compatible bundle (CSV plus an .R script with labels), and Stata bundle (CSV plus a .do file with labels).',
            'h' => 'what-other-export-formats-are-available'],
        ['t' => 'Exports and integrations', 's' => 'exports-integrations',
            'q' => 'Can I send results to Google Sheets or Drive?',
            'a' => "Yes, on every plan. Connect your Google account once in Account > Integrations, then use the Send to Sheets or Save to Drive options on any survey's Reports tab.",
            'h' => 'can-i-send-results-to-google-sheets-or-drive'],
        ['t' => 'Exports and integrations', 's' => 'exports-integrations',
            'q' => 'Is there an API?',
            'a' => 'Yes, on Business plans. The REST API supports listing surveys, fetching responses, creating bearer tokens, and pushing dataset uploads. Developer docs are at /developers.',
            'h' => 'is-there-an-api'],

        ['t' => 'Privacy and compliance', 's' => 'privacy-compliance',
            'q' => 'What anonymity protections does ReliCheck offer?',
            'a' => 'Anonymous mode hides respondent identifiers from the dashboard. K-anonymity suppression on group rollups protects small subgroups from re-identification (groups below the threshold show counts only, never breakdowns).',
            'h' => 'what-anonymity-protections-does-relicheck-offer'],
        ['t' => 'Privacy and compliance', 's' => 'privacy-compliance',
            'q' => 'Where is data stored?',
            'a' => 'Default storage is in IONOS data centers in the EU. US-region storage is available on Business plans. Survey content is encrypted at rest with per-tenant key separation.',
            'h' => 'where-is-data-stored'],
        ['t' => 'Privacy and compliance', 's' => 'privacy-compliance',
            'q' => 'Is ReliCheck GDPR compliant?',
            'a' => 'Yes. ReliCheck offers a Data Processing Agreement (DPA) on request, EU data residency by default, encryption in transit and at rest, data-subject access request (DSAR) tooling on every account, documented sub-processor lists, and breach-notification procedures aligned with Article 33. Full details are in the privacy policy.',
            'h' => 'is-relicheck-gdpr-compliant'],
        ['t' => 'Privacy and compliance', 's' => 'privacy-compliance',
            'q' => 'Is ReliCheck FERPA compliant?',
            'a' => 'Yes. Education customer data is treated as an education record under FERPA. ReliCheck signs as a designated school official under the school official exception when required, with access controls, audit logging, and customer-specific FERPA agreements available on Team and Institution plans.',
            'h' => 'is-relicheck-ferpa-aligned'],
        ['t' => 'Privacy and compliance', 's' => 'privacy-compliance',
            'q' => 'What about SOC 2?',
            'a' => 'SOC 2 Type II audit is currently underway, with the Type I observation period complete. Current audit status, the audit firm, and the Type I report are available to enterprise prospects under NDA via sales.',
            'h' => 'what-about-soc-2'],

        ['t' => 'Account and billing', 's' => 'account-billing',
            'q' => 'How do I add teammates?',
            'a' => 'Professional and Business plans include team sharing. Invite from Account > Team. Roles include admin, editor, and viewer.',
            'h' => 'how-do-i-add-teammates'],
        ['t' => 'Account and billing', 's' => 'account-billing',
            'q' => 'Can I share results with non-account holders?',
            'a' => 'Yes. Reports can be shared via a read-only link, and PDF / Word / Excel / SPSS exports work for any audience without an account.',
            'h' => 'can-i-share-results-with-non-account-holders'],
        ['t' => 'Account and billing', 's' => 'account-billing',
            'q' => 'How do I change plans?',
            'a' => 'Go to Account > Billing > Change plan. The change takes effect immediately, with a prorated charge or credit on your next invoice.',
            'h' => 'how-do-i-change-plans'],
        ['t' => 'Account and billing', 's' => 'account-billing',
            'q' => 'How do I cancel my subscription?',
            'a' => 'Account > Billing > Cancel. Your access continues through the end of the current paid period. Survey content is retained per the retention policy.',
            'h' => 'how-do-i-cancel-my-subscription'],
        ['t' => 'Account and billing', 's' => 'account-billing',
            'q' => 'Do you offer education or non-profit pricing?',
            'a' => 'Yes. Educators, students, verified non-profits, and government use cases get up to 30% off all paid tiers. Contact sales for verification and a promo code.',
            'h' => 'do-you-offer-education-or-non-profit-pricing'],
        ['t' => 'Account and billing', 's' => 'account-billing',
            'q' => 'Can I get an invoice instead of paying by card?',
            'a' => 'Yes, on Business plans. Annual contracts can be invoiced with net-30 or net-45 terms. Email sales@relichecksurvey.com to set up.',
            'h' => 'can-i-get-an-invoice-instead-of-paying-by-card'],

        ['t' => 'Troubleshooting', 's' => 'troubleshooting',
            'q' => 'Why is alpha not showing for my scale?',
            'a' => 'Reliability statistics need at least three valid items and at least 30 complete-case respondents. Below those thresholds, ReliCheck shows the count and a sample-size note instead of an estimate.',
            'h' => 'why-is-alpha-not-showing-for-my-scale'],
        ['t' => 'Troubleshooting', 's' => 'troubleshooting',
            'q' => 'My alpha is unexpectedly low. What now?',
            'a' => 'Check three things: are reverse-scored items flagged, do all items measure the same construct, and is the sample large enough? Item-total correlations point at weak contributors. The reliability guide pitfalls covers the most common causes.',
            'h' => 'my-alpha-is-unexpectedly-low-what-now'],
        ['t' => 'Troubleshooting', 's' => 'troubleshooting',
            'q' => 'I uploaded a CSV and the import failed.',
            'a' => 'The mapping wizard requires column headers in row 1 and at least one Likert, single-choice, or open-ended column. Files over 10MB or with more than 50,000 rows need to be split. CSVs with mixed delimiters (comma plus semicolon) need cleaning first.',
            'h' => 'i-uploaded-a-csv-and-the-import-failed'],
        ['t' => 'Troubleshooting', 's' => 'troubleshooting',
            'q' => 'Respondents say the share link does not work.',
            'a' => "Check three things: is the survey published (not just saved), is the access window open, and is the response cap not exceeded. Settings under the survey's Distribute tab show all three at a glance.",
            'h' => 'respondents-say-the-share-link-does-not-work'],
        ['t' => 'Troubleshooting', 's' => 'troubleshooting',
            'q' => 'The AI features are not responding.',
            'a' => 'AI features need an API key configured server-side. If you self-host or your admin has not set anthropic_api_key in the platform config, AI features return a clear "AI features are not configured" message. Contact your admin or support.',
            'h' => 'the-ai-features-are-not-responding'],
    ];
}

function help_categories(): array
{
    $cats = [];
    foreach (help_index() as $row) {
        $slug = $row['s'];
        if (!isset($cats[$slug])) {
            $cats[$slug] = ['slug' => $slug, 'title' => $row['t'], 'count' => 0];
        }
        $cats[$slug]['count']++;
    }
    return array_values($cats);
}
