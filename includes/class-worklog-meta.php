<?php
if (!defined('ABSPATH')) exit;

/**
 * 作業ログメタデータ管理クラス
 * 
 * 投稿・固定ページに対する作業ログメタデータの登録・保存・取得を管理
 */
class OFWN_Worklog_Meta {
    
    // メタキー定数
    const META_LOG_TEXT = 'work_log_text';
    const META_LOG_AUTHOR_ID = 'work_log_author_user_id';
    const META_LOG_AUTHOR_LOGIN = 'work_log_author_login';
    const META_LOG_AUTHOR_NAME = 'work_log_author_display_name';
    const META_LOG_DATETIME = 'work_log_datetime';
    const META_LOG_LAST_REVISION = 'work_log_last_revision';
    const META_LOG_SKIPPED_COUNT = 'work_log_skipped_count';
    
    public function __construct() {
        add_action('init', [$this, 'register_meta_fields']);
        add_action('rest_api_init', [$this, 'register_rest_fields']);
        add_action('wp_ajax_ofwn_save_worklog', [$this, 'ajax_save_worklog']);
        add_action('wp_ajax_ofwn_skip_worklog', [$this, 'ajax_skip_worklog']);
        add_action('wp_ajax_ofwn_get_worklog_status', [$this, 'ajax_get_worklog_status']);
        add_action('wp_ajax_ofwn_check_should_prompt', [$this, 'ajax_check_should_prompt']);
        add_action('wp_ajax_ofwn_mark_prompted', [$this, 'ajax_mark_prompted']);
    }
    
    /**
     * メタフィールドを登録
     */
    public function register_meta_fields() {
        $post_types = OFWN_Worklog_Settings::get_target_post_types();
        
        foreach ($post_types as $post_type) {
            // 作業ログ本文
            register_post_meta($post_type, self::META_LOG_TEXT, [
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'auth_callback' => [$this, 'meta_auth_callback'],
                'sanitize_callback' => 'sanitize_textarea_field'
            ]);
            
            // 作業ログ作成者ID
            register_post_meta($post_type, self::META_LOG_AUTHOR_ID, [
                'show_in_rest' => true,
                'single' => true,
                'type' => 'integer',
                'auth_callback' => [$this, 'meta_auth_callback'],
                'sanitize_callback' => 'absint'
            ]);
            
            // 作業ログ作成者ログイン名
            register_post_meta($post_type, self::META_LOG_AUTHOR_LOGIN, [
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'auth_callback' => [$this, 'meta_auth_callback'],
                'sanitize_callback' => 'sanitize_text_field'
            ]);
            
            // 作業ログ作成者表示名
            register_post_meta($post_type, self::META_LOG_AUTHOR_NAME, [
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'auth_callback' => [$this, 'meta_auth_callback'],
                'sanitize_callback' => 'sanitize_text_field'
            ]);
            
            // 作業ログ作成日時
            register_post_meta($post_type, self::META_LOG_DATETIME, [
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'auth_callback' => [$this, 'meta_auth_callback'],
                'sanitize_callback' => 'sanitize_text_field'
            ]);
            
            // 最後にログを記録したリビジョンID
            register_post_meta($post_type, self::META_LOG_LAST_REVISION, [
                'show_in_rest' => true,
                'single' => true,
                'type' => 'integer',
                'auth_callback' => [$this, 'meta_auth_callback'],
                'sanitize_callback' => 'absint'
            ]);
            
            // スキップ回数
            register_post_meta($post_type, self::META_LOG_SKIPPED_COUNT, [
                'show_in_rest' => true,
                'single' => true,
                'type' => 'integer',
                'auth_callback' => [$this, 'meta_auth_callback'],
                'sanitize_callback' => 'absint'
            ]);
        }
    }
    
    /**
     * REST API フィールドを追加
     */
    public function register_rest_fields() {
        $post_types = OFWN_Worklog_Settings::get_target_post_types();
        
        foreach ($post_types as $post_type) {
            // 作業ログ状態を取得するフィールド
            register_rest_field($post_type, 'worklog_status', [
                'get_callback' => [$this, 'get_worklog_status_for_rest'],
                'schema' => [
                    'description' => '作業ログの状態情報',
                    'type' => 'object',
                    'context' => ['edit'],
                    'properties' => [
                        'has_log' => ['type' => 'boolean'],
                        'last_revision' => ['type' => 'integer'],
                        'current_revision' => ['type' => 'integer'],
                        'should_prompt' => ['type' => 'boolean'],
                        'skipped_count' => ['type' => 'integer']
                    ]
                ]
            ]);
        }
    }
    
    /**
     * メタデータの認証コールバック
     */
    public function meta_auth_callback($allowed, $meta_key, $post_id, $user_id, $cap, $caps) {
        return current_user_can('edit_post', $post_id);
    }
    
    /**
     * AJAX: 作業ログを保存
     */
    public function ajax_save_worklog() {
        $this->verify_ajax_request();
        
        $post_id = intval($_POST['post_id'] ?? 0);
        $log_text = sanitize_textarea_field($_POST['log_text'] ?? '');
        $revision_id = intval($_POST['revision_id'] ?? 0);
        
        if (!$post_id || !$log_text) {
            wp_send_json_error(['message' => __('必要な情報が不足しています。', 'work-notes')]);
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('この投稿を編集する権限がありません。', 'work-notes')]);
        }
        
        $post_type = get_post_type($post_id);
        if (!OFWN_Worklog_Settings::is_target_post_type($post_type)) {
            wp_send_json_error(['message' => __('この投稿タイプは作業ログの対象外です。', 'work-notes')]);
        }
        
        // 現在ユーザーの情報を取得
        $current_user = wp_get_current_user();
        $now = current_time('Y-m-d H:i:s');
        
        // メタデータを保存
        $result = $this->save_worklog_meta($post_id, [
            'text' => $log_text,
            'author_id' => $current_user->ID,
            'author_login' => $current_user->user_login,
            'author_name' => $current_user->display_name,
            'datetime' => $now,
            'revision_id' => $revision_id
        ]);
        
        if ($result) {
            // アクションフック実行
            do_action('of_worklog_saved', $post_id, $revision_id, $current_user->ID, $log_text);
            
            wp_send_json_success([
                'message' => __('作業ログを保存しました。', 'work-notes'),
                'log_data' => [
                    'text' => $log_text,
                    'author_name' => $current_user->display_name,
                    'datetime' => $now,
                    'revision_id' => $revision_id
                ]
            ]);
        } else {
            wp_send_json_error(['message' => __('作業ログの保存に失敗しました。', 'work-notes')]);
        }
    }
    
    /**
     * AJAX: 作業ログをスキップ
     */
    public function ajax_skip_worklog() {
        $this->verify_ajax_request();
        
        $post_id = intval($_POST['post_id'] ?? 0);
        
        if (!$post_id) {
            wp_send_json_error(['message' => __('投稿IDが指定されていません。', 'work-notes')]);
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('この投稿を編集する権限がありません。', 'work-notes')]);
        }
        
        // スキップ回数をインクリメント
        $current_count = intval(get_post_meta($post_id, self::META_LOG_SKIPPED_COUNT, true));
        $new_count = $current_count + 1;
        
        update_post_meta($post_id, self::META_LOG_SKIPPED_COUNT, $new_count);
        
        wp_send_json_success([
            'message' => __('作業ログをスキップしました。', 'work-notes'),
            'skipped_count' => $new_count
        ]);
    }
    
    /**
     * AJAX: 作業ログ状態を取得
     */
    public function ajax_get_worklog_status() {
        $this->verify_ajax_request();
        
        $post_id = intval($_POST['post_id'] ?? 0);
        
        if (!$post_id) {
            wp_send_json_error(['message' => __('投稿IDが指定されていません。', 'work-notes')]);
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('この投稿を編集する権限がありません。', 'work-notes')]);
        }
        
        $status = $this->get_worklog_status($post_id);
        wp_send_json_success($status);
    }
    
    /**
     * 作業ログメタデータを保存
     */
    public function save_worklog_meta($post_id, $data) {
        $results = [];
        
        $results[] = update_post_meta($post_id, self::META_LOG_TEXT, $data['text']);
        $results[] = update_post_meta($post_id, self::META_LOG_AUTHOR_ID, $data['author_id']);
        $results[] = update_post_meta($post_id, self::META_LOG_AUTHOR_LOGIN, $data['author_login']);
        $results[] = update_post_meta($post_id, self::META_LOG_AUTHOR_NAME, $data['author_name']);
        $results[] = update_post_meta($post_id, self::META_LOG_DATETIME, $data['datetime']);
        $results[] = update_post_meta($post_id, self::META_LOG_LAST_REVISION, $data['revision_id']);
        
        // 少なくとも一つが成功すればOK
        return in_array(true, $results, true);
    }
    
    /**
     * 作業ログの状態を取得
     */
    public function get_worklog_status($post_id) {
        $last_revision = intval(get_post_meta($post_id, self::META_LOG_LAST_REVISION, true));
        $current_revision = $this->get_current_revision_id($post_id);
        $has_log = !empty(get_post_meta($post_id, self::META_LOG_TEXT, true));
        $skipped_count = intval(get_post_meta($post_id, self::META_LOG_SKIPPED_COUNT, true));
        
        // 促し表示の判定
        $should_prompt = $this->should_prompt_for_worklog($post_id, $current_revision, $last_revision);
        
        return [
            'has_log' => $has_log,
            'last_revision' => $last_revision,
            'current_revision' => $current_revision,
            'should_prompt' => $should_prompt,
            'skipped_count' => $skipped_count,
            'log_text' => get_post_meta($post_id, self::META_LOG_TEXT, true),
            'log_author_name' => get_post_meta($post_id, self::META_LOG_AUTHOR_NAME, true),
            'log_datetime' => get_post_meta($post_id, self::META_LOG_DATETIME, true)
        ];
    }
    
    /**
     * REST API用の作業ログ状態取得
     */
    public function get_worklog_status_for_rest($post, $field_name, $request, $object_type) {
        return $this->get_worklog_status($post['id']);
    }
    
    /**
     * 作業ログ促しを表示すべきかどうか判定
     */
    public function should_prompt_for_worklog($post_id, $current_revision = null, $last_logged_revision = null) {
        // 対象ユーザーかどうか
        if (!OFWN_Worklog_Settings::is_target_user()) {
            return false;
        }
        
        // 対象投稿タイプかどうか
        $post_type = get_post_type($post_id);
        if (!OFWN_Worklog_Settings::is_target_post_type($post_type)) {
            return false;
        }
        
        // リビジョン情報を取得
        if ($current_revision === null) {
            $current_revision = $this->get_current_revision_id($post_id);
        }
        
        if ($last_logged_revision === null) {
            $last_logged_revision = intval(get_post_meta($post_id, self::META_LOG_LAST_REVISION, true));
        }
        
        // 最新リビジョンに対してログが記録済みかどうか
        $needs_log = ($current_revision !== $last_logged_revision);
        
        // フィルターで条件をカスタマイズ可能
        return apply_filters('of_worklog_should_prompt', $needs_log, $post_id, $current_revision, $last_logged_revision);
    }
    
    /**
     * 現在のリビジョンIDを取得
     */
    public function get_current_revision_id($post_id) {
        // 最新のリビジョンを取得
        $revisions = wp_get_post_revisions($post_id, [
            'numberposts' => 1,
            'fields' => 'ids'
        ]);
        
        if (!empty($revisions)) {
            return reset($revisions);
        }
        
        // リビジョンがない場合は投稿ID自体を返す
        return $post_id;
    }
    
    /**
     * AJAX リクエストを検証
     */
    private function verify_ajax_request() {
        if (!check_ajax_referer('ofwn_worklog_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('セキュリティチェックに失敗しました。', 'work-notes')]);
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('ログインが必要です。', 'work-notes')]);
        }
    }
    
    /**
     * 作業ログ情報を取得（表示用）
     */
    public static function get_worklog_display_data($post_id) {
        $text = get_post_meta($post_id, self::META_LOG_TEXT, true);
        $author_name = get_post_meta($post_id, self::META_LOG_AUTHOR_NAME, true);
        $datetime = get_post_meta($post_id, self::META_LOG_DATETIME, true);
        $skipped_count = get_post_meta($post_id, self::META_LOG_SKIPPED_COUNT, true);
        
        return [
            'text' => $text,
            'author_name' => $author_name,
            'datetime' => $datetime,
            'skipped_count' => intval($skipped_count),
            'has_log' => !empty($text)
        ];
    }
    
    /**
     * 作業ログをクリア（管理用）
     */
    public static function clear_worklog($post_id) {
        $meta_keys = [
            self::META_LOG_TEXT,
            self::META_LOG_AUTHOR_ID,
            self::META_LOG_AUTHOR_LOGIN,
            self::META_LOG_AUTHOR_NAME,
            self::META_LOG_DATETIME,
            self::META_LOG_LAST_REVISION,
            self::META_LOG_SKIPPED_COUNT
        ];
        
        foreach ($meta_keys as $key) {
            delete_post_meta($post_id, $key);
        }
        
        return true;
    }
    
    /**
     * AJAX: 作業ログ促し判定
     */
    public function ajax_check_should_prompt() {
        $this->verify_ajax_request();
        
        $post_id = intval($_POST['post_id'] ?? 0);
        $user_id = get_current_user_id();
        
        if (!$post_id || !$user_id) {
            wp_send_json_error(['message' => __('必要な情報が不足しています。', 'work-notes')]);
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('この投稿を編集する権限がありません。', 'work-notes')]);
        }
        
        $should_prompt = $this->should_prompt_for_worklog_strict($post_id, $user_id);
        
        wp_send_json_success([
            'should_prompt' => $should_prompt,
            'post_id' => $post_id,
            'user_id' => $user_id
        ]);
    }
    
    /**
     * AJAX: 促し表示済みマーク
     */
    public function ajax_mark_prompted() {
        $this->verify_ajax_request();
        
        $post_id = intval($_POST['post_id'] ?? 0);
        $user_id = get_current_user_id();
        
        if (!$post_id || !$user_id) {
            wp_send_json_error(['message' => __('必要な情報が不足しています。', 'work-notes')]);
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('この投稿を編集する権限がありません。', 'work-notes')]);
        }
        
        $this->mark_worklog_prompted($post_id, $user_id);
        
        wp_send_json_success(['message' => __('促し表示済みとしてマークしました。', 'work-notes')]);
    }
    
    /**
     * 作業ログ促し判定（厳密版・3段ガード）
     */
    public function should_prompt_for_worklog_strict($post_id, $user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }
        
        if (!$post_id || !$user_id) {
            return false;
        }
        
        // 対象ユーザーかどうか
        if (class_exists('OFWN_Worklog_Settings') && !OFWN_Worklog_Settings::is_target_user($user_id)) {
            return false;
        }
        
        // 対象投稿タイプかどうか
        $post_type = get_post_type($post_id);
        if (class_exists('OFWN_Worklog_Settings') && !OFWN_Worklog_Settings::is_target_post_type($post_type)) {
            return false;
        }
        
        // クールダウンチェック（5分間）
        $cooldown_key = "ofwn_lock_{$post_id}_{$user_id}";
        if (get_transient($cooldown_key)) {
            return false;
        }
        
        // リビジョン差分チェック
        $current_revision = $this->get_current_revision_id($post_id);
        $last_logged_revision = get_user_meta($user_id, "ofwn_last_log_rev_{$post_id}", true);
        
        if ($current_revision && $current_revision == $last_logged_revision) {
            return false;
        }
        
        // 内容ハッシュ差分チェック
        $current_hash = $this->get_post_content_hash($post_id);
        $last_logged_hash = get_user_meta($user_id, "ofwn_last_log_hash_{$post_id}", true);
        
        if ($current_hash && $current_hash === $last_logged_hash) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 作業ログ促し実行済みマーク
     */
    public function mark_worklog_prompted($post_id, $user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }
        
        if (!$post_id || !$user_id) {
            return false;
        }
        
        $current_revision = $this->get_current_revision_id($post_id);
        $current_hash = $this->get_post_content_hash($post_id);
        
        update_user_meta($user_id, "ofwn_last_log_rev_{$post_id}", $current_revision);
        update_user_meta($user_id, "ofwn_last_log_hash_{$post_id}", $current_hash);
        
        // クールダウンセット（5分）
        $cooldown_key = "ofwn_lock_{$post_id}_{$user_id}";
        set_transient($cooldown_key, 1, 5 * MINUTE_IN_SECONDS);
        
        return true;
    }
    
    /**
     * 投稿内容ハッシュを取得
     */
    private function get_post_content_hash($post_id) {
        $post = get_post($post_id);
        if (!$post) return '';
        $content_parts = [$post->post_title, $post->post_content, $post->post_excerpt];
        return md5(implode('||', array_filter($content_parts)));
    }
}