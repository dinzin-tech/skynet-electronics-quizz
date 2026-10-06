#!/usr/bin/env bash
# ==============================================================================
# CorpQuiz Observability Setup Script (Idempotent)
# Target: Ubuntu 22.04 / 24.04 LTS on Hostinger KVM 2 VPS (2 vCPU, 8 GB RAM)
#
# Usage:
#   sudo bash setup-observability.sh --local      # Option 1: Full local Prometheus on VPS
#   sudo bash setup-observability.sh --remote     # Option 2: Lightweight exporters only (offload to Grafana Cloud)
# ==============================================================================
set -euo pipefail

MODE="${1:-}"

if [ -z "$MODE" ]; then
    echo "=============================================================================="
    echo " CorpQuiz Observability Setup"
    echo "=============================================================================="
    echo "Choose deployment mode:"
    echo "  1) Local Prometheus (Installs Prometheus + Node Exporter directly on this VPS)"
    echo "  2) Remote / Grafana Cloud (Installs ONLY Node Exporter; offloads TSDB & alerts)"
    echo "=============================================================================="
    read -r -p "Enter choice [1 or 2] (Default 1): " CHOICE
    if [ "$CHOICE" = "2" ]; then
        MODE="--remote"
    else
        MODE="--local"
    fi
fi

export DEBIAN_FRONTEND=noninteractive

if [ "$MODE" = "--remote" ]; then
    echo ">>> [Mode: REMOTE] Setting up lightweight exporters for external scraping..."

    echo ">>> [1/3] Installing prometheus-node-exporter & logrotate..."
    apt-get update && apt-get install -y --no-install-recommends \
        prometheus-node-exporter logrotate

    systemctl enable prometheus-node-exporter
    systemctl restart prometheus-node-exporter

    # If Prometheus was previously installed locally, stop and disable it to save CPU/RAM
    if systemctl is-active --quiet prometheus 2>/dev/null; then
        echo "Disabling local Prometheus server to free VPS resources..."
        systemctl stop prometheus || true
        systemctl disable prometheus || true
    fi

    echo ">>> [2/3] Setting up Logrotate for CorpQuiz Logs..."
    cat << 'EOF' > /etc/logrotate.d/corpquiz
/var/log/corpquiz/*.log {
    daily
    missingok
    rotate 7
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
    sharedscripts
    postrotate
        systemctl reload quiz-flusher quiz-scheduler quiz-finalizer quiz-jobs > /dev/null 2>&1 || true
    endscript
}
EOF

    echo ">>> [3/3] Testing Application Metrics Endpoint..."
    if curl -s http://127.0.0.1/metrics | grep -q "quiz_queue_dirty_attempts"; then
        echo "SUCCESS: Local endpoint /metrics is responding."
    else
        echo "WARNING: /metrics not reachable on 127.0.0.1:80. Make sure Nginx and PHP-FPM are reloaded."
    fi

    echo ""
    echo "=============================================================================="
    echo " REMOTE OBSERVABILITY READY (Zero TSDB overhead on 2 vCPU VPS)!"
    echo "=============================================================================="
    echo "1. Node Exporter is running on port 9100."
    echo "2. CorpQuiz Metrics available at http://127.0.0.1/metrics."
    echo "3. Add this scrape target to your external Prometheus or Grafana Cloud Agent:"
    echo ""
    echo "   - job_name: 'corpquiz'"
    echo "     metrics_path: '/metrics'"
    echo "     scheme: 'https'"
    echo "     static_configs:"
    echo "       - targets: ['<YOUR_DOMAIN_OR_VPS_IP>']"
    echo ""
    echo "   - job_name: 'node_exporter'"
    echo "     static_configs:"
    echo "       - targets: ['<YOUR_VPS_IP>:9100']"
    echo "=============================================================================="
    exit 0
fi

# ==============================================================================
# MODE: LOCAL
# ==============================================================================
echo ">>> [Mode: LOCAL] Installing Full Prometheus & Exporters on this VPS..."

echo ">>> [1/4] Installing Prometheus, Node Exporter, & Logrotate..."
apt-get update && apt-get install -y --no-install-recommends \
    prometheus prometheus-node-exporter logrotate

echo ">>> [2/4] Configuring Prometheus & Alerting Rules..."
mkdir -p /etc/prometheus

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cp "${SCRIPT_DIR}/prometheus/prometheus.yml" /etc/prometheus/prometheus.yml
cp "${SCRIPT_DIR}/prometheus/alert.rules.yml" /etc/prometheus/alert.rules.yml

if command -v promtool &> /dev/null; then
    echo "Validating Prometheus rules with promtool..."
    promtool check rules /etc/prometheus/alert.rules.yml
    promtool check config /etc/prometheus/prometheus.yml
fi

systemctl restart prometheus prometheus-node-exporter
systemctl enable prometheus prometheus-node-exporter

echo ">>> [3/4] Setting up Logrotate for CorpQuiz Logs..."
cat << 'EOF' > /etc/logrotate.d/corpquiz
/var/log/corpquiz/*.log {
    daily
    missingok
    rotate 7
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
    sharedscripts
    postrotate
        systemctl reload quiz-flusher quiz-scheduler quiz-finalizer quiz-jobs > /dev/null 2>&1 || true
    endscript
}
EOF

echo ">>> [4/4] Testing Application Metrics Endpoint..."
if curl -s http://127.0.0.1/metrics | grep -q "quiz_queue_dirty_attempts"; then
    echo "SUCCESS: Local Prometheus endpoint /metrics is responding."
else
    echo "WARNING: /metrics not reachable on 127.0.0.1:80. Make sure Nginx and PHP-FPM are reloaded."
fi

echo ""
echo "=============================================================================="
echo " LOCAL OBSERVABILITY READY!"
echo "=============================================================================="
echo "Prometheus is running on: http://127.0.0.1:9090"
echo "Node Exporter is running on: http://127.0.0.1:9100/metrics"
echo "CorpQuiz Metrics available at: http://127.0.0.1/metrics"
echo "Import 'ops/observability/grafana/corpquiz-dashboard.json' into Grafana."
echo "=============================================================================="
