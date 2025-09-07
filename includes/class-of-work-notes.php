<?php
if (!defined('ABSPATH')) exit;

class OF_Work_Notes {
    const CPT = 'of_work_note';
    const NONCE = 'ofwn_nonce';
    const OPT_REQUESTERS = 'ofwn_requesters';
    const OPT_WORKERS    = 'ofwn_workers';
    const OPT_UPDATE_CHANNEL = 'ofwn_update_channel';

    public function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_action('init', [$this, 'register_meta_fields']);
        add_action('init', [$this, 'add_rewrite_rules']);
        
        // 作業ログ機能を初期化
        $this->init_worklog_features();
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_note_meta']);
        add_action('save_post', [$this, 'capture_quick_note_from_parent'], 20, 2);
        
        // Gutenberg対応: 投稿/固定ページ保存時にメタデータから作業メモCPT自動生成
        // タイミング調査用: 優先度の異なるフックを追加
        add_action('save_post_post', [$this, 'debug_save_timing_early'], 5, 2);
        add_action('save_post_post', [$this, 'auto_create_work_note_from_meta'], 99, 2);
        add_action('save_post_post', [$this, 'debug_save_timing_late'], 100, 2);
        add_action('save_post_page', [$this, 'debug_save_timing_early'], 5, 2);
        add_action('save_post_page', [$this, 'auto_create_work_note_from_meta'], 99, 2);
        add_action('save_post_page', [$this, 'debug_save_timing_late'], 100, 2);
        
        // 代替フック調査用
        add_action('wp_after_insert_post', [$this, 'debug_after_insert_post'], 10, 4);
        
        // Step B: wp_after_insert_postでのバックアップCPT作成（save_postで失敗した場合の保険）
        add_action('wp_after_insert_post', [$this, 'fallback_create_work_note_from_meta'], 20, 4);
        
        add_filter('manage_edit-' . self::CPT . '_columns', [$this, 'cols']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [$this, 'col_content'], 10, 2);
        add_filter('manage_edit-' . self::CPT . '_sortable_columns', [$this, 'sortable_cols']);
        add_action('pre_get_posts', [$this, 'handle_sortable_columns']);
        add_action('admin_bar_menu', [$this, 'admin_bar_quick_add'], 80);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('current_screen', [$this, 'maybe_prefill_target_meta']);
        add_action('wp_ajax_ofwn_get_sidebar_data', [$this, 'ajax_get_sidebar_data']);

        // 旧設定ページのリダイレクト処理
        add_action('admin_init', [$this, 'handle_legacy_settings_redirect']);

        // 作業一覧ページ
        add_action('admin_menu', [$this, 'add_list_page']);
        
        // 仮想配布ルート
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_action('template_redirect', [$this, 'handle_updates_request']);
        
        // 配布確認AJAX
        add_action('wp_ajax_ofwn_check_distribution', [$this, 'ajax_check_distribution']);
    }
    
    /**
     * 作業ログ設定機能を初期化
     */
    private function init_worklog_features() {
        // 設定クラスを初期化
        if (!class_exists('OFWN_Worklog_Settings')) {
            require_once OFWN_DIR . 'includes/class-worklog-settings.php';
        }
        new OFWN_Worklog_Settings();
        
        // Gutenberg サイドバー用アセット読み込み
        if (is_admin()) {
            add_action('enqueue_block_editor_assets', [$this, 'enqueue_gutenberg_sidebar_assets']);
            // 一覧画面のカスタマイズは既存のcolsとcol_contentメソッドで実装済み
        }
    }

    /* ===== 基本 ===== */

    public function register_cpt() {
        register_post_type(self::CPT, [
            'labels' => [
                'name' => __('作業メモ', 'work-notes'),
                'singular_name' => __('作業メモ', 'work-notes'),
                'add_new' => __('新規メモ', 'work-notes'),
                'add_new_item' => __('作業メモを追加', 'work-notes'),
                'edit_item' => __('作業メモを編集', 'work-notes'),
                'new_item' => __('新規作業メモ', 'work-notes'),
                'view_item' => __('作業メモを表示', 'work-notes'),
                'search_items' => __('作業メモを検索', 'work-notes'),
                'menu_name' => __('作業メモ', 'work-notes'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-clipboard',
            'supports' => ['title','editor','author'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    public function enqueue_admin_assets($hook) {
        // 作業メモ関連画面のみで読み込み
        $screen = get_current_screen();
        
        // メイン条件: 作業メモのpost_typeまたは関連ページ
        $is_work_notes_screen = false;
        
        if ($screen && self::CPT === $screen->post_type) {
            $is_work_notes_screen = true;
        }
        
        // 作業メモの管理ページ
        if (in_array($hook, [
            'of_work_note_page_ofwn-settings',
            'of_work_note_page_ofwn-list'
        ])) {
            $is_work_notes_screen = true;
        }
        
        // edit.php?post_type=of_work_note
        if ($hook === 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] === self::CPT) {
            $is_work_notes_screen = true;
        }
        
        // 個別投稿/固定ページ編集画面（作業メモボックス表示のため）
        if (in_array($hook, ['post.php', 'post-new.php']) && $screen) {
            $public_post_types = get_post_types(['public' => true], 'names');
            if (in_array($screen->post_type, $public_post_types)) {
                $is_work_notes_screen = true;
            }
        }
        
        if (!$is_work_notes_screen) {
            return;
        }
        
        wp_enqueue_style(
            'ofwn-admin', 
            OFWN_URL . 'assets/admin.css', 
            [], 
            filemtime(OFWN_DIR . 'assets/admin.css')
        );
        wp_enqueue_script(
            'ofwn-admin', 
            OFWN_URL . 'assets/admin.js', 
            [], 
            filemtime(OFWN_DIR . 'assets/admin.js'), 
            true
        );
    }

    /* ===== 設定（マスター管理） ===== */

    /**
     * 旧設定ページへのアクセスを作業ログ設定にリダイレクト
     */
    public function handle_legacy_settings_redirect() {
        if (isset($_GET['post_type']) && $_GET['post_type'] === self::CPT &&
            isset($_GET['page']) && $_GET['page'] === 'ofwn-settings') {
            wp_redirect(admin_url('edit.php?post_type=' . self::CPT . '&page=ofwn-worklog-settings'));
            exit;
        }
    }

    public function register_settings() {
        register_setting('ofwn_settings', self::OPT_REQUESTERS, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_list'],
            'default' => [],
            'show_in_rest' => false,
            'autoload' => false
        ]);
        register_setting('ofwn_settings', self::OPT_WORKERS, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_list'],
            'default' => $this->default_workers(),
            'show_in_rest' => false,
            'autoload' => false
        ]);
        register_setting('ofwn_settings', self::OPT_UPDATE_CHANNEL, [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_update_channel'],
            'default' => 'stable',
            'show_in_rest' => false,
            'autoload' => false
        ]);

        add_settings_section('ofwn_section_main', __('マスター管理', 'work-notes'), '__return_false', 'ofwn_settings');

        add_settings_field(self::OPT_REQUESTERS, __('依頼元マスター（1行1件）', 'work-notes'), function(){
            $v = get_option(self::OPT_REQUESTERS, []);
            echo '<textarea name="'.esc_attr(self::OPT_REQUESTERS).'[]" rows="3" style="width:600px;">'.esc_textarea(implode("\n", $v))."</textarea>";
            echo '<p class="description">' . esc_html__('ここに入力した内容が「依頼元」のセレクトに表示されます。', 'work-notes') . '</p>';
        }, 'ofwn_settings', 'ofwn_section_main');

        add_settings_field(self::OPT_WORKERS, __('担当者マスター（1行1件）', 'work-notes'), function(){
            $v = get_option(self::OPT_WORKERS, $this->default_workers());
            echo '<textarea name="'.esc_attr(self::OPT_WORKERS).'[]" rows="3" style="width:600px;">'.esc_textarea(implode("\n", $v))."</textarea>";
            echo '<p class="description">' . esc_html__('ここに入力した内容が「担当者」のセレクトに表示されます。', 'work-notes') . '</p>';
        }, 'ofwn_settings', 'ofwn_section_main');

        add_settings_section('ofwn_section_update', __('アップデート設定', 'work-notes'), '__return_false', 'ofwn_settings');

        add_settings_field(self::OPT_UPDATE_CHANNEL, __('更新チャンネル', 'work-notes'), function(){
            $current = get_option(self::OPT_UPDATE_CHANNEL, 'stable');
            echo '<select name="'.esc_attr(self::OPT_UPDATE_CHANNEL).'">';
            echo '<option value="stable"' . selected($current, 'stable', false) . '>' . esc_html__('安定版 (Stable)', 'work-notes') . '</option>';
            echo '<option value="beta"' . selected($current, 'beta', false) . '>' . esc_html__('ベータ版 (Beta)', 'work-notes') . '</option>';
            echo '</select>';
            echo '<p class="description">' . esc_html__('プラグインの自動更新で使用するチャンネルを選択してください。', 'work-notes') . '</p>';
        }, 'ofwn_settings', 'ofwn_section_update');
    }

    public function sanitize_list($raw) {
        if (is_array($raw) && count($raw) === 1 && is_string($raw[0])) $raw = $raw[0];
        $text = is_array($raw) ? implode("\n", $raw) : (string)$raw;
        $lines = array_filter(array_map(function($s){
            $s = trim(str_replace(["\r\n","\r"], "\n", $s)); return $s;
        }, explode("\n", $text)));
        $lines = array_values(array_unique($lines));
        return $lines;
    }
    
    public function sanitize_update_channel($input) {
        return in_array($input, ['stable', 'beta'], true) ? $input : 'stable';
    }

    private function default_workers() {
        $roles = ['administrator','editor','author'];
        $users = get_users(['role__in'=>$roles, 'fields'=>['display_name']]);
        $names = array_map(function($u){ return $u->display_name; }, $users);
        $names = array_filter(array_unique($names));
        if (empty($names)) $names = [wp_get_current_user()->display_name ?: '担当者A'];
        return array_values($names);
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('作業メモ設定', 'work-notes'); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('ofwn_settings');
                do_settings_sections('ofwn_settings');
                submit_button(__('保存', 'work-notes'));
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

    /* ===== メタボックス ===== */

    public function add_meta_boxes() {
        // 作業メモCPTはそのまま維持（既存機能の互換性）
        if (!function_exists('use_block_editor_for_post_type') || !use_block_editor_for_post_type(self::CPT)) {
            add_meta_box('ofwn_fields', __('作業メモ属性', 'work-notes'), [$this, 'box_note_fields'], self::CPT, 'side', 'default');
        }
        
        // 投稿と固定ページにClassic Editor時のみメタボックス追加
        $target_post_types = ['post', 'page'];
        foreach ($target_post_types as $post_type) {
            if (!function_exists('use_block_editor_for_post_type') || !use_block_editor_for_post_type($post_type)) {
                add_meta_box('ofwn_parent', __('作業メモ', 'work-notes'), [$this, 'box_parent_notes'], $post_type, 'normal', 'default');
            }
        }
    }

    private function get_meta($post_id, $key, $default = '') {
        $v = get_post_meta($post_id, $key, true);
        return $v !== '' ? $v : $default;
    }

    private function render_select_with_custom($name, $options, $current_value, $placeholder='その他（手入力）') {
        $options = is_array($options) ? $options : [];
        $is_custom = $current_value && !in_array($current_value, $options, true);
        echo '<div class="ofwn-inline">';
        echo '<select name="'.esc_attr($name).'_select" data-ofwn-select="1">';
        echo '<option value="">（選択）</option>';
        foreach ($options as $opt) {
            printf('<option value="%1$s"%2$s>%1$s</option>',
                esc_attr($opt),
                selected($current_value, $opt, false)
            );
        }
        echo '<option value="__custom__"'.selected($is_custom, true, false).'>'.esc_html($placeholder).'</option>';
        echo '</select>';
        echo ' <input type="text" data-ofwn-custom="'.esc_attr($name).'_select" name="'.esc_attr($name).'" value="'.esc_attr($current_value).'" placeholder="'.esc_attr($placeholder).'" '.($is_custom?'':'style="display:none"').'>';
        echo '</div>';
    }

    public function box_note_fields($post) {
        if (!current_user_can('edit_post', $post->ID)) return;
        wp_nonce_field(self::NONCE, self::NONCE);

        $target_type  = $this->get_meta($post->ID, '_ofwn_target_type', '');
        $target_id    = $this->get_meta($post->ID, '_ofwn_target_id', '');
        // 統合された作業タイトル（対象ラベルからの移行を含む）
        $work_title = get_post_meta($post->ID, '_ofwn_work_title', true) ?: $this->get_meta($post->ID, '_ofwn_target_label', '');
        $status       = $this->get_meta($post->ID, '_ofwn_status', '依頼');
        $requester    = $this->get_meta($post->ID, '_ofwn_requester', '');
        $worker       = $this->get_meta($post->ID, '_ofwn_worker', wp_get_current_user()->display_name);
        $date         = $this->get_meta($post->ID, '_ofwn_work_date', current_time('Y-m-d'));

        $req_opts = get_option(self::OPT_REQUESTERS, []);
        $wrk_opts = get_option(self::OPT_WORKERS, $this->default_workers());

        ?>
        <p><label><?php esc_html_e('対象タイプ', 'work-notes'); ?><br>
            <select name="ofwn_target_type">
                <option value=""><?php esc_html_e('（任意）', 'work-notes'); ?></option>
                <option value="post" <?php selected($target_type,'post');?>><?php esc_html_e('投稿/固定ページ', 'work-notes'); ?></option>
                <option value="site" <?php selected($target_type,'site');?>><?php esc_html_e('サイト全体/設定/テーマ', 'work-notes'); ?></option>
                <option value="other" <?php selected($target_type,'other');?>><?php esc_html_e('その他', 'work-notes'); ?></option>
            </select>
        </label></p>

        <p><label><?php esc_html_e('対象ID（投稿IDなど）', 'work-notes'); ?><br>
            <input type="text" name="ofwn_target_id" value="<?php echo esc_attr($target_id);?>" style="width:100%;">
        </label></p>


        <!-- 作業タイトル（2行入力） -->
        <p><label><?php esc_html_e('作業タイトル', 'work-notes'); ?><br>
            <textarea name="ofwn_work_title" style="width:100%; height:50px;"><?php echo esc_textarea($work_title);?></textarea>
        </label></p>

        <p><label><?php esc_html_e('作業内容', 'work-notes'); ?><br>
            <textarea name="ofwn_work_content" style="width:100%; height:80px;"><?php echo esc_textarea(get_post_meta($post->ID, '_ofwn_work_content', true));?></textarea>
        </label></p>

        <p class="ofwn-inline"><label><?php esc_html_e('依頼元', 'work-notes'); ?></label><br>
            <?php $this->render_select_with_custom('ofwn_requester', $req_opts, $requester, __('依頼元を手入力', 'work-notes')); ?>
        </p>

        <p class="ofwn-inline"><label><?php esc_html_e('担当者', 'work-notes'); ?></label><br>
            <?php $this->render_select_with_custom('ofwn_worker', $wrk_opts, $worker, __('担当者を手入力', 'work-notes')); ?>
        </p>

        <p class="ofwn-inline"><label><?php esc_html_e('ステータス', 'work-notes'); ?><br>
            <select name="ofwn_status">
                <option value="依頼" <?php selected($status,'依頼');?>><?php esc_html_e('依頼', 'work-notes'); ?></option>
                <option value="対応中" <?php selected($status,'対応中');?>><?php esc_html_e('対応中', 'work-notes'); ?></option>
                <option value="完了" <?php selected($status,'完了');?>><?php esc_html_e('完了', 'work-notes'); ?></option>
            </select>
        </label></p>

        <p class="ofwn-inline"><label><?php esc_html_e('実施日', 'work-notes'); ?><br>
            <input type="date" name="ofwn_work_date" value="<?php echo esc_attr($date);?>">
        </label></p>
        <?php
    }

    public function save_note_meta($post_id) {
        // デバッグログ開始（本番では無効化）
        $debug_log = defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
        if ($debug_log) {
            error_log('[OFWN] save_note_meta called for post_id: ' . $post_id);
        }
        
        // ノンス検証（最優先）
        if (!isset($_POST[self::NONCE])) {
            if ($debug_log) error_log('[OFWN] Nonce field missing');
            return;
        }
        
        if (!wp_verify_nonce($_POST[self::NONCE], self::NONCE)) {
            if ($debug_log) error_log('[OFWN] Nonce verification failed');
            return;
        }
        
        // 自動保存スキップ
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            if ($debug_log) error_log('[OFWN] Skipping autosave');
            return;
        }
        
        // 権限チェック
        if (!current_user_can('edit_post', $post_id)) {
            if ($debug_log) error_log('[OFWN] User cannot edit post');
            return;
        }
        
        // 投稿タイプチェック
        if (self::CPT !== get_post_type($post_id)) {
            if ($debug_log) error_log('[OFWN] Wrong post type: ' . get_post_type($post_id));
            return;
        }
        
        // Quick Edit 対策：メタフィールドが存在しない場合はスキップ
        if (!isset($_POST['ofwn_requester_select']) && !isset($_POST['ofwn_requester'])) {
            if ($debug_log) error_log('[OFWN] Meta fields not present, possibly Quick Edit - skipping');
            return;
        }

        $requester = $this->resolve_select_or_custom('ofwn_requester');
        $worker    = $this->resolve_select_or_custom('ofwn_worker');

        $map = [
            '_ofwn_target_type'  => 'ofwn_target_type',
            '_ofwn_target_id'    => 'ofwn_target_id',
            // '_ofwn_target_label' => 'ofwn_target_label', // 廃止：作業タイトルに統合
            '_ofwn_requester'    => $requester,
            '_ofwn_worker'       => $worker,
            '_ofwn_status'       => 'ofwn_status',
            '_ofwn_work_date'    => 'ofwn_work_date',
            // 修正: 新フィールドを$_POSTキー名で指定し、他のフィールドと同じ処理フローに合わせる
            '_ofwn_work_title'   => 'ofwn_work_title',
            '_ofwn_work_content' => 'ofwn_work_content',
        ];
        
        // 作業内容が空で作業タイトルがある場合の自動コピー処理
        $work_title_value = isset($_POST['ofwn_work_title']) ? sanitize_text_field($_POST['ofwn_work_title']) : '';
        $work_content_value = isset($_POST['ofwn_work_content']) ? sanitize_textarea_field($_POST['ofwn_work_content']) : '';
        
        if (empty($work_content_value) && !empty($work_title_value)) {
            $_POST['ofwn_work_content'] = $work_title_value; // $_POSTを更新して後続処理で使用
            if ($debug_log) {
                error_log('[OFWN] Auto-copied work_title to work_content: ' . $work_title_value);
            }
        }
        // 保存前のログ
        if ($debug_log) {
            error_log('[OFWN] Saving meta: requester=' . $requester . ', worker=' . $worker);
        }
        
        foreach ($map as $meta => $fieldOrValue) {
            $old_value = get_post_meta($post_id, $meta, true);
            
            if (is_string($fieldOrValue) && isset($_POST[$fieldOrValue])) {
                $new_value = sanitize_text_field($_POST[$fieldOrValue]);
                update_post_meta($post_id, $meta, $new_value);
                if ($debug_log && $old_value !== $new_value) {
                    error_log('[OFWN] Updated ' . $meta . ': "' . $old_value . '" -> "' . $new_value . '"');
                }
            } elseif (!is_string($fieldOrValue) && $fieldOrValue !== null) {
                $new_value = sanitize_text_field($fieldOrValue);
                update_post_meta($post_id, $meta, $new_value);
                if ($debug_log && $old_value !== $new_value) {
                    error_log('[OFWN] Updated ' . $meta . ': "' . $old_value . '" -> "' . $new_value . '"');
                }
            }
        }
        
        // 保存後の検証
        if ($debug_log) {
            $saved_req = get_post_meta($post_id, '_ofwn_requester', true);
            $saved_worker = get_post_meta($post_id, '_ofwn_worker', true);
            // 新フィールドの保存状態も確認
            $saved_title = get_post_meta($post_id, '_ofwn_work_title', true);
            $saved_content = get_post_meta($post_id, '_ofwn_work_content', true);
            error_log('[OFWN] Post-save verification: requester=' . $saved_req . ', worker=' . $saved_worker);
            error_log('[OFWN] save_note_meta map resolved: title="' . $saved_title . '" content="' . $saved_content . '" for note ID=' . $post_id);
        }

        if (empty($_POST['post_title'])) {
            $t = get_post_field('post_title', $post_id);
            if (!$t) {
                wp_update_post([
                    'ID' => $post_id,
                    'post_title' => '作業メモ ' . current_time('Y-m-d H:i')
                ]);
            }
        }
    }

    private function resolve_select_or_custom($baseName) {
        $sel = $_POST[$baseName . '_select'] ?? '';
        $custom = $_POST[$baseName] ?? '';
        if ($sel === '__custom__') return $custom;
        return $sel ?: $custom;
    }

    public function cols($cols) {
        $new = [];
        $new['cb'] = $cols['cb'] ?? '';
        $new['title'] = __('タイトル', 'work-notes');
        // 新規追加: 作業タイトルと作業内容列をタイトル列の直後に追加
        $new['work_title'] = __('作業タイトル', 'work-notes');
        $new['work_content'] = __('作業内容', 'work-notes');
        $new['ofwn_requester'] = '依頼元';
        $new['ofwn_assignee'] = '担当者';
        $new['ofwn_target'] = '対象';
        $new['ofwn_status'] = 'ステータス';
        $new['author'] = '作成者';
        $new['date'] = '日付';
        return $new;
    }

    public function col_content($col, $post_id) {
        // 作業タイトル列の表示処理（対象ラベルからの統合を含む）
        if ($col === 'work_title') {
            $work_title = get_post_meta($post_id, '_ofwn_work_title', true);
            $target_label = get_post_meta($post_id, '_ofwn_target_label', true);
            $display_title = $work_title ?: $target_label ?: __('データなし', 'work-notes');
            echo esc_html($display_title);
        }
        // 新規追加: 作業内容列の表示処理
        if ($col === 'work_content') {
            $work_content = get_post_meta($post_id, '_ofwn_work_content', true);
            // 長い内容は省略表示し、空の場合は'データなし'を表示
            if (!empty($work_content)) {
                $truncated_content = mb_strlen($work_content) > 50 ? mb_substr($work_content, 0, 47) . '...' : $work_content;
                echo esc_html($truncated_content);
            } else {
                echo esc_html(__('データなし', 'work-notes'));
            }
        }
        if ($col === 'ofwn_requester') {
            $requester = $this->get_meta($post_id, '_ofwn_requester');
            echo esc_html($requester ?: '—');
        }
        if ($col === 'ofwn_assignee') {
            $worker = $this->get_meta($post_id, '_ofwn_worker');
            echo esc_html($worker ?: '—');
        }
        if ($col === 'ofwn_target') {
            $type  = $this->get_meta($post_id, '_ofwn_target_type');
            $id    = $this->get_meta($post_id, '_ofwn_target_id');
            $label = $this->get_meta($post_id, '_ofwn_target_label');
            if ('post' === $type && $id) {
                $link = get_edit_post_link((int)$id);
                $title = get_the_title((int)$id);
                echo '<a href="'.esc_url($link).'">'.esc_html($title ?: ('ID:'.$id)).'</a>';
            } else {
                echo esc_html($label ?: '—');
            }
        }
        if ($col === 'ofwn_status') {
            $s = $this->get_meta($post_id, '_ofwn_status','依頼');
            $cls = '完了' === $s ? 'done' : '';
            echo '<span class="ofwn-badge ' . esc_attr($cls) . '">' . esc_html($s) . '</span>';
        }
    }

    public function sortable_cols($cols) {
        $cols['ofwn_requester'] = 'ofwn_requester';
        $cols['ofwn_assignee'] = 'ofwn_assignee';
        return $cols;
    }

    public function handle_sortable_columns($query) {
        if (!is_admin() || !$query->is_main_query()) return;
        
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== self::CPT || $screen->base !== 'edit') return;

        $orderby = $query->get('orderby');
        if ($orderby === 'ofwn_requester') {
            $query->set('meta_key', '_ofwn_requester');
            $query->set('orderby', 'meta_value');
        } elseif ($orderby === 'ofwn_assignee') {
            $query->set('meta_key', '_ofwn_worker');
            $query->set('orderby', 'meta_value');
        }
    }

    /**
     * ブロックエディタ対応のためのメタフィールド登録
     * 本番での保存バグを修正するための REST API 対応
     */
    public function register_meta_fields() {
        // 投稿と固定ページに作業メモのメタフィールドを登録
        $target_post_types = ['post', 'page'];
        $all_meta_fields = [
            '_ofwn_requester', '_ofwn_worker', '_ofwn_target_type', 
            '_ofwn_target_id', '_ofwn_target_label', '_ofwn_status', '_ofwn_work_date',
            // 修正: 新フィールドを追加してGutenbergサイドバーでREST API経由の保存を可能に
            '_ofwn_work_title', '_ofwn_work_content'
        ];
        
        foreach ($target_post_types as $post_type) {
            foreach ($all_meta_fields as $meta_key) {
                // 作業内容は複数行テキストなのでsanitize_textarea_fieldを使用
                $sanitize_callback = ($meta_key === '_ofwn_work_content') ? 'sanitize_textarea_field' : 'sanitize_text_field';
                
                register_post_meta($post_type, $meta_key, [
                    'show_in_rest' => true,
                    'single' => true,
                    'type' => 'string',
                    'auth_callback' => function($allowed, $meta_key, $post_id) {
                        return current_user_can('edit_post', $post_id);
                    },
                    'sanitize_callback' => $sanitize_callback
                ]);
            }
        }
        
        // 既存の作業メモCPT用メタフィールドも維持（互換性のため）
        register_post_meta(self::CPT, '_ofwn_requester', [
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'auth_callback' => function($allowed, $meta_key, $post_id) {
                return current_user_can('edit_post', $post_id);
            },
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        
        register_post_meta(self::CPT, '_ofwn_worker', [
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string', 
            'auth_callback' => function($allowed, $meta_key, $post_id) {
                return current_user_can('edit_post', $post_id);
            },
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        
        // 正規リンク用メタフィールド（数値）
        register_post_meta(self::CPT, '_ofwn_bound_post_id', [
            'show_in_rest' => true,
            'single' => true,
            'type' => 'integer',
            'auth_callback' => function($allowed, $meta_key, $post_id) {
                return current_user_can('edit_post', $post_id);
            },
            'sanitize_callback' => 'absint'
        ]);
        
        // 新規追加: 作業タイトルと作業内容のメタフィールド
        // CPTだけでなくpost/pageにも登録してGutenbergサイドバーで使えるようにする
        $work_meta_post_types = [self::CPT, 'post', 'page'];
        
        foreach ($work_meta_post_types as $post_type) {
            register_post_meta($post_type, '_ofwn_work_title', [
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'auth_callback' => function($allowed, $meta_key, $post_id) {
                    return current_user_can('edit_post', $post_id);
                },
                'sanitize_callback' => 'sanitize_text_field'
            ]);
            
            register_post_meta($post_type, '_ofwn_work_content', [
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'auth_callback' => function($allowed, $meta_key, $post_id) {
                    return current_user_can('edit_post', $post_id);
                },
                'sanitize_callback' => 'sanitize_textarea_field'
            ]);
        }
        
        $other_metas = [
            '_ofwn_target_type', '_ofwn_target_id', '_ofwn_target_label', 
            '_ofwn_status', '_ofwn_work_date'
        ];
        foreach ($other_metas as $meta_key) {
            register_post_meta(self::CPT, $meta_key, [
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'auth_callback' => function($allowed, $meta_key, $post_id) {
                    return current_user_can('edit_post', $post_id);
                },
                'sanitize_callback' => 'sanitize_text_field'
            ]);
        }
        
        // デバッグログ用（WP_DEBUG_LOG 有効時のみ）
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[OFWN] Meta fields registered for block editor compatibility');
        }
    }

    public function box_parent_notes($post) {
        if (!current_user_can('edit_post', $post->ID)) return;
        wp_nonce_field(self::NONCE, self::NONCE);

        $cache_key = 'ofwn_notes_' . $post->ID;
        $notes = wp_cache_get($cache_key, 'work_notes');
        if (false === $notes) {
            $query = new WP_Query([
                'post_type' => self::CPT,
                'posts_per_page' => 20,
                'meta_query' => [
                    'relation' => 'AND',
                    ['key' => '_ofwn_target_type', 'value' => 'post', 'compare' => '='],
                    ['key' => '_ofwn_target_id', 'value' => (string)$post->ID, 'compare' => '='],
                ],
                'orderby' => 'date',
                'order' => 'DESC',
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
            ]);
            $notes = $query->posts;
            wp_cache_set($cache_key, $notes, 'work_notes', 5 * MINUTE_IN_SECONDS);
        }

        echo '<div class="ofwn-list">';
        if ($notes) {
            foreach ($notes as $n) {
                $status = get_post_meta($n->ID, '_ofwn_status', true);
                $req = get_post_meta($n->ID, '_ofwn_requester', true);
                $worker = get_post_meta($n->ID, '_ofwn_worker', true);
                $date = get_post_meta($n->ID, '_ofwn_work_date', true);
                echo '<div class="ofwn-note-item">';
                echo '<strong>'.esc_html(get_the_title($n)).'</strong> ';
                echo '<span class="ofwn-badge ' . esc_attr($status==='完了'?'done':'') . '">' . esc_html($status ?: '—') . '</span><br>';
                echo wpautop(esc_html($n->post_content));
                echo '<small>依頼元: '.esc_html($req ?: '—').' / 担当: '.esc_html($worker ?: '—').' / 実施日: '.esc_html($date ?: '—').'</small>';
                echo ' / <a href="'.esc_url(get_edit_post_link($n->ID)).'">' . esc_html__('編集', 'work-notes') . '</a>';
                echo '</div>';
            }
        } else {
            echo '<p>' . esc_html__('このコンテンツに紐づく作業メモはまだありません。', 'work-notes') . '</p>';
        }
        echo '</div>';

        $req_opts = get_option(self::OPT_REQUESTERS, []);
        $wrk_opts = get_option(self::OPT_WORKERS, $this->default_workers());
        ?>
        <hr>
        <h4><?php esc_html_e('この投稿に作業メモを追加', 'work-notes'); ?></h4>

        <p><label><?php esc_html_e('依頼元', 'work-notes'); ?></label><br>
            <?php $this->render_select_with_custom('ofwn_quick_requester', $req_opts, ''); ?>
        </p>

        <p><label><?php esc_html_e('内容（作業メモ本文）', 'work-notes'); ?><br><textarea name="ofwn_quick_content" rows="4" style="width:100%;"></textarea></label></p>

        <p class="ofwn-inline">
            <label><?php esc_html_e('ステータス', 'work-notes'); ?>
                <select name="ofwn_quick_status">
                    <option value="依頼"><?php esc_html_e('依頼', 'work-notes'); ?></option>
                    <option value="対応中"><?php esc_html_e('対応中', 'work-notes'); ?></option>
                    <option value="完了"><?php esc_html_e('完了', 'work-notes'); ?></option>
                </select>
            </label>

            <label><?php esc_html_e('実施日', 'work-notes'); ?>
                <input type="date" name="ofwn_quick_date" value="<?php echo esc_attr(current_time('Y-m-d'));?>">
            </label>

            <label><?php esc_html_e('担当者', 'work-notes'); ?></label>
            <?php $this->render_select_with_custom('ofwn_quick_worker', $wrk_opts, wp_get_current_user()->display_name); ?>
        </p>
        <?php
    }

    public function capture_quick_note_from_parent($post_id, $post) {
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce(sanitize_key($_POST[self::NONCE]), self::NONCE)) {
            return;
        }
        if (!wp_doing_ajax() && (!isset($_POST['action']) || 'editpost' !== $_POST['action'])) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (!in_array($post->post_type, get_post_types(['public'=>true], 'names'))) return;

        $content = isset($_POST['ofwn_quick_content']) ? trim(wp_unslash($_POST['ofwn_quick_content'])) : '';
        if ($content === '') return;

        $requester = $this->resolve_select_or_custom('ofwn_quick_requester');
        $status    = sanitize_text_field($_POST['ofwn_quick_status'] ?? '依頼');
        $date      = sanitize_text_field($_POST['ofwn_quick_date'] ?? current_time('Y-m-d'));
        $workerVal = $this->resolve_select_or_custom('ofwn_quick_worker');

        $note_id = wp_insert_post([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'post_title' => '作業メモ ' . current_time('Y-m-d H:i'),
            'post_content' => $content,
            'post_author' => get_current_user_id(),
        ]);

        if ($note_id && !is_wp_error($note_id)) {
            update_post_meta($note_id, '_ofwn_target_type', 'post');
            update_post_meta($note_id, '_ofwn_target_id', (string)$post_id);
            // update_post_meta($note_id, '_ofwn_target_label', get_the_title($post_id)); // 廃止：作業タイトルに統合
            update_post_meta($note_id, '_ofwn_requester', sanitize_text_field($requester));
            update_post_meta($note_id, '_ofwn_worker', sanitize_text_field($workerVal));
            update_post_meta($note_id, '_ofwn_status', $status);
            update_post_meta($note_id, '_ofwn_work_date', $date);
            
            // 正規リンク用メタフィールドを自動付与
            update_post_meta($note_id, '_ofwn_bound_post_id', (int)$post_id);
        }
    }

    /**
     * Gutenberg対応: 投稿/固定ページ保存時にメタデータから作業メモCPTを自動生成
     * save_post_post, save_post_page フックで実行
     */
    public function auto_create_work_note_from_meta($post_id, $post) {
        // ガード条件
        if (wp_is_post_revision($post_id)) return;
        if (wp_is_post_autosave($post_id)) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        
        // 投稿タイプの制限：postとpageのみ対象（CPT自身は除外）
        if (!in_array($post->post_type, ['post', 'page'])) {
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('[OFWN] Skipping auto-create for post_type: ' . $post->post_type . ' (ID: ' . $post_id . ')');
            }
            return;
        }
        
        // 作業メモ関連のメタデータを取得
        $target_type = get_post_meta($post_id, '_ofwn_target_type', true);
        $target_id = get_post_meta($post_id, '_ofwn_target_id', true);
        $target_label = get_post_meta($post_id, '_ofwn_target_label', true);
        $requester = get_post_meta($post_id, '_ofwn_requester', true);
        $worker = get_post_meta($post_id, '_ofwn_worker', true);
        $status = get_post_meta($post_id, '_ofwn_status', true);
        $work_date = get_post_meta($post_id, '_ofwn_work_date', true);
        
        // 新規追加: 作業タイトルと作業内容を取得
        $work_title_check = get_post_meta($post_id, '_ofwn_work_title', true);
        $work_content_check = get_post_meta($post_id, '_ofwn_work_content', true);
        
        // デバッグログ: post/page保存時のメタ情報（タイミング調査用）
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $current_action = current_action();
            $doing_autosave = defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ? 'true' : 'false';
            $is_rest = defined('REST_REQUEST') && REST_REQUEST ? 'true' : 'false';
            error_log('[OFWN TIMING] Processing post/page save - ID: ' . $post_id . ', Type: ' . $post->post_type . ', Action: ' . $current_action . ', Autosave: ' . $doing_autosave . ', REST: ' . $is_rest);
            error_log('[OFWN TIMING] Work title from post: "' . $work_title_check . '", Work content from post: "' . $work_content_check . '"');
            error_log('[OFWN TIMING] Other meta - requester: "' . $requester . '", worker: "' . $worker . '", status: "' . $status . '"');
            
            // RESTリクエストのメタデータを確認
            if ($is_rest && isset($_POST['meta'])) {
                $rest_meta = $_POST['meta'];
                error_log('[OFWN TIMING] REST meta received: ' . json_encode($rest_meta));
            }
        }
        
        // いずれかのフィールドが空でない場合のみ処理続行（新フィールドも含める）
        $has_content = !empty($target_type) || !empty($target_id) || !empty($target_label) || 
                      !empty($requester) || !empty($worker) || !empty($status) || !empty($work_date) ||
                      !empty($work_title_check) || !empty($work_content_check);
        
        if (!$has_content) return;
        
        // 段階2: REST APIリクエスト時のメタ準備完了検証
        if (defined('REST_REQUEST') && REST_REQUEST) {
            // 作業タイトル・作業内容が入力されているのにメタが空の場合は保留
            $work_title_empty = empty($work_title_check);
            $work_content_empty = empty($work_content_check);
            
            // REST経由でのメタ保存が完了していない可能性をチェック
            if ($work_title_empty && $work_content_empty) {
                // 他のメタフィールドもチェック（完全に空の場合は次回保存で処理）
                $other_meta_empty = empty($requester) && empty($worker) && empty($status);
                
                if ($other_meta_empty) {
                    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                        error_log('[OFWN] REST request with empty work meta detected, marking for retry on post ' . $post_id);
                    }
                    
                    // 次回save_postで再処理するためのフラグを設定
                    update_post_meta($post_id, '_ofwn_pending_cpt_creation', 1);
                    return;
                }
            } else {
                // 正常にメタがある場合はフラグを削除
                delete_post_meta($post_id, '_ofwn_pending_cpt_creation');
            }
        }
        
        // フラグがある場合の再処理ロジック
        $pending_flag = get_post_meta($post_id, '_ofwn_pending_cpt_creation', true);
        if ($pending_flag) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('[OFWN] Processing delayed CPT creation for post ' . $post_id);
            }
            delete_post_meta($post_id, '_ofwn_pending_cpt_creation');
        }
        
        // 重複防止のためのハッシュ生成（新フィールドも含める）
        $work_title = get_post_meta($post_id, '_ofwn_work_title', true);
        $work_content = get_post_meta($post_id, '_ofwn_work_content', true);
        
        $meta_payload = [
            'target_type' => $target_type,
            'target_id' => $target_id,
            'target_label' => $target_label,
            'requester' => $requester,
            'worker' => $worker,
            'status' => $status,
            'work_date' => $work_date,
            'work_title' => $work_title,
            'work_content' => $work_content
        ];
        $current_hash = md5(wp_json_encode($meta_payload));
        $last_hash = get_post_meta($post_id, '_ofwn_last_sync_hash', true);
        
        // ハッシュが同じなら重複防止でスキップ
        if ($current_hash === $last_hash) {
            return;
        }
        
        // 作業メモCPTを作成
        $note_title = '作業メモ ' . current_time('Y-m-d H:i');
        $note_content = $this->generate_work_note_content($meta_payload, $post);
        
        $note_id = wp_insert_post([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'post_title' => $note_title,
            'post_content' => $note_content,
            'post_author' => get_current_user_id(),
        ], true);
        
        if (!is_wp_error($note_id) && $note_id) {
            // 作業メモCPTにメタデータを設定
            update_post_meta($note_id, '_ofwn_target_type', $target_type ?: 'post');
            update_post_meta($note_id, '_ofwn_target_id', (string)$post_id);
            // update_post_meta($note_id, '_ofwn_target_label', $target_label ?: get_the_title($post_id)); // 廃止：作業タイトルに統合
            update_post_meta($note_id, '_ofwn_requester', $requester);
            update_post_meta($note_id, '_ofwn_worker', $worker);
            update_post_meta($note_id, '_ofwn_status', $status ?: '依頼');
            update_post_meta($note_id, '_ofwn_work_date', $work_date ?: current_time('Y-m-d'));
            
            // 新規追加: 作業タイトルと作業内容をpost/pageからCPTへ転送
            $work_title_from_post = get_post_meta($post_id, '_ofwn_work_title', true);
            $work_content_from_post = get_post_meta($post_id, '_ofwn_work_content', true);
            
            // CPT側の既存値を確認（上書き防止のため）
            $existing_title = get_post_meta($note_id, '_ofwn_work_title', true);
            $existing_content = get_post_meta($note_id, '_ofwn_work_content', true);
            
            // 作業内容が空の場合は作業タイトルをコピー
            if (empty($work_content_from_post) && !empty($work_title_from_post)) {
                $work_content_from_post = $work_title_from_post;
            }
            
            // 空の場合のみ転送（既に値がある場合は上書きしない）
            if (empty($existing_title)) {
                update_post_meta($note_id, '_ofwn_work_title', $work_title_from_post);
            }
            if (empty($existing_content)) {
                update_post_meta($note_id, '_ofwn_work_content', $work_content_from_post);
            }
            
            // 正規リンク用メタフィールドを設定
            update_post_meta($note_id, '_ofwn_bound_post_id', $post_id);
            
            // 重複防止用ハッシュを親投稿に保存
            update_post_meta($post_id, '_ofwn_last_sync_hash', $current_hash);
            
            // デバッグログ（新フィールド情報も含める）
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                $elapsed_time = microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
                error_log('[OFWN TIMING] Auto-created work note ID: ' . $note_id . ' for post ID: ' . $post_id . ' (elapsed: ' . round($elapsed_time * 1000, 2) . 'ms)');
                error_log('[OFWN TIMING] auto-create transfer: src post=' . $post_id . ' -> note=' . $note_id . ', title="' . $work_title_from_post . '", content="' . $work_content_from_post . '"');
                if (!empty($existing_title) || !empty($existing_content)) {
                    error_log('[OFWN TIMING] Transfer skipped: existing title="' . $existing_title . '", existing content="' . $existing_content . '"');
                } else {
                    error_log('[OFWN TIMING] Transfer executed: new title="' . $work_title_from_post . '", new content="' . $work_content_from_post . '"');
                }
            }
        }
    }
    
    /**
     * 作業メモの内容を生成（Gutenbergサイドバーからの自動作成用）
     */
    private function generate_work_note_content($meta_payload, $post) {
        $content_parts = [];
        
        $content_parts[] = sprintf('投稿「%s」の作業メモを右サイドバーから作成しました。', get_the_title($post));
        
        if (!empty($meta_payload['requester'])) {
            $content_parts[] = "依頼元: " . $meta_payload['requester'];
        }
        
        if (!empty($meta_payload['worker'])) {
            $content_parts[] = "担当者: " . $meta_payload['worker'];
        }
        
        if (!empty($meta_payload['status'])) {
            $content_parts[] = "ステータス: " . $meta_payload['status'];
        }
        
        if (!empty($meta_payload['work_date'])) {
            $content_parts[] = "実施日: " . $meta_payload['work_date'];
        }
        
        return implode("\n\n", $content_parts);
    }

    public function admin_bar_quick_add($wp_admin_bar) {
        if (!is_admin_bar_showing() || !current_user_can('edit_posts')) return;

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $args = [
            'id' => 'ofwn_quick_add',
            'title' => '＋作業メモ',
            'href' => admin_url('post-new.php?post_type=' . self::CPT),
            'meta' => ['title'=>'作業メモを追加']
        ];

        if ($screen && $screen->base === 'post' && isset($_GET['post'])) {
            $pid = (int)$_GET['post'];
            $args['href'] = admin_url('post-new.php?post_type=' . self::CPT . '&ofwn_target=post:' . $pid);
        }
        $wp_admin_bar->add_node($args);
    }

    public function maybe_prefill_target_meta($screen) {
        if ($screen && $screen->id === self::CPT && $screen->base === 'post' && isset($_GET['ofwn_target'])) {
            add_filter('default_title', function($title){ return '作業メモ ' . current_time('Y-m-d H:i'); });
            add_action('save_post_' . self::CPT, function($post_id){
                if (!isset($_GET['ofwn_target'])) return;
                $raw = sanitize_text_field($_GET['ofwn_target']);
                if (strpos($raw, 'post:') === 0) {
                    $pid = (int)substr($raw, 5);
                    update_post_meta($post_id, '_ofwn_target_type', 'post');
                    update_post_meta($post_id, '_ofwn_target_id', (string)$pid);
                    // update_post_meta($post_id, '_ofwn_target_label', get_the_title($pid)); // 廃止：作業タイトルに統合
                    
                    // 正規リンク用メタフィールドを自動付与
                    update_post_meta($post_id, '_ofwn_bound_post_id', $pid);
                }
            }, 10, 1);
        }
    }

    /* ===== 作業一覧（WP_List_Table） ===== */

    public function add_list_page() {
        add_submenu_page(
            'edit.php?post_type=' . self::CPT,
            __('作業一覧', 'work-notes'),
            __('作業一覧', 'work-notes'),
            'edit_posts',
            'ofwn-list',
            [$this, 'render_list_page']
        );
    }

    public function render_list_page() {
        if (!current_user_can('edit_posts')) return;

        if (!class_exists('WP_List_Table')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }

        $table = new OFWN_List_Table([
            'requesters' => get_option(self::OPT_REQUESTERS, []),
            'workers'    => get_option(self::OPT_WORKERS, $this->default_workers())
        ]);
        $table->prepare_items();

        echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__('作業一覧', 'work-notes') . '</h1>';
        echo '<form method="get">';
        echo '<input type="hidden" name="post_type" value="'.esc_attr(self::CPT).'">';
        echo '<input type="hidden" name="page" value="ofwn-list">';
        $table->search_box(__('キーワード検索', 'work-notes'), 'ofwn-search');
        $table->views();
        $table->display();
        echo '</form></div>';
    }
    
    /**
     * Gutenberg サイドバー用アセットを読み込み
     */
    public function enqueue_gutenberg_sidebar_assets() {
        // 投稿と固定ページの編集画面のみで読み込み
        $target_post_types = ['post', 'page'];
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, $target_post_types)) {
            return;
        }
        
        // Gutenberg エディタでない場合はスキップ
        if (!$this->is_gutenberg_editor()) {
            return;
        }
        
        // Gutenberg環境での念のためのメタボックス削除（保険処理）
        foreach ($target_post_types as $post_type) {
            remove_meta_box('ofwn_parent', $post_type, 'normal');
        }
        
        wp_enqueue_script(
            'ofwn-gutenberg-sidebar',
            OFWN_URL . 'assets/js/gutenberg-sidebar.js',
            [
                'wp-plugins',     // registerPlugin
                'wp-edit-post',   // PluginPostStatusInfo
                'wp-element',     // createElement, Fragment, useState
                'wp-components',  // TextControl, SelectControl, PanelRow, Button
                'wp-data',        // useSelect, useDispatch
                'wp-core-data',   // useEntityProp
                'wp-i18n'         // __
            ],
            filemtime(OFWN_DIR . 'assets/js/gutenberg-sidebar.js'),
            true
        );
        
        // Gutenbergサイドバー専用CSS
        wp_enqueue_style(
            'ofwn-gutenberg-sidebar-css',
            OFWN_URL . 'assets/css/gutenberg-sidebar.css',
            [],
            filemtime(OFWN_DIR . 'assets/css/gutenberg-sidebar.css')
        );
        
        // 依頼元・担当者のオプションを取得してJavaScriptに渡す
        $requesters = get_option('ofwn_requesters', []);
        $workers = get_option('ofwn_workers', $this->default_workers());
        
        // 現在の投稿IDを取得
        $current_post_id = 0;
        if (isset($_GET['post'])) {
            $current_post_id = (int)$_GET['post'];
        } elseif (isset($_POST['post_ID'])) {
            $current_post_id = (int)$_POST['post_ID'];
        }
        
        // 初期値用の最新作業メモを取得
        $prefill_data = null;
        if ($current_post_id) {
            $latest_note = $this->get_latest_work_note_for_prefill($current_post_id);
            if ($latest_note) {
                $prefill_data = [
                    'target_type' => $latest_note['target_type'] ?: 'post',
                    'target_id' => (string)$current_post_id, // 現在の投稿IDを設定
                    'requester' => $latest_note['requester'],
                    'worker' => $latest_note['worker'],
                    'needs_backfill' => $latest_note['needs_backfill'],
                    'note_id' => $latest_note['note_id'],
                    'current_post_id' => $current_post_id
                ];
            }
        }
        
        wp_localize_script('ofwn-gutenberg-sidebar', 'ofwnGutenbergData', [
            'requesters' => $requesters,
            'workers' => $workers,
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ofwn_sidebar_nonce')
        ]);
        
        // 初期値データを別の変数として渡す
        wp_localize_script('ofwn-gutenberg-sidebar', 'ofwnPrefill', $prefill_data ?: []);
    }
    
    /**
     * Gutenberg エディタかどうかを判定
     */
    private function is_gutenberg_editor() {
        // WordPress 5.0+ のブロックエディタチェック
        if (function_exists('use_block_editor_for_post')) {
            global $post;
            return $post && use_block_editor_for_post($post);
        }
        
        // 旧バージョン対応
        if (function_exists('is_gutenberg_page')) {
            return is_gutenberg_page();
        }
        
        return false;
    }
    
    /**
     * 指定投稿に紐づく最新の作業メモを取得（初期値流し込み用）
     * 正規リンク→フォールバック→lazy backfillの順で実行
     */
    private function get_latest_work_note_for_prefill($post_id) {
        $post_id = (int)$post_id;
        if (!$post_id) return null;
        
        // 1. 正規リンクで検索（_ofwn_bound_post_id）
        $query_args = [
            'post_type' => self::CPT,
            'posts_per_page' => 1,
            'post_status' => 'publish',
            'no_found_rows' => true,
            'update_post_meta_cache' => true,
            'meta_query' => [
                ['key' => '_ofwn_bound_post_id', 'value' => $post_id, 'type' => 'NUMERIC', 'compare' => '=']
            ],
            'orderby' => [
                'meta_value' => 'DESC',        // 実施日優先
                'post_modified_gmt' => 'DESC', // 次点で更新日時
                'post_date' => 'DESC'          // 最後に作成日時
            ],
            'meta_key' => '_ofwn_work_date'
        ];
        
        $query = new WP_Query($query_args);
        
        // 2. 正規リンクでヒットしない場合、フォールバック検索
        if (!$query->have_posts()) {
            $query_args['meta_query'] = [
                'relation' => 'AND',
                ['key' => '_ofwn_target_type', 'value' => ['post', 'page'], 'compare' => 'IN'],
                ['key' => '_ofwn_target_id', 'value' => (string)$post_id, 'compare' => '='],
            ];
            
            $query = new WP_Query($query_args);
        }
        
        if (!$query->have_posts()) {
            return null;
        }
        
        $latest_note = $query->posts[0];
        
        // フォールバック経由で取得した場合、lazy backfill用の情報を返す
        $needs_backfill = !get_post_meta($latest_note->ID, '_ofwn_bound_post_id', true);
        
        return [
            'note_id' => $latest_note->ID,
            'target_type' => get_post_meta($latest_note->ID, '_ofwn_target_type', true),
            'target_id' => get_post_meta($latest_note->ID, '_ofwn_target_id', true),
            'requester' => get_post_meta($latest_note->ID, '_ofwn_requester', true),
            'worker' => get_post_meta($latest_note->ID, '_ofwn_worker', true),
            'needs_backfill' => $needs_backfill,
            'current_post_id' => $post_id
        ];
    }

    /**
     * サイドバーデータ取得用AJAX
     */
    public function ajax_get_sidebar_data() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ofwn_sidebar_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        $data = [
            'requesters' => get_option('ofwn_requesters', []),
            'workers' => get_option('ofwn_workers', $this->default_workers())
        ];
        
        wp_send_json_success($data);
    }
    
    /* ===== 仮想配布ルート ===== */
    
    /**
     * リライトルールを追加
     */
    public function add_rewrite_rules() {
        add_rewrite_rule('^updates/([^/]+)/?', 'index.php?ofwn_updates=1&f=$matches[1]', 'top');
    }
    
    /**
     * クエリ変数を追加
     */
    public function add_query_vars($vars) {
        $vars[] = 'ofwn_updates';
        $vars[] = 'f';
        return $vars;
    }
    
    /**
     * 配布ファイルリクエストを処理
     */
    public function handle_updates_request() {
        if (!get_query_var('ofwn_updates')) {
            return;
        }
        
        $filename = sanitize_file_name(get_query_var('f'));
        if (empty($filename)) {
            status_header(404);
            exit;
        }
        
        // セキュリティ: パストラバーサル攻撃防止
        if (strpos($filename, '..') !== false || strpos($filename, '/') !== false) {
            status_header(404);
            exit;
        }
        
        // ホワイトリストチェック
        if (!$this->is_allowed_update_file($filename)) {
            status_header(404);
            exit;
        }
        
        // ファイル検索と配信
        $file_path = $this->find_update_file($filename);
        if (!$file_path) {
            status_header(404);
            exit;
        }
        
        $this->serve_update_file($file_path, $filename);
        exit;
    }
    
    /**
     * ファイル名がホワイトリストに含まれるかチェック
     */
    private function is_allowed_update_file($filename) {
        // JSON ファイル
        if ('stable.json' === $filename || 'beta.json' === $filename) {
            return true;
        }
        
        // ZIP ファイル (work-notes-*.zip 形式のみ)
        if (preg_match('/^work-notes-[a-zA-Z0-9\.-]+\.zip$/', $filename)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 更新ファイルを検索（優先順位付き）
     */
    private function find_update_file($filename) {
        $upload_dir = wp_upload_dir();
        $search_paths = [
            // 最優先: uploads/work-notes-updates/
            trailingslashit($upload_dir['basedir']) . 'work-notes-updates/' . $filename,
            // フォールバック: プラグイン内 updates/
            OFWN_DIR . 'updates/' . $filename
        ];
        
        foreach ($search_paths as $path) {
            if (file_exists($path) && is_readable($path)) {
                return $path;
            }
        }
        
        return false;
    }
    
    /**
     * ファイルを配信
     */
    private function serve_update_file($file_path, $filename) {
        // キャッシュヘッダー
        header('Cache-Control: public, max-age=300');
        
        // Content-Type 設定
        if (str_ends_with($filename, '.json')) {
            header('Content-Type: application/json; charset=UTF-8');
        } elseif (str_ends_with($filename, '.zip')) {
            header('Content-Type: application/zip');
        }
        
        // Content-Length 設定
        $file_size = filesize($file_path);
        if ($file_size) {
            header('Content-Length: ' . $file_size);
        }
        
        // ETag / Last-Modified (オプション)
        $last_modified = filemtime($file_path);
        if ($last_modified) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s T', $last_modified));
            header('ETag: "' . md5($file_path . $last_modified) . '"');
        }
        
        // ファイル出力
        readfile($file_path);
    }
    
    /**
     * 配布エンドポイント確認AJAX
     */
    public function ajax_check_distribution() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ofwn_distribution_check')) {
            wp_send_json_error(['message' => __('セキュリティチェックに失敗しました。', 'work-notes')]);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('権限がありません。', 'work-notes')]);
        }
        
        $test_url = home_url('/updates/stable.json');
        $response = wp_remote_get($test_url, [
            'timeout' => 10,
            'sslverify' => false
        ]);
        
        if (is_wp_error($response)) {
            wp_send_json_error([
                'message' => sprintf(
                    __('テストURL %s へのアクセスに失敗: %s', 'work-notes'),
                    esc_url($test_url),
                    $response->get_error_message()
                )
            ]);
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        
        if (200 === $status_code) {
            wp_send_json_success([
                'message' => sprintf(
                    __('配布エンドポイントは正常に動作しています。<br>URL: %s<br>Content-Type: %s', 'work-notes'),
                    esc_url($test_url),
                    esc_html($content_type)
                )
            ]);
        } elseif (404 === $status_code) {
            wp_send_json_error([
                'message' => sprintf(
                    __('テストファイルが見つかりません (404)。<br>%s または %s にファイルを配置してください。', 'work-notes'),
                    esc_html(wp_upload_dir()['basedir'] . '/work-notes-updates/stable.json'),
                    esc_html(OFWN_DIR . 'updates/stable.json')
                )
            ]);
        } else {
            wp_send_json_error([
                'message' => sprintf(
                    __('予期しないレスポンス (HTTP %d): %s', 'work-notes'),
                    $status_code,
                    esc_url($test_url)
                )
            ]);
        }
    }
    
    /**
     * タイミング調査用: 早期段階でのメタデータ確認
     */
    public function debug_save_timing_early($post_id, $post) {
        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) return;
        if (!in_array($post->post_type, ['post', 'page'])) return;
        
        $work_title = get_post_meta($post_id, '_ofwn_work_title', true);
        $work_content = get_post_meta($post_id, '_ofwn_work_content', true);
        $current_action = current_action();
        $is_rest = defined('REST_REQUEST') && REST_REQUEST ? 'true' : 'false';
        
        error_log('[OFWN TIMING EARLY] Hook: ' . $current_action . ', Post: ' . $post_id . ', REST: ' . $is_rest . ', Title: "' . $work_title . '", Content: "' . $work_content . '"');
    }
    
    /**
     * タイミング調査用: 遅期段階でのメタデータ確認
     */
    public function debug_save_timing_late($post_id, $post) {
        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) return;
        if (!in_array($post->post_type, ['post', 'page'])) return;
        
        $work_title = get_post_meta($post_id, '_ofwn_work_title', true);
        $work_content = get_post_meta($post_id, '_ofwn_work_content', true);
        $current_action = current_action();
        
        // 作成されたCPTの確認
        $created_notes = get_posts([
            'post_type' => self::CPT,
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => '_ofwn_bound_post_id',
                    'value' => $post_id,
                    'compare' => '='
                ]
            ]
        ]);
        
        $cpt_count = count($created_notes);
        $cpt_info = '';
        if ($cpt_count > 0) {
            $note = $created_notes[0];
            $cpt_title = get_post_meta($note->ID, '_ofwn_work_title', true);
            $cpt_content = get_post_meta($note->ID, '_ofwn_work_content', true);
            $cpt_info = ' CPT_ID: ' . $note->ID . ', CPT_Title: "' . $cpt_title . '", CPT_Content: "' . $cpt_content . '"';
        }
        
        error_log('[OFWN TIMING LATE] Hook: ' . $current_action . ', Post: ' . $post_id . ', Title: "' . $work_title . '", Content: "' . $work_content . '", CPT_Count: ' . $cpt_count . $cpt_info);
    }
    
    /**
     * wp_after_insert_post調査用: 投稿挿入完了後のメタデータ確認
     */
    public function debug_after_insert_post($post_id, $post, $update, $post_before) {
        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) return;
        if (!in_array($post->post_type, ['post', 'page'])) return;
        
        $work_title = get_post_meta($post_id, '_ofwn_work_title', true);
        $work_content = get_post_meta($post_id, '_ofwn_work_content', true);
        $requester = get_post_meta($post_id, '_ofwn_requester', true);
        $worker = get_post_meta($post_id, '_ofwn_worker', true);
        $status = get_post_meta($post_id, '_ofwn_status', true);
        $work_date = get_post_meta($post_id, '_ofwn_work_date', true);
        $target_label = get_post_meta($post_id, '_ofwn_target_label', true);
        
        $is_rest = defined('REST_REQUEST') && REST_REQUEST ? 'true' : 'false';
        $update_status = $update ? 'update' : 'new';
        
        error_log('[OFWN][wp_after_insert] Post: ' . $post_id . ', Type: ' . $post->post_type . ', Update: ' . $update_status . ', REST: ' . $is_rest);
        error_log('[OFWN][wp_after_insert] work_title="' . $work_title . '" work_content="' . $work_content . '" requester="' . $requester . '" worker="' . $worker . '" status="' . $status . '" work_date="' . $work_date . '" target_label="' . $target_label . '"');
    }
    
    /**
     * wp_after_insert_postでのバックアップCPT作成
     * save_postで作成できなかった場合の保険処理
     */
    public function fallback_create_work_note_from_meta($post_id, $post, $update, $post_before) {
        // ガード条件
        if (!in_array($post->post_type, ['post', 'page'])) return;
        if (wp_is_post_revision($post_id)) return;
        if (wp_is_post_autosave($post_id)) return;
        if (!current_user_can('edit_post', $post_id)) return;
        
        // 既にCPTが作成されているかチェック
        $existing_notes = get_posts([
            'post_type' => self::CPT,
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => '_ofwn_bound_post_id',
                    'value' => $post_id,
                    'compare' => '='
                ]
            ]
        ]);
        
        if (!empty($existing_notes)) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('[OFWN][fallback] CPT already exists for post ' . $post_id . ', skipping fallback creation');
            }
            return; // 既にCPTが存在する場合はスキップ
        }
        
        // メタデータを確認
        $work_title = get_post_meta($post_id, '_ofwn_work_title', true);
        $work_content = get_post_meta($post_id, '_ofwn_work_content', true);
        $requester = get_post_meta($post_id, '_ofwn_requester', true);
        $worker = get_post_meta($post_id, '_ofwn_worker', true);
        $status = get_post_meta($post_id, '_ofwn_status', true);
        $work_date = get_post_meta($post_id, '_ofwn_work_date', true);
        $target_label = get_post_meta($post_id, '_ofwn_target_label', true);
        
        // いずれかのフィールドが空でない場合のみ処理続行
        $has_content = !empty($work_title) || !empty($work_content) || !empty($requester) || 
                      !empty($worker) || !empty($status) || !empty($work_date) || !empty($target_label);
        
        if (!$has_content) return;
        
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[OFWN][fallback] Creating CPT via wp_after_insert_post for post ' . $post_id);
        }
        
        // auto_create_work_note_from_meta と同じロジックでCPT作成
        $this->create_work_note_from_meta_data($post_id, $post);
    }
    
    /**
     * メタデータからCPT作成の共通処理
     * auto_create_work_note_from_meta と fallback_create_work_note_from_meta で共用
     */
    private function create_work_note_from_meta_data($post_id, $post) {
        // 作業メモ関連のメタデータを取得
        $target_type = get_post_meta($post_id, '_ofwn_target_type', true);
        $target_id = get_post_meta($post_id, '_ofwn_target_id', true);
        $target_label = get_post_meta($post_id, '_ofwn_target_label', true);
        $requester = get_post_meta($post_id, '_ofwn_requester', true);
        $worker = get_post_meta($post_id, '_ofwn_worker', true);
        $status = get_post_meta($post_id, '_ofwn_status', true);
        $work_date = get_post_meta($post_id, '_ofwn_work_date', true);
        $work_title = get_post_meta($post_id, '_ofwn_work_title', true);
        $work_content = get_post_meta($post_id, '_ofwn_work_content', true);
        
        // 重複防止のためのハッシュ生成
        $meta_payload = [
            'target_type' => $target_type,
            'target_id' => $target_id,
            'target_label' => $target_label,
            'requester' => $requester,
            'worker' => $worker,
            'status' => $status,
            'work_date' => $work_date,
            'work_title' => $work_title,
            'work_content' => $work_content,
            'post_id' => $post_id
        ];
        
        $content_hash = md5(serialize($meta_payload));
        
        // 同一内容のCPTが既に存在するかチェック
        $duplicate_check = get_posts([
            'post_type' => self::CPT,
            'meta_query' => [
                [
                    'key' => '_ofwn_content_hash',
                    'value' => $content_hash,
                    'compare' => '='
                ]
            ]
        ]);
        
        if (!empty($duplicate_check)) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('[OFWN] Duplicate work note detected, skipping creation for post ' . $post_id);
            }
            return;
        }
        
        // CPT作成
        $work_note_id = wp_insert_post([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'post_title' => !empty($work_title) ? $work_title : 'Work Note for Post ' . $post_id,
            'post_content' => $work_content,
            'meta_input' => [
                '_ofwn_target_type' => $target_type ?: $post->post_type,
                '_ofwn_target_id' => $target_id ?: $post_id,
                // '_ofwn_target_label' => $target_label, // 廃止：作業タイトルに統合
                '_ofwn_requester' => $requester,
                '_ofwn_worker' => $worker,
                '_ofwn_status' => $status,
                '_ofwn_work_date' => $work_date,
                '_ofwn_work_title' => $work_title,
                '_ofwn_work_content' => $work_content,
                '_ofwn_bound_post_id' => $post_id,
                '_ofwn_content_hash' => $content_hash
            ]
        ]);
        
        if ($work_note_id && !is_wp_error($work_note_id)) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('[OFWN] Auto-created work note ID: ' . $work_note_id . ' for post ID: ' . $post_id . ' (elapsed: ' . (microtime(true) * 1000 - $_SERVER['REQUEST_TIME_FLOAT'] * 1000) . 'ms)');
            }
        } else {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                $error_msg = is_wp_error($work_note_id) ? $work_note_id->get_error_message() : 'Unknown error';
                error_log('[OFWN] Failed to create work note for post ' . $post_id . ': ' . $error_msg);
            }
        }
    }
    
}
