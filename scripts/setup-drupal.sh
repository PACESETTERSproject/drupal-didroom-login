#!/usr/bin/env bash
# Spin up a throwaway Drupal 11 site with this module symlinked in, using DDEV
# and the ddev-drupal-contrib add-on. Safe to re-run.
set -euo pipefail
cd "$(dirname "$0")/.."

if [ ! -f .ddev/config.yaml ]; then
  ddev config --project-name=didroom-login --project-type=drupal --docroot=web \
    --php-version=8.3 --corepack-enable
fi
if [ ! -f .ddev/config.contrib.yaml ]; then
  ddev add-on get ddev/ddev-drupal-contrib
fi

ddev start
# Build the Drupal codebase in web/ + vendor/ from this module's composer.json.
ddev poser
# Symlink this repo into web/modules/custom/didroom_login.
ddev symlink-project

if ! ddev drush status --field=bootstrap 2>/dev/null | grep -q Successful; then
  ddev drush site:install standard --site-name="DIDroom Login dev" --account-name=admin -y
fi

ddev drush pm:install didroom_login -y

# A pre-existing account, to test "link wallet login to existing user by email".
ddev drush user:create existing --mail=existing@example.com 2>/dev/null || true

ddev drush cache:rebuild
echo
echo "Site: $(ddev describe -j | grep -o '"primary_url":"[^"]*"' | cut -d'"' -f4)"
echo "Admin one-time login: ddev drush uli"
