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

# Safety regression: after-write verification faults + receipt crash windows.
OAI_DISPOSABLE_WP_TEST=1 wp_test eval-file "$PWD/tests/wordpress-elementor-failure-windows.php"

# Negative cross-process interleaving regression (Issue #13).
# A PASS proves unsafe external-editor lost-update is REPRODUCIBLE and remains
# a blocker. Never interpret this as a safety/production release gate passing.
OAI_DISPOSABLE_WP_TEST=1 OAI_F3_PHASE=seed wp_test eval-file "$PWD/tests/wordpress-elementor-external-editor-race.php"
(
  OAI_DISPOSABLE_WP_TEST=1 OAI_F3_PHASE=ai wp_test eval-file "$PWD/tests/wordpress-elementor-external-editor-race.php" > "$WORK/f3-ai.log" 2>&1
) &
F3_AI_PID=$!
(
  OAI_DISPOSABLE_WP_TEST=1 OAI_F3_PHASE=editor wp_test eval-file "$PWD/tests/wordpress-elementor-external-editor-race.php" > "$WORK/f3-editor.log" 2>&1
) &
F3_EDITOR_PID=$!
wait "$F3_AI_PID" || { cat "$WORK/f3-ai.log" "$WORK/f3-editor.log"; exit 1; }
wait "$F3_EDITOR_PID" || { cat "$WORK/f3-ai.log" "$WORK/f3-editor.log"; exit 1; }
cat "$WORK/f3-ai.log" "$WORK/f3-editor.log"
grep -q 'F3_UNSAFE_RACE_REPRODUCED' "$WORK/f3-ai.log"
grep -q 'F3_EDITOR_SAVED' "$WORK/f3-editor.log"
OAI_DISPOSABLE_WP_TEST=1 OAI_F3_PHASE=verify wp_test eval-file "$PWD/tests/wordpress-elementor-external-editor-race.php"

# Exploratory, NOT a release clearance: two interleavings using MySQL FOR UPDATE.
# Even a successful transaction experiment does not prove that all Elementor
# browser writes, save hooks, CSS and side effects are safely serialized.
for F3_TX_SCENARIO in editor_first ai_first; do
  F3_TX_SYNC="$WORK/f3-transaction-$F3_TX_SCENARIO"
  mkdir -p "$F3_TX_SYNC"
  OAI_DISPOSABLE_WP_TEST=1 OAI_F3_TRANSACTION_SCENARIO="$F3_TX_SCENARIO" OAI_F3_TRANSACTION_SYNC="$F3_TX_SYNC" OAI_F3_TRANSACTION_MODE=seed \
    wp_test eval-file "$PWD/tests/wordpress-elementor-external-transaction.php"
  (
    OAI_DISPOSABLE_WP_TEST=1 OAI_F3_TRANSACTION_SCENARIO="$F3_TX_SCENARIO" OAI_F3_TRANSACTION_SYNC="$F3_TX_SYNC" OAI_F3_TRANSACTION_MODE=ai \
      wp_test eval-file "$PWD/tests/wordpress-elementor-external-transaction.php" > "$WORK/f3-tx-ai.log" 2>&1
  ) &
  F3_TX_AI_PID=$!
  (
    OAI_DISPOSABLE_WP_TEST=1 OAI_F3_TRANSACTION_SCENARIO="$F3_TX_SCENARIO" OAI_F3_TRANSACTION_SYNC="$F3_TX_SYNC" OAI_F3_TRANSACTION_MODE=editor \
      wp_test eval-file "$PWD/tests/wordpress-elementor-external-transaction.php" > "$WORK/f3-tx-editor.log" 2>&1
  ) &
  F3_TX_EDITOR_PID=$!
  wait "$F3_TX_AI_PID" || { cat "$WORK/f3-tx-ai.log" "$WORK/f3-tx-editor.log"; exit 1; }
  wait "$F3_TX_EDITOR_PID" || { cat "$WORK/f3-tx-ai.log" "$WORK/f3-tx-editor.log"; exit 1; }
  cat "$WORK/f3-tx-ai.log" "$WORK/f3-tx-editor.log"
  OAI_DISPOSABLE_WP_TEST=1 OAI_F3_TRANSACTION_SCENARIO="$F3_TX_SCENARIO" OAI_F3_TRANSACTION_SYNC="$F3_TX_SYNC" OAI_F3_TRANSACTION_MODE=verify \
    wp_test eval-file "$PWD/tests/wordpress-elementor-external-transaction.php"
done
