#!/usr/bin/env bash
set -euo pipefail
: "${RUNNER_TEMP:=/tmp}"
WORK="$RUNNER_TEMP/wpai-native-smoke"
mkdir -p "$WORK/site"
curl -fsSL --retry 3 https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o "$WORK/wp-cli.phar"
wp_test() { php "$WORK/wp-cli.phar" --path="$WORK/site" --allow-root "$@"; }
wp_test core download --version="${WP_TEST_VERSION:-latest}"
wp_test config create --dbname=wp_test --dbuser=root --dbpass=test-password --dbhost=127.0.0.1
wp_test core install --url=http://localhost:8080 --title=Smoke --admin_user=ci-admin --admin_password=test-only-ci-password --admin_email=ci@example.invalid --skip-email
install_ci_plugin() {
  local plugin="$1" version="$2"
  if [[ "$version" == "latest" ]]; then
    wp_test plugin install "$plugin" --activate
  else
    wp_test plugin install "$plugin" --version="$version" --activate
  fi
}
install_ci_plugin elementor "${ELEMENTOR_TEST_VERSION:-latest}"
install_ci_plugin woocommerce "${WOO_TEST_VERSION:-latest}"
install_ci_plugin wordpress-seo "${YOAST_TEST_VERSION:-latest}"
mkdir -p "$WORK/site/wp-content/plugins/okean-ai-admin"
cp -R okean-ai-admin.php includes starter-themes "$WORK/site/wp-content/plugins/okean-ai-admin/"
wp_test plugin activate okean-ai-admin
wp_test eval 'if(!class_exists("OAI_Elementor")) { throw new Exception("Missing Elementor adapter"); } echo "PASS: Elementor adapter loaded\\n";'
wp_test eval 'if(!class_exists("OAI_MCP")) { throw new Exception("Missing MCP"); } echo "PASS: MCP loaded\\n";'
wp_test plugin status okean-ai-admin

# Run fail-closed persisted operation and actor-binding tests on disposable MySQL/WP.
OAI_DISPOSABLE_WP_TEST=1 wp_test eval-file "$PWD/tests/wordpress-elementor-operation-binding.php"

# Two independent PHP workers compete to claim one approved operation in MySQL.
# Run both Apply and Rollback state transitions in disposable installations.
for LEASE_MODE in apply rollback; do
  OAI_DISPOSABLE_WP_TEST=1 OAI_LEASE_MODE="$LEASE_MODE" OAI_LEASE_PHASE=seed wp_test eval-file "$PWD/tests/wordpress-elementor-lease-race.php"
  (
    OAI_DISPOSABLE_WP_TEST=1 OAI_LEASE_MODE="$LEASE_MODE" OAI_LEASE_PHASE=claim wp_test eval-file "$PWD/tests/wordpress-elementor-lease-race.php" > "$WORK/claim-1.log" 2>&1
  ) &
  LEASE_PID1=$!
  (
    OAI_DISPOSABLE_WP_TEST=1 OAI_LEASE_MODE="$LEASE_MODE" OAI_LEASE_PHASE=claim wp_test eval-file "$PWD/tests/wordpress-elementor-lease-race.php" > "$WORK/claim-2.log" 2>&1
  ) &
  LEASE_PID2=$!
  wait "$LEASE_PID1" || { cat "$WORK/claim-1.log" "$WORK/claim-2.log"; exit 1; }
  wait "$LEASE_PID2" || { cat "$WORK/claim-1.log" "$WORK/claim-2.log"; exit 1; }
  cat "$WORK/claim-1.log" "$WORK/claim-2.log"
  CLAIMS=$(grep -hxc 'CLAIMED' "$WORK/claim-1.log" "$WORK/claim-2.log" | awk '{s+=$1} END {print s+0}')
  REFUSALS=$(grep -hxc 'REFUSED' "$WORK/claim-1.log" "$WORK/claim-2.log" | awk '{s+=$1} END {print s+0}')
  [[ "$CLAIMS" -eq 1 && "$REFUSALS" -eq 1 ]] || { echo "Wrong $LEASE_MODE CAS result: claimed=$CLAIMS refused=$REFUSALS"; exit 1; }
  OAI_DISPOSABLE_WP_TEST=1 OAI_LEASE_MODE="$LEASE_MODE" OAI_LEASE_PHASE=verify wp_test eval-file "$PWD/tests/wordpress-elementor-lease-race.php"
done

# Stage-5 internal-only executor acceptance. Never run on a production host.
OAI_DISPOSABLE_WP_TEST=1 wp_test eval-file "$PWD/tests/wordpress-elementor-document-executor.php"

# Stage-6 read-only recovery diagnosis of interrupted document operations.
OAI_DISPOSABLE_WP_TEST=1 wp_test eval-file "$PWD/tests/wordpress-elementor-document-recovery.php"
