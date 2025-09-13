<?php
if (!defined('ABSPATH')) exit;

/**
 * work-notes 統合設定管理クラス
 * 
 * マスター管理とプラグイン設定を統合管理
 */
class OFWN_Worklog_Settings {
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_master_settings']);
        
        // AJAX ハンドラー追加
        add_action('wp_ajax_ofwn_clear_cache', [$this, 'ajax_clear_cache']);
        
        // マイグレーション処理（通知機能削除）
        add_action('plugins_loaded', [$this, 'run_migration'], 1);
    }
    
    /**
     * 設定ページを追加
     */
    public function add_settings_page() {
        add_submenu_page(
            'edit.php?post_type=of_work_note',
            __('作業メモ設定', 'work-notes'),
            __('作業メモ設定', 'work-notes'),
            'manage_options',
            'ofwn-worklog-settings',
            [$this, 'render_settings_page']
        );
    }
    
    /**
     * マスター設定項目を統合登録
     */
    public function register_master_settings() {
        register_setting('ofwn_settings', 'ofwn_requesters', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_list'],
            'default' => [],
            'show_in_rest' => false,
            'autoload' => false
        ]);
        register_setting('ofwn_settings', 'ofwn_workers', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_list'],
            'default' => $this->default_workers(),
            'show_in_rest' => false,
            'autoload' => false
        ]);
        register_setting('ofwn_settings', 'ofwn_update_channel', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_update_channel'],
            'default' => 'stable',
            'show_in_rest' => false,
            'autoload' => false
        ]);

        add_settings_section('ofwn_section_main', __('マスター管理', 'work-notes'), '__return_false', 'ofwn_settings');

        add_settings_field('ofwn_requesters', __('依頼元マスター（1行1件）', 'work-notes'), function(){
            $v = get_option('ofwn_requesters', []);
            echo '<textarea name="ofwn_requesters[]" rows="3" style="width:600px;">'.esc_textarea(implode("\n", $v))."</textarea>";
            echo '<p class="description">' . esc_html__('ここに入力した内容が「依頼元」のセレクトに表示されます。', 'work-notes') . '</p>';
        }, 'ofwn_settings', 'ofwn_section_main');

        add_settings_field('ofwn_workers', __('担当者マスター（1行1件）', 'work-notes'), function(){
            $v = get_option('ofwn_workers', $this->default_workers());
            echo '<textarea name="ofwn_workers[]" rows="3" style="width:600px;">'.esc_textarea(implode("\n", $v))."</textarea>";
            echo '<p class="description">' . esc_html__('ここに入力した内容が「担当者」のセレクトに表示されます。', 'work-notes') . '</p>';
        }, 'ofwn_settings', 'ofwn_section_main');

        add_settings_section('ofwn_section_update', __('アップデート設定', 'work-notes'), '__return_false', 'ofwn_settings');

        add_settings_field('ofwn_update_channel', __('更新チャンネル', 'work-notes'), function(){
            $current = get_option('ofwn_update_channel', 'stable');
            echo '<select name="ofwn_update_channel">';
            echo '<option value="stable"' . selected($current, 'stable', false) . '>' . esc_html__('安定版 (Stable)', 'work-notes') . '</option>';
            echo '<option value="beta"' . selected($current, 'beta', false) . '>' . esc_html__('ベータ版 (Beta)', 'work-notes') . '</option>';
            echo '</select>';
            echo '<p class="description">' . esc_html__('プラグインの自動更新で使用するチャンネルを選択してください。', 'work-notes') . '</p>';
        }, 'ofwn_settings', 'ofwn_section_update');
    }
    
    /**
     * リストデータのサニタイズ処理
     */
    public function sanitize_list($raw) {
        if (is_array($raw) && count($raw) === 1 && is_string($raw[0])) $raw = $raw[0];
        $text = is_array($raw) ? implode("\n", $raw) : (string)$raw;
        $lines = array_filter(array_map(function($s){
            $s = trim(str_replace(["\r\n","\r"], "\n", $s)); return $s;
        }, explode("\n", $text)));
        $lines = array_values(array_unique($lines));
        return $lines;
    }
    
    /**
     * アップデートチャンネルのサニタイズ処理
     */
    public function sanitize_update_channel($input) {
        return in_array($input, ['stable', 'beta'], true) ? $input : 'stable';
    }

    /**
     * デフォルト担当者リスト取得
     */
    private function default_workers() {
        $roles = ['administrator','editor','author'];
        $users = get_users(['role__in'=>$roles, 'fields'=>['display_name']]);
        $names = array_map(function($u){ return $u->display_name; }, $users);
        $names = array_filter(array_unique($names));
        if (empty($names)) $names = [wp_get_current_user()->display_name ?: '担当者A'];
        return array_values($names);
    }
    
    /**
     * 設定ページをレンダリング
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('この設定を変更する権限がありません。', 'work-notes'));
        }
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('作業メモ設定', 'work-notes'); ?></h1>
            
            <!-- マスター管理セクション -->
            <form method="post" action="options.php">
                <?php
                settings_fields('ofwn_settings');
                do_settings_sections('ofwn_settings');
                submit_button(esc_html__('マスター設定を保存', 'work-notes'));
                ?>
            </form>
            
            <?php if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options')) : ?>
            <hr>

            <h2><?php esc_html_e('キャッシュクリア', 'work-notes'); ?></h2>
            <p><?php esc_html_e('作業メモの保存に問題がある場合、キャッシュをクリアして改善する可能性があります。', 'work-notes'); ?></p>

            <div id="ofwn-cache-clear">
                <button type="button" id="ofwn-clear-cache-btn" class="button button-secondary">
                    <?php esc_html_e('キャッシュをクリア', 'work-notes'); ?>
                </button>
                <div id="ofwn-cache-result" style="margin-top: 10px;"></div>
            </div>
            <?php endif; ?>
            
            
            <script>
            jQuery(document).ready(function($) {
                
                <?php if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options')) : ?>
                // キャッシュクリア機能
                $('#ofwn-clear-cache-btn').on('click', function() {
                    var $btn = $(this);
                    var $result = $('#ofwn-cache-result');

                    $btn.prop('disabled', true).text('<?php esc_html_e('クリア中...', 'work-notes'); ?>');
                    $result.html('');

                    $.post(ajaxurl, {
                        action: 'ofwn_clear_cache',
                        nonce: '<?php echo esc_attr(wp_create_nonce('ofwn_clear_cache')); ?>'
                    }, function(response) {
                        if (response.success) {
                            $result.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                        } else {
                            $result.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                        }
                    }).fail(function() {
                        $result.html('<div class="notice notice-error inline"><p><?php esc_html_e('キャッシュクリアに失敗しました。', 'work-notes'); ?></p></div>');
                    }).always(function() {
                        $btn.prop('disabled', false).text('<?php esc_html_e('キャッシュをクリア', 'work-notes'); ?>');
                    });
                });
                <?php endif; ?>
                
            });
            </script>
        </div>
        <?php
    }
    
    /**
     * キャッシュクリアのAJAXハンドラー
     */
    public function ajax_clear_cache() {
        // ノンス検証
        $nonce = isset($_POST['nonce']) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if (!wp_verify_nonce($nonce, 'ofwn_clear_cache')) {
            wp_send_json_error(['message' => __('セキュリティチェックに失敗しました。', 'work-notes')]);
        }
        
        // 権限チェック
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('この操作を実行する権限がありません。', 'work-notes')]);
        }
        
        try {
            $cleared_items = [];
            
            // WordPressオブジェクトキャッシュクリア
            if (wp_cache_flush()) {
                $cleared_items[] = 'WordPressオブジェクトキャッシュ';
            }
            
            // WordPress投稿キャッシュクリア
            $this->clear_posts_cache();
            $cleared_items[] = '投稿メタデータキャッシュ';
            
            // トランジェントAPIクリア
            $this->clear_work_notes_transients();
            $cleared_items[] = 'work-notes関連トランジェント';
            
            // OPcacheクリア（利用可能な場合）
            if (function_exists('opcache_reset') && opcache_reset()) {
                $cleared_items[] = 'OPcache';
            }
            
            $message = 'キャッシュクリア完了: ' . implode('、', $cleared_items);
            
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                ofwn_log('CACHE_CLEAR ' . $message);
            }
            
            wp_send_json_success(['message' => $message]);
            
        } catch (Exception $e) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                ofwn_log('CACHE_CLEAR Error: ' . $e->getMessage());
            }
            /* translators: %1$s: PHP exception message */
            wp_send_json_error(['message' => sprintf(__('キャッシュクリア中にエラーが発生しました: %1$s', 'work-notes'), esc_html($e->getMessage()))]);
        }
    }
    
    /**
     * work-notes関連の投稿キャッシュをクリア
     */
    private function clear_posts_cache() {
        global $wpdb;
        
        // work-notes関連の投稿IDを取得
        $args = ['post', 'page', 'of_work_note'];
        $cache_key = 'ofwn:' . md5(serialize($args) . ':post_ids');
        if (false !== ($cached = wp_cache_get($cache_key, 'ofwn'))) {
            $post_ids = $cached;
        } else {
            $placeholders = implode(',', array_fill(0, count($args), '%s'));
            $q = new WP_Query([
                'post_type' => $args,
                'fields' => 'ids',
                'nopaging' => true,
                'no_found_rows' => true,
                // 'suppress_filters' => true, // VIP: 禁止のため無効化
            ]);
            $post_ids = $q->posts;
            wp_cache_set($cache_key, $post_ids, 'ofwn', 300);
        }
        
        // 各投稿のキャッシュをクリア
        foreach ($post_ids as $post_id) {
            clean_post_cache($post_id);
            wp_cache_delete($post_id, 'post_meta');
        }
    }
    
    /**
     * work-notes関連のトランジェントをクリア
     */
    private function clear_work_notes_transients() {
        global $wpdb;
        
        // work-notes関連のトランジェントキーを検索して削除
        $args = ['_transient_ofwn_%', '_transient_timeout_ofwn_%', '_transient_work_notes_%'];
        $cache_key = 'ofwn:' . md5(serialize($args) . ':transient_keys');
        if (false !== ($cached = wp_cache_get($cache_key, 'ofwn'))) {
            $transient_keys = $cached;
        } else {
            $all_options = function_exists('wp_load_alloptions') ? wp_load_alloptions() : [];
            $transient_keys = [];
            foreach (array_keys($all_options) as $option_name) {
                if (strpos($option_name, '_transient_ofwn_') === 0 ||
                    strpos($option_name, '_transient_timeout_ofwn_') === 0 ||
                    strpos($option_name, '_transient_work_notes_') === 0) {
                    $transient_keys[] = $option_name;
                }
            }
            wp_cache_set($cache_key, $transient_keys, 'ofwn', 300);
        }
        
        foreach ($transient_keys as $key) {
            if (strpos($key, '_transient_timeout_') === 0) {
                // タイムアウト用のキーは削除をスキップ（deleteTransientで自動処理）
                continue;
            }
            
            $transient_name = str_replace(['_transient_', '_transient_timeout_'], '', $key);
            delete_transient($transient_name);
        }
    }
    
    /**
     * 通知機能削除のマイグレーション処理
     */
    public function run_migration() {
        $migrated_version = get_option('ofwn_migrated_version', '0.0.0');
        $current_version = defined('OFWN_VER') ? OFWN_VER : '1.0.3';
        
        // 通知機能削除マイグレーション（バージョン1.0.3以降）
        if (version_compare($migrated_version, '1.0.3', '<')) {
            $this->cleanup_worklog_notice_data();
            update_option('ofwn_migrated_version', $current_version);
        }
    }
    
    /**
     * 通知機能関連データのクリーンアップ
     */
    private function cleanup_worklog_notice_data() {
        global $wpdb;
        
        // 削除対象のオプションキー
        $option_keys = [
            'of_worklog_target_user_ids',
            'of_worklog_target_post_types',
            'of_worklog_min_role',
            'ofwn_worklog_mode'
        ];
        
        foreach ($option_keys as $key) {
            delete_option($key);
        }
        
        // 削除対象のユーザーメタキー
        $user_meta_keys = [
            'ofwn_worklog_prompted_%',
            'ofwn_worklog_last_prompted_%'
        ];
        
        foreach ($user_meta_keys as $pattern) {
            delete_metadata('user', 0, str_replace('%', '', $pattern), '', true);
            // 関連キャッシュクリア
            wp_cache_delete('ofwn:' . md5($pattern . ':usermeta'), 'ofwn');
        }
        
        // 削除対象のポストメタキー
        $post_meta_keys = [
            'ofwn_worklog_prompted_%',
            'ofwn_worklog_last_prompted_%',
            'ofwn_worklog_revision_%'
        ];
        
        foreach ($post_meta_keys as $pattern) {
            delete_post_meta_by_key(str_replace('%', '', $pattern));
            // 関連キャッシュクリア
            wp_cache_delete('ofwn:' . md5($pattern . ':postmeta'), 'ofwn');
        }
        
        // 削除対象のトランジェント
        $transient_keys = [
            'ofwn_worklog_%'
        ];
        
        foreach ($transient_keys as $pattern) {
            $all_options = function_exists('wp_load_alloptions') ? wp_load_alloptions() : [];
            foreach (array_keys($all_options) as $option_name) {
                if (strpos($option_name, '_transient_' . str_replace('%', '', $pattern)) === 0 ||
                    strpos($option_name, '_transient_timeout_' . str_replace('%', '', $pattern)) === 0) {
                    delete_option($option_name);
                }
            }
            
            // 関連キャッシュクリア
            wp_cache_delete('ofwn:' . md5($pattern . ':transients'), 'ofwn');
        }
    }
}