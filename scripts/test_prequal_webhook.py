#!/usr/bin/env python3
import urllib.request
import json
import argparse
import sys

def test_webhook(url):
    print(f"[*] Testing Prequalification Webhook: {url}")
    
    # Test 1: Summar Qualified Case
    payload_summar = {
        "company_name": "Apex Freight Logistics LLC",
        "owner_name": "Marcus Vance",
        "phone": "(555) 019-2834",
        "email": "test-summar@apexhauling.com",
        "mc_dot": "MC-982144",
        "equipment": "Dry Van (53ft)",
        "authority_age": "over_90_days",
        "monthly_gross": "25k_50k_plus",
        "factoring_status": "not_factoring"
    }

    print("\n[1] Sending Summar Qualified Carrier Payload...")
    req = urllib.request.Request(
        url,
        data=json.dumps(payload_summar).encode('utf-8'),
        headers={'Content-Type': 'application/json', 'User-Agent': 'Prequal-Test-Suite'}
    )
    try:
        with urllib.request.urlopen(req, timeout=15) as resp:
            data = json.loads(resp.read().decode())
            print(f"    Status: HTTP {resp.status}")
            print(f"    Tier: {data.get('tier')} (Expected: SUMMAR)")
            print(f"    Rep: {data.get('rep_name')} | Booking URL: {data.get('booking_url')}")
            assert data.get('tier') == 'SUMMAR', "Tier should be SUMMAR"
            print("    [PASS] Summar Route Validated Successfully.")
    except Exception as e:
        print(f"    [FAIL] Summar test failed: {e}")

    # Test 2: BasicBlock Qualified Case (New Authority)
    payload_basicblock = {
        "company_name": "Eagle Express Trucking",
        "owner_name": "Dmitri Miller",
        "phone": "(555) 773-1920",
        "email": "test-basicblock@eaglefreight.com",
        "mc_dot": "MC-109283",
        "equipment": "26ft Box Truck",
        "authority_age": "under_90_days",
        "monthly_gross": "under_10k",
        "factoring_status": "not_factoring"
    }

    print("\n[2] Sending BasicBlock Qualified Carrier Payload...")
    req = urllib.request.Request(
        url,
        data=json.dumps(payload_basicblock).encode('utf-8'),
        headers={'Content-Type': 'application/json', 'User-Agent': 'Prequal-Test-Suite'}
    )
    try:
        with urllib.request.urlopen(req, timeout=15) as resp:
            data = json.loads(resp.read().decode())
            print(f"    Status: HTTP {resp.status}")
            print(f"    Tier: {data.get('tier')} (Expected: BASICBLOCK)")
            print(f"    Rep: {data.get('rep_name')} | Activation URL: {data.get('activation_url')}")
            assert data.get('tier') == 'BASICBLOCK', "Tier should be BASICBLOCK"
            print("    [PASS] BasicBlock Route Validated Successfully.")
    except Exception as e:
        print(f"    [FAIL] BasicBlock test failed: {e}")

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description="Test Prequalification Webhook")
    parser.add_argument("--url", default="https://n8n.businessautomationempire.online/webhook/trucker-factoring-prequal", help="Webhook endpoint URL")
    args = parser.parse_args()
    test_webhook(args.url)
