# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

`mox3-utils` is a reusable package containing common tools, workflow templates, and documentation for projects. It's designed to be added as a dependency or reference to standardize development practices across multiple projects.

**Current Contents:**
- Laravel deployment workflow template (`Laravel-Deploy-Sample.yml`)

## Deployment Workflow (Laravel-Deploy-Sample.yml)

The sample deployment workflow is designed for Laravel applications and includes:

**Build Process:**
- PHP 8.3 setup with Composer dependency installation (optimized, no-dev)
- Node.js 20 setup with npm dependencies and asset building

**Deployment Process:**
- SSH-based deployment using rsync to Cloudways hosting
- Uses `rsync -rlpvz` flags (recursive, symlinks, permissions, verbose, compression)
- Note: `-t` (preserve times) flag excluded due to Cloudways hosting restrictions
- Excludes: `.git`, `node_modules`, database files, `storage`, and `.env`
- File permissions reset via Cloudways API after deployment

**Post-Deployment Commands:**
```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

**Required GitHub Secrets/Variables:**
- `DEPLOY_CLOUDWAYS_SSH_KEY` (secret): SSH private key
- `DEPLOY_CLOUDWAYS_HOST` (variable): Server hostname
- `DEPLOY_CLOUDWAYS_USER` (variable): SSH username
- `DEPLOY_PATH` (variable): Target deployment directory
- `SLACK_WEBHOOK_URL` (secret): Slack webhook for notifications

**Slack Integration:**
- Sends deployment status notifications with commit details
- Includes links to commit and workflow run
- Uses emoji indicators (✅/❌) for success/failure

## Usage

This package is intended to be referenced or included in other projects to provide:
- GitHub Actions workflow templates
- Common utility functions/scripts
- Standardized documentation templates
- Project configuration examples

When adding new templates or utilities, ensure they are:
- Well-documented with clear usage instructions
- Generalized for use across different projects
- Configurable via environment variables or parameters where appropriate
