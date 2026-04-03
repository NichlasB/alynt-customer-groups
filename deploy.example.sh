#!/bin/bash
# Copy this file to deploy.sh and customize deploy.sh locally.
# Keep deploy.sh gitignored; commit only deploy.example.sh.
set -e

REMOTE_HOST="your-ssh-alias"
REMOTE_PATH="/var/www/your-site/htdocs/wp-content/plugins/alynt-customer-groups"

echo "Deploying alynt-customer-groups..."
rsync -avz --delete \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='node_modules' \
  --exclude='vendor' \
  --exclude='tests' \
  --exclude='docs' \
  --exclude='scripts/' \
  --exclude='build/' \
  --exclude='assets/src/' \
  --exclude='coverage' \
  --exclude='.DS_Store' \
  --exclude='.env' \
  --exclude='.env.local' \
  --exclude='composer.phar' \
  --exclude='composer.json' \
  --exclude='composer.lock' \
  --exclude='package.json' \
  --exclude='package-lock.json' \
  --exclude='.phpcs.xml' \
  --exclude='.phpcs.xml.dist' \
  --exclude='.gitignore' \
  --exclude='.gitattributes' \
  --exclude='.editorconfig' \
  --exclude='phpunit.xml' \
  --exclude='phpunit.xml.dist' \
  --exclude='deploy.sh' \
  --exclude='deploy.example.sh' \
  --exclude='session-context.tmp.md' \
  --exclude='README.md' \
  --exclude='CHANGELOG.md' \
  --exclude='*.map' \
  ./ "${REMOTE_HOST}:${REMOTE_PATH}/"
echo "Deployment complete."
echo "Remote path: ${REMOTE_PATH}"
