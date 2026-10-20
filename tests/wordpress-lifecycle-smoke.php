<?php
/**
 * Disposable WordPress-only acceptance: Sandbox → Approval → Apply →
 * Verify → Rollback → Verify, including optimistic concurrency checks.
 * Never execute this fixture on production.
 */
if (getenv('OAI_DISPOSABLE_WP_TEST') !== '1') {
    throw new RuntimeException('Refusing to run outside disposable test site');
}
if (strpos(home_url(), 'localhost') === false && strpos(home_url(), '.test') === false) {
    throw new RuntimeException('Refusing to modify non-test WordPress');
}
wp_set_current_user(1);
function oai_smoke_ok($result, $message) {
    if (is_wp_error($result)) {
        throw new RuntimeException($message.': '.$result->get_error_code());
    }
    return $result;
}
function oai_smoke_assert($value, $message) {
    if (!$value) throw new RuntimeException($message);
}
function oai_smoke_plan($value) {
    $req = new WP_REST_Request('POST', '/okean-ai/v1/sandbox');
    $req->set_param('type', 'option_update');
    $req->set_param('target', 'thumbnail_size_w');
    $req->set_param('value', $value);
    return OAI_REST::sandbox_create($req);
}
$before = get_option('thumbnail_size_w', null);
$prior = OAI_AI_Lifecycle_Control::status();
try {
    update_option('thumbnail_size_w', 150, false);
    oai_smoke_ok(OAI_AI_Lifecycle_Control::enable(1), 'Enable test lifecycle');
    $plan = oai_smoke_ok(oai_smoke_plan(151), 'Prepare sandbox');
    $id = (int)($plan['id'] ?? 0);
    oai_smoke_assert($id > 0, 'Missing sandbox operation');
    oai_smoke_assert((int)get_option('thumbnail_size_w') === 150, 'Sandbox wrote before approval');

    // Owner-approved lifecycle; external client requests do not reach this test.
    oai_smoke_ok(OAI_AI_Lifecycle_Control::perform($id, 'approve', 'disposable-lifecycle-ci', 1), 'Approve');
    update_option('thumbnail_size_w', 153, false);
    $conflict = OAI_AI_Lifecycle_Control::perform($id, 'apply', 'disposable-lifecycle-ci', 1);
    oai_smoke_assert(is_wp_error($conflict), 'Stale apply was accepted');
    oai_smoke_assert((int)get_option('thumbnail_size_w') === 153, 'Stale apply overwrote concurrent change');
    update_option('thumbnail_size_w', 150, false);
    // A conflicted apply transitions the operation to a failed terminal state.
    // Never retry that operation: plan a new immutable snapshot for the valid edit.
    $valid = oai_smoke_ok(oai_smoke_plan(151), 'Prepare fresh sandbox');
    $id = (int)($valid['id'] ?? 0);
    oai_smoke_assert($id > 0, 'Missing fresh sandbox operation');
    oai_smoke_ok(OAI_AI_Lifecycle_Control::perform($id, 'approve', 'disposable-lifecycle-ci', 1), 'Approve fresh sandbox');

    oai_smoke_ok(OAI_AI_Lifecycle_Control::perform($id, 'apply', 'disposable-lifecycle-ci', 1), 'Apply');
    oai_smoke_assert((int)get_option('thumbnail_size_w') === 151, 'Approved apply did not persist');
    oai_smoke_assert(!empty(OAI_Verify::operation($id)['verified']), 'Apply verification failed');
    $appliedReceipt = OAI_Receipts::build($id);
    oai_smoke_assert(is_array($appliedReceipt) && $appliedReceipt['status']==='applied',
        'Applied operation receipt/status missing');
    oai_smoke_assert(!empty($appliedReceipt['receipt']), 'Apply receipt not persisted');
    update_option('thumbnail_size_w', 154, false);
    $conflict = OAI_AI_Lifecycle_Control::perform($id, 'rollback', 'disposable-lifecycle-ci', 1);
    oai_smoke_assert(is_wp_error($conflict), 'Rollback overwrote later edit');
    oai_smoke_assert((int)get_option('thumbnail_size_w') === 154, 'Conflicted rollback changed setting');
    update_option('thumbnail_size_w', 151, false);

    oai_smoke_ok(OAI_AI_Lifecycle_Control::perform($id, 'rollback', 'disposable-lifecycle-ci', 1), 'Rollback');
    oai_smoke_assert((int)get_option('thumbnail_size_w') === 150, 'Rollback did not restore snapshot');
    oai_smoke_assert(!empty(OAI_Verify::operation($id)['verified']), 'Rollback verification failed');
    $rolledReceipt = OAI_Receipts::build($id);
    oai_smoke_assert(is_array($rolledReceipt) && $rolledReceipt['status']==='rolled_back',
        'Rollback receipt/status missing');
    oai_smoke_assert(!empty($rolledReceipt['receipt']), 'Rollback receipt not persisted');
    echo "PASS: real WP sandbox, owner approval, stale apply, verify, rollback conflicts and restoration\n";
} finally {
    OAI_AI_Lifecycle_Control::disable(1);
    if ($before === null) delete_option('thumbnail_size_w');
    else update_option('thumbnail_size_w', $before, false);
}
