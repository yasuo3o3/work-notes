<?php
if (!defined('ABSPATH')) exit;

/**
 * 作業ログ促し（Snackbar）機能の設定管理クラス
 * 
 * 複数ユーザー対象設定、投稿タイプ設定、権限設定を管理
 */
class OFWN_Worklog_Settings {
    
    // オプション名定数
    const OPT_TARGET_USERS = 'of_worklog_target_user_ids';
    const OPT_TARGET_POST_TYPES = 'of_worklog_target_post_types';
    const OPT_MIN_ROLE = 'of_worklog_min_role';
    
    // デフォルト設定
    const DEFAULT_POST_TYPES = ['post', 'page'];
    const DEFAULT_MIN_ROLE = 'administrator';
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_worklog_search_users', [$this, 'ajax_search_users']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_settings_assets']);
    }
    
    /**
     * 設定ページを追加
     */
    public function add_settings_page() {
        add_submenu_page(
            'edit.php?post_type=of_work_note',
            __('作業ログ設定', 'work-notes'),
            __('作業ログ設定', 'work-notes'),
            $this->get_minimum_capability(),
            'ofwn-worklog-settings',
            [$this, 'render_settings_page']
        );
    }
    
    /**
     * 設定項目を登録
     */
    public function register_settings() {
        // 対象ユーザー設定
        register_setting('ofwn_worklog_settings', self::OPT_TARGET_USERS, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_user_ids'],
            'default' => [],
            'show_in_rest' => false
        ]);
        
        // 対象投稿タイプ設定
        register_setting('ofwn_worklog_settings', self::OPT_TARGET_POST_TYPES, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_post_types'],
            'default' => self::DEFAULT_POST_TYPES,
            'show_in_rest' => false
        ]);
        
        // 最小権限設定
        register_setting('ofwn_worklog_settings', self::OPT_MIN_ROLE, [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_min_role'],
            'default' => self::DEFAULT_MIN_ROLE,
            'show_in_rest' => false
        ]);
        
        // 設定セクション
        add_settings_section(
            'ofwn_worklog_main',
            __('作業ログ促し設定', 'work-notes'),
            [$this, 'render_settings_section'],
            'ofwn_worklog_settings'
        );
        
        // 対象ユーザー フィールド
        add_settings_field(
            self::OPT_TARGET_USERS,
            __('対象ユーザー', 'work-notes'),
            [$this, 'render_target_users_field'],
            'ofwn_worklog_settings',
            'ofwn_worklog_main'
        );
        
        // 対象投稿タイプ フィールド
        add_settings_field(
            self::OPT_TARGET_POST_TYPES,
            __('対象投稿タイプ', 'work-notes'),
            [$this, 'render_post_types_field'],
            'ofwn_worklog_settings',
            'ofwn_worklog_main'
        );
        
        // 最小権限 フィールド
        add_settings_field(
            self::OPT_MIN_ROLE,
            __('設定変更権限', 'work-notes'),
            [$this, 'render_min_role_field'],
            'ofwn_worklog_settings',
            'ofwn_worklog_main'
        );
    }
    
    /**
     * 設定用アセットを読み込み
     */
    public function enqueue_settings_assets($hook) {
        if ($hook !== 'of_work_note_page_ofwn-worklog-settings') return;
        
        wp_enqueue_script(
            'ofwn-worklog-settings',
            OFWN_URL . 'assets/worklog-settings.js',
            ['jquery', 'wp-util'],
            filemtime(OFWN_DIR . 'assets/worklog-settings.js'),
            true
        );
        
        wp_localize_script('ofwn-worklog-settings', 'ofwnWorklogSettings', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ofwn_worklog_settings'),
            'strings' => [
                'search_placeholder' => __('ユーザー名またはメールアドレスで検索', 'work-notes'),
                'add_user' => __('追加', 'work-notes'),
                'remove_user' => __('削除', 'work-notes'),
                'user_deleted' => __('(削除済み)', 'work-notes'),
                'no_results' => __('該当するユーザーが見つかりません', 'work-notes'),
            ]
        ]);
        
        wp_enqueue_style(
            'ofwn-worklog-settings',
            OFWN_URL . 'assets/worklog-settings.css',
            ['wp-admin'],
            filemtime(OFWN_DIR . 'assets/worklog-settings.css')
        );
    }
    
    /**
     * 設定ページをレンダリング
     */
    public function render_settings_page() {
        if (!current_user_can($this->get_minimum_capability())) {
            wp_die(__('この設定を変更する権限がありません。', 'work-notes'));
        }
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('作業ログ設定', 'work-notes'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('ofwn_worklog_settings');
                do_settings_sections('ofwn_worklog_settings');
                submit_button(__('設定を保存', 'work-notes'));
                ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * 設定セクションの説明をレンダリング
     */
    public function render_settings_section() {
        echo '<p>' . esc_html__('投稿・固定ページ保存後に作業ログ記録を促すSnackbar表示に関する設定です。', 'work-notes') . '</p>';
    }
    
    /**
     * 対象ユーザー フィールドをレンダリング
     */
    public function render_target_users_field() {
        $user_ids = get_option(self::OPT_TARGET_USERS, []);
        $users = [];
        
        // ユーザー情報を取得（削除済みユーザーも考慮）
        if (!empty($user_ids)) {
            $existing_users = get_users(['include' => $user_ids, 'fields' => ['ID', 'display_name', 'user_login']]);
            $existing_ids = wp_list_pluck($existing_users, 'ID');
            
            foreach ($user_ids as $user_id) {
                $user_id = intval($user_id);
                $user_key = array_search($user_id, $existing_ids);
                
                if ($user_key !== false) {
                    $users[] = $existing_users[$user_key];
                } else {
                    // 削除済みユーザー
                    $users[] = (object) [
                        'ID' => $user_id,
                        'display_name' => __('削除済みユーザー', 'work-notes'),
                        'user_login' => 'deleted_user_' . $user_id,
                        'deleted' => true
                    ];
                }
            }
        }
        
        ?>
        <div id="ofwn-user-selector">
            <div class="ofwn-user-search">
                <input type="text" id="ofwn-user-search-input" placeholder="<?php esc_attr_e('ユーザー名またはメールアドレスで検索', 'work-notes'); ?>" />
                <button type="button" id="ofwn-add-user-btn" class="button" disabled><?php esc_html_e('追加', 'work-notes'); ?></button>
                <div id="ofwn-user-search-results" style="display: none;"></div>
            </div>
            
            <div class="ofwn-selected-users">
                <h4><?php esc_html_e('選択中のユーザー', 'work-notes'); ?></h4>
                <ul id="ofwn-selected-users-list">
                    <?php foreach ($users as $user): ?>
                        <li data-user-id="<?php echo esc_attr($user->ID); ?>">
                            <?php if (!empty($user->deleted)): ?>
                                <span class="ofwn-deleted-user">
                                    <?php echo esc_html($user->display_name); ?> (ID: <?php echo esc_html($user->ID); ?>)
                                </span>
                            <?php else: ?>
                                <span class="ofwn-user-info">
                                    <?php echo esc_html($user->display_name); ?> (<?php echo esc_html($user->user_login); ?>)
                                </span>
                            <?php endif; ?>
                            <button type="button" class="ofwn-remove-user button-link-delete"><?php esc_html_e('削除', 'work-notes'); ?></button>
                            <input type="hidden" name="<?php echo esc_attr(self::OPT_TARGET_USERS); ?>[]" value="<?php echo esc_attr($user->ID); ?>" />
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (empty($users)): ?>
                    <p class="ofwn-no-users"><?php esc_html_e('まだユーザーが選択されていません。上の検索欄からユーザーを追加してください。', 'work-notes'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <p class="description">
            <?php esc_html_e('ここで選択されたユーザーに対してのみ、保存後に作業ログ記録を促すSnackbarが表示されます。', 'work-notes'); ?>
        </p>
        <?php
    }
    
    /**
     * 対象投稿タイプ フィールドをレンダリング
     */
    public function render_post_types_field() {
        $selected_types = get_option(self::OPT_TARGET_POST_TYPES, self::DEFAULT_POST_TYPES);
        $available_types = ['post' => '投稿', 'page' => '固定ページ'];
        
        foreach ($available_types as $type => $label) {
            $checked = in_array($type, $selected_types) ? 'checked' : '';
            ?>
            <label>
                <input type="checkbox" name="<?php echo esc_attr(self::OPT_TARGET_POST_TYPES); ?>[]" 
                       value="<?php echo esc_attr($type); ?>" <?php echo $checked; ?> />
                <?php echo esc_html($label); ?>
            </label><br>
            <?php
        }
        
        ?>
        <p class="description">
            <?php esc_html_e('作業ログ促しを表示する投稿タイプを選択してください。', 'work-notes'); ?>
        </p>
        <?php
    }
    
    /**
     * 最小権限 フィールドをレンダリング
     */
    public function render_min_role_field() {
        $current_role = get_option(self::OPT_MIN_ROLE, self::DEFAULT_MIN_ROLE);
        $roles = [
            'administrator' => __('管理者のみ', 'work-notes'),
            'editor' => __('編集者以上', 'work-notes')
        ];
        
        ?>
        <select name="<?php echo esc_attr(self::OPT_MIN_ROLE); ?>">
            <?php foreach ($roles as $role => $label): ?>
                <option value="<?php echo esc_attr($role); ?>" <?php selected($current_role, $role); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e('この設定ページを変更できる最小権限を選択してください。', 'work-notes'); ?>
        </p>
        <?php
    }
    
    /**
     * AJAX: ユーザー検索
     */
    public function ajax_search_users() {
        check_ajax_referer('ofwn_worklog_settings', 'nonce');
        
        if (!current_user_can($this->get_minimum_capability())) {
            wp_die(__('権限がありません。', 'work-notes'));
        }
        
        $search = sanitize_text_field($_POST['search'] ?? '');
        if (empty($search)) {
            wp_send_json_error(['message' => __('検索語句が空です。', 'work-notes')]);
        }
        
        $users = get_users([
            'search' => '*' . $search . '*',
            'search_columns' => ['user_login', 'user_email', 'display_name'],
            'number' => 10,
            'fields' => ['ID', 'display_name', 'user_login', 'user_email']
        ]);
        
        if (empty($users)) {
            wp_send_json_error(['message' => __('該当するユーザーが見つかりません。', 'work-notes')]);
        }
        
        $results = [];
        foreach ($users as $user) {
            $results[] = [
                'id' => $user->ID,
                'display_name' => $user->display_name,
                'user_login' => $user->user_login,
                'user_email' => $user->user_email
            ];
        }
        
        wp_send_json_success(['users' => $results]);
    }
    
    /**
     * ユーザーID配列をサニタイズ
     */
    public function sanitize_user_ids($input) {
        if (!is_array($input)) return [];
        
        $user_ids = [];
        foreach ($input as $id) {
            $id = intval($id);
            if ($id > 0) {
                $user_ids[] = $id;
            }
        }
        
        return array_unique($user_ids);
    }
    
    /**
     * 投稿タイプ配列をサニタイズ
     */
    public function sanitize_post_types($input) {
        if (!is_array($input)) return self::DEFAULT_POST_TYPES;
        
        $allowed_types = ['post', 'page'];
        $post_types = [];
        
        foreach ($input as $type) {
            if (in_array($type, $allowed_types)) {
                $post_types[] = $type;
            }
        }
        
        return empty($post_types) ? self::DEFAULT_POST_TYPES : $post_types;
    }
    
    /**
     * 最小権限をサニタイズ
     */
    public function sanitize_min_role($input) {
        $allowed_roles = ['administrator', 'editor'];
        return in_array($input, $allowed_roles) ? $input : self::DEFAULT_MIN_ROLE;
    }
    
    /**
     * 現在の最小権限を取得
     */
    public function get_minimum_capability() {
        $min_role = get_option(self::OPT_MIN_ROLE, self::DEFAULT_MIN_ROLE);
        $capability = $min_role === 'editor' ? 'edit_posts' : 'manage_options';
        
        // フィルターで上書き可能
        return apply_filters('of_worklog_min_cap', $capability);
    }
    
    /**
     * 対象ユーザーIDを取得
     */
    public static function get_target_user_ids() {
        return get_option(self::OPT_TARGET_USERS, []);
    }
    
    /**
     * 対象投稿タイプを取得
     */
    public static function get_target_post_types() {
        return get_option(self::OPT_TARGET_POST_TYPES, self::DEFAULT_POST_TYPES);
    }
    
    /**
     * 指定ユーザーが対象ユーザーかどうか判定
     */
    public static function is_target_user($user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }
        
        $target_ids = self::get_target_user_ids();
        return in_array(intval($user_id), $target_ids);
    }
    
    /**
     * 指定投稿タイプが対象かどうか判定
     */
    public static function is_target_post_type($post_type) {
        $target_types = self::get_target_post_types();
        return in_array($post_type, $target_types);
    }
}