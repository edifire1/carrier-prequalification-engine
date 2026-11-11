# Carrier Factoring Prequalification Engine & Warm Handoff Architecture

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Automation: n8n](https://img.shields.io/badge/Workflow-n8n-EA4B71.svg)](https://n8n.io)
[![WordPress: Plugin](https://img.shields.io/badge/WordPress-Plugin-21759B.svg)](https://wordpress.org)
[![Status: Production Live](https://img.shields.io/badge/Status-Production%20Live-success.svg)](https://www.dispatch.businessautomationempire.com/carrier-factoring-prequalification-engine/)

An intelligent, mobile-first, 45-second micro-prequalification engine, WordPress lead capture plugin, and automated n8n routing workflow built for freight dispatchers, freight brokers, and trucking affiliate marketers.

Converts cold carrier outreach (e.g., 10,000+ carrier email lists) into high-intent factoring accounts by prequalifying carriers dynamically, pre-filling known data, and routing them to the optimal funding partner (Summar Financial vs BasicBlock) while protecting lifetime referral commissions.

---

## 🌐 Live Production Deployment

- **Live Page:** [https://www.dispatch.businessautomationempire.com/carrier-factoring-prequalification-engine/](https://www.dispatch.businessautomationempire.com/carrier-factoring-prequalification-engine/)
- **Target Audience:** Owner-Operators, Independent Fleet Owners, Freight Dispatchers, Factoring Brokers

---

## 🚀 Key Features

1. **Smart URL Pre-Fill & Verification Banner:**
   - Accepts URL parameters (`?mc=...&name=...&company=...&phone=...&email=...`).
   - Automatically displays green **"Carrier Verified"** banner.
   - Collapses contact fieldset so truckers only need to answer 2 primary questions (Authority Age & Revenue).

2. **Silent Lead Capture Ping:**
   - Immediately fires a background ping to `/wp-json/empire-dispatch/v1/prequal-ping` on page load.
   - Captures abandoned clicks in MySQL (`Form Opened` status) before the form is even submitted.

3. **Dual Redundant Submission Engine:**
   - **Primary:** Local WordPress REST Endpoint (`/wp-json/empire-dispatch/v1/prequal-submit`) storing submissions directly in MySQL `wpda_carrier_prequal_submissions` and forwarding to n8n.
   - **Fallback:** Direct webhook to n8n if the local endpoint is unreachable.

4. **Dynamic Underwriting Routing:**
   - **Summar Financial:** Matched for authorities active >90 days and higher monthly volume. Direct calendar booking with Elizabeth Valencia.
   - **BasicBlock:** Matched for new authorities (<90 days) with flat 2.5% zero-contract terms. Direct application link to Sydney Lock.

5. **WordPress Admin Leads Dashboard:**
   - Dedicated dashboard in `wp-admin` (`admin.php?page=carrier-prequal-leads`).
   - Real-time search, filtering, and one-click CSV export.

---

## 📁 Repository Structure

```
├── frontend/
│   ├── index.html                  # Standalone prequalification engine
│   └── wordpress-embed.html        # Working WordPress Custom HTML embed code
├── wordpress-plugin/
│   └── carrier-factoring-prequal-leads.php # WordPress Plugin (MySQL table, REST API, Admin Dashboard)
├── n8n/
│   ├── trucker-factoring-prequal-workflow.json # n8n lead routing & email notification workflow
│   └── README.md                   # n8n setup guide
├── scripts/
│   ├── deploy_wp_page.py           # Automated WordPress page deployment via Royal MCP
│   ├── deploy_n8n_workflow.py      # Automated n8n workflow deployment
│   └── test_prequal_webhook.py     # Webhook integration test script
├── docs/
│   ├── routing-logic.md            # Factoring underwriting logic documentation
│   └── cold-email-templates.md     # Hormozi email outreach templates & Kit setup
├── tests/
│   └── prequal.test.cjs            # End-to-end regression tests
├── LICENSE                         # MIT License
└── README.md
```

---

## 📄 License
This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
