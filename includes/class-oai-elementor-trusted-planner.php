<?php
/**
 * Stage 4 — internal-only, owner-derived Elementor document sandbox planner.
 *
 * This module is NOT registered in bootstrap, REST or MCP. It creates only a
 * pending operation; it never approves or writes to an Elementor document.
 * The new tree is proposed input. Identity, before-image, operation id, site,
 * status, expiry, channel and integrity proof are derived by this server.
 */
if (!defined('ABSPATH')) exit;
final class OAI_Elementor_Trusted_Planner {
    public const FORMAT = 'oai_elementor_document_v1';
    public const CHANNEL = 'wp_admin_human';

    private static function required_classes() {
        return class_exists('OAI_Elementor_Schema_Save') &&
            class_exists('OAI_Elementor_Operation_Binding') &&
            class_exists('OAI_DB') && class_exists('OAI_Platform');
    }

    private static function signature_input($operation_id, $post_id, $meta, $created_at, $expires_at) {
        return array(
            self::FORMAT, (string)$operation_id, (int)$post_id,
            (string)($meta['site_id'] ?? ''), (int)($meta['post_id'] ?? 0),
            (int)($meta['owner_user_id'] ?? 0), (string)($meta['channel'] ?? ''),
            (string)($meta['before_sha256'] ?? ''), (string)($meta['after_sha256'] ?? ''),
            (string)$created_at, (string)$expires_at
        );
    }

    private static function sign($operation_id, $post_id, $meta, $created_at, $expires_at) {
        return hash_hmac('sha256',
            wp_json_encode(self::signature_input($operation_id, $post_id, $meta, $created_at, $expires_at)),
            wp_salt('auth')
        );
    }

    /** No client-selected before-image, owner, site, operation id, or approval. */
    public static function prepare_human($post_id, $proposed_tree) {
        $post_id=absint($post_id);
        $actor=get_current_user_id();
        if (!$actor || !$post_id || !current_user_can('manage_options') ||
            !current_user_can('edit_post',$post_id)) {
            return new WP_Error('oai_planner_forbidden','Administrator edit permission required',array('status'=>403));
        }
        $post=get_post($post_id);
        if (!$post || $post->post_type!=='page' ||
            in_array((string)$post->post_status,array('trash','auto-draft','inherit'),true)) {
            return new WP_Error('oai_planner_post','Target post cannot be edited',array('status'=>404));
        }
        if (!self::required_classes()) {
            return new WP_Error('oai_planner_missing','Native planner prerequisites unavailable',array('status'=>503));
        }
        if (!is_array($proposed_tree) || !array_is_list($proposed_tree)) {
            return new WP_Error('oai_planner_tree','Proposed document must be a list',array('status'=>422));
        }
        $before=OAI_Elementor_Schema_Save::snapshot($post_id);
        if (is_wp_error($before)) return $before;
        $change=OAI_Elementor_Schema_Save::validate_replace(
            $before['tree'],$proposed_tree,$before['sha256']
        );
        if (is_wp_error($change)) return $change;

        // Re-read immediately before persisting the immutable proposal.
        // This does not replace the atomic Apply lease's conflict check.
        $current=OAI_Elementor_Schema_Save::snapshot($post_id);
        if (is_wp_error($current)) return $current;
        if (!OAI_Elementor_Schema_Save::same_tree($current['tree'],$change['before'])) {
            return new WP_Error('oai_planner_stale','Document changed while planning',array('status'=>409));
        }
        $site=OAI_Platform::site_id();
        if (!is_string($site) || $site==='') {
            return new WP_Error('oai_planner_site','Missing site identity',array('status'=>503));
        }

        $operation_id='OAI-E-DOC-'.wp_generate_uuid4();
        $created_at=current_time('mysql',true);
        $expires_at=gmdate('Y-m-d H:i:s',time()+7*DAY_IN_SECONDS);
        $meta=array(
            'format'=>self::FORMAT,
            'site_id'=>$site,'post_id'=>$post_id,'owner_user_id'=>$actor,
            'channel'=>self::CHANNEL,
            'before_sha256'=>$change['before_sha256'],
            'after_sha256'=>$change['after_sha256']
        );
        $meta['planner_seal']=self::sign($operation_id,$post_id,$meta,$created_at,$expires_at);
        $row=array(
            'operation_id'=>$operation_id,'status'=>'pending','phase'=>'awaiting_approval',
            'type'=>OAI_Elementor_Operation_Binding::TYPE,'target'=>(string)$post_id,
            'before_data'=>wp_json_encode($change['before'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION),
            'after_data'=>wp_json_encode($change['after'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION),
            'meta_data'=>wp_json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'diff_data'=>wp_json_encode(array('changed'=>true,'before_sha256'=>$change['before_sha256'],'after_sha256'=>$change['after_sha256'])),
            'risk'=>'medium','created_at'=>$created_at,'expires_at'=>$expires_at
        );
        $id=OAI_DB::insert($row);
        if (is_wp_error($id)) return $id;
        $stored=OAI_DB::get($id);
        $verified=self::verify_record($stored);
        if (is_wp_error($verified)) {
            // Fail closed: no accessible approved operation is returned, ever.
            OAI_DB::update($id,array('status'=>'rejected','phase'=>'rejected'));
            return $verified;
        }
        if (class_exists('OAI_Audit')) OAI_Audit::log('created',$stored,array('channel'=>self::CHANNEL));
        // Do not return authorization tokens or the private HMAC seal.
        return array('id'=>$id,'operation_id'=>$operation_id,'status'=>'pending',
            'post_id'=>$post_id,'before_sha256'=>$change['before_sha256'],
            'after_sha256'=>$change['after_sha256'],'requires_human_approval'=>true);
    }

    /**
     * Human approval provenance for a prepared operation. A status field and
     * timestamp alone are insufficient: the existing REST handler must have
     * written an audit event by the same WP administrator who planned it.
     * This is additional evidence, not a substitute for nonce/session checks.
     */
    public static function verify_approval_record($row) {
        $origin=self::verify_record($row);
        if (is_wp_error($origin)) return $origin;
        if (!in_array((string)($row['status'] ?? ''),
            array('approved','applying','applied','rolling_back','rolled_back'),true) ||
            empty($row['approved_at']) || empty($row['id'])) {
            return new WP_Error('oai_planner_approval','A persisted approval is required',array('status'=>403));
        }
        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb,'prepare') ||
            !method_exists($wpdb,'get_var') || !method_exists('OAI_DB','audit_table')) {
            return new WP_Error('oai_planner_audit','Approval audit store unavailable',array('status'=>503));
        }
        $meta=json_decode((string)($row['meta_data'] ?? ''),true);
        $actor=absint($meta['owner_user_id'] ?? 0);
        $audit=OAI_DB::audit_table();
        $sql="SELECT id FROM {$audit} WHERE operation_db_id=%d
            AND operation_id=%s AND event=%s AND actor=%d
            AND created_at >= %s LIMIT 1";
        $audit_id=$wpdb->get_var($wpdb->prepare($sql,
            (int)$row['id'], (string)$row['operation_id'], 'approved',
            $actor, (string)$row['created_at']));
        if (!$audit_id) {
            return new WP_Error('oai_planner_approval',
                'Operation has no trusted administrator approval audit event',array('status'=>403));
        }
        return true;
    }

    /**
     * Verify planner-issued provenance of the persisted DB record. A valid
     * signature proves planning origin, not the later human approval event.
     */
    public static function verify_record($row) {
        if (!is_array($row) || ($row['type'] ?? '')!==OAI_Elementor_Operation_Binding::TYPE) {
            return new WP_Error('oai_planner_record','Not a staged document operation',array('status'=>409));
        }
        $meta=json_decode((string)($row['meta_data'] ?? ''),true);
        $before=json_decode((string)($row['before_data'] ?? ''),true);
        $after=json_decode((string)($row['after_data'] ?? ''),true);
        if (!is_array($meta) || !is_array($before) || !is_array($after) ||
            !array_is_list($before) || !array_is_list($after) ||
            ($meta['format'] ?? '')!==self::FORMAT ||
            ($meta['channel'] ?? '')!==self::CHANNEL) {
            return new WP_Error('oai_planner_record','Malformed or untrusted document record',array('status'=>422));
        }
        $target=(string)($row['target'] ?? '');
        $post_id=absint($target);
        if (!$post_id || $target!==(string)$post_id ||
            absint($meta['post_id'] ?? 0)!==$post_id ||
            !is_string($meta['site_id'] ?? null) ||
            !hash_equals((string)$meta['site_id'],(string)get_option('oai_site_id','')) ||
            !$meta['site_id'] || !absint($meta['owner_user_id'] ?? 0)) {
            return new WP_Error('oai_planner_identity','Stored document owner, post or site mismatch',array('status'=>403));
        }
        $seal=$meta['planner_seal'] ?? null;
        if (!is_string($seal) || preg_match('/^[a-f0-9]{64}$/D',$seal)!==1) {
            return new WP_Error('oai_planner_seal','Missing planner signature',array('status'=>403));
        }
        $expected=self::sign((string)($row['operation_id'] ?? ''),$post_id,$meta,
            (string)($row['created_at'] ?? ''),(string)($row['expires_at'] ?? ''));
        if (!hash_equals($expected,$seal)) {
            return new WP_Error('oai_planner_seal','Operation content was not issued by trusted planner',array('status'=>403));
        }
        $bh=OAI_Elementor_Schema_Save::tree_hash($before);
        $ah=OAI_Elementor_Schema_Save::tree_hash($after);
        if (!is_string($meta['before_sha256'] ?? null) ||
            !is_string($meta['after_sha256'] ?? null) ||
            !hash_equals($bh,$meta['before_sha256']) ||
            !hash_equals($ah,$meta['after_sha256']) ||
            OAI_Elementor_Schema_Save::same_tree($before,$after)) {
            return new WP_Error('oai_planner_integrity','Stored document was changed',array('status'=>409));
        }
        return true;
    }
}
