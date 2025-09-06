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
        
        // マイグレーション処理（通知機能削除）
        add_action('plugins_loaded', [$this, 'run_migration'], 1);
    }
    
    /**
     * 設定ページを追加
     */
    public function add_settings_page() {
        add_submenu_page(
            'edit.php?post_type=of_work_note',
            __('作業ログ設定', 'work-notes'),
            __('作業ログ設定', 'work-notes'),
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
            wp_die(__('この設定を変更する権限がありません。', 'work-notes'));
        }
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('作業ログ設定', 'work-notes'); ?></h1>
            
            <!-- マスター管理セクション -->
            <form method="post" action="options.php">
                <?php
                settings_fields('ofwn_settings');
                do_settings_sections('ofwn_settings');
                submit_button(__('マスター設定を保存', 'work-notes'));
                ?>
            </form>
            
            <hr>
            
            <h2><?php esc_html_e('配布エンドポイント確認', 'work-notes'); ?></h2>
            <p><?php esc_html_e('仮想配布ルート /updates/ の動作を確認します。', 'work-notes'); ?></p>
            
            <div id="ofwn-distribution-check">
                <button type="button" id="ofwn-check-distribution-btn" class="button">
                    <?php esc_html_e('配布エンドポイントをテスト', 'work-notes'); ?>
                </button>
                <div id="ofwn-distribution-result" style="margin-top: 10px;"></div>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                $('#ofwn-check-distribution-btn').on('click', function() {
                    var $btn = $(this);
                    var $result = $('#ofwn-distribution-result');
                    
                    $btn.prop('disabled', true).text('<?php esc_html_e('確認中...', 'work-notes'); ?>');
                    $result.html('');
                    
                    $.post(ajaxurl, {
                        action: 'ofwn_check_distribution',
                        nonce: '<?php echo wp_create_nonce('ofwn_distribution_check'); ?>'
                    }, function(response) {
                        if (response.success) {
                            $result.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                        } else {
                            $result.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                        }
                    }).fail(function() {
                        $result.html('<div class="notice notice-error inline"><p><?php esc_html_e('テストに失敗しました。', 'work-notes'); ?></p></div>');
                    }).always(function() {
                        $btn.prop('disabled', false).text('<?php esc_html_e('配布エンドポイントをテスト', 'work-notes'); ?>');
                    });
                });
            });
            </script>
        </div>
        <?php
    }
    
    /**
     * 通知機能削除のマイグレーション処理
     */
    public function run_migration() {
        $migrated_version = get_option('ofwn_migrated_version', '0.0.0');
        $current_version = defined('OFWN_VER') ? OFWN_VER : '1.0.2';
        
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
            $wpdb->query($wpdb->prepare("
                DELETE FROM {$wpdb->usermeta} 
                WHERE meta_key LIKE %s
            ", $pattern));
        }
        
        // 削除対象のポストメタキー
        $post_meta_keys = [
            'ofwn_worklog_prompted_%',
            'ofwn_worklog_last_prompted_%',
            'ofwn_worklog_revision_%'
        ];
        
        foreach ($post_meta_keys as $pattern) {
            $wpdb->query($wpdb->prepare("
                DELETE FROM {$wpdb->postmeta} 
                WHERE meta_key LIKE %s
            ", $pattern));
        }
        
        // 削除対象のトランジェント
        $transient_keys = [
            'ofwn_worklog_%'
        ];
        
        foreach ($transient_keys as $pattern) {
            $wpdb->query($wpdb->prepare("
                DELETE FROM {$wpdb->options} 
                WHERE option_name LIKE %s
            ", '_transient_' . $pattern));
            
            $wpdb->query($wpdb->prepare("
                DELETE FROM {$wpdb->options} 
                WHERE option_name LIKE %s
            ", '_transient_timeout_' . $pattern));
        }
    }
}