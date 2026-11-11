#!/usr/bin/env python3
"""
Deploy WordPress embed content to the existing Carrier Factoring Prequalification Engine page.
Uses verified TLS certificates and updates the existing page ID (223) rather than creating duplicates.
"""
import os
import sys
import urllib.request
import json
import ssl

PAGE_ID = int(os.environ.get('WP_PAGE_ID', '223'))

def deploy_page(wp_url, auth_token, content_file):
    # Enforce verified TLS certificate validation
    ctx = ssl.create_default_context()

    if not os.path.exists(content_file):
        print(f"[-] Content file not found: {content_file}", file=sys.stderr)
        sys.exit(1)

    with open(content_file, 'r', encoding='utf-8') as f:
        html_content = f.read()

    def mcp_call(method, params, session_id=None):
        payload = {'jsonrpc': '2.0', 'id': 1, 'method': method, 'params': params}
        headers = {
            'Authorization': f'Bearer {auth_token}',
            'Content-Type': 'application/json',
            'User-Agent': 'Empire-Dispatch-Deployer'
        }
        if session_id:
            headers['Mcp-Session-Id'] = session_id
        req = urllib.request.Request(wp_url, data=json.dumps(payload).encode('utf-8'), headers=headers)
        with urllib.request.urlopen(req, context=ctx, timeout=30) as resp:
            sid = resp.headers.get('mcp-session-id')
            return json.loads(resp.read().decode('utf-8')), sid

    # 1. MCP Protocol Handshake
    init_res, sid = mcp_call('initialize', {
        'protocolVersion': '2024-11-05',
        'capabilities': {},
        'clientInfo': {'name': 'deployer', 'version': '1.1'}
    })
    print(f"[+] MCP Initialized successfully (Session: {sid[:8]}...)")

    # 2. Update Existing Page (ID: 223)
    update_res, _ = mcp_call('tools/call', {
        'name': 'wp_update_post',
        'arguments': {
            'id': PAGE_ID,
            'content': html_content
        }
    }, sid)
    print(f"[+] Page {PAGE_ID} updated successfully.")

    # 3. Purge LiteSpeed Cache
    try:
        purge_res, _ = mcp_call('tools/call', {
            'name': 'litespeed_purge_cache',
            'arguments': {}
        }, sid)
        print(f"[+] LiteSpeed Cache Purged.")
    except Exception as e:
        print(f"[-] Cache purge notification: {e}")

if __name__ == '__main__':
    wp_url = os.environ.get('WP_MCP_URL', 'https://www.dispatch.businessautomationempire.com/wp-json/royal-mcp/v1/mcp')
    auth_token = os.environ.get('WP_AUTH_TOKEN')
    
    # Fallback to local secure credentials file if env var is not set
    if not auth_token:
        default_cred = r'D:\EMPIRE GROUP\N8N\APIs\Wordpress dispatch-businessautomationempire MCP Server API.txt'
        if os.path.exists(default_cred):
            with open(default_cred, 'r', encoding='utf-8') as f:
                auth_token = f.read().splitlines()[0].strip()

    if not auth_token:
        print("Please export WP_AUTH_TOKEN before running.", file=sys.stderr)
        sys.exit(1)

    content_path = os.path.join(os.path.dirname(__file__), '..', 'frontend', 'wordpress-embed.html')
    deploy_page(wp_url, auth_token, content_path)
