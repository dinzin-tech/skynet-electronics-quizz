#!/usr/bin/env python3
"""
Nginx Configuration Renderer for CorpQuiz
Dynamically generates Nginx configuration with optional Let's Encrypt SSL.
"""
import argparse
import os
import re
import sys

def parse_args():
    parser = argparse.ArgumentParser(description="Render CorpQuiz Nginx configuration")
    parser.add_argument("--template", default="ops/nginx/corpquiz.conf", help="Path to base corpquiz.conf")
    parser.add_argument("--domain", default=None, help="Target domain (e.g. quiz.example.com)")
    parser.add_argument("--app-dir", default="/var/www/corpquiz", help="Root application directory")
    parser.add_argument("--ssl-cert", default=None, help="Custom SSL certificate path")
    parser.add_argument("--ssl-key", default=None, help="Custom SSL private key path")
    parser.add_argument("--ssl-mode", choices=["auto", "force", "off"], default="auto", help="SSL mode")
    parser.add_argument("--output", default=None, help="Output file path (default stdout)")
    return parser.parse_args()

def get_domain_from_env(app_dir):
    env_file = os.path.join(app_dir, ".env")
    if not os.path.isfile(env_file):
        return None
    app_domain = None
    base_url = None
    try:
        with open(env_file, "r", encoding="utf-8", errors="ignore") as f:
            for line in f:
                line = line.strip()
                if line.startswith("#") or "=" not in line:
                    continue
                k, v = line.split("=", 1)
                k = k.strip()
                v = v.strip().strip("'\"")
                if k == "APP_DOMAIN" and v:
                    app_domain = v
                elif k == "BASE_URL" and v:
                    base_url = v
    except Exception:
        pass
    if app_domain:
        return app_domain
    if base_url:
        m = re.match(r"^https?://([^/:]+)", base_url)
        if m:
            return m.group(1)
    return None

def main():
    args = parse_args()

    domain = args.domain
    if not domain:
        domain = get_domain_from_env(args.app_dir) or "quiz.corp.local"

    with open(args.template, "r", encoding="utf-8") as f:
        template = f.read()

    # Determine SSL paths
    ssl_cert = args.ssl_cert or f"/etc/letsencrypt/live/{domain}/fullchain.pem"
    ssl_key = args.ssl_key or f"/etc/letsencrypt/live/{domain}/privkey.pem"

    enable_ssl = False
    if args.ssl_mode == "force":
        enable_ssl = True
    elif args.ssl_mode == "auto":
        is_local = domain in ["localhost", "127.0.0.1", "quiz.corp.local"]
        if not is_local and os.path.isfile(ssl_cert) and os.path.isfile(ssl_key):
            enable_ssl = True

    pattern = re.compile(r"server\s*\{\s*listen\s+80;\s*listen\s+\[::\]:80;\s*server_name\s+[^;]+;", re.MULTILINE)

    if enable_ssl:
        replacement = f"""# ==============================================================================
# Port 80 HTTP - Redirect to HTTPS
# ==============================================================================
server {{
    listen 80;
    listen [::]:80;
    server_name {domain};

    # Local healthcheck endpoint exempt from HTTPS redirect
    location = /healthz {{
        access_log off;
        return 200 "OK\\n";
    }}

    # Let's Encrypt ACME challenge verification path
    location /.well-known/acme-challenge/ {{
        root {args.app_dir}/public;
    }}

    # Redirect all other HTTP traffic to HTTPS
    location / {{
        return 301 https://$host$request_uri;
    }}
}}

# ==============================================================================
# Port 443 HTTPS - Production Application
# ==============================================================================
server {{
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name {domain};

    ssl_certificate {ssl_cert};
    ssl_certificate_key {ssl_key};
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;"""
        output = pattern.sub(replacement, template, count=1)
    else:
        # Standard HTTP with domain substitution
        def sub_http(match):
            return f"server {{\n    listen 80;\n    listen [::]:80;\n    server_name {domain};"
        output = pattern.sub(sub_http, template, count=1)

    if args.output:
        with open(args.output, "w", encoding="utf-8") as f:
            f.write(output)
    else:
        sys.stdout.write(output)

if __name__ == "__main__":
    main()
