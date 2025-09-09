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
        add_action('wp_ajax_ofwn_cleanup_old_meta', [$this, 'ajax_cleanup_old_meta']);
        add_action('wp_ajax_ofwn_debug_meta_status', [$this, 'ajax_debug_meta_status']);
        add_action('wp_ajax_ofwn_fix_missing_cpts', [$this, 'ajax_fix_missing_cpts']);
        
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
            
            <hr>
            
            <h2><?php esc_html_e('配布エンドポイント確認', 'work-notes'); ?></h2>
            <p><?php esc_html_e('仮想配布ルート /updates/ の動作を確認します。', 'work-notes'); ?></p>
            
            <div id="ofwn-distribution-check">
                <button type="button" id="ofwn-check-distribution-btn" class="button">
                    <?php esc_html_e('配布エンドポイントをテスト', 'work-notes'); ?>
                </button>
                <div id="ofwn-distribution-result" style="margin-top: 10px;"></div>
            </div>
            
            <hr>
            
            <h2><?php esc_html_e('キャッシュクリア', 'work-notes'); ?></h2>
            <p><?php esc_html_e('作業メモの保存に問題がある場合、キャッシュをクリアして改善する可能性があります。', 'work-notes'); ?></p>
            
            <div id="ofwn-cache-clear">
                <button type="button" id="ofwn-clear-cache-btn" class="button button-secondary">
                    <?php esc_html_e('キャッシュをクリア', 'work-notes'); ?>
                </button>
                <div id="ofwn-cache-result" style="margin-top: 10px;"></div>
            </div>
            
            <hr>
            
            <h2><?php esc_html_e('データクリーンアップ', 'work-notes'); ?></h2>
            <p><?php esc_html_e('古いメタデータや重複した作業メモCPTを削除します。', 'work-notes'); ?></p>
            <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 4px; margin: 10px 0;">
                <strong>注意:</strong> この操作は孤立したメタデータ、重複したCPT、空のメタデータを削除します。実行前にバックアップを取ることをお勧めします。
            </div>
            
            <div id="ofwn-cleanup">
                <button type="button" id="ofwn-debug-meta-btn" class="button">
                    <?php esc_html_e('データ状況を確認', 'work-notes'); ?>
                </button>
                <button type="button" id="ofwn-fix-missing-cpts-btn" class="button button-primary">
                    <?php esc_html_e('不足しているCPTを作成', 'work-notes'); ?>
                </button>
                <button type="button" id="ofwn-cleanup-meta-btn" class="button button-secondary">
                    <?php esc_html_e('古いデータをクリーンアップ', 'work-notes'); ?>
                </button>
                <div id="ofwn-cleanup-result" style="margin-top: 10px;"></div>
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
                        nonce: '<?php echo esc_attr(wp_create_nonce('ofwn_distribution_check')); ?>'
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
                
                // データ状況確認機能
                $('#ofwn-debug-meta-btn').on('click', function() {
                    var $btn = $(this);
                    var $result = $('#ofwn-cleanup-result');
                    
                    $btn.prop('disabled', true).text('<?php esc_html_e('確認中...', 'work-notes'); ?>');
                    $result.html('');
                    
                    $.post(ajaxurl, {
                        action: 'ofwn_debug_meta_status',
                        nonce: '<?php echo esc_attr(wp_create_nonce('ofwn_debug_meta')); ?>'
                    }, function(response) {
                        if (response.success) {
                            var data = response.data;
                            var html = '<div class="notice notice-info inline"><h4>データ状況:</h4>';
                            
                            html += '<h5>メタデータ統計:</h5><ul>';
                            $.each(data.meta_statistics, function(key, count) {
                                html += '<li>' + key + ': ' + count + '件</li>';
                            });
                            html += '</ul>';
                            
                            html += '<h5>作業メモCPT統計:</h5>';
                            html += '<p>合計CPT: ' + (data.cpt_statistics.total_cpts || 0) + '件 / ';
                            html += '紐づけられた親投稿: ' + (data.cpt_statistics.unique_parents || 0) + '件</p>';
                            
                            if (data.duplicate_cpts && data.duplicate_cpts.length > 0) {
                                html += '<h5>重複CPT:</h5><ul>';
                                $.each(data.duplicate_cpts, function(i, dup) {
                                    html += '<li>投稿ID ' + dup.parent_id + ': ' + dup.cpt_count + '個のCPT</li>';
                                });
                                html += '</ul>';
                            }
                            
                            if (data.meta_posts && data.meta_posts.length > 0) {
                                html += '<h5>メタデータを持つ投稿:</h5><ul>';
                                $.each(data.meta_posts, function(i, post) {
                                    html += '<li>ID:' + post.ID + ' "' + (post.post_title || 'タイトルなし') + '" (' + post.post_type + ', ' + post.post_status + ')';
                                    if (post.work_title) html += ' - タイトル: "' + post.work_title + '"';
                                    if (post.work_content) html += ' - 内容: "' + post.work_content.substring(0, 50) + '..."';
                                    html += '</li>';
                                });
                                html += '</ul>';
                            }
                            
                            if (data.missing_cpts && data.missing_cpts.length > 0) {
                                html += '<h5 style="color:red;">CPTが作成されていない投稿:</h5><ul>';
                                $.each(data.missing_cpts, function(i, post) {
                                    html += '<li style="color:red;">ID:' + post.ID + ' "' + (post.post_title || 'タイトルなし') + '"';
                                    if (post.work_title) html += ' - タイトル: "' + post.work_title + '"';
                                    if (post.work_content) html += ' - 内容: "' + post.work_content.substring(0, 50) + '..."';
                                    html += '</li>';
                                });
                                html += '</ul>';
                                html += '<p style="color:red;"><strong>警告:</strong> これらの投稿は作業メモメタデータを持っているのにCPTが作成されていません。</p>';
                            }
                            
                            html += '</div>';
                            $result.html(html);
                        } else {
                            $result.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                        }
                    }).fail(function() {
                        $result.html('<div class="notice notice-error inline"><p><?php esc_html_e('データ確認に失敗しました。', 'work-notes'); ?></p></div>');
                    }).always(function() {
                        $btn.prop('disabled', false).text('<?php esc_html_e('データ状況を確認', 'work-notes'); ?>');
                    });
                });
                
                // 不足CPT作成機能
                $('#ofwn-fix-missing-cpts-btn').on('click', function() {
                    if (!confirm('不足している作業メモCPTを作成しますか？')) {
                        return;
                    }
                    
                    var $btn = $(this);
                    var $result = $('#ofwn-cleanup-result');
                    
                    $btn.prop('disabled', true).text('<?php esc_html_e('CPT作成中...', 'work-notes'); ?>');
                    $result.html('');
                    
                    $.post(ajaxurl, {
                        action: 'ofwn_fix_missing_cpts',
                        nonce: '<?php echo esc_attr(wp_create_nonce('ofwn_fix_missing_cpts')); ?>'
                    }, function(response) {
                        if (response.success) {
                            var html = '<div class="notice notice-success inline"><p>' + response.data.message + '</p>';
                            if (response.data.created_cpts && response.data.created_cpts.length > 0) {
                                html += '<ul>';
                                $.each(response.data.created_cpts, function(i, item) {
                                    html += '<li>' + item + '</li>';
                                });
                                html += '</ul>';
                            }
                            html += '</div>';
                            $result.html(html);
                        } else {
                            $result.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                        }
                    }).fail(function() {
                        $result.html('<div class="notice notice-error inline"><p><?php esc_html_e('CPT作成に失敗しました。', 'work-notes'); ?></p></div>');
                    }).always(function() {
                        $btn.prop('disabled', false).text('<?php esc_html_e('不足しているCPTを作成', 'work-notes'); ?>');
                    });
                });
                
                // クリーンアップ機能
                $('#ofwn-cleanup-meta-btn').on('click', function() {
                    if (!confirm('古いデータのクリーンアップを実行しますか？この操作は元に戻せません。')) {
                        return;
                    }
                    
                    var $btn = $(this);
                    var $result = $('#ofwn-cleanup-result');
                    
                    $btn.prop('disabled', true).text('<?php esc_html_e('クリーンアップ中...', 'work-notes'); ?>');
                    $result.html('');
                    
                    $.post(ajaxurl, {
                        action: 'ofwn_cleanup_old_meta',
                        nonce: '<?php echo esc_attr(wp_create_nonce('ofwn_cleanup_old_meta')); ?>'
                    }, function(response) {
                        if (response.success) {
                            var html = '<div class="notice notice-success inline"><p>' + response.data.message + '</p>';
                            if (response.data.cleaned_items && response.data.cleaned_items.length > 0) {
                                html += '<ul>';
                                $.each(response.data.cleaned_items, function(i, item) {
                                    html += '<li>' + item + '</li>';
                                });
                                html += '</ul>';
                            }
                            html += '</div>';
                            $result.html(html);
                        } else {
                            $result.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                        }
                    }).fail(function() {
                        $result.html('<div class="notice notice-error inline"><p><?php esc_html_e('クリーンアップに失敗しました。', 'work-notes'); ?></p></div>');
                    }).always(function() {
                        $btn.prop('disabled', false).text('<?php esc_html_e('古いデータをクリーンアップ', 'work-notes'); ?>');
                    });
                });
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
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ofwn_clear_cache')) {
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
        $post_ids = $wpdb->get_col($wpdb->prepare("
            SELECT ID FROM {$wpdb->posts} 
            WHERE post_type IN ('post', 'page', 'of_work_note')
        "));
        
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
        $transient_keys = $wpdb->get_col($wpdb->prepare("
            SELECT option_name FROM {$wpdb->options} 
            WHERE option_name LIKE %s 
            OR option_name LIKE %s
            OR option_name LIKE %s
        ", 
            '_transient_ofwn_%',
            '_transient_timeout_ofwn_%',
            '_transient_work_notes_%'
        ));
        
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
    
    /**
     * 古いメタデータクリーンアップ（AJAX）
     */
    public function ajax_cleanup_old_meta() {
        // ノンス検証
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ofwn_cleanup_old_meta')) {
            wp_send_json_error(['message' => __('セキュリティチェックに失敗しました。', 'work-notes')]);
        }
        
        // 権限チェック
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('この操作を実行する権限がありません。', 'work-notes')]);
        }
        
        try {
            global $wpdb;
            $cleaned_items = [];
            
            // 1. 孤立した作業メモメタデータ（親投稿が存在しないもの）をクリーンアップ
            $orphan_meta_keys = ['_ofwn_work_title', '_ofwn_work_content', '_ofwn_target_label', '_ofwn_requester', '_ofwn_worker', '_ofwn_status', '_ofwn_work_date'];
            
            foreach ($orphan_meta_keys as $meta_key) {
                $orphan_count = $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                    LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    WHERE pm.meta_key = %s AND (p.ID IS NULL OR p.post_status = 'trash')
                ", $meta_key));
                
                if ($orphan_count > 0) {
                    $wpdb->query($wpdb->prepare("
                        DELETE pm FROM {$wpdb->postmeta} pm
                        LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                        WHERE pm.meta_key = %s AND (p.ID IS NULL OR p.post_status = 'trash')
                    ", $meta_key));
                    
                    $cleaned_items[] = $meta_key . ': ' . $orphan_count . '件の孤立メタデータ削除';
                }
            }
            
            // 2. 古い重複作業メモCPTをクリーンアップ（同じ親投稿に対して複数作成されたもの）
            // Plugin Check対策: prepare追加
            $duplicate_cpts = (array) $wpdb->get_results($wpdb->prepare("
                SELECT pm.meta_value as parent_id, COUNT(*) as cpt_count
                FROM {$wpdb->postmeta} pm
                JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                WHERE pm.meta_key = %s
                AND p.post_type = %s
                AND p.post_status = %s
                GROUP BY pm.meta_value
                HAVING COUNT(*) > 1
            ", '_ofwn_bound_post_id', 'of_work_note', 'publish'));
            
            $duplicate_removed = 0;
            foreach ($duplicate_cpts as $dup) {
                // 最新のもの以外を削除
                $cpt_ids = $wpdb->get_col($wpdb->prepare("
                    SELECT p.ID FROM {$wpdb->posts} p
                    JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                    WHERE pm.meta_key = '_ofwn_bound_post_id'
                    AND pm.meta_value = %s
                    AND p.post_type = 'of_work_note'
                    ORDER BY p.post_date DESC
                    LIMIT 999 OFFSET 1
                ", $dup->parent_id));
                
                foreach ($cpt_ids as $cpt_id) {
                    wp_delete_post($cpt_id, true); // 完全削除
                    $duplicate_removed++;
                }
            }
            
            if ($duplicate_removed > 0) {
                $cleaned_items[] = '重複作業メモCPT: ' . $duplicate_removed . '件削除';
            }
            
            // 3. 空の作業メモメタデータを削除
            foreach (['_ofwn_work_title', '_ofwn_work_content'] as $key) {
                $empty_count = $wpdb->query($wpdb->prepare("
                    DELETE FROM {$wpdb->postmeta}
                    WHERE meta_key = %s AND (meta_value = '' OR meta_value IS NULL)
                ", $key));
                
                if ($empty_count > 0) {
                    $cleaned_items[] = $key . ': ' . $empty_count . '件の空メタデータ削除';
                }
            }
            
            // 4. 全トランジェントクリア
            $this->clear_work_notes_transients();
            $cleaned_items[] = 'work-notes関連トランジェント全削除';
            
            // 5. 全キャッシュクリア
            wp_cache_flush();
            $cleaned_items[] = 'WordPressキャッシュ全クリア';
            
            $message = empty($cleaned_items) ? 
                'クリーンアップ対象は見つかりませんでした。' : 
                'クリーンアップ完了: ' . implode('、', $cleaned_items);
            
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                ofwn_log('CLEANUP ' . $message);
            }
            
            wp_send_json_success(['message' => $message, 'cleaned_items' => $cleaned_items]);
            
        } catch (Exception $e) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                ofwn_log('CLEANUP Error: ' . $e->getMessage());
            }
            /* translators: %1$s: PHP exception message */
            wp_send_json_error(['message' => sprintf(__('クリーンアップ中にエラーが発生しました: %1$s', 'work-notes'), esc_html($e->getMessage()))]);
        }
    }
    
    /**
     * メタデータ状況デバッグ（AJAX）
     */
    public function ajax_debug_meta_status() {
        // ノンス検証
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ofwn_debug_meta')) {
            wp_send_json_error(['message' => __('セキュリティチェックに失敗しました。', 'work-notes')]);
        }
        
        // 権限チェック
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('この操作を実行する権限がありません。', 'work-notes')]);
        }
        
        try {
            global $wpdb;
            $debug_info = [];
            
            // 1. 作業メモメタデータを持つ投稿の統計
            $meta_stats = [];
            $meta_keys = ['_ofwn_work_title', '_ofwn_work_content', '_ofwn_target_label', '_ofwn_requester', '_ofwn_worker', '_ofwn_status', '_ofwn_work_date'];
            
            foreach ($meta_keys as $key) {
                $count = $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
                    JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    WHERE pm.meta_key = %s AND pm.meta_value != ''
                    AND p.post_type IN ('post', 'page') AND p.post_status = 'publish'
                ", $key));
                
                $meta_stats[$key] = $count;
            }
            $debug_info['meta_statistics'] = $meta_stats;
            
            // 2. 作業メモCPTの統計
            // Plugin Check対策: prepare追加
            $cpt_stats = $wpdb->get_row($wpdb->prepare("
                SELECT 
                    COUNT(*) as total_cpts,
                    COUNT(DISTINCT pm.meta_value) as unique_parents
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
                WHERE p.post_type = %s AND p.post_status = %s
            ", '_ofwn_bound_post_id', 'of_work_note', 'publish'));
            $debug_info['cpt_statistics'] = $cpt_stats;
            
            // 3. 重複CPTの確認
            // Plugin Check対策: prepare追加
            $duplicates = (array) $wpdb->get_results($wpdb->prepare("
                SELECT pm.meta_value as parent_id, COUNT(*) as cpt_count
                FROM {$wpdb->postmeta} pm
                JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                WHERE pm.meta_key = %s
                AND p.post_type = %s
                AND p.post_status = %s
                GROUP BY pm.meta_value
                HAVING COUNT(*) > 1
                ORDER BY cpt_count DESC
                LIMIT 10
            ", '_ofwn_bound_post_id', 'of_work_note', 'publish'));
            $debug_info['duplicate_cpts'] = $duplicates;
            
            // 4. メタデータを持つ投稿の詳細情報
            // Plugin Check対策: prepare追加（固定値のためプレースホルダー使用）
            $meta_posts = (array) $wpdb->get_results($wpdb->prepare("
                SELECT 
                    p.ID,
                    p.post_title,
                    p.post_type,
                    p.post_status,
                    p.post_modified,
                    pm1.meta_value as work_title,
                    pm2.meta_value as work_content,
                    pm3.meta_value as target_label,
                    pm4.meta_value as requester,
                    pm5.meta_value as worker
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = %s
                LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = %s
                LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = %s
                LEFT JOIN {$wpdb->postmeta} pm4 ON p.ID = pm4.post_id AND pm4.meta_key = %s
                LEFT JOIN {$wpdb->postmeta} pm5 ON p.ID = pm5.post_id AND pm5.meta_key = %s
                WHERE p.post_type IN ('post', 'page')
                AND (pm1.meta_value IS NOT NULL OR pm2.meta_value IS NOT NULL OR pm3.meta_value IS NOT NULL OR pm4.meta_value IS NOT NULL OR pm5.meta_value IS NOT NULL)
                ORDER BY p.post_modified DESC
                LIMIT 20
            ", '_ofwn_work_title', '_ofwn_work_content', '_ofwn_target_label', '_ofwn_requester', '_ofwn_worker'));
            $debug_info['meta_posts'] = $meta_posts;
            
            // 5. CPT作成が失敗した可能性のある投稿を特定
            // Plugin Check対策: prepare追加
            $should_have_cpts = (array) $wpdb->get_results($wpdb->prepare("
                SELECT 
                    p.ID,
                    p.post_title,
                    pm1.meta_value as work_title,
                    pm2.meta_value as work_content,
                    COUNT(cpt.ID) as existing_cpt_count
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = %s
                LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = %s
                LEFT JOIN {$wpdb->postmeta} pm_bound ON p.ID = pm_bound.meta_value AND pm_bound.meta_key = %s
                LEFT JOIN {$wpdb->posts} cpt ON pm_bound.post_id = cpt.ID AND cpt.post_type = %s AND cpt.post_status = %s
                WHERE p.post_type IN ('post', 'page') 
                AND p.post_status = %s
                AND (pm1.meta_value != '' OR pm2.meta_value != '')
                GROUP BY p.ID
                HAVING existing_cpt_count = 0
                ORDER BY p.post_modified DESC
            ", '_ofwn_work_title', '_ofwn_work_content', '_ofwn_bound_post_id', 'of_work_note', 'publish', 'publish'));
            $debug_info['missing_cpts'] = $should_have_cpts;
            
            wp_send_json_success($debug_info);
            
        } catch (Exception $e) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                ofwn_log('DEBUG Error: ' . $e->getMessage());
            }
            /* translators: %1$s: PHP exception message */
            wp_send_json_error(['message' => sprintf(__('デバッグ情報取得中にエラーが発生しました: %1$s', 'work-notes'), esc_html($e->getMessage()))]);
        }
    }
    
    /**
     * 不足しているCPTを作成（AJAX）
     */
    public function ajax_fix_missing_cpts() {
        // ノンス検証
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ofwn_fix_missing_cpts')) {
            wp_send_json_error(['message' => __('セキュリティチェックに失敗しました。', 'work-notes')]);
        }
        
        // 権限チェック
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('この操作を実行する権限がありません。', 'work-notes')]);
        }
        
        try {
            global $wpdb;
            $created_cpts = [];
            
            // CPTが作成されていない投稿を取得
            // Plugin Check対策: prepare追加
            $missing_posts = (array) $wpdb->get_results($wpdb->prepare("
                SELECT 
                    p.ID,
                    p.post_title,
                    pm1.meta_value as work_title,
                    pm2.meta_value as work_content,
                    pm3.meta_value as requester,
                    pm4.meta_value as worker,
                    pm5.meta_value as target_label
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = %s
                LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = %s
                LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = %s
                LEFT JOIN {$wpdb->postmeta} pm4 ON p.ID = pm4.post_id AND pm4.meta_key = %s
                LEFT JOIN {$wpdb->postmeta} pm5 ON p.ID = pm5.post_id AND pm5.meta_key = %s
                LEFT JOIN {$wpdb->postmeta} pm_bound ON p.ID = pm_bound.meta_value AND pm_bound.meta_key = %s
                LEFT JOIN {$wpdb->posts} cpt ON pm_bound.post_id = cpt.ID AND cpt.post_type = %s AND cpt.post_status = %s
                WHERE p.post_type IN ('post', 'page') 
                AND p.post_status = %s
                AND (pm1.meta_value != '' OR pm2.meta_value != '')
                AND cpt.ID IS NULL
                ORDER BY p.post_modified DESC
            ", '_ofwn_work_title', '_ofwn_work_content', '_ofwn_requester', '_ofwn_worker', '_ofwn_target_label', '_ofwn_bound_post_id', 'of_work_note', 'publish', 'publish'));
            
            foreach ($missing_posts as $post) {
                $work_title = trim($post->work_title ?: '');
                $work_content = trim($post->work_content ?: '');
                
                // 少なくとも一つの内容がある場合のみ作成
                if (empty($work_title) && empty($work_content)) {
                    continue;
                }
                
                // CPTを作成
                $note_title = !empty($work_title) ? sanitize_text_field($work_title) : '作業メモ ' . current_time('Y-m-d H:i');
                $note_content = !empty($work_content) ? wp_kses_post($work_content) : '';
                
                $note_id = wp_insert_post([
                    'post_type' => 'of_work_note',
                    'post_status' => 'publish',
                    'post_title' => $note_title,
                    'post_content' => $note_content,
                    'post_author' => get_current_user_id(),
                ]);
                
                if ($note_id && !is_wp_error($note_id)) {
                    // メタデータを設定
                    update_post_meta($note_id, '_ofwn_target_type', 'post');
                    update_post_meta($note_id, '_ofwn_target_id', (string)$post->ID);
                    update_post_meta($note_id, '_ofwn_bound_post_id', (int)$post->ID);
                    
                    if (!empty($post->requester)) {
                        update_post_meta($note_id, '_ofwn_requester', sanitize_text_field($post->requester));
                    }
                    if (!empty($post->worker)) {
                        update_post_meta($note_id, '_ofwn_worker', sanitize_text_field($post->worker));
                    }
                    if (!empty($post->target_label)) {
                        update_post_meta($note_id, '_ofwn_target_label', sanitize_text_field($post->target_label));
                    }
                    
                    $created_cpts[] = 'CPT #' . $note_id . ' を作成 (親投稿: #' . $post->ID . ' "' . $post->post_title . '")';
                }
            }
            
            $message = empty($created_cpts) ? 
                '作成すべきCPTは見つかりませんでした。' : 
                count($created_cpts) . '件のCPTを作成しました。';
            
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                ofwn_log('FIX_CPT ' . $message);
            }
            
            wp_send_json_success(['message' => $message, 'created_cpts' => $created_cpts]);
            
        } catch (Exception $e) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                ofwn_log('FIX_CPT Error: ' . $e->getMessage());
            }
            /* translators: %1$s: PHP exception message */
            wp_send_json_error(['message' => sprintf(__('CPT作成中にエラーが発生しました: %1$s', 'work-notes'), esc_html($e->getMessage()))]);
        }
    }
}