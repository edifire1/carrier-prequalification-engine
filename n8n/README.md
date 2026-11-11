# n8n Workflow Configuration Guide

## Workflow Overview
- **Name:** Trucker Factoring Prequal & Warm Handoff to Elizabeth (Summar Financial)
- **Input:** HTTP POST JSON Webhook (`/webhook/trucker-factoring-prequal`)
- **Output:** Synchronous HTTP 200 JSON Response + Multi-party Email Notification

---

## Step-by-Step Installation

### 1. Import Workflow
1. Open your n8n workspace.
2. Select **Workflows** > **Import from File**.
3. Choose `trucker-factoring-prequal-workflow.json`.

### 2. Connect Gmail OAuth2 Credential
The workflow contains two Gmail nodes:
1. `Send Intro Email to Elizabeth (Summar)`
2. `Send BasicBlock Activation Email`

Double click each node, click **Credential for Gmail OAuth2**, and select your connected Google account (e.g. `your-email@gmail.com`).

### 3. Verify Webhook Path
The webhook trigger path is set to:
`trucker-factoring-prequal`

Make sure the **Allowed Origins** setting is set to `*` to allow browser `fetch()` requests from your website domain.

### 4. Activate Workflow
Click the **Active** toggle switch in the upper right corner of your n8n canvas.

Test webhook URL:
`https://<your-n8n-instance>/webhook-test/trucker-factoring-prequal`

Production webhook URL:
`https://<your-n8n-instance>/webhook/trucker-factoring-prequal`
