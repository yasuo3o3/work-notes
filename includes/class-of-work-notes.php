<?php
if (!defined('ABSPATH')) exit;

class OF_Work_Notes {
    const CPT = 'of_work_note';
    const NONCE = 'ofwn_nonce';
    const OPT_REQUESTERS = 'ofwn_requesters';
    const OPT_WORKERS    = 'ofwn_workers';

    public function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_note_meta']);
        add_action('save_post', [$this, 'capture_quick_note_from_parent'], 20, 2);
        add_filter('manage_edit-' . self::CPT . '_columns', [$this, 'cols']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [$this, 'col_content'], 10, 2);
        add_action('admin_bar_menu', [$this, 'admin_bar_quick_add'], 80);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('current_screen', [$this, 'maybe_prefill_target_meta']);

        // 設定ページ
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);

        // 作業一覧ページ
        add_action('admin_menu', [$this, 'add_list_page']);
    }

    /* ===== 基本 ===== */

    public function register_cpt() {
        register_post_type(self::CPT, [
            'labels' => [
                'name' => '作業メモ',
                'singular_name' => '作業メモ',
                'add_new' => '新規メモ',
                'add_new_item' => '作業メモを追加',
                'edit_item' => '作業メモを編集',
                'new_item' => '新規作業メモ',
                'view_item' => '作業メモを表示',
                'search_items' => '作業メモを検索',
                'menu_name' => '作業メモ',
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
        // 管理画面のみ
        wp_enqueue_style('ofwn-admin', OFWN_URL.'assets/admin.css', [], filemtime(OFWN_DIR.'assets/admin.css'));
        wp_enqueue_script('ofwn-admin', OFWN_URL.'assets/admin.js', [], filemtime(OFWN_DIR.'assets/admin.js'), true);
    }

    /* ===== 設定（マスター管理） ===== */

    public function add_settings_page() {
        add_submenu_page(
            'edit.php?post_type=' . self::CPT,
            '作業メモ設定',
            '設定',
            'manage_options',
            'ofwn-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('ofwn_settings', self::OPT_REQUESTERS, [
            'type' => 'array','sanitize_callback' => [$this, 'sanitize_list'],'default' => []
        ]);
        register_setting('ofwn_settings', self::OPT_WORKERS, [
            'type' => 'array','sanitize_callback' => [$this, 'sanitize_list'],'default' => $this->default_workers()
        ]);

        add_settings_section('ofwn_section_main', 'マスター管理', '__return_false', 'ofwn_settings');

        add_settings_field(self::OPT_REQUESTERS, '依頼元マスター（1行1件）', function(){
            $v = get_option(self::OPT_REQUESTERS, []);
            echo '<textarea name="'.esc_attr(self::OPT_REQUESTERS).'[]" rows="8" style="width:600px;">'.esc_textarea(implode("\n", $v))."</textarea>";
            echo '<p class="description">ここに入力した内容が「依頼元」のセレクトに表示されます。</p>';
        }, 'ofwn_settings', 'ofwn_section_main');

        add_settings_field(self::OPT_WORKERS, '担当者マスター（1行1件）', function(){
            $v = get_option(self::OPT_WORKERS, $this->default_workers());
            echo '<textarea name="'.esc_attr(self::OPT_WORKERS).'[]" rows="8" style="width:600px;">'.esc_textarea(implode("\n", $v))."</textarea>";
            echo '<p class="description">ここに入力した内容が「担当者」のセレクトに表示されます。</p>';
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
        echo '<div class="wrap"><h1>作業メモ設定</h1><form method="post" action="options.php">';
        settings_fields('ofwn_settings');
        do_settings_sections('ofwn_settings');
        submit_button('保存');
        echo '</form></div>';
    }

    /* ===== メタボックス ===== */

    public function add_meta_boxes() {
        add_meta_box('ofwn_fields', '作業メモ属性', [$this, 'box_note_fields'], self::CPT, 'side', 'default');
        foreach (get_post_types(['public' => true], 'names') as $pt) {
            add_meta_box('ofwn_parent', '作業メモ', [$this, 'box_parent_notes'], $pt, 'normal', 'default');
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
        echo '<option value="__custom__"'.selected($is_custom, true, false).'>'.$placeholder.'</option>';
        echo '</select>';
        echo ' <input type="text" data-ofwn-custom="'.esc_attr($name).'_select" name="'.esc_attr($name).'" value="'.esc_attr($current_value).'" placeholder="'.$placeholder.'" '.($is_custom?'':'style="display:none"').'>';
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
        <p><label>対象タイプ<br>
            <select name="ofwn_target_type">
                <option value="">（任意）</option>
                <option value="post" <?php selected($target_type,'post');?>>投稿/固定ページ</option>
                <option value="site" <?php selected($target_type,'site');?>>サイト全体/設定/テーマ</option>
                <option value="other" <?php selected($target_type,'other');?>>その他</option>
            </select>
        </label></p>

        <p><label>対象ID（投稿IDなど）<br>
            <input type="text" name="ofwn_target_id" value="<?php echo esc_attr($target_id);?>" style="width:100%;">
        </label></p>

        <p><label>対象ラベル（例：トップページ、パーマリンク設定 等）<br>
            <input type="text" name="ofwn_target_label" value="<?php echo esc_attr($target_label);?>" style="width:100%;">
        </label></p>

        <p><label>依頼元</label><br>
            <?php $this->render_select_with_custom('ofwn_requester', $req_opts, $requester, '依頼元を手入力'); ?>
        </p>

        <p><label>担当者</label><br>
            <?php $this->render_select_with_custom('ofwn_worker', $wrk_opts, $worker, '担当者を手入力'); ?>
        </p>

        <p><label>ステータス<br>
            <select name="ofwn_status">
                <option <?php selected($status,'依頼');?>>依頼</option>
                <option <?php selected($status,'対応中');?>>対応中</option>
                <option <?php selected($status,'完了');?>>完了</option>
            </select>
        </label></p>

        <p><label>実施日<br>
            <input type="date" name="ofwn_work_date" value="<?php echo esc_attr($date);?>">
        </label></p>
        <?php
    }

    public function save_note_meta($post_id) {
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce($_POST[self::NONCE], self::NONCE)) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (get_post_type($post_id) !== self::CPT) return;

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
        foreach ($map as $meta => $fieldOrValue) {
            if (is_string($fieldOrValue) && isset($_POST[$fieldOrValue])) {
                update_post_meta($post_id, $meta, sanitize_text_field($_POST[$fieldOrValue]));
            } elseif (!is_string($fieldOrValue) && $fieldOrValue !== null) {
                update_post_meta($post_id, $meta, sanitize_text_field($fieldOrValue));
            }
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
        $new['title'] = 'タイトル';
        $new['ofwn_target'] = '対象';
        $new['ofwn_status'] = 'ステータス';
        $new['author'] = '作成者';
        $new['date'] = '日付';
        return $new;
    }

    public function col_content($col, $post_id) {
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
            echo '<span class="ofwn-badge '.$cls.'">'.esc_html($s).'</span>';
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
                echo '<span class="ofwn-badge '.($status==='完了'?'done':'').'">'.esc_html($status ?: '—').'</span><br>';
                echo wpautop(esc_html($n->post_content));
                echo '<small>依頼元: '.esc_html($req ?: '—').' / 担当: '.esc_html($worker ?: '—').' / 実施日: '.esc_html($date ?: '—').'</small>';
                echo ' / <a href="'.esc_url(get_edit_post_link($n->ID)).'">編集</a>';
                echo '</div>';
            }
        } else {
            echo '<p>このコンテンツに紐づく作業メモはまだありません。</p>';
        }
        echo '</div>';

        $req_opts = get_option(self::OPT_REQUESTERS, []);
        $wrk_opts = get_option(self::OPT_WORKERS, $this->default_workers());
        ?>
        <hr>
        <h4>この投稿に作業メモを追加</h4>

        <p><label>依頼元</label><br>
            <?php $this->render_select_with_custom('ofwn_quick_requester', $req_opts, ''); ?>
        </p>

        <p><label>内容（作業メモ本文）<br><textarea name="ofwn_quick_content" rows="4" style="width:100%;"></textarea></label></p>

        <p class="ofwn-inline">
            <label>ステータス
                <select name="ofwn_quick_status">
                    <option>依頼</option>
                    <option>対応中</option>
                    <option>完了</option>
                </select>
            </label>

            <label>実施日
                <input type="date" name="ofwn_quick_date" value="<?php echo esc_attr(current_time('Y-m-d'));?>">
            </label>

            <label>担当者</label>
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
            '作業一覧',
            '作業一覧',
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

        echo '<div class="wrap"><h1 class="wp-heading-inline">作業一覧</h1>';
        echo '<form method="get">';
        echo '<input type="hidden" name="post_type" value="'.esc_attr(self::CPT).'">';
        echo '<input type="hidden" name="page" value="ofwn-list">';
        $table->search_box('キーワード検索', 'ofwn-search');
        $table->views();
        $table->display();
        echo '</form></div>';
    }
}
