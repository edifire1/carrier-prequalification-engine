#!/usr/bin/env python3
import os
import urllib.request
import json
import ssl

def deploy(n8n_url, api_key, workflow_file):
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE

    with open(workflow_file, 'r', encoding='utf-8') as f:
        payload = json.load(f)

    headers = {
        'X-N8N-API-KEY': api_key,
        'Content-Type': 'application/json',
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
    }

    req = urllib.request.Request(f"{n8n_url}/api/v1/workflows", data=json.dumps(payload).encode('utf-8'), headers=headers)
    with urllib.request.urlopen(req, context=ctx, timeout=15) as resp:
        res = json.loads(resp.read().decode())
        wf_id = res.get('id')
        print(f"[+] Created Workflow ID: {wf_id} ({res.get('name')})")

    # Activate
    act_req = urllib.request.Request(f"{n8n_url}/api/v1/workflows/{wf_id}/activate", data=b'{}', headers=headers)
    with urllib.request.urlopen(act_req, context=ctx, timeout=10) as act_resp:
        act_res = json.loads(act_resp.read().decode())
        print(f"[+] Activation Status: {act_res.get('active')}")

if __name__ == '__main__':
    n8n_url = os.environ.get('N8N_URL', 'https://n8n.businessautomationempire.online')
    api_key = os.environ.get('N8N_API_KEY')
    if not api_key:
        print("Please export N8N_API_KEY before running.")
        exit(1)
    wf_path = os.path.join(os.path.dirname(__file__), '..', 'n8n', 'trucker-factoring-prequal-workflow.json')
    deploy(n8n_url, api_key, wf_path)
