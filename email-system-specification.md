# ReliCheck Email Notification System

**Implementation-ready developer specification**

Version 1.0, May 10, 2026
Owner: Dr. Donald Easton-Brooks
Stack target: PHP 8.x / MySQL on IONOS, integrated with the existing ReliCheck application and admin panel.

---

## Table of contents

1. Overview and privacy rule
2. Section A: Email Department Map
3. Section B: Customer Email Trigger Table
4. Section C: Employee / Admin Email Trigger Table
5. Section D: Customer Email Timelines
6. Section E: Employee / Admin Email Timelines
7. Section F: Email Template System (with launch templates)
8. Section G: Backend Logic
9. Section H: Customer Email Preferences
10. Section I: Employee Notification Preferences
11. Section J: Database Structure
12. Section K: Admin Panel Requirements
13. Section L: Email Sending Rules
14. Section M: Email Copy Rules
15. Section N: Launch Priority Phasing
16. Appendix: Implementation notes

---

## 1. Overview and privacy rule

ReliCheck sends two distinct streams of email:

1. **Customer-facing emails**: account, survey, membership, billing, support, privacy, legal, marketing, sales.
2. **Employee / admin-facing emails**: HR, support workflow, membership / billing actions, services tasks, privacy / legal escalations, sales pipeline alerts.

Every system-generated email is dispatched through one of eleven official ReliCheck departments. No other sender addresses (such as `noreply@`, `info@`, `contact@`, `hello@`, `admin@`, `notifications@`) are permitted unless explicitly authorized.

### Hard privacy rule

ReliCheck employees, customer service staff, and admin panel viewers must never receive or be able to view, through email or email logs:

- Private survey responses
- Respondent-level data
- Private survey results
- Uploaded survey files
- Customer AI-generated analysis

Employee and admin emails reference only:

- Customer account identifiers
- Membership status
- Billing status
- Support ticket status
- Survey project status (project name, ID, state, counts)
- Service request status

A future "elevated review" permission level (`PERM_PRIVATE_DATA_ACCESS`) may be added so that, with the customer's explicit grant, designated staff can view restricted data through a separate channel. The standard email system never carries this data by default.

---

## 2. Section A: Email Department Map

| # | Department | Display name | Sender email address | Used for | Audience | Email class |
|---|---|---|---|---|---|---|
| 1 | HR | ReliCheck HR | hr@relichecksurvey.com | Employee account lifecycle, onboarding, role / access changes | Employee | Operational |
| 2 | Support | ReliCheck Support | support@relichecksurvey.com | Customer help requests, ticket lifecycle, password / login help, support workflow alerts | Both | Transactional / operational |
| 3 | Legal | ReliCheck Legal | legal@relichecksurvey.com | Formal legal notices, legal escalations, threat-of-action handling | Both | Legal |
| 4 | Welcome | ReliCheck Welcome | welcome@relichecksurvey.com | New customer onboarding, account introduction, getting-started guidance | Customer | Transactional |
| 5 | Marketing | ReliCheck Marketing | marketing@relichecksurvey.com | Newsletters, product education, promotional campaigns, feature announcements | Both | Marketing |
| 6 | Membership | ReliCheck Membership | membership@relichecksurvey.com | Plans, upgrades, downgrades, trials, cancellations, promo codes | Both | Operational |
| 7 | Services | ReliCheck Services | services@relichecksurvey.com | Survey lifecycle, reports, AI insights, service task delivery | Both | Operational |
| 8 | Privacy | ReliCheck Privacy | privacy@relichecksurvey.com | Privacy policy changes, data exports, deletions, suspicious login alerts, privacy escalations | Both | Privacy |
| 9 | Terms | ReliCheck Terms | terms@relichecksurvey.com | Terms of service updates, terms acceptance prompts | Both | Legal |
| 10 | Sales | ReliCheck Sales | sales@relichecksurvey.com | Demo requests, quotes, institutional pricing, upgrade opportunities, lead alerts | Both | Sales |
| 11 | Billing | ReliCheck Billing | billing@relichecksurvey.com | Receipts, invoices, payment failures, refunds, payment method updates | Both | Billing |

---

## 3. Section B: Customer Email Trigger Table

Priority levels: **P0** = critical security / billing / legal. **P1** = high-value transactional. **P2** = operational / activity. **P3** = marketing / educational.

| Trigger event | Email name | Department sender | Sender address | Recipient | Timing | Purpose | Priority | Required / Optional | Unsubscribable | Destination |
|---|---|---|---|---|---|---|---|---|---|---|
| Customer creates account | Welcome / Verify Email | Welcome | welcome@relichecksurvey.com | Customer | Immediate | Confirm signup and verify email | P0 | Required | No | /verify?token= |
| Email verified | Account Confirmed | Welcome | welcome@relichecksurvey.com | Customer | Immediate | Confirm activation, route into product | P1 | Required | No | /dashboard |
| First successful login | Getting Started Guidance | Welcome | welcome@relichecksurvey.com | Customer | Within 5 min of first login | Orient new user, link to first survey | P2 | Required | No | /surveys/new |
| Password reset requested | Password Reset | Support | support@relichecksurvey.com | Customer | Immediate | Provide single-use reset link | P0 | Required | No | /reset?token= |
| Password changed | Password Changed | Support | support@relichecksurvey.com | Customer | Immediate | Confirm change, alert if not them | P0 | Required | No | /security |
| Account locked (failed logins) | Account Locked | Support | support@relichecksurvey.com | Customer | Immediate | Notify lockout and unlock path | P0 | Required | No | /unlock |
| New device / location login | New Login Alert | Privacy | privacy@relichecksurvey.com | Customer | Immediate | Security awareness, secure-account link | P0 | Required | No | /security/sessions |
| First survey created | First Survey Created | Services | services@relichecksurvey.com | Customer | Immediate | Celebrate, link to launch checklist | P2 | Required | No | /surveys/{id}/edit |
| Survey published | Survey Is Live | Services | services@relichecksurvey.com | Customer | Immediate | Confirm launch, share link | P1 | Required | No | /surveys/{id} |
| Survey scheduled to launch | Survey Scheduled | Services | services@relichecksurvey.com | Customer | Immediate after scheduling | Confirm scheduled time | P2 | Required | No | /surveys/{id} |
| Survey auto-closes | Survey Closed (auto) | Services | services@relichecksurvey.com | Customer | Immediate at close | Confirm closure, next steps | P1 | Required | No | /surveys/{id}/results |
| Customer manually closes | Survey Closed | Services | services@relichecksurvey.com | Customer | Immediate | Confirm action | P1 | Required | No | /surveys/{id}/results |
| Response milestone hit | Milestone Reached | Services | services@relichecksurvey.com | Customer | Real-time | Engagement signal | P2 | Optional | Yes (activity) | /surveys/{id} |
| Low response rate | Low Response Rate | Services | services@relichecksurvey.com | Customer | After 2-3 days | Suggest distribution boost | P2 | Optional | Yes (activity) | /surveys/{id}/distribute |
| Zero responses | No Responses Yet | Services | services@relichecksurvey.com | Customer | After 3 days | Help re-distribute | P2 | Optional | Yes (activity) | /surveys/{id}/distribute |
| Report generated | Report Ready | Services | services@relichecksurvey.com | Customer | Immediate | Notify report available | P1 | Required | No | /surveys/{id}/report |
| AI insights complete | AI Insights Ready | Services | services@relichecksurvey.com | Customer | Immediate | Notify insights available | P1 | Required | No | /surveys/{id}/insights |
| Customer requests setup help | Service Request Received (setup) | Services | services@relichecksurvey.com | Customer | Immediate | Acknowledge request | P1 | Required | No | /support/tickets/{id} |
| Customer requests results help | Service Request Received (results) | Services | services@relichecksurvey.com | Customer | Immediate | Acknowledge request | P1 | Required | No | /support/tickets/{id} |
| Trial starts | Trial Started | Membership | membership@relichecksurvey.com | Customer | Immediate | Confirm trial, set expectations | P1 | Required | No | /dashboard |
| Trial halfway | Trial Midpoint | Membership | membership@relichecksurvey.com | Customer | Day N/2 | Encourage activation | P2 | Optional | Yes (membership-promo only) | /pricing |
| Trial ending soon | Trial Ending Soon | Membership | membership@relichecksurvey.com | Customer | 2 days before end | Reminder | P1 | Required | No | /billing/upgrade |
| Trial expired | Trial Expired | Membership | membership@relichecksurvey.com | Customer | Immediate at expiry | Notify and convert | P1 | Required | No | /billing/upgrade |
| Customer upgrades | Membership Upgraded | Membership | membership@relichecksurvey.com | Customer | Immediate | Confirm new plan | P1 | Required | No | /billing |
| Customer downgrades | Membership Changed | Membership | membership@relichecksurvey.com | Customer | Immediate | Confirm change, effective date | P1 | Required | No | /billing |
| Customer cancels | Cancellation Confirmation | Membership | membership@relichecksurvey.com | Customer | Immediate | Confirm cancel, retention path | P1 | Required | No | /billing |
| Plan expires | Plan Expired | Membership | membership@relichecksurvey.com | Customer | Immediate | Notify and offer renew | P1 | Required | No | /billing |
| Access level changes | Access Level Updated | Membership | membership@relichecksurvey.com | Customer | Immediate | Confirm new access | P1 | Required | No | /account |
| Promo code applied | Promo Code Applied | Membership | membership@relichecksurvey.com | Customer | Immediate | Confirm discount | P2 | Required | No | /billing |
| Promo expiring | Promo Expiring Soon | Membership | membership@relichecksurvey.com | Customer | 3 days before | Encourage use | P3 | Optional | Yes (membership-promo) | /billing/upgrade |
| Payment succeeds | Payment Receipt | Billing | billing@relichecksurvey.com | Customer | Immediate | Receipt of charge | P0 | Required | No | /billing/invoices/{id} |
| Invoice issued | Invoice Available | Billing | billing@relichecksurvey.com | Customer | Immediate | Provide invoice | P0 | Required | No | /billing/invoices/{id} |
| Payment fails | Payment Failed | Billing | billing@relichecksurvey.com | Customer | Immediate | Action required | P0 | Required | No | /billing/payment-method |
| Payment retry fails | Payment Retry Failed | Billing | billing@relichecksurvey.com | Customer | Immediate after retry | Continued action | P0 | Required | No | /billing/payment-method |
| Final payment notice | Final Payment Notice | Billing | billing@relichecksurvey.com | Customer | After final retry | Last chance, suspension warning | P0 | Required | No | /billing/payment-method |
| Renewal soon | Subscription Renewing Soon | Billing | billing@relichecksurvey.com | Customer | 7 days before | Heads-up | P1 | Required | No | /billing |
| Refund issued | Refund Confirmation | Billing | billing@relichecksurvey.com | Customer | Immediate | Confirm refund | P1 | Required | No | /billing/invoices/{id} |
| Payment method updated | Payment Method Updated | Billing | billing@relichecksurvey.com | Customer | Immediate | Security confirmation | P0 | Required | No | /billing/payment-method |
| Billing info changed | Billing Info Changed | Billing | billing@relichecksurvey.com | Customer | Immediate | Security confirmation | P0 | Required | No | /billing |
| Support ticket submitted | Support Ticket Received | Support | support@relichecksurvey.com | Customer | Immediate | Confirm receipt and ticket # | P1 | Required | No | /support/tickets/{id} |
| Employee replies | Support Response | Support | support@relichecksurvey.com | Customer | Immediate | New reply available | P1 | Required | No | /support/tickets/{id} |
| Ticket status changes | Ticket Status Update | Support | support@relichecksurvey.com | Customer | Immediate | Notify state change | P2 | Required | No | /support/tickets/{id} |
| Ticket closed | Support Ticket Closed | Support | support@relichecksurvey.com | Customer | Immediate | Confirm closure | P1 | Required | No | /support/tickets/{id} |
| No customer reply 2-3 days | Awaiting Your Reply | Support | support@relichecksurvey.com | Customer | After 2-3 days idle | Re-engage | P2 | Required | No | /support/tickets/{id} |
| Closed-ticket follow-up | Support Satisfaction Survey | Support | support@relichecksurvey.com | Customer | 24h after closure | Quality feedback | P3 | Optional | Yes (support feedback) | /support/satisfaction |
| Privacy policy changes | Privacy Policy Update | Privacy | privacy@relichecksurvey.com | Customer | Immediate at change | Notify policy update | P0 | Required | No | /privacy |
| Data export requested | Data Export Requested | Privacy | privacy@relichecksurvey.com | Customer | Immediate | Confirm request | P1 | Required | No | /account/data |
| Data export ready | Data Export Ready | Privacy | privacy@relichecksurvey.com | Customer | Immediate when ready | Provide download link | P1 | Required | No | /account/data/exports/{id} |
| Survey data deletion requested | Survey Data Deletion Requested | Privacy | privacy@relichecksurvey.com | Customer | Immediate | Confirm request, schedule | P1 | Required | No | /account/data |
| Survey data deleted | Survey Data Deleted | Privacy | privacy@relichecksurvey.com | Customer | Immediate at deletion | Confirm completion | P1 | Required | No | /account/data |
| Account deletion requested | Account Deletion Requested | Privacy | privacy@relichecksurvey.com | Customer | Immediate | Confirm and grace period | P0 | Required | No | /account/delete |
| Account deleted | Account Deleted | Privacy | privacy@relichecksurvey.com | Customer | Immediate at deletion | Confirm completion | P0 | Required | No | (none) |
| Terms updated | Terms Update | Terms | terms@relichecksurvey.com | Customer | Immediate at change | Notify terms change | P0 | Required | No | /terms |
| Terms acceptance required | Terms Acceptance Required | Terms | terms@relichecksurvey.com | Customer | Immediate | Force re-acceptance | P0 | Required | No | /terms/accept |
| Formal legal notice | Legal Notice | Legal | legal@relichecksurvey.com | Customer | Immediate | Provide formal notice | P0 | Required | No | (case-specific) |
| Legal escalation | Legal Escalation | Legal | legal@relichecksurvey.com | Customer | Immediate | Notify escalation | P0 | Required | No | (case-specific) |
| Product update | Product Update | Marketing | marketing@relichecksurvey.com | Customer | On release | Announce updates | P3 | Optional | Yes | /changelog |
| New feature announcement | New Feature | Marketing | marketing@relichecksurvey.com | Customer | On release | Announce feature | P3 | Optional | Yes | /features/{slug} |
| Newsletter | Newsletter | Marketing | marketing@relichecksurvey.com | Customer | Recurring schedule | Brand engagement | P3 | Optional | Yes | (web view) |
| Educational content | Survey Best Practices | Marketing | marketing@relichecksurvey.com | Customer | Drip schedule | Educate | P3 | Optional | Yes | /resources |
| Promotional campaign | Promotion | Marketing | marketing@relichecksurvey.com | Customer | As scheduled | Drive upgrades | P3 | Optional | Yes | /pricing |
| Demo request follow-up | Demo Follow-Up | Sales | sales@relichecksurvey.com | Customer | Within 1 business day | Sales touch | P2 | Optional | Yes (sales) | /demo |
| Institutional pricing inquiry | Institutional Pricing | Sales | sales@relichecksurvey.com | Customer | Within 1 business day | Sales touch | P2 | Optional | Yes (sales) | /pricing/institutions |
| Upgrade reminder | Upgrade Reminder | Sales | sales@relichecksurvey.com | Customer | When usage hits threshold | Convert | P3 | Optional | Yes (sales) | /billing/upgrade |
| Approaching plan limit | Plan Limit Warning | Sales | sales@relichecksurvey.com | Customer | 80% / 95% usage | Convert | P2 | Required | No (operational) | /billing/upgrade |
| Quote request | Quote Follow-Up | Sales | sales@relichecksurvey.com | Customer | Within 1 business day | Sales touch | P2 | Optional | Yes (sales) | /quote/{id} |

---

## 4. Section C: Employee / Admin Email Trigger Table

| Trigger event | Email name | Department sender | Sender address | Recipient role | Timing | Purpose | Priority | Required / Optional | Destination | Restricted data? |
|---|---|---|---|---|---|---|---|---|---|---|
| Employee account created | Admin Panel Invitation | HR | hr@relichecksurvey.com | Invited employee | Immediate | Send invite link | P0 | Required | /admin/accept-invite?token= | No |
| Employee invited | Employee Invite Reminder | HR | hr@relichecksurvey.com | Invited employee | 48h after invite if unaccepted | Reminder | P2 | Required | /admin/accept-invite | No |
| Employee setup complete | Employee Account Confirmed | HR | hr@relichecksurvey.com | Employee | Immediate | Confirm activation | P1 | Required | /admin | No |
| Employee role changes | Employee Role Changed | HR | hr@relichecksurvey.com | Employee | Immediate | Inform role | P1 | Required | /admin/profile | No |
| Employee access changes | Employee Access Changed | HR | hr@relichecksurvey.com | Employee | Immediate | Inform access | P1 | Required | /admin/profile | No |
| Employee access removed | Employee Access Removed | HR | hr@relichecksurvey.com | Employee + supervisor | Immediate | Notify removal | P0 | Required | (none) | No |
| Employee assigned to dept | Department Assignment | HR | hr@relichecksurvey.com | Employee + dept lead | Immediate | Notify assignment | P2 | Required | /admin | No |
| Onboarding message | New Employee Onboarding | HR | hr@relichecksurvey.com | Employee | After confirmation | Orient | P2 | Required | /admin/onboarding | No |
| New ticket assigned | New Ticket Assigned | Support | support@relichecksurvey.com | Assigned agent | Immediate | Action required | P1 | Required | /admin/tickets/{id} | No |
| Customer replies to ticket | Customer Reply Received | Support | support@relichecksurvey.com | Assigned agent | Immediate | Action required | P1 | Required | /admin/tickets/{id} | No |
| Ticket reassigned | Ticket Reassigned | Support | support@relichecksurvey.com | New + previous agent | Immediate | Handoff | P2 | Required | /admin/tickets/{id} | No |
| Ticket overdue | Ticket Overdue | Support | support@relichecksurvey.com | Agent + supervisor | At SLA breach | Escalate | P1 | Required | /admin/tickets/{id} | No |
| Ticket escalated | Ticket Escalated | Support | support@relichecksurvey.com | Supervisor + escalation owner | Immediate | Action required | P0 | Required | /admin/tickets/{id} | No |
| Ticket resolved | Ticket Resolved (internal) | Support | support@relichecksurvey.com | Agent | Immediate | Workflow close | P3 | Optional | /admin/tickets/{id} | No |
| Customer upgrades | Customer Upgraded (alert) | Membership | membership@relichecksurvey.com | Sales lead, account owner | Immediate | Sales / CRM signal | P2 | Optional | /admin/customers/{id} | No |
| Customer cancels | Customer Cancelled (alert) | Membership | membership@relichecksurvey.com | Retention, account owner | Immediate | Retention follow-up | P1 | Required | /admin/customers/{id} | No |
| Plan manually changed | Plan Manually Changed | Membership | membership@relichecksurvey.com | Owner / supervisor | Immediate | Audit + visibility | P1 | Required | /admin/customers/{id}/billing | No |
| Promo code created | Promo Code Created | Membership | membership@relichecksurvey.com | Marketing + owner | Immediate | Audit | P2 | Required | /admin/promos/{id} | No |
| Promo code edited | Promo Code Edited | Membership | membership@relichecksurvey.com | Marketing + owner | Immediate | Audit | P2 | Required | /admin/promos/{id} | No |
| Promo code used heavily | Promo Code Spike | Membership | membership@relichecksurvey.com | Marketing + finance | Threshold trigger | Risk / fraud check | P1 | Required | /admin/promos/{id} | No |
| Payment failed (customer) | Payment Failure Alert | Billing | billing@relichecksurvey.com | Billing ops | Immediate | Visibility | P1 | Required | /admin/customers/{id}/billing | No |
| Refund issued | Refund Issued (alert) | Billing | billing@relichecksurvey.com | Billing ops | Immediate | Audit | P1 | Required | /admin/customers/{id}/billing | No |
| Invoice problem | Invoice Problem | Billing | billing@relichecksurvey.com | Billing ops | Immediate | Action required | P0 | Required | /admin/customers/{id}/billing | No |
| Account suspended | Account Suspended | Billing | billing@relichecksurvey.com | Billing + support leads | Immediate | Visibility | P0 | Required | /admin/customers/{id} | No |
| Service request: setup | Service Task Assigned (setup) | Services | services@relichecksurvey.com | Assigned services rep | Immediate | Workflow | P1 | Required | /admin/services/{id} | No |
| Service request: reports | Service Task Assigned (reports) | Services | services@relichecksurvey.com | Assigned services rep | Immediate | Workflow | P1 | Required | /admin/services/{id} | No |
| Service request: AI insights | Service Task Assigned (insights) | Services | services@relichecksurvey.com | Assigned services rep | Immediate | Workflow | P1 | Required | /admin/services/{id} | No |
| Service task overdue | Service Task Overdue | Services | services@relichecksurvey.com | Rep + supervisor | At SLA breach | Escalate | P1 | Required | /admin/services/{id} | No |
| Survey service issue escalated | Service Issue Escalated | Services | services@relichecksurvey.com | Supervisor | Immediate | Action required | P0 | Required | /admin/services/{id} | No |
| Privacy issue reported | Privacy Review Needed | Privacy | privacy@relichecksurvey.com | Privacy officer | Immediate | Action required | P0 | Required | /admin/privacy/{id} | No |
| Data deletion review | Data Deletion Review | Privacy | privacy@relichecksurvey.com | Privacy officer | Immediate | Action required | P0 | Required | /admin/privacy/deletions/{id} | No |
| Data export issue | Data Export Issue | Privacy | privacy@relichecksurvey.com | Privacy officer + ops | Immediate | Action required | P1 | Required | /admin/privacy/exports/{id} | No |
| Privacy escalation | Privacy Escalation | Privacy | privacy@relichecksurvey.com | Owner + privacy officer | Immediate | Action required | P0 | Required | /admin/privacy/{id} | No |
| Terms update published | Terms Update Published (internal) | Terms | terms@relichecksurvey.com | Legal + marketing | Immediate | Visibility | P1 | Required | /admin/legal/terms | No |
| Legal issue submitted | Legal Review Needed | Legal | legal@relichecksurvey.com | Legal owner | Immediate | Action required | P0 | Required | /admin/legal/{id} | No |
| Customer threatens legal action | Legal Threat Alert | Legal | legal@relichecksurvey.com | Owner + legal owner | Immediate | Action required | P0 | Required | /admin/legal/{id} | No |
| Legal escalation | Legal Escalation (internal) | Legal | legal@relichecksurvey.com | Owner + legal | Immediate | Action required | P0 | Required | /admin/legal/{id} | No |
| New demo request | Demo Request | Sales | sales@relichecksurvey.com | Sales lead | Immediate | Action required | P1 | Required | /admin/sales/leads/{id} | No |
| Institutional pricing inquiry | Institutional Inquiry | Sales | sales@relichecksurvey.com | Sales lead | Immediate | Action required | P1 | Required | /admin/sales/leads/{id} | No |
| Lead submits contact form | New Lead | Sales | sales@relichecksurvey.com | Sales lead | Immediate | Action required | P1 | Required | /admin/sales/leads/{id} | No |
| Customer requests quote | Quote Request | Sales | sales@relichecksurvey.com | Sales lead | Immediate | Action required | P1 | Required | /admin/sales/quotes/{id} | No |
| Campaign launched | Campaign Launched | Marketing | marketing@relichecksurvey.com | Marketing team | Immediate | Visibility | P2 | Required | /admin/marketing/campaigns/{id} | No |
| Newsletter scheduled | Newsletter Scheduled | Marketing | marketing@relichecksurvey.com | Marketing team | At scheduling | Visibility | P3 | Optional | /admin/marketing/newsletters/{id} | No |
| High-value lead | High-Value Lead Alert | Sales | sales@relichecksurvey.com | Sales lead + owner | Immediate | Action required | P1 | Required | /admin/sales/leads/{id} | No |
| Upgrade opportunity | Upgrade Opportunity | Sales | sales@relichecksurvey.com | Sales rep | Threshold trigger | Action required | P2 | Optional | /admin/customers/{id} | No |
| System error / outage | System Alert | Support | support@relichecksurvey.com | Ops on-call | Immediate | Action required | P0 | Required | /admin/system | No |

---

## 5. Section D: Customer Email Timelines

### 5.1 New customer journey

| When | Email | Department | Sender address | Purpose |
|---|---|---|---|---|
| T+0 | Welcome / Verify Email | Welcome | welcome@relichecksurvey.com | Verify email |
| T+0 (after verify) | Account Confirmed | Welcome | welcome@relichecksurvey.com | Confirm activation |
| T+5 min after first login | Getting Started Guidance | Welcome | welcome@relichecksurvey.com | Orient user |
| T+1 day | Survey Best Practices (if opted in) | Marketing | marketing@relichecksurvey.com | Educate |
| T+3 days (no survey) | "Build your first survey" nudge | Marketing | marketing@relichecksurvey.com | Activate |

### 5.2 Free trial journey (assume 14 days)

| When | Email | Department | Sender address | Purpose |
|---|---|---|---|---|
| Day 0 | Trial Started | Membership | membership@relichecksurvey.com | Confirm trial |
| Day 7 | Trial Midpoint | Membership | membership@relichecksurvey.com | Encourage activation |
| Day 12 | Trial Ending Soon | Membership | membership@relichecksurvey.com | Reminder |
| Day 14 | Trial Expired | Membership | membership@relichecksurvey.com | Convert |
| Day 14 + 24h (if no upgrade) | Upgrade Reminder | Sales | sales@relichecksurvey.com | Convert |

### 5.3 Paid customer journey

| When | Email | Department | Sender address | Purpose |
|---|---|---|---|---|
| Charge succeeds | Payment Receipt | Billing | billing@relichecksurvey.com | Receipt |
| Invoice issued | Invoice Available | Billing | billing@relichecksurvey.com | Provide invoice |
| 7 days before renewal | Subscription Renewing Soon | Billing | billing@relichecksurvey.com | Heads-up |
| At renewal | Payment Receipt | Billing | billing@relichecksurvey.com | Receipt |
| Plan change | Membership Upgraded / Membership Changed | Membership | membership@relichecksurvey.com | Confirm |
| 80% of plan limit | Plan Limit Warning | Sales | sales@relichecksurvey.com | Convert / heads-up |
| Quarterly | Newsletter (if opted in) | Marketing | marketing@relichecksurvey.com | Engagement |

### 5.4 Survey project journey

| When | Email | Department | Sender address | Purpose |
|---|---|---|---|---|
| Created | First Survey Created (if first) | Services | services@relichecksurvey.com | Encourage launch |
| Scheduled | Survey Scheduled | Services | services@relichecksurvey.com | Confirm |
| Launched | Survey Is Live | Services | services@relichecksurvey.com | Confirm + share link |
| 2-3 days idle | Low Response Rate | Services | services@relichecksurvey.com | Suggest distribution |
| 3 days zero | No Responses Yet | Services | services@relichecksurvey.com | Help re-distribute |
| Milestone hit | Milestone Reached | Services | services@relichecksurvey.com | Celebrate |
| Closed (auto / manual) | Survey Closed | Services | services@relichecksurvey.com | Confirm + next steps |
| Report ready | Report Ready | Services | services@relichecksurvey.com | Notify |
| AI insights ready | AI Insights Ready | Services | services@relichecksurvey.com | Notify |

### 5.5 Support ticket journey

| When | Email | Department | Sender address | Purpose |
|---|---|---|---|---|
| Submission | Support Ticket Received | Support | support@relichecksurvey.com | Confirm |
| Agent reply | Support Response | Support | support@relichecksurvey.com | Notify reply |
| Status change | Ticket Status Update | Support | support@relichecksurvey.com | Notify state |
| 2-3 days idle | Awaiting Your Reply | Support | support@relichecksurvey.com | Re-engage |
| Closure | Support Ticket Closed | Support | support@relichecksurvey.com | Confirm |
| 24h after close | Support Satisfaction Survey (optional) | Support | support@relichecksurvey.com | Quality |

### 5.6 Cancellation / reactivation journey

| When | Email | Department | Sender address | Purpose |
|---|---|---|---|---|
| Cancel submitted | Cancellation Confirmation | Membership | membership@relichecksurvey.com | Confirm |
| 3 days after cancel | Win-back (Marketing, optional) | Marketing | marketing@relichecksurvey.com | Re-engage |
| Plan expires | Plan Expired | Membership | membership@relichecksurvey.com | Notify |
| 14 days after expiry | Reactivation Offer (Sales, optional) | Sales | sales@relichecksurvey.com | Reactivate |
| Reactivation purchased | Membership Upgraded + Payment Receipt | Membership / Billing | membership@/billing@ | Confirm |

---

## 6. Section E: Employee / Admin Email Timelines

### 6.1 New employee onboarding

| Trigger / time | Email | Department | Sender address | Recipient role | Purpose |
|---|---|---|---|---|---|
| Account created | Admin Panel Invitation | HR | hr@relichecksurvey.com | Invitee | Send invite link |
| 48h after invite | Employee Invite Reminder | HR | hr@relichecksurvey.com | Invitee | Reminder |
| Setup complete | Employee Account Confirmed | HR | hr@relichecksurvey.com | Employee | Confirm activation |
| Department assigned | Department Assignment | HR | hr@relichecksurvey.com | Employee + dept lead | Notify |
| First login | New Employee Onboarding | HR | hr@relichecksurvey.com | Employee | Orient |

### 6.2 Customer service workflow

| Trigger / time | Email | Department | Sender address | Recipient role | Purpose |
|---|---|---|---|---|---|
| Ticket assigned | New Ticket Assigned | Support | support@relichecksurvey.com | Agent | Action required |
| Customer replies | Customer Reply Received | Support | support@relichecksurvey.com | Agent | Action required |
| Reassignment | Ticket Reassigned | Support | support@relichecksurvey.com | New + prior agent | Handoff |
| SLA breach | Ticket Overdue | Support | support@relichecksurvey.com | Agent + supervisor | Escalate |
| Resolved | Ticket Resolved (internal) | Support | support@relichecksurvey.com | Agent | Workflow close |

### 6.3 Supervisor workflow

| Trigger / time | Email | Department | Sender address | Recipient role | Purpose |
|---|---|---|---|---|---|
| Escalation | Ticket Escalated | Support | support@relichecksurvey.com | Supervisor | Action required |
| Service task overdue | Service Task Overdue | Services | services@relichecksurvey.com | Supervisor | Escalate |
| Service issue escalated | Service Issue Escalated | Services | services@relichecksurvey.com | Supervisor | Action required |
| Account suspended | Account Suspended | Billing | billing@relichecksurvey.com | Supervisor | Visibility |

### 6.4 Billing workflow

| Trigger / time | Email | Department | Sender address | Recipient role | Purpose |
|---|---|---|---|---|---|
| Payment failed | Payment Failure Alert | Billing | billing@relichecksurvey.com | Billing ops | Visibility |
| Refund issued | Refund Issued (alert) | Billing | billing@relichecksurvey.com | Billing ops | Audit |
| Invoice problem | Invoice Problem | Billing | billing@relichecksurvey.com | Billing ops | Action required |
| Customer cancels | Customer Cancelled (alert) | Membership | membership@relichecksurvey.com | Retention + owner | Action required |
| Plan manually changed | Plan Manually Changed | Membership | membership@relichecksurvey.com | Owner | Audit |

### 6.5 Privacy / legal escalation workflow

| Trigger / time | Email | Department | Sender address | Recipient role | Purpose |
|---|---|---|---|---|---|
| Privacy report | Privacy Review Needed | Privacy | privacy@relichecksurvey.com | Privacy officer | Action required |
| Deletion review | Data Deletion Review | Privacy | privacy@relichecksurvey.com | Privacy officer | Action required |
| Privacy escalation | Privacy Escalation | Privacy | privacy@relichecksurvey.com | Owner | Action required |
| Legal issue submitted | Legal Review Needed | Legal | legal@relichecksurvey.com | Legal owner | Action required |
| Legal threat | Legal Threat Alert | Legal | legal@relichecksurvey.com | Owner + legal | Action required |
| Legal escalation | Legal Escalation (internal) | Legal | legal@relichecksurvey.com | Owner + legal | Action required |
| Terms published | Terms Update Published (internal) | Terms | terms@relichecksurvey.com | Legal + marketing | Visibility |

### 6.6 Sales lead workflow

| Trigger / time | Email | Department | Sender address | Recipient role | Purpose |
|---|---|---|---|---|---|
| Demo request | Demo Request | Sales | sales@relichecksurvey.com | Sales rep | Action required |
| New lead | New Lead | Sales | sales@relichecksurvey.com | Sales rep | Action required |
| Quote request | Quote Request | Sales | sales@relichecksurvey.com | Sales rep | Action required |
| Institutional inquiry | Institutional Inquiry | Sales | sales@relichecksurvey.com | Sales rep | Action required |
| High-value lead | High-Value Lead Alert | Sales | sales@relichecksurvey.com | Sales rep + owner | Action required |
| Upgrade signal | Upgrade Opportunity | Sales | sales@relichecksurvey.com | Sales rep | Action required |

---

## 7. Section F: Email Template System

### 7.1 Template metadata schema

Every template carries the following metadata:

- `template_key` (unique, snake_case)
- `email_name` (human label)
- `department` (FK to email_departments)
- `sender_email` (derived from department, but storable for override)
- `sender_display_name` (derived; storable)
- `subject_line` (with variable interpolation)
- `preview_text` (50-90 char preheader)
- `body_html`, `body_text`
- `primary_button_label`, `primary_button_url_template`
- `dynamic_fields` (JSON array of required variables)
- `audience` (`customer` | `employee` | `both`)
- `required` (boolean)
- `unsubscribable` (boolean)
- `unsubscribe_group` (FK to email_preferences group, nullable)
- `restricted_data` (boolean, must be false for any employee template)
- `is_active` (boolean)
- `version` (int, history kept in `email_template_versions`)

### 7.2 Tone and copy guardrails

Templates use short paragraphs (1-3 sentences each), one primary button, plain English subject lines under 60 characters, preheaders that reinforce (not repeat) the subject. Marketing templates carry an unsubscribe link and physical address footer. Transactional, billing, privacy, legal, security, support, and required membership templates do not carry an unsubscribe link.

Variable interpolation uses `{{variable_name}}` syntax. Buttons link to canonical URLs only. No fake urgency, no hype language, no exclamation points outside Welcome and Marketing departments.

### 7.3 Customer launch templates (1-27)

#### 7.3.1 Welcome / Verify Email

- **Department / sender**: Welcome, welcome@relichecksurvey.com
- **Subject**: Verify your ReliCheck account
- **Preview**: One quick step to activate your account.
- **Body**:

  > Hi {{first_name}},
  >
  > Welcome to ReliCheck. Please confirm this email address so we can finish setting up your account.
  >
  > The link below is valid for 24 hours.
  >
  > [Verify email]
  >
  > If you did not create a ReliCheck account, you can ignore this message.
  >
  > Thanks,
  > The ReliCheck team

- **Button**: Verify email -> /verify?token={{verify_token}}
- **Dynamic fields**: first_name, verify_token, expires_at
- **Audience**: Customer. **Required**. **No unsubscribe.**

#### 7.3.2 Account Confirmed

- **Department / sender**: Welcome, welcome@relichecksurvey.com
- **Subject**: Your ReliCheck account is ready
- **Preview**: You're all set. Sign in and create your first survey.
- **Body**:

  > Hi {{first_name}},
  >
  > Your email is verified and your ReliCheck account is active.
  >
  > Sign in any time to build a survey, invite respondents, and see results in your dashboard.
  >
  > [Open my dashboard]

- **Button**: Open my dashboard -> /dashboard
- **Dynamic fields**: first_name
- **Customer. Required. No unsubscribe.**

#### 7.3.3 Password Reset

- **Department / sender**: Support, support@relichecksurvey.com
- **Subject**: Reset your ReliCheck password
- **Preview**: Use the link below to set a new password.
- **Body**:

  > Hi {{first_name}},
  >
  > We received a request to reset the password for {{email}}. The link below expires in 30 minutes.
  >
  > [Reset password]
  >
  > If you did not request this, you can ignore this message and your password will stay the same.

- **Button**: Reset password -> /reset?token={{reset_token}}
- **Dynamic fields**: first_name, email, reset_token, expires_at
- **Customer. Required. No unsubscribe.**

#### 7.3.4 Password Changed

- **Department / sender**: Support, support@relichecksurvey.com
- **Subject**: Your password was changed
- **Preview**: This is a confirmation of recent account activity.
- **Body**:

  > Hi {{first_name}},
  >
  > The password for your ReliCheck account was changed on {{changed_at}} from {{ip_address}}.
  >
  > If this was you, no further action is needed. If not, secure your account right away.
  >
  > [Secure my account]

- **Button**: Secure my account -> /security
- **Dynamic fields**: first_name, changed_at, ip_address
- **Customer. Required. No unsubscribe.**

#### 7.3.5 New Login Alert

- **Department / sender**: Privacy, privacy@relichecksurvey.com
- **Subject**: New sign-in to your ReliCheck account
- **Preview**: We noticed a sign-in from a new device or location.
- **Body**:

  > Hi {{first_name}},
  >
  > We saw a new sign-in to your ReliCheck account.
  >
  > Device: {{device}}
  > Location: {{approximate_location}}
  > Time: {{login_at}}
  >
  > If this was you, you can ignore this message. If not, secure your account.
  >
  > [Review activity]

- **Button**: Review activity -> /security/sessions
- **Dynamic fields**: first_name, device, approximate_location, login_at
- **Customer. Required. No unsubscribe.**

#### 7.3.6 First Survey Created

- **Department / sender**: Services, services@relichecksurvey.com
- **Subject**: Your first survey is ready to build
- **Preview**: Add questions, set distribution, and launch when ready.
- **Body**:

  > Hi {{first_name}},
  >
  > You've created your first survey, {{survey_name}}. Add the rest of your questions, choose how respondents will receive it, and launch when ready.
  >
  > [Continue building]

- **Button**: Continue building -> /surveys/{{survey_id}}/edit
- **Dynamic fields**: first_name, survey_name, survey_id
- **Customer. Required. No unsubscribe.**

#### 7.3.7 Survey Is Live

- **Department / sender**: Services, services@relichecksurvey.com
- **Subject**: {{survey_name}} is live
- **Preview**: Share the link below to start collecting responses.
- **Body**:

  > Hi {{first_name}},
  >
  > {{survey_name}} is live. Share the link with your respondents.
  >
  > {{public_survey_link}}
  >
  > Track responses any time from your dashboard.
  >
  > [Open survey]

- **Button**: Open survey -> /surveys/{{survey_id}}
- **Dynamic fields**: first_name, survey_name, survey_id, public_survey_link
- **Customer. Required. No unsubscribe.**

#### 7.3.8 Survey Closed

- **Department / sender**: Services, services@relichecksurvey.com
- **Subject**: {{survey_name}} is closed
- **Preview**: Your survey is no longer accepting responses.
- **Body**:

  > Hi {{first_name}},
  >
  > {{survey_name}} closed on {{closed_at}} with {{response_count}} total responses.
  >
  > You can review the results or generate a report any time.
  >
  > [View results]

- **Button**: View results -> /surveys/{{survey_id}}/results
- **Dynamic fields**: first_name, survey_name, survey_id, closed_at, response_count
- **Customer. Required. No unsubscribe.**

#### 7.3.9 Report Ready

- **Department / sender**: Services, services@relichecksurvey.com
- **Subject**: Your report for {{survey_name}} is ready
- **Preview**: Open your report to review the findings.
- **Body**:

  > Hi {{first_name}},
  >
  > Your report for {{survey_name}} is ready.
  >
  > [Open report]

- **Button**: Open report -> /surveys/{{survey_id}}/report
- **Dynamic fields**: first_name, survey_name, survey_id
- **Customer. Required. No unsubscribe.**

#### 7.3.10 AI Insights Ready

- **Department / sender**: Services, services@relichecksurvey.com
- **Subject**: AI insights are ready for {{survey_name}}
- **Preview**: Open your dashboard to review the analysis.
- **Body**:

  > Hi {{first_name}},
  >
  > Your AI-assisted insights for {{survey_name}} are ready in your dashboard.
  >
  > [View insights]

- **Button**: View insights -> /surveys/{{survey_id}}/insights
- **Dynamic fields**: first_name, survey_name, survey_id
- **Customer. Required. No unsubscribe.**

#### 7.3.11 Trial Started

- **Department / sender**: Membership, membership@relichecksurvey.com
- **Subject**: Your ReliCheck trial has started
- **Preview**: You have full access for the next {{trial_days}} days.
- **Body**:

  > Hi {{first_name}},
  >
  > Your free ReliCheck trial is now active. You have full access to {{plan_name}} until {{trial_end_date}}.
  >
  > [Open dashboard]

- **Button**: Open dashboard -> /dashboard
- **Dynamic fields**: first_name, plan_name, trial_days, trial_end_date
- **Customer. Required. No unsubscribe.**

#### 7.3.12 Trial Ending Soon

- **Department / sender**: Membership, membership@relichecksurvey.com
- **Subject**: Your trial ends in 2 days
- **Preview**: Choose a plan to keep your data and projects.
- **Body**:

  > Hi {{first_name}},
  >
  > Your ReliCheck trial ends on {{trial_end_date}}. To keep your projects, results, and account access, choose a plan before then.
  >
  > [Choose a plan]

- **Button**: Choose a plan -> /billing/upgrade
- **Dynamic fields**: first_name, trial_end_date
- **Customer. Required. No unsubscribe.**

#### 7.3.13 Trial Expired

- **Department / sender**: Membership, membership@relichecksurvey.com
- **Subject**: Your trial has ended
- **Preview**: Reactivate any time to regain full access.
- **Body**:

  > Hi {{first_name}},
  >
  > Your ReliCheck trial ended on {{trial_end_date}}. Your projects are saved. Choose a plan to restore full access.
  >
  > [Choose a plan]

- **Button**: Choose a plan -> /billing/upgrade
- **Dynamic fields**: first_name, trial_end_date
- **Customer. Required. No unsubscribe.**

#### 7.3.14 Membership Upgraded

- **Department / sender**: Membership, membership@relichecksurvey.com
- **Subject**: Your plan has been upgraded
- **Preview**: New features and limits are now available.
- **Body**:

  > Hi {{first_name}},
  >
  > Your account is now on the {{plan_name}} plan, effective {{effective_date}}.
  >
  > [Open billing]

- **Button**: Open billing -> /billing
- **Dynamic fields**: first_name, plan_name, effective_date
- **Customer. Required. No unsubscribe.**

#### 7.3.15 Membership Changed

- **Department / sender**: Membership, membership@relichecksurvey.com
- **Subject**: Your plan has been updated
- **Preview**: Review your updated access and limits.
- **Body**:

  > Hi {{first_name}},
  >
  > Your plan was changed to {{plan_name}}, effective {{effective_date}}. Updated access and usage limits are reflected in your billing summary.
  >
  > [Open billing]

- **Button**: Open billing -> /billing
- **Dynamic fields**: first_name, plan_name, effective_date
- **Customer. Required. No unsubscribe.**

#### 7.3.16 Cancellation Confirmation

- **Department / sender**: Membership, membership@relichecksurvey.com
- **Subject**: Your ReliCheck subscription has been cancelled
- **Preview**: Access continues until {{access_end_date}}.
- **Body**:

  > Hi {{first_name}},
  >
  > Your subscription was cancelled on {{cancelled_at}}. You will keep access to your current plan through {{access_end_date}}.
  >
  > You can reactivate any time before that date with one click.
  >
  > [Reactivate]

- **Button**: Reactivate -> /billing/reactivate
- **Dynamic fields**: first_name, cancelled_at, access_end_date
- **Customer. Required. No unsubscribe.**

#### 7.3.17 Payment Receipt

- **Department / sender**: Billing, billing@relichecksurvey.com
- **Subject**: Receipt for your ReliCheck payment
- **Preview**: {{amount}} charged on {{charge_date}}.
- **Body**:

  > Hi {{first_name}},
  >
  > Thank you for your payment.
  >
  > Amount: {{amount}}
  > Date: {{charge_date}}
  > Plan: {{plan_name}}
  > Invoice: {{invoice_number}}
  >
  > [View invoice]

- **Button**: View invoice -> /billing/invoices/{{invoice_id}}
- **Dynamic fields**: first_name, amount, charge_date, plan_name, invoice_number, invoice_id
- **Customer. Required. No unsubscribe.**

#### 7.3.18 Payment Failed

- **Department / sender**: Billing, billing@relichecksurvey.com
- **Subject**: We couldn't process your payment
- **Preview**: Update your payment method to keep your account active.
- **Body**:

  > Hi {{first_name}},
  >
  > Your most recent payment of {{amount}} on {{attempted_at}} did not go through. Please update your payment method so we can retry the charge.
  >
  > [Update payment method]

- **Button**: Update payment method -> /billing/payment-method
- **Dynamic fields**: first_name, amount, attempted_at
- **Customer. Required. No unsubscribe.**

#### 7.3.19 Refund Confirmation

- **Department / sender**: Billing, billing@relichecksurvey.com
- **Subject**: Your refund has been issued
- **Preview**: {{amount}} returned to your original payment method.
- **Body**:

  > Hi {{first_name}},
  >
  > A refund of {{amount}} for invoice {{invoice_number}} was issued on {{refund_date}}. Funds typically appear in 5 to 10 business days.
  >
  > [View invoice]

- **Button**: View invoice -> /billing/invoices/{{invoice_id}}
- **Dynamic fields**: first_name, amount, invoice_number, refund_date, invoice_id
- **Customer. Required. No unsubscribe.**

#### 7.3.20 Support Ticket Received

- **Department / sender**: Support, support@relichecksurvey.com
- **Subject**: Ticket {{ticket_number}}: we got your message
- **Preview**: A team member will reply within {{sla_hours}} hours.
- **Body**:

  > Hi {{first_name}},
  >
  > Thanks for reaching out. Your ticket is {{ticket_number}}, and a member of our team will reply within {{sla_hours}} hours.
  >
  > Subject: {{ticket_subject}}
  >
  > [View ticket]

- **Button**: View ticket -> /support/tickets/{{ticket_id}}
- **Dynamic fields**: first_name, ticket_number, ticket_id, ticket_subject, sla_hours
- **Customer. Required. No unsubscribe.**

#### 7.3.21 Support Response

- **Department / sender**: Support, support@relichecksurvey.com
- **Subject**: New reply on ticket {{ticket_number}}
- **Preview**: {{agent_first_name}} responded to your request.
- **Body**:

  > Hi {{first_name}},
  >
  > {{agent_first_name}} from ReliCheck Support replied to your ticket {{ticket_number}}.
  >
  > [View reply]

- **Button**: View reply -> /support/tickets/{{ticket_id}}
- **Dynamic fields**: first_name, agent_first_name, ticket_number, ticket_id
- **Customer. Required. No unsubscribe.**

#### 7.3.22 Support Ticket Closed

- **Department / sender**: Support, support@relichecksurvey.com
- **Subject**: Ticket {{ticket_number}} is closed
- **Preview**: We're glad we could help. Reopen any time.
- **Body**:

  > Hi {{first_name}},
  >
  > Your ticket {{ticket_number}} is now closed. If you need anything else, reply to this email and we'll reopen it.
  >
  > [View ticket]

- **Button**: View ticket -> /support/tickets/{{ticket_id}}
- **Dynamic fields**: first_name, ticket_number, ticket_id
- **Customer. Required. No unsubscribe.**

#### 7.3.23 Privacy Policy Update

- **Department / sender**: Privacy, privacy@relichecksurvey.com
- **Subject**: We've updated our privacy policy
- **Preview**: New policy takes effect on {{effective_date}}.
- **Body**:

  > Hi {{first_name}},
  >
  > We've updated the ReliCheck privacy policy. The new policy takes effect on {{effective_date}}. A short summary of the changes is on our site.
  >
  > [Read the policy]

- **Button**: Read the policy -> /privacy
- **Dynamic fields**: first_name, effective_date
- **Customer. Required. No unsubscribe.**

#### 7.3.24 Data Export Ready

- **Department / sender**: Privacy, privacy@relichecksurvey.com
- **Subject**: Your data export is ready
- **Preview**: The download link is valid for 7 days.
- **Body**:

  > Hi {{first_name}},
  >
  > The data export you requested on {{requested_at}} is ready. The download link is valid for 7 days.
  >
  > [Download export]

- **Button**: Download export -> /account/data/exports/{{export_id}}
- **Dynamic fields**: first_name, requested_at, export_id
- **Customer. Required. No unsubscribe.**

#### 7.3.25 Terms Update

- **Department / sender**: Terms, terms@relichecksurvey.com
- **Subject**: Updates to our Terms of Service
- **Preview**: New terms take effect on {{effective_date}}.
- **Body**:

  > Hi {{first_name}},
  >
  > We've updated the ReliCheck Terms of Service. The new terms take effect on {{effective_date}}. Continued use of the service after that date constitutes acceptance.
  >
  > [Read the new terms]

- **Button**: Read the new terms -> /terms
- **Dynamic fields**: first_name, effective_date
- **Customer. Required. No unsubscribe.**

#### 7.3.26 Demo Follow-Up

- **Department / sender**: Sales, sales@relichecksurvey.com
- **Subject**: Following up on your ReliCheck demo request
- **Preview**: Let's pick a time that works for you.
- **Body**:

  > Hi {{first_name}},
  >
  > Thanks for requesting a demo of ReliCheck. Pick a time that works and a member of our team will walk you through the platform.
  >
  > [Pick a time]

- **Button**: Pick a time -> {{calendly_link}}
- **Dynamic fields**: first_name, calendly_link
- **Customer. Optional. Unsubscribable (sales follow-ups).**

#### 7.3.27 Product Update

- **Department / sender**: Marketing, marketing@relichecksurvey.com
- **Subject**: What's new in ReliCheck
- **Preview**: A roundup of what shipped this month.
- **Body**:

  > Hi {{first_name}},
  >
  > Here's a quick look at what we shipped recently in ReliCheck.
  >
  > {{summary_block}}
  >
  > [See full changelog]
  >
  > You're receiving this because you opted in to product updates. {{unsubscribe_link}}

- **Button**: See full changelog -> /changelog
- **Dynamic fields**: first_name, summary_block, unsubscribe_link
- **Customer. Optional. Unsubscribable (product updates).**

### 7.4 Employee / admin launch templates (1-19)

#### 7.4.1 Admin Panel Invitation

- **HR, hr@relichecksurvey.com**
- **Subject**: You've been invited to the ReliCheck admin panel
- **Body**:

  > Hi {{first_name}},
  >
  > You've been invited to the ReliCheck admin panel by {{inviter_name}} as a {{role_name}} in the {{department_name}} department.
  >
  > [Accept invitation]
  >
  > This link expires in 72 hours.

- **Button**: Accept invitation -> /admin/accept-invite?token={{invite_token}}
- **Fields**: first_name, inviter_name, role_name, department_name, invite_token

#### 7.4.2 Employee Account Confirmed

- **HR, hr@relichecksurvey.com**
- **Subject**: Your ReliCheck admin account is active
- **Body**: confirmation message, link to /admin
- **Fields**: first_name, role_name

#### 7.4.3 Employee Role Changed

- **HR, hr@relichecksurvey.com**
- **Subject**: Your role has been updated
- **Body**: previous role, new role, effective date, link to /admin/profile
- **Fields**: first_name, previous_role, new_role, effective_date

#### 7.4.4 Employee Access Removed

- **HR, hr@relichecksurvey.com**
- **Subject**: Your ReliCheck admin access has been removed
- **Body**: notice of removal, contact for questions
- **Fields**: first_name, removed_at, contact_email

#### 7.4.5 New Ticket Assigned

- **Support, support@relichecksurvey.com**
- **Subject**: New ticket assigned: {{ticket_number}}
- **Body**:

  > {{agent_first_name}}, ticket {{ticket_number}} has been assigned to you.
  >
  > Customer: {{customer_account_name}}
  > Subject: {{ticket_subject}}
  > Priority: {{priority}}
  > SLA due: {{sla_due}}
  >
  > [Open ticket]

- **Fields**: agent_first_name, ticket_number, ticket_id, customer_account_name, ticket_subject, priority, sla_due

#### 7.4.6 Customer Reply Received

- **Support, support@relichecksurvey.com**
- **Subject**: Customer reply on ticket {{ticket_number}}
- **Body**: notice of new customer message, link to ticket. Does not include the customer's message body if it could contain private survey data; references "view in admin panel" instead.
- **Fields**: agent_first_name, ticket_number, ticket_id, customer_account_name

#### 7.4.7 Ticket Overdue

- **Support, support@relichecksurvey.com**
- **Subject**: Overdue: ticket {{ticket_number}}
- **Body**: SLA breach notice, time overdue, link to ticket
- **Fields**: agent_first_name, ticket_number, ticket_id, sla_due, overdue_by

#### 7.4.8 Ticket Escalated

- **Support, support@relichecksurvey.com**
- **Subject**: Escalated: ticket {{ticket_number}}
- **Body**: escalation reason, escalating agent, link to ticket
- **Fields**: supervisor_first_name, ticket_number, ticket_id, escalated_by, reason

#### 7.4.9 Membership Manually Changed

- **Membership, membership@relichecksurvey.com**
- **Subject**: Plan manually changed for {{customer_account_name}}
- **Body**: who changed it, prior plan, new plan, effective date
- **Fields**: actor_name, customer_account_name, customer_id, prior_plan, new_plan, effective_date

#### 7.4.10 Promo Code Created

- **Membership, membership@relichecksurvey.com**
- **Subject**: Promo code created: {{promo_code}}
- **Body**: code, discount, validity window, created by
- **Fields**: promo_code, discount_label, valid_from, valid_to, created_by

#### 7.4.11 Promo Code Edited

- **Membership, membership@relichecksurvey.com**
- **Subject**: Promo code updated: {{promo_code}}
- **Body**: edited fields, before / after, edited by
- **Fields**: promo_code, changes_summary, edited_by

#### 7.4.12 Payment Issue Alert

- **Billing, billing@relichecksurvey.com**
- **Subject**: Payment issue: {{customer_account_name}}
- **Body**: failure reason, attempt count, link to admin billing
- **Fields**: customer_account_name, customer_id, attempts, failure_reason

#### 7.4.13 Refund Processed

- **Billing, billing@relichecksurvey.com**
- **Subject**: Refund processed: {{customer_account_name}}
- **Body**: amount, invoice, processed by
- **Fields**: customer_account_name, customer_id, amount, invoice_number, processed_by

#### 7.4.14 Service Task Assigned

- **Services, services@relichecksurvey.com**
- **Subject**: New service task: {{task_type}} for {{customer_account_name}}
- **Body**: task type (setup / report help / AI insight help), SLA, link to admin
- **Fields**: rep_first_name, task_type, customer_account_name, customer_id, sla_due, task_id
- **Note**: Does not include any survey content. Task type label only.

#### 7.4.15 Privacy Review Needed

- **Privacy, privacy@relichecksurvey.com**
- **Subject**: Privacy review needed: {{case_reference}}
- **Body**: type of issue, customer reference, due date
- **Fields**: privacy_officer_first_name, case_reference, case_id, issue_type, due_date

#### 7.4.16 Legal Review Needed

- **Legal, legal@relichecksurvey.com**
- **Subject**: Legal review needed: {{case_reference}}
- **Body**: type of issue, customer reference, urgency
- **Fields**: legal_owner_first_name, case_reference, case_id, issue_type, urgency

#### 7.4.17 Demo Request

- **Sales, sales@relichecksurvey.com**
- **Subject**: New demo request from {{lead_organization}}
- **Body**: lead name, organization, segment, link to lead
- **Fields**: rep_first_name, lead_name, lead_organization, lead_segment, lead_id

#### 7.4.18 New Lead

- **Sales, sales@relichecksurvey.com**
- **Subject**: New lead: {{lead_organization}}
- **Body**: source, lead summary, link to lead
- **Fields**: rep_first_name, lead_name, lead_organization, source, lead_id

#### 7.4.19 System Alert

- **Support, support@relichecksurvey.com**
- **Subject**: System alert: {{alert_label}}
- **Body**: severity, affected component, link to ops dashboard
- **Fields**: oncall_first_name, alert_label, severity, component, dashboard_url

---

## 8. Section G: Backend Logic

### 8.1 Event-driven dispatcher

A central `EmailDispatcher` service receives `EmailEvent` objects from across the application and routes them through templates. Application code does not call SMTP directly. Recommended flow:

1. Application emits an event (for example, `survey.published`, `billing.charge.failed`, `support.ticket.assigned`).
2. `EmailDispatcher::handle($event)` looks up the template binding for the event in `email_events` (event_key -> template_key).
3. Dispatcher resolves recipient(s) by event type (customer, employee, role-based group).
4. Dispatcher checks customer / employee preferences and the suppression list.
5. Dispatcher checks the dedupe table for the same `(event_key, recipient_id, dedupe_window_minutes, idempotency_key)` tuple.
6. Dispatcher hydrates the template, renders HTML and text, writes to `email_logs` with status `queued`, then enqueues the send job.
7. The send worker calls the SMTP provider (PHPMailer over IONOS SMTP or a transactional provider via API).
8. The worker writes provider message ID and updates status to `sent`. Webhook callbacks update to `delivered`, `opened`, `clicked`, `bounced`, or `complained`.

### 8.2 Duplicate prevention

Every send call requires an `idempotency_key`. Recommended construction:

```
sha1(event_key . ':' . recipient_id . ':' . entity_id . ':' . dedupe_bucket)
```

`dedupe_bucket` is the time bucket (for example, hour, day) appropriate to the event. The dispatcher rejects sends whose key already exists in `email_logs` within the dedupe window.

### 8.3 Delivery, open, click tracking

- The provider's webhook posts delivery status to `/api/webhooks/email`.
- The endpoint matches by provider message ID and updates `email_logs.status` plus appends rows to `email_open_events` or `email_click_events`.
- Click tracking uses signed redirect URLs (`/r?u=<base64-url>&s=<sig>&log=<email_log_id>`) so the destination is always logged before redirect.

### 8.4 Failure handling and retries

- Soft bounces and provider 5xx: exponential backoff at 1m, 5m, 30m, 2h, 12h. Max 5 attempts. After max, mark `failed_permanent` and write `email_delivery_failures` with reason.
- Hard bounces: write to `email_suppression_list` immediately, mark status `bounced`, do not retry.
- Spam complaints: write to suppression list, set the recipient's marketing preferences to off automatically, leave required transactional sends unaffected unless the address is hard-bounced.

### 8.5 Verification email resend

`POST /api/account/resend-verification` rate-limited to 3 sends per email per hour. Generates a fresh token, invalidates prior tokens, dispatches Welcome / Verify Email.

### 8.6 Logging and history

Every dispatch writes one row to `email_logs` regardless of outcome. The customer-facing admin "email history" view reads from this table filtered by customer ID. Bodies are stored as a hash plus a sanitized snapshot. Snapshots strip any value listed in `restricted_fields` for the template before storage. Employee templates are flagged `restricted_data = false`; the system refuses to send any employee template that references a restricted variable.

### 8.7 Unsubscribe handling

Marketing and other optional categories carry a one-click unsubscribe link with a signed token. The endpoint `/u?t=<token>` flips the appropriate row in `email_preferences` and writes an `email_audit_logs` entry. Required emails do not render the unsubscribe link, and the dispatcher will refuse to honor an unsubscribe attempt against a required category.

### 8.8 Rate limiting

Per-recipient ceiling: 8 marketing emails per 30 days, 1 per 24 hours unless overridden by an explicit campaign opt-in. Activity emails (low response, milestone) cap at 3 per survey per week. Transactional and required emails are exempt.

### 8.9 Digesting

Low-priority activity events (milestone reached, low response rate, no responses yet) can be delivered as a daily digest if the customer's preference includes `digest_mode = on`. The dispatcher buffers these events in `email_event_buffer` and flushes on the digest schedule.

### 8.10 Audit logs

Template edits, manual resends, suppression list edits, and preference overrides all write to `email_audit_logs`, capturing actor ID, action, target, before / after JSON, and timestamp.

---

## 9. Section H: Customer Email Preferences

Settings page lives at `/account/email-preferences` and surfaces the following groups. Required groups are toggle-disabled with explanatory tooltip.

| Preference group | Description | Customer can disable? |
|---|---|---|
| Account / security emails | Verification, password, login alerts | No |
| Membership emails | Trial, plan, access changes | No |
| Billing emails | Receipts, invoices, payment failures | No |
| Privacy / security notifications | Privacy policy, data export, deletion, suspicious login | No |
| Terms / legal notices | Terms updates, formal legal notices | No |
| Support ticket emails (customer-initiated) | Confirmations, replies, status, closure | No |
| Required service delivery emails | Survey live, closed, report ready, AI insights ready | No |
| Survey activity notifications | Milestones, low response, no responses | Yes |
| Report / insight follow-up nudges | Optional follow-ups after report is ready | Yes |
| Sales / demo follow-ups | Demo follow-up, quote follow-up, upgrade reminders | Yes |
| Product updates | Feature releases, changelog digests | Yes |
| Newsletter | Periodic newsletter | Yes |
| Promotional campaigns | Marketing offers and promos | Yes |
| Educational content | Survey best practices, drip series | Yes |

Digest mode (per group): on / off, frequency (`immediate`, `daily`, `weekly`).

---

## 10. Section I: Employee Notification Preferences

Settings page lives at `/admin/notification-preferences`. Required vs. optional is determined by role through `role_required_notifications` (a join table). Examples below.

| Preference group | Roles where required | Roles where optional | Roles where unavailable |
|---|---|---|---|
| Assigned support tickets | Support agent, supervisor | Owner | Marketing, Sales (unless assigned) |
| Overdue tickets | Support agent, supervisor | Owner | Others |
| Escalations | Supervisor, owner, escalation owners | Support agent | Others |
| Membership changes | Membership ops, owner | Sales, support supervisor | Others |
| Billing issues | Billing ops, owner | Membership ops | Others |
| Refunds | Billing ops, owner | Membership ops | Others |
| Promo code changes | Marketing lead, owner | Membership ops | Others |
| Privacy issues | Privacy officer, owner | Legal | Others |
| Legal issues | Legal owner, owner | Privacy | Others |
| Service task assignments | Services rep, services supervisor | Owner | Others |
| System alerts | On-call rota, ops | Owner | Others |
| Sales leads | Sales rep, sales lead, owner | Marketing | Others |
| Demo requests | Sales rep, sales lead | Owner | Others |
| Marketing campaign notices | Marketing team | Sales, owner | Others |

Required preferences cannot be turned off by the employee; they can be turned off only by the owner via HR override (with audit log).

---

## 11. Section J: Database Structure

All tables use InnoDB, utf8mb4. Primary keys are `BIGINT UNSIGNED AUTO_INCREMENT`. Timestamps are `DATETIME` in UTC. JSON columns use `JSON` type.

### 11.1 `email_departments`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| code | VARCHAR(32) UNIQUE | e.g. `support` |
| display_name | VARCHAR(64) | e.g. `ReliCheck Support` |
| sender_email | VARCHAR(128) UNIQUE | e.g. `support@relichecksurvey.com` |
| email_class | ENUM('transactional','operational','marketing','legal','privacy','billing') | |
| audience | ENUM('customer','employee','both') | |
| is_active | TINYINT(1) | |
| created_at, updated_at | DATETIME | |

Seed with the eleven official departments from Section A.

### 11.2 `email_templates`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| template_key | VARCHAR(96) UNIQUE | e.g. `customer.welcome.verify_email` |
| email_name | VARCHAR(128) | |
| department_id | BIGINT UNSIGNED FK | -> email_departments.id |
| subject_line | VARCHAR(255) | |
| preview_text | VARCHAR(255) | |
| body_html | MEDIUMTEXT | |
| body_text | MEDIUMTEXT | |
| primary_button_label | VARCHAR(64) NULL | |
| primary_button_url_template | VARCHAR(512) NULL | |
| dynamic_fields | JSON | array of variable names |
| audience | ENUM('customer','employee') | |
| is_required | TINYINT(1) | |
| is_unsubscribable | TINYINT(1) | |
| unsubscribe_group | VARCHAR(64) NULL | FK-style key into preference groups |
| restricted_data | TINYINT(1) | always 0 for employee templates |
| current_version | INT UNSIGNED | |
| is_active | TINYINT(1) | |
| created_at, updated_at | DATETIME | |

### 11.3 `email_template_versions`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| template_id | BIGINT UNSIGNED FK | |
| version_number | INT UNSIGNED | |
| subject_line, preview_text, body_html, body_text, primary_button_label, primary_button_url_template, dynamic_fields | (mirror) | |
| edited_by_user_id | BIGINT UNSIGNED | |
| change_note | VARCHAR(512) | |
| created_at | DATETIME | |

Index on (template_id, version_number).

### 11.4 `email_events`

Maps system events to templates and routing.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| event_key | VARCHAR(96) UNIQUE | e.g. `survey.published` |
| description | VARCHAR(255) | |
| customer_template_id | BIGINT UNSIGNED NULL | |
| employee_template_id | BIGINT UNSIGNED NULL | |
| recipient_resolver | VARCHAR(96) | name of resolver class / function |
| dedupe_window_minutes | INT UNSIGNED DEFAULT 0 | |
| priority | ENUM('P0','P1','P2','P3') | |
| is_required | TINYINT(1) | |
| is_active | TINYINT(1) | |

### 11.5 `email_logs`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| event_key | VARCHAR(96) | |
| template_id | BIGINT UNSIGNED FK | |
| template_version | INT UNSIGNED | |
| department_id | BIGINT UNSIGNED FK | |
| sender_email | VARCHAR(128) | |
| sender_display_name | VARCHAR(128) | |
| recipient_user_id | BIGINT UNSIGNED NULL | |
| recipient_email | VARCHAR(255) | |
| recipient_role | VARCHAR(64) NULL | |
| customer_account_id | BIGINT UNSIGNED NULL | |
| subject | VARCHAR(512) | |
| preview | VARCHAR(255) | |
| body_snapshot_hash | CHAR(64) | sha256 of sanitized body |
| sanitized_body | MEDIUMTEXT | scrubbed of restricted variables |
| dynamic_payload | JSON | sanitized variables only |
| idempotency_key | VARCHAR(96) UNIQUE | |
| provider_message_id | VARCHAR(255) NULL | |
| status | ENUM('queued','sending','sent','delivered','opened','clicked','bounced','failed','failed_permanent','suppressed','complained') | |
| attempts | INT UNSIGNED DEFAULT 0 | |
| last_error | VARCHAR(512) NULL | |
| sent_at | DATETIME NULL | |
| delivered_at | DATETIME NULL | |
| created_at, updated_at | DATETIME | |

Indexes: (recipient_user_id, created_at), (customer_account_id, created_at), (event_key, created_at), (status).

### 11.6 `email_preferences`

One row per customer per preference group.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| user_id | BIGINT UNSIGNED FK | |
| preference_group | VARCHAR(64) | |
| is_enabled | TINYINT(1) | |
| digest_mode | ENUM('immediate','daily','weekly') DEFAULT 'immediate' | |
| updated_by | ENUM('user','system','admin') | |
| updated_at | DATETIME | |

UNIQUE(user_id, preference_group).

### 11.7 `employee_notification_preferences`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| employee_user_id | BIGINT UNSIGNED FK | |
| preference_group | VARCHAR(64) | |
| is_enabled | TINYINT(1) | |
| can_disable | TINYINT(1) | derived from role + role_required_notifications |
| updated_by | ENUM('employee','admin','system') | |
| updated_at | DATETIME | |

UNIQUE(employee_user_id, preference_group).

### 11.8 `unsubscribe_tokens`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| user_id | BIGINT UNSIGNED FK | |
| preference_group | VARCHAR(64) | |
| token | CHAR(64) UNIQUE | |
| created_at | DATETIME | |
| used_at | DATETIME NULL | |
| expires_at | DATETIME | |

### 11.9 `email_delivery_failures`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| email_log_id | BIGINT UNSIGNED FK | |
| attempt_number | INT UNSIGNED | |
| error_code | VARCHAR(64) | |
| error_message | VARCHAR(512) | |
| provider_response | TEXT | |
| failed_at | DATETIME | |

### 11.10 `email_click_events`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| email_log_id | BIGINT UNSIGNED FK | |
| url | VARCHAR(1024) | |
| clicked_at | DATETIME | |
| user_agent | VARCHAR(255) | |
| ip_hash | CHAR(64) | |

### 11.11 `email_open_events`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| email_log_id | BIGINT UNSIGNED FK | |
| opened_at | DATETIME | |
| user_agent | VARCHAR(255) | |
| ip_hash | CHAR(64) | |

### 11.12 `email_audit_logs`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| actor_user_id | BIGINT UNSIGNED FK | |
| action | VARCHAR(64) | e.g. `template.edit`, `email.resend`, `suppression.add` |
| target_type | VARCHAR(64) | |
| target_id | BIGINT UNSIGNED NULL | |
| before_json | JSON NULL | |
| after_json | JSON NULL | |
| created_at | DATETIME | |

### 11.13 `email_suppression_list`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| email | VARCHAR(255) UNIQUE | |
| reason | ENUM('hard_bounce','complaint','manual','invalid') | |
| added_at | DATETIME | |
| added_by_user_id | BIGINT UNSIGNED NULL | |
| notes | VARCHAR(512) NULL | |

### 11.14 Optional supporting tables

- `email_event_buffer`: rows for digestible events, fields `id, user_id, event_key, payload_json, created_at, flushed_at NULL`.
- `role_required_notifications`: maps role -> required preference_group.
- `email_send_jobs`: queue table if you do not use Redis / SQS.

---

## 12. Section K: Admin Panel Requirements

### 12.1 Navigation

Add a top-level "Email System" section to the admin panel with these tabs:

1. Departments
2. Templates
3. Logs
4. Failures
5. Preferences (Customer)
6. Preferences (Employee)
7. Suppression List
8. Audit Log

### 12.2 Capabilities by tab

**Departments**: View only. Display department code, display name, sender address, email class, audience, active status.

**Templates**: List view filterable by department, audience, required, active. Detail view shows current version, dynamic fields, audience, restricted_data flag, version history. Actions: edit (per-department permission), preview (render with sample data), test-send (to allowlisted internal addresses), activate / deactivate.

**Logs**: Filterable by customer, employee, department, event_key, status, date range. Row click opens log detail with sanitized body, status timeline (queued -> sent -> delivered -> opened -> clicked), and resend action where allowed (verification, support reply, password reset). Restricted variables never display.

**Failures**: List of `email_delivery_failures` joined with `email_logs`. Actions: retry (where eligible), suppress recipient.

**Preferences (Customer)**: Read-only view of a customer's preferences. Owner / privacy / support leads can override with audit entry.

**Preferences (Employee)**: Edit view per employee, gated by HR + owner.

**Suppression List**: View and manually add / remove. Bulk import supported.

**Audit Log**: Read-only stream of `email_audit_logs`, filterable by actor and action.

### 12.3 Access control matrix

| Capability | Owner | Upper mgmt | HR | Marketing | Sales | Membership | Billing | Privacy | Legal | Support | Services |
|---|---|---|---|---|---|---|---|---|---|---|---|
| View departments | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y |
| View templates (own dept) | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y |
| Edit general (Welcome, Services, Membership) templates | Y | Y | N | N | N | dept-only | N | N | N | N | dept-only |
| Edit Marketing templates | Y | Y | N | Y | N | N | N | N | N | N | N |
| Edit Sales templates | Y | Y | N | N | Y | N | N | N | N | N | N |
| Edit Billing templates | Y | Y | N | N | N | N | Y | N | N | N | N |
| Edit HR templates | Y | Y | Y | N | N | N | N | N | N | N | N |
| Edit Privacy templates | Y | Y | N | N | N | N | N | Y | N | N | N |
| Edit Terms templates | Y | Y | N | N | N | N | N | Y | Y | N | N |
| Edit Legal templates | Y | Y | N | N | N | N | N | N | Y | N | N |
| Edit Support templates | Y | Y | N | N | N | N | N | N | N | Y | N |
| View customer email history | Y | Y | N | view-own-dept | view-own-dept | Y | Y | Y | Y | Y | Y |
| Resend verification | Y | Y | N | N | N | Y | N | N | N | Y | N |
| Resend support email | Y | Y | N | N | N | N | N | N | N | Y | N |
| View suppression list | Y | Y | N | Y | Y | N | Y | Y | N | Y | N |
| Edit customer preferences | Y | Y | N | N | N | N | N | Y | N | Y | N |
| Edit employee preferences | Y | Y | Y | N | N | N | N | N | N | N | N |
| View audit log | Y | Y | view-own-actions | view-own-actions | view-own-actions | view-own-actions | view-own-actions | Y | Y | view-own-actions | view-own-actions |

Customer service staff may view email history but never see restricted survey data through it. The system enforces this by filtering `dynamic_payload` and `sanitized_body` to scrub restricted variables for any role lacking `PERM_PRIVATE_DATA_ACCESS`.

### 12.4 Filtering and search

All list views support filters: department, event_key, status, date range, customer account, employee account. Logs view supports CSV export of headers (no body content).

---

## 13. Section L: Email Sending Rules

| Rule | Trigger | Latency target |
|---|---|---|
| Send Welcome / Verify Email immediately after signup | `user.created` | < 30 sec |
| Send Account Confirmed immediately after verification | `user.email_verified` | < 30 sec |
| Send Getting Started Guidance after first login | `user.first_login` | < 5 min |
| Send Password Reset immediately | `password.reset_requested` | < 30 sec |
| Send Password Changed immediately | `password.changed` | < 30 sec |
| Send New Login Alert when risk rules trip | `auth.new_device_or_location` | < 1 min |
| Send Survey Is Live immediately on launch | `survey.published` | < 30 sec |
| Send Survey Scheduled immediately on scheduling | `survey.scheduled` | < 30 sec |
| Send Low Response Rate after 2-3 days if rate < threshold | nightly job | within day |
| Send No Responses Yet after 3 days if zero responses | nightly job | within day |
| Send Survey Closed immediately at closure | `survey.closed` | < 30 sec |
| Send Report Ready immediately after report generation | `report.generated` | < 1 min |
| Send AI Insights Ready immediately after analysis | `insights.generated` | < 1 min |
| Send Trial Started immediately when trial begins | `trial.started` | < 30 sec |
| Send Trial Midpoint at trial_days/2 | scheduled | within hour |
| Send Trial Ending Soon 2 days before end | scheduled | within hour |
| Send Trial Expired immediately at expiry | scheduled | < 5 min |
| Send Payment Failed immediately after failed charge | `billing.charge.failed` | < 30 sec |
| Send Final Payment Notice after final retry | `billing.dunning.exhausted` | < 30 sec |
| Send Renewing Soon 7 days before renewal | scheduled | within hour |
| Send Support Ticket Received immediately after submission | `support.ticket.created` | < 30 sec |
| Send Awaiting Your Reply after 2-3 days idle | nightly job | within day |
| Send Ticket Overdue at SLA breach | scheduled | within minute |
| Send Privacy / Terms / Legal notices immediately when required | event-driven | < 1 min |
| Send employee / admin alerts immediately when action required | event-driven | < 1 min |
| Send marketing only when preferences allow and rate limits permit | scheduled / event | per campaign |

---

## 14. Section M: Email Copy Rules

- Do not sound robotic; write like a thoughtful colleague.
- Do not over-explain; lead with the action or outcome.
- Use short paragraphs (1 to 3 sentences).
- Use one primary action button. If a secondary action exists, render it as a plain text link below the button.
- Use clear subject lines under 60 characters.
- Avoid fake urgency unless legally or operationally necessary (Final Payment Notice, Terms Acceptance Required, Legal Notice).
- Never include private survey responses in employee or admin emails.
- Never include respondent-level data in employee or admin emails.
- Never include private AI-generated survey analysis in employee or admin emails.
- Do not include unnecessary customer data; reference the customer account by name and ID, not by personal detail.
- Keep privacy, billing, and legal emails precise; favor specific dates, amounts, and reference numbers over adjectives.
- Keep marketing emails optional and unsubscribe-friendly; include a footer with the registered business address and a working unsubscribe link.
- Make customer emails reassuring and action-oriented; the next step is always obvious.
- Make employee and admin emails direct and operational; subject line carries the entity reference, body carries SLA and link.
- Always identify the correct ReliCheck department sender.
- Always use the official sender email address ending in `@relichecksurvey.com`.
- Never invent additional sender addresses.

---

## 15. Section N: Launch Priority Phasing

### 15.1 Launch-required emails (must ship before public launch)

**Customer (24)**:
Welcome / Verify Email, Account Confirmed, Password Reset, Password Changed, New Login Alert, First Survey Created, Survey Is Live, Survey Closed, Report Ready, AI Insights Ready, Trial Started, Trial Ending Soon, Trial Expired, Membership Updated (Upgraded / Changed), Cancellation Confirmation, Payment Receipt, Payment Failed, Refund Confirmation, Support Ticket Received, Support Response, Support Ticket Closed, Privacy Policy Update, Data Export Ready, Terms Update.

**Employee / admin (19)**:
Admin Panel Invitation, Employee Account Confirmed, Employee Role Changed, Employee Access Removed, New Ticket Assigned, Customer Reply Received, Ticket Overdue, Ticket Escalated, Membership Manually Changed, Promo Code Created, Promo Code Edited, Payment Issue Alert, Refund Processed, Service Task Assigned, Privacy Review Needed, Legal Review Needed, Demo Request, New Lead, System Alert.

### 15.2 Phase 2 emails

Getting Started Guidance, Survey Scheduled, Milestone Reached, Low Response Rate, No Responses Yet, Awaiting Your Reply, Support Satisfaction Survey, Trial Midpoint, Promo Code Applied, Promo Expiring Soon, Subscription Renewing Soon, Payment Method Updated, Billing Info Changed, Plan Limit Warning, Demo Follow-Up, Institutional Pricing, Quote Follow-Up, Upgrade Reminder, Newsletter, Product Update, Survey Best Practices, Promotion, Ticket Status Update, Ticket Reassigned, Customer Upgraded (alert), Customer Cancelled (alert), Promo Code Spike, Service Task Overdue, Service Issue Escalated, Data Deletion Review, Privacy Escalation, Terms Update Published (internal), Legal Threat Alert, Legal Escalation (internal), Institutional Inquiry, High-Value Lead Alert, Quote Request, Upgrade Opportunity, Campaign Launched, Newsletter Scheduled.

### 15.3 Optional / future

Win-back, Reactivation Offer, multi-language template variants, in-app notification mirroring, SMS escalation for P0 employee alerts, Slack mirroring of P0 / P1 employee alerts (already partially supported by Phase 30 webhooks), template A/B testing framework, predictive send-time optimization, customer-defined survey-respondent reminder cadence (separate domain).

---

## 16. Appendix: Implementation notes

### 16.1 Sender configuration

Use the existing `send_mail()` helper's `opts['from']` override (already supported per project memory). Map each `template.department_id` to the official sender at render time. Reject any template whose computed sender does not match one of the eleven official addresses.

### 16.2 IONOS SMTP and timezones

Per project memory, PHP and MySQL on IONOS run in different timezones. All scheduled sends must compute "due at" inside SQL using `DATE_ADD(NOW(), INTERVAL N MINUTE)` rather than PHP `DateTimeImmutable`. The scheduler worker selects due rows with `WHERE due_at <= NOW()`.

### 16.3 Migration strategy

Ship the schema in two SQL migrations:

- `db/schema_phase31_email_core.sql`: departments, templates, template_versions, events, logs, audit_logs, suppression_list.
- `db/schema_phase32_email_preferences.sql`: email_preferences, employee_notification_preferences, unsubscribe_tokens, delivery_failures, click_events, open_events, event_buffer, role_required_notifications.

Each migration starts with `USE dbs15641829;`, includes a "select the database first" reminder comment, ends with a verification SELECT, and provides a roll-back block (per project memory standard for ReliCheck migrations).

### 16.4 Additive endpoint discipline

Per project memory, do not modify existing list / get / update endpoints to bolt on email logic. Add new endpoints under `/api/email/*` for resend, preference toggle, suppression management, and webhook receipt. Wire dispatcher calls in event listeners, not in existing controllers. Verify in production before any cleanup of legacy code paths.

### 16.5 FileZilla deployment manifest

Files to ship to IONOS for the email system:

- `/api/email/dispatcher.php`
- `/api/email/preferences.php`
- `/api/email/unsubscribe.php`
- `/api/email/resend.php`
- `/api/email/suppression.php`
- `/api/webhooks/email.php`
- `/admin/email/` (templates, logs, failures, preferences, suppression, audit)
- `/lib/EmailDispatcher.php`
- `/lib/EmailTemplateRenderer.php`
- `/lib/EmailEventResolver.php`
- `/templates/email/*.html` and `/templates/email/*.txt`
- The two migration files above (run via phpMyAdmin, not uploaded via FileZilla)

### 16.6 Test plan summary

- Unit: dispatcher dedupe, preference enforcement, restricted-variable scrubbing.
- Integration: end-to-end for the 24 customer launch templates and 19 employee launch templates.
- Negative: attempt to send an employee template that references a restricted variable, confirm the dispatcher refuses and logs the violation.
- Production smoke: send each template to an internal allowlisted address and verify rendering across Apple Mail, Outlook web, and Gmail web.

End of specification.
