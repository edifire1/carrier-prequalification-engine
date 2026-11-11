#!/usr/bin/env python3
import os
import urllib.request
import json
import ssl

def deploy_page(wp_url, auth_token, content_file):
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE

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
        with urllib.request.urlopen(req, context=ctx, timeout=20) as resp:
            sid = resp.headers.get('mcp-session-id')
            return json.loads(resp.read().decode()), sid

    # Init
    init_res, sid = mcp_call('initialize', {'protocolVersion': '2024-11-05', 'capabilities': {}, 'clientInfo': {'name': 'deployer', 'version': '1.0'}})
    print(f"[+] MCP Initialized: {sid}")

    # Create Page
    create_res, _ = mcp_call('tools/call', {
        'name': 'wp_create_page',
        'arguments': {
            'title': 'Carrier Factoring Prequalification Engine',
            'content': html_content,
            'status': 'publish'
        }
    }, sid)
    print(f"[+] Page Created: {create_res}")

    # Purge Cache
    try:
        purge_res, _ = mcp_call('tools/call', {'name': 'litespeed_purge_cache', 'arguments': {}}, sid)
        print(f"[+] LiteSpeed Purge: {purge_res}")
    except Exception as e:
        print(f"[-] Purge error: {e}")

if __name__ == '__main__':
    wp_url = os.environ.get('WP_MCP_URL', 'https://www.dispatch.businessautomationempire.com/wp-json/royal-mcp/v1/mcp')
    auth_token = os.environ.get('WP_AUTH_TOKEN')
    if not auth_token:
        print("Please export WP_AUTH_TOKEN before running.")
        exit(1)
    content_path = os.path.join(os.path.dirname(__file__), '..', 'frontend', 'wordpress-embed.html')
    deploy_page(wp_url, auth_token, content_path)
