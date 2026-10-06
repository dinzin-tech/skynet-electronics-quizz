#!/usr/bin/env bash
# ==============================================================================
# CorpQuiz GitHub Actions Self-Hosted Runner Installer
# Target: Ubuntu 22.04 / 24.04 LTS VPS
# ==============================================================================
set -euo pipefail

RUNNER_VERSION="2.322.0"
RUNNER_ARCH="x64"
RUNNER_DIR="/opt/actions-runner"
RUNNER_USER="runner"

REPO_URL="${1:-}"
RUNNER_TOKEN="${2:-}"
RUNNER_NAME="${3:-corpquiz-vps-$(hostname)}"

echo "========================================================================"
echo " CorpQuiz GitHub Actions Self-Hosted Runner Setup"
echo "========================================================================"

if [ "$(id -u)" -ne 0 ]; then
    echo "ERROR: Please run this script with sudo or as root."
    exit 1
fi

# Prompt for repository URL if not provided
if [ -z "$REPO_URL" ]; then
    read -rp "Enter GitHub Repository URL (e.g. https://github.com/dinzin-tech/skynet-electronics-quizz): " REPO_URL
fi

# Prompt for runner token if not provided
if [ -z "$RUNNER_TOKEN" ]; then
    echo ""
    echo "To get a runner registration token:"
    echo "1. Go to your GitHub repository -> Settings -> Actions -> Runners"
    echo "2. Click 'New self-hosted runner'"
    echo "3. Copy the token shown in the configuration command"
    echo ""
    read -rp "Enter Runner Registration Token: " RUNNER_TOKEN
fi

if [ -z "$REPO_URL" ] || [ -z "$RUNNER_TOKEN" ]; then
    echo "ERROR: Both REPO_URL and RUNNER_TOKEN are required!"
    exit 1
fi

echo ">>> [1/6] Installing runner system dependencies..."
apt-get update -y
apt-get install -y --no-install-recommends \
    curl tar jq git ca-certificates libicu-dev libssl-dev

echo ">>> [2/6] Configuring runner system user..."
if ! id -u "$RUNNER_USER" &>/dev/null; then
    useradd -m -s /bin/bash "$RUNNER_USER"
    usermod -aG sudo "$RUNNER_USER" 2>/dev/null || true
    echo "Created user: $RUNNER_USER"
fi

# Add runner to www-data group so it can interact with web directory
usermod -aG www-data "$RUNNER_USER" 2>/dev/null || true

echo ">>> [3/6] Applying Sudoers rules for passwordless deployment..."
if [ -f /var/www/corpquiz/ops/sudoers.d/corpquiz ]; then
    cp /var/www/corpquiz/ops/sudoers.d/corpquiz /etc/sudoers.d/corpquiz
    chmod 0440 /etc/sudoers.d/corpquiz
    echo "Sudoers drop-in installed at /etc/sudoers.d/corpquiz"
fi

echo ">>> [4/6] Downloading GitHub Actions Runner v${RUNNER_VERSION}..."
mkdir -p "$RUNNER_DIR"
cd "$RUNNER_DIR"

RUNNER_TARBALL="actions-runner-linux-${RUNNER_ARCH}-${RUNNER_VERSION}.tar.gz"
if [ ! -f "$RUNNER_TARBALL" ]; then
    curl -o "$RUNNER_TARBALL" -L "https://github.com/actions/runner/releases/download/v${RUNNER_VERSION}/${RUNNER_TARBALL}"
fi

tar xzf "$RUNNER_TARBALL"
chown -R "$RUNNER_USER":"$RUNNER_USER" "$RUNNER_DIR"

echo ">>> [5/6] Registering self-hosted runner with GitHub..."
# Install any missing runner dependencies
./bin/installdependencies.sh

# Run configuration as the runner user
su - "$RUNNER_USER" -c "cd '$RUNNER_DIR' && ./config.sh \
    --url '$REPO_URL' \
    --token '$RUNNER_TOKEN' \
    --name '$RUNNER_NAME' \
    --labels 'self-hosted,linux,x64,production' \
    --work _work \
    --unattended \
    --replace"

echo ">>> [6/6] Installing and starting runner systemd service..."
./svc.sh install "$RUNNER_USER"
./svc.sh start

echo "========================================================================"
echo ">>> Runner installed and registered successfully!"
echo ">>> Runner Name   : $RUNNER_NAME"
echo ">>> Status Check  : ./svc.sh status (or systemctl status actions.runner.*)"
echo "========================================================================"
