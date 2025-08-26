<?php
if (!defined('ABSPATH')) exit;

class OF_Work_Notes {
    const CPT = 'of_work_note';
    const NONCE = 'ofwn_nonce';
    const OPT_REQUESTERS = 'ofwn_requesters';
    const OPT_WORKERS    = 'ofwn_workers';

    public function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_action('init', [$this, 'register_meta_fields']);
        
        // 作業ログ機能を初期化
        $this->init_worklog_features();
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_note_meta']);
        add_action('save_post', [$this, 'capture_quick_note_from_parent'], 20, 2);
        add_filter('manage_edit-' . self::CPT . '_columns', [$this, 'cols']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [$this, 'col_content'], 10, 2);
        add_filter('manage_edit-' . self::CPT . '_sortable_columns', [$this, 'sortable_cols']);
        add_action('pre_get_posts', [$this, 'handle_sortable_columns']);
        add_action('admin_bar_menu', [$this, 'admin_bar_quick_add'], 80);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('current_screen', [$this, 'maybe_prefill_target_meta']);

        // 設定ページ
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);

        // 作業一覧ページ
        add_action('admin_menu', [$this, 'add_list_page']);
    }
    
    /**
     * 作業ログ促し機能を初期化
     */
    private function init_worklog_features() {
        // 設定クラスを初期化
        if (!class_exists('OFWN_Worklog_Settings')) {
            require_once OFWN_DIR . 'includes/class-worklog-settings.php';
        }
        new OFWN_Worklog_Settings();
        
        // メタデータクラスを初期化
        if (!class_exists('OFWN_Worklog_Meta')) {
            require_once OFWN_DIR . 'includes/class-worklog-meta.php';
        }
        new OFWN_Worklog_Meta();
        
        // エディタ統合（管理画面のみ）
        if (is_admin()) {
            add_action('admin_enqueue_scripts', [$this, 'enqueue_worklog_editor_assets']);
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
        
        if ($screen && $screen->post_type === self::CPT) {
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

    public function add_settings_page() {
        add_submenu_page(
            'edit.php?post_type=' . self::CPT,
            __('作業メモ設定', 'work-notes'),
            __('設定', 'work-notes'),
            'manage_options',
            'ofwn-settings',
            [$this, 'render_settings_page']
        );
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

        add_settings_section('ofwn_section_main', __('マスター管理', 'work-notes'), '__return_false', 'ofwn_settings');

        add_settings_field(self::OPT_REQUESTERS, __('依頼元マスター（1行1件）', 'work-notes'), function(){
            $v = get_option(self::OPT_REQUESTERS, []);
            echo '<textarea name="'.esc_attr(self::OPT_REQUESTERS).'[]" rows="8" style="width:600px;">'.esc_textarea(implode("\n", $v))."</textarea>";
            echo '<p class="description">' . esc_html__('ここに入力した内容が「依頼元」のセレクトに表示されます。', 'work-notes') . '</p>';
        }, 'ofwn_settings', 'ofwn_section_main');

        add_settings_field(self::OPT_WORKERS, __('担当者マスター（1行1件）', 'work-notes'), function(){
            $v = get_option(self::OPT_WORKERS, $this->default_workers());
            echo '<textarea name="'.esc_attr(self::OPT_WORKERS).'[]" rows="8" style="width:600px;">'.esc_textarea(implode("\n", $v))."</textarea>";
            echo '<p class="description">' . esc_html__('ここに入力した内容が「担当者」のセレクトに表示されます。', 'work-notes') . '</p>';
        }, 'ofwn_settings', 'ofwn_section_main');
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
        echo '<div class="wrap"><h1>' . esc_html__('作業メモ設定', 'work-notes') . '</h1><form method="post" action="options.php">';
        settings_fields('ofwn_settings');
        do_settings_sections('ofwn_settings');
        submit_button(__('保存', 'work-notes'));
        echo '</form></div>';
    }

    /* ===== メタボックス ===== */

    public function add_meta_boxes() {
        add_meta_box('ofwn_fields', __('作業メモ属性', 'work-notes'), [$this, 'box_note_fields'], self::CPT, 'side', 'default');
        foreach (get_post_types(['public' => true], 'names') as $pt) {
            add_meta_box('ofwn_parent', __('作業メモ', 'work-notes'), [$this, 'box_parent_notes'], $pt, 'normal', 'default');
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
        $target_label = $this->get_meta($post->ID, '_ofwn_target_label', '');
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

        <p><label><?php esc_html_e('対象ラベル（例：トップページ、パーマリンク設定 等）', 'work-notes'); ?><br>
            <input type="text" name="ofwn_target_label" value="<?php echo esc_attr($target_label);?>" style="width:100%;">
        </label></p>

        <p><label><?php esc_html_e('依頼元', 'work-notes'); ?></label><br>
            <?php $this->render_select_with_custom('ofwn_requester', $req_opts, $requester, __('依頼元を手入力', 'work-notes')); ?>
        </p>

        <p><label><?php esc_html_e('担当者', 'work-notes'); ?></label><br>
            <?php $this->render_select_with_custom('ofwn_worker', $wrk_opts, $worker, __('担当者を手入力', 'work-notes')); ?>
        </p>

        <p><label><?php esc_html_e('ステータス', 'work-notes'); ?><br>
            <select name="ofwn_status">
                <option value="依頼" <?php selected($status,'依頼');?>><?php esc_html_e('依頼', 'work-notes'); ?></option>
                <option value="対応中" <?php selected($status,'対応中');?>><?php esc_html_e('対応中', 'work-notes'); ?></option>
                <option value="完了" <?php selected($status,'完了');?>><?php esc_html_e('完了', 'work-notes'); ?></option>
            </select>
        </label></p>

        <p><label><?php esc_html_e('実施日', 'work-notes'); ?><br>
            <input type="date" name="ofwn_work_date" value="<?php echo esc_attr($date);?>">
        </label></p>
        <?php
    }

    public function save_note_meta($post_id) {
        // デバッグログ開始
        $debug_log = defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
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
        if (get_post_type($post_id) !== self::CPT) {
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
            '_ofwn_target_label' => 'ofwn_target_label',
            '_ofwn_requester'    => $requester,
            '_ofwn_worker'       => $worker,
            '_ofwn_status'       => 'ofwn_status',
            '_ofwn_work_date'    => 'ofwn_work_date',
        ];
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
            error_log('[OFWN] Post-save verification: requester=' . $saved_req . ', worker=' . $saved_worker);
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
        $new['ofwn_requester'] = '依頼元';
        $new['ofwn_assignee'] = '担当者';
        $new['ofwn_target'] = '対象';
        $new['ofwn_status'] = 'ステータス';
        $new['author'] = '作成者';
        $new['date'] = '日付';
        return $new;
    }

    public function col_content($col, $post_id) {
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
            if ($type === 'post' && $id) {
                $link = get_edit_post_link((int)$id);
                $title = get_the_title((int)$id);
                echo '<a href="'.esc_url($link).'">'.esc_html($title ?: ('ID:'.$id)).'</a>';
            } else {
                echo esc_html($label ?: '—');
            }
        }
        if ($col === 'ofwn_status') {
            $s = $this->get_meta($post_id, '_ofwn_status','依頼');
            $cls = $s==='完了' ? 'done' : '';
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
        // 依頼元フィールド（仕様書での ofwn_requester に相当）
        register_post_meta(self::CPT, '_ofwn_requester', [
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'auth_callback' => function($allowed, $meta_key, $post_id) {
                return current_user_can('edit_post', $post_id);
            },
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        
        // 担当者フィールド（仕様書での ofwn_assignee に相当）
        register_post_meta(self::CPT, '_ofwn_worker', [
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string', 
            'auth_callback' => function($allowed, $meta_key, $post_id) {
                return current_user_can('edit_post', $post_id);
            },
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        
        // その他のメタフィールドもブロックエディタ対応
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

        $notes = get_posts([
            'post_type' => self::CPT,
            'posts_per_page' => 20,
            'meta_query' => [
                ['key' => '_ofwn_target_type','value' => 'post'],
                ['key' => '_ofwn_target_id','value' => (string)$post->ID],
            ],
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

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
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce($_POST[self::NONCE], self::NONCE)) return;
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
            update_post_meta($note_id, '_ofwn_target_label', get_the_title($post_id));
            update_post_meta($note_id, '_ofwn_requester', sanitize_text_field($requester));
            update_post_meta($note_id, '_ofwn_worker', sanitize_text_field($workerVal));
            update_post_meta($note_id, '_ofwn_status', $status);
            update_post_meta($note_id, '_ofwn_work_date', $date);
        }
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
                    update_post_meta($post_id, '_ofwn_target_label', get_the_title($pid));
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
     * 作業ログエディタ用アセットを読み込み
     */
    public function enqueue_worklog_editor_assets($hook) {
        // 早期リターン：投稿編集画面のみ
        if (!in_array($hook, ['post.php', 'post-new.php'])) {
            return;
        }
        
        // スクリーンチェックを最初に実行
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, ['post', 'page'])) {
            return;
        }
        
        // 対象投稿タイプかどうかチェック（設定クラスが存在する場合のみ）
        if (class_exists('OFWN_Worklog_Settings')) {
            if (!OFWN_Worklog_Settings::is_target_post_type($screen->post_type)) {
                return;
            }
            
            // 対象ユーザーかどうかチェック
            if (!OFWN_Worklog_Settings::is_target_user()) {
                return;
            }
        } else {
            // 設定クラスが存在しない場合はデフォルトで post/page のみ
            if (!in_array($screen->post_type, ['post', 'page'])) {
                return;
            }
        }
        
        // Gutenberg エディタかどうかチェック
        if (!$this->is_gutenberg_page()) {
            return;
        }
        
        wp_enqueue_script(
            'ofwn-worklog-editor',
            OFWN_URL . 'assets/worklog-editor.js',
            ['wp-data', 'wp-editor', 'wp-core-data', 'wp-notices', 'wp-element', 'wp-i18n'],
            filemtime(OFWN_DIR . 'assets/worklog-editor.js'),
            true
        );
        
        wp_localize_script('ofwn-worklog-editor', 'ofwnWorklogEditor', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ofwn_worklog_nonce'),
            'post_id' => get_the_ID(),
            'autoHideDelay' => 10000, // 10秒で自動消失
            'strings' => [
                /* translators: Snackbar message asking to record work log */
                'prompt_message' => apply_filters('of_worklog_snackbar_message', __('今回の変更の作業ログを残しますか？', 'work-notes')),
                /* translators: Button label to write work log immediately */
                'write_now' => __('今すぐ書く', 'work-notes'),
                /* translators: Button label to skip work log for this time */
                'skip_this_time' => __('今回はスルー', 'work-notes'),
                /* translators: Prompt message for fallback work log input dialog */
                'fallback_prompt' => __('作業ログを入力してください（空の場合はスキップされます）:', 'work-notes')
            ]
        ]);
    }
    
    /**
     * Gutenberg エディタであるかどうか判定
     */
    private function is_gutenberg_page() {
        // WordPress 5.0+ の Gutenberg エディタチェック
        if (function_exists('is_gutenberg_page') && is_gutenberg_page()) {
            return true;
        }
        
        // ブロックエディタが有効かどうか
        if (function_exists('use_block_editor_for_post')) {
            global $post;
            return $post && use_block_editor_for_post($post);
        }
        
        return false;
    }
}
