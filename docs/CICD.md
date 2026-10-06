# GitHub Actions CI/CD Setup Guide (Self-Hosted Production)

This guide documents the continuous integration and continuous deployment (CI/CD) pipelines for CorpQuiz on self-hosted infrastructure (Hostinger KVM 2 VPS or equivalent Ubuntu LTS server).

---

## 🏗️ Architecture Overview

The system includes two workflows:

1. **Continuous Integration (`.github/workflows/ci.yml`)**:
   - Triggers on all branch pushes and pull requests targeting `master`/`main`.
   - Runs backend syntax and style checks via **PHPCS (PSR-12)**.
   - Spins up **isolated MySQL 8.0 and Redis 7 service containers** on GitHub runners.
   - Runs database migrations and full **PHPUnit test suites**.
   - Validates and compiles the Employee React SPA bundle (`npm --prefix spa run build`).

2. **Continuous Deployment (`.github/workflows/deploy.yml`)**:
   - Triggers automatically upon merging/pushing to `master`, or manually via GitHub's **Run workflow** button (`workflow_dispatch`).
   - Supports **two deployment execution modes**:
     - **Mode 1 (SSH Remote Execution)**: A GitHub-hosted runner connects over SSH to your VPS and runs `/var/www/corpquiz/ops/deploy.sh`.
     - **Mode 2 (Self-Hosted Runner)**: An `actions-runner` daemon running directly on your VPS picks up the job and executes `/var/www/corpquiz/ops/deploy.sh` locally.

---

## 🔑 Mode 1: SSH-Based Deployment Setup (Recommended)

In this mode, GitHub Actions uses encrypted SSH keys stored in GitHub Secrets. Your VPS does **not** need to run any background runner daemons, saving CPU and RAM for quiz traffic.

### Step 1: Generate an SSH Keypair on Your Local Machine or VPS

Run on your local machine (or server):
```bash
ssh-keygen -t ed25519 -C "github-actions-deploy@corpquiz" -f ~/.ssh/corpquiz_deploy
```
This generates two files:
- `~/.ssh/corpquiz_deploy` (Private Key)
- `~/.ssh/corpquiz_deploy.pub` (Public Key)

### Step 2: Install Public Key on the VPS

Append the public key to the deploy or root user's authorized keys on the VPS:
```bash
# On your VPS:
mkdir -p ~/.ssh && chmod 700 ~/.ssh
cat ~/.ssh/corpquiz_deploy.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

### Step 3: Configure GitHub Secrets

Go to your repository on GitHub:
**Settings** > **Secrets and variables** > **Actions** > **New repository secret**:

| Secret Name | Description | Example Value |
|---|---|---|
| `SERVER_HOST` | VPS public IP or hostname | `194.163.xxx.xxx` or `quiz.corp.local` |
| `SERVER_USER` | SSH user | `root` or `deploy` |
| `SSH_PRIVATE_KEY` | Entire content of `~/.ssh/corpquiz_deploy` | `-----BEGIN OPENSSH PRIVATE KEY-----...` |
| `SERVER_PORT` | *(Optional)* Custom SSH port | `22` (default) |

---

## 🏃 Mode 2: Dedicated Self-Hosted Runner Setup

In this mode, GitHub Actions jobs with `runs-on: self-hosted` run directly inside your VPS. No inbound SSH ports need to be exposed to GitHub.

### Step 1: Obtain a Runner Token from GitHub

1. In your GitHub repository, navigate to:
   **Settings** > **Actions** > **Runners** > **New self-hosted runner**
2. Select **OS: Linux**, **Architecture: x64**.
3. Under *Download & Configure*, copy the **Token** shown (e.g. `AB12C345...`).

### Step 2: Run the Automated Provisioning Script on Your VPS

SSH into your Hostinger VPS as `root` and execute:
```bash
cd /var/www/corpquiz
sudo ./ops/setup-runner.sh "https://github.com/dinzin-tech/skynet-electronics-quizz" "<YOUR_RUNNER_TOKEN>"
```

The script will automatically:
- Install all required libraries (`libicu`, `curl`, `jq`, etc.).
- Create a dedicated `runner` system user and add it to `www-data`.
- Install the drop-in sudoers file (`/etc/sudoers.d/corpquiz`) for passwordless deployment commands.
- Download, configure, and register the GitHub Actions runner.
- Register and start the systemd service (`actions.runner.*`).

### Step 3: Verify Runner Status

Check that the runner service is active:
```bash
cd /opt/actions-runner
sudo ./svc.sh status
```
In your GitHub repo under **Settings > Actions > Runners**, the runner will show as **Idle / Online** with labels `self-hosted`, `linux`, `x64`, `production`.

### Step 4: Configure GitHub Variables for Mode 2

To set Self-Hosted runner as the default mode for automatic git push events:
Go to **Settings** > **Secrets and variables** > **Actions** > **Variables** tab > **New repository variable**:
- Name: `DEPLOY_METHOD`
- Value: `self-hosted`

*(If `DEPLOY_METHOD` is not set or set to `ssh`, the pipeline defaults to Mode 1).*

---

## 🚀 Running Deployments

### 1. Automatic Deployment on Push
Whenever changes are pushed or merged into the `master` branch:
1. CI tests run.
2. The `Deploy to Self-Hosted Production` workflow activates and deploys using the configured default method.

### 2. Manual Deployment (Workflow Dispatch)
You can deploy any branch on demand:
1. Go to **Actions** tab in GitHub.
2. Click **Deploy to Self-Hosted Production**.
3. Click **Run workflow**:
   - **Deployment execution method**: Choose `ssh` or `self-hosted`.
   - **Branch to deploy**: Enter `master` or feature branch name.
4. Click **Run workflow**.

---

## 🔍 What Happens During Deployment (`ops/deploy.sh`)

Every deployment follows a 7-step zero-downtime procedure:
1. **Pull Latest Changes:** Fetches and fast-forwards the target git branch in `/var/www/corpquiz`.
2. **Install PHP Dependencies:** Runs `composer install --no-dev --optimize-autoloader --quiet`.
3. **Compile SPA Bundle:** Builds the React + Vite bundle into `/public/app/`.
4. **Cache Configuration:** Runs `php bin/console config:cache`.
5. **Run Migrations:** Executes pending database migrations via `php bin/console migrations run`.
6. **Reload Daemons:** Gracefully reloads PHP-FPM (`systemctl reload php8.2-fpm`) and restarts background workers (`quiz-flusher`, `quiz-scheduler`, `quiz-finalizer`, `quiz-jobs`).
7. **Smoke Test:** Hits `http://127.0.0.1/healthz` and verifies an HTTP 200 response before marking the build as successful.

---

## 🔄 Rollback Procedure

If a deployment fails the smoke test or an issue is discovered:
1. Revert to the previous commit locally or on GitHub:
   ```bash
   git revert HEAD
   git push origin master
   ```
2. Or trigger a manual deployment to the previous known good commit/tag using **Run workflow**.
3. Or manually on the VPS:
   ```bash
   cd /var/www/corpquiz
   git reset --hard HEAD~1
   ./ops/deploy.sh master
   ```
