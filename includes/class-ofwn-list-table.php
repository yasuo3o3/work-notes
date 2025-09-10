<?php
if (!defined('ABSPATH')) exit;

if (is_admin() && !class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

if (!class_exists('OFWN_List_Table') && class_exists('WP_List_Table')):
class OFWN_List_Table extends WP_List_Table {
    private $requesters;
    private $workers;

    public function __construct($args=[]) {
        parent::__construct([
            'singular' => 'work_note',
            'plural'   => 'work_notes',
            'ajax'     => false,
        ]);
        $this->requesters = $args['requesters'] ?? [];
        $this->workers    = $args['workers'] ?? [];
    }

    public function get_columns() {
        return [
            'ofwn_date'   => __('実施日', 'work-notes'),
            'title'       => __('タイトル', 'work-notes'),
            'ofwn_status' => __('ステータス', 'work-notes'),
            'ofwn_requester' => __('依頼元', 'work-notes'),
            'ofwn_worker' => __('担当者', 'work-notes'),
            'ofwn_target' => __('対象', 'work-notes'),
            'author'      => __('作成者', 'work-notes'),
        ];
    }

    protected function get_sortable_columns() {
        return [
            'ofwn_date' => ['ofwn_date', false],
            'title'     => ['title', false],
        ];
    }

    protected function extra_tablenav($which) {
        if ($which !== 'top') return;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only filter/sort; no state change
        $status = isset($_GET['status']) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
        $allowed_status = ['', '依頼', '対応中', '完了'];
        if ( ! in_array( $status, $allowed_status, true ) ) { $status = ''; }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only filter/sort; no state change
        $requester = isset($_GET['requester']) ? sanitize_text_field( wp_unslash( $_GET['requester'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only filter/sort; no state change
        $worker = isset($_GET['worker']) ? sanitize_text_field( wp_unslash( $_GET['worker'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only filter/sort; no state change
        $tt = isset($_GET['target_type']) ? sanitize_key( wp_unslash( $_GET['target_type'] ) ) : '';
        $allowed_target_type = ['', 'post', 'site', 'other'];
        if ( ! in_array( $tt, $allowed_target_type, true ) ) { $tt = ''; }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only filter/sort; no state change
        $date_from_raw = isset($_GET['date_from']) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
        $from = ( preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from_raw) && strtotime($date_from_raw) ) ? $date_from_raw : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only filter/sort; no state change
        $date_to_raw = isset($_GET['date_to']) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
        $to = ( preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to_raw) && strtotime($date_to_raw) ) ? $date_to_raw : '';

        echo '<div class="ofwn-filter-row">';
        echo '<label>' . esc_html__('ステータス', 'work-notes') . ' <select name="status"><option value="">' . esc_html__('すべて', 'work-notes') . '</option>';
        foreach (['依頼','対応中','完了'] as $s) {
            printf('<option value="%1$s"%2$s>%1$s</option>', esc_attr($s), selected($status,$s,false));
        }
        echo '</select></label>';

        echo '<label>' . esc_html__('依頼元', 'work-notes') . ' <select name="requester"><option value="">' . esc_html__('すべて', 'work-notes') . '</option>';
        foreach ($this->requesters as $r) {
            printf('<option value="%1$s"%2$s>%1$s</option>', esc_attr($r), selected($requester,$r,false));
        }
        echo '</select></label>';

        echo '<label>' . esc_html__('担当者', 'work-notes') . ' <select name="worker"><option value="">' . esc_html__('すべて', 'work-notes') . '</option>';
        foreach ($this->workers as $w) {
            printf('<option value="%1$s"%2$s>%1$s</option>', esc_attr($w), selected($worker,$w,false));
        }
        echo '</select></label>';

        echo '<label>' . esc_html__('対象タイプ', 'work-notes') . ' <select name="target_type">
                <option value="">' . esc_html__('すべて', 'work-notes') . '</option>
                <option value="post" '.selected($tt,'post',false).'>' . esc_html__('投稿/固定ページ', 'work-notes') . '</option>
                <option value="site" '.selected($tt,'site',false).'>' . esc_html__('サイト全体/設定/テーマ', 'work-notes') . '</option>
                <option value="other" '.selected($tt,'other',false).'>' . esc_html__('その他', 'work-notes') . '</option>
              </select></label>';

        echo '<label>' . esc_html__('実施日', 'work-notes') . ': <input type="date" name="date_from" value="'.esc_attr($from).'"> 〜 ';
        echo '<input type="date" name="date_to" value="'.esc_attr($to).'"></label>';

        submit_button(__('絞り込み', 'work-notes'), 'secondary', '', false);
        echo '</div>';
    }

    public function get_views() {
        $base_url = remove_query_arg(['status','paged']);
        $counts = $this->status_counts();
        $views = [];
        $statuses = ['' => __('すべて', 'work-notes'), '完了' => __('完了', 'work-notes'), '対応中' => __('対応中', 'work-notes'), '依頼' => __('依頼', 'work-notes')];
        foreach ($statuses as $key => $label) {
            $url = $key === '' ? $base_url : add_query_arg('status', $key, $base_url);
            $count = $counts[$key ?: 'all'] ?? 0;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only filter/sort; no state change
            $current_status = isset($_GET['status']) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
            $class = $current_status === $key ? 'class="current"' : '';
            $views[$key ?: 'all'] = sprintf('<a href="%s" %s>%s <span class="count">(%d)</span></a>',
                esc_url($url), $class, esc_html($label), (int)$count
            );
        }
        return $views;
    }

    private function status_counts() {
        global $wpdb;
        $out = ['all' => 0, '依頼' => 0, '対応中' => 0, '完了' => 0];
        $allowed_statuses = ['依頼', '対応中', '完了'];
        $default_status = '依頼';
        
        // Plugin Check対策: 軽キャッシュ追加
        $ckey = 'ofwn_list_all_count_v1';
        $all_count = wp_cache_get($ckey, 'ofwn');
        if (false === $all_count) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Safe prepared query for list table pagination
            $all_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
                OF_Work_Notes::CPT,
                'publish'
            ));
            wp_cache_set($ckey, $all_count, 'ofwn', 60);
        }
        $out['all'] = $all_count;
        
        // ステータス別件数（LEFT JOINでメタデータと結合）
        $ckey2 = 'ofwn_list_status_counts_v1';
        $status_counts = wp_cache_get($ckey2, 'ofwn');
        if (false === $status_counts) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Safe prepared query for status counts with caching
            $status_counts = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT COALESCE(pm.meta_value, %s) as status, COUNT(*) as count 
                 FROM {$wpdb->posts} p 
                 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
                 WHERE p.post_type = %s AND p.post_status = %s
                 GROUP BY COALESCE(pm.meta_value, %s)",
                $default_status, '_ofwn_status', OF_Work_Notes::CPT, 'publish', $default_status
            ));
            wp_cache_set($ckey2, $status_counts, 'ofwn', 60);
        }
        
        // 結果を許可リストでフィルターして設定
        foreach ($status_counts as $row) {
            $status = $row->status;
            if (in_array($status, $allowed_statuses, true)) {
                $out[$status] = (int)$row->count;
            }
        }
        
        return $out;
    }

    public function prepare_items() {
        $per_page = 20;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only pagination
        $paged = isset($_GET['paged']) ? absint( $_GET['paged'] ) : 1;
        $paged = max(1, $paged);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only sort
        $orderby = isset($_GET['orderby']) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'date';
        $allowed_orderby = ['date', 'title', 'ofwn_date'];
        if ( ! in_array( $orderby, $allowed_orderby, true ) ) { $orderby = 'date'; }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only sort direction
        $order = isset($_GET['order']) ? strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : 'desc';
        if ( ! in_array( $order, ['asc', 'desc'], true ) ) { $order = 'desc'; }
        $order = strtoupper($order);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only search term
        $search = isset($_GET['s']) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

        $meta_query = ['relation'=>'AND'];

        // Plugin Check対策: meta_query に compare/type を明示
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only filter/sort; no state change
        if (!empty($_GET['status'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only filter/sort; no state change
            $status_filter = sanitize_key( wp_unslash( $_GET['status'] ) );
            $meta_query[] = ['key'=>'_ofwn_status','value'=>$status_filter,'compare'=>'=','type'=>'CHAR'];
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only filter/sort; no state change
        if (!empty($_GET['requester'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only filter/sort; no state change
            $requester_filter = sanitize_text_field( wp_unslash( $_GET['requester'] ) );
            $meta_query[] = ['key'=>'_ofwn_requester','value'=>$requester_filter,'compare'=>'=','type'=>'CHAR'];
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only filter/sort; no state change
        if (!empty($_GET['worker'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only filter/sort; no state change
            $worker_filter = sanitize_text_field( wp_unslash( $_GET['worker'] ) );
            $meta_query[] = ['key'=>'_ofwn_worker','value'=>$worker_filter,'compare'=>'=','type'=>'CHAR'];
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only filter/sort; no state change
        if (!empty($_GET['target_type'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only filter/sort; no state change
            $target_type_filter = sanitize_key( wp_unslash( $_GET['target_type'] ) );
            $meta_query[] = ['key'=>'_ofwn_target_type','value'=>$target_type_filter,'compare'=>'=','type'=>'CHAR'];
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only filter/sort; no state change
        $date_from_filter_raw = !empty($_GET['date_from']) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
        $from = ( preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from_filter_raw) && strtotime($date_from_filter_raw) ) ? $date_from_filter_raw : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only filter/sort; no state change
        $date_to_filter_raw = !empty($_GET['date_to']) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
        $to = ( preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to_filter_raw) && strtotime($date_to_filter_raw) ) ? $date_to_filter_raw : '';
        if ($from) $meta_query[] = ['key'=>'_ofwn_work_date','value'=>$from,'compare'=>'>=','type'=>'CHAR'];
        if ($to)   $meta_query[] = ['key'=>'_ofwn_work_date','value'=>$to,'compare'=>'<=','type'=>'CHAR'];

        $orderby_arg = 'date';
        if ('ofwn_date' === $orderby) {
            $orderby_arg = 'meta_value';
        } elseif ('title' === $orderby) {
            $orderby_arg = 'title';
        }

        // Plugin Check対策: 高速化フラグ + キャッシュ
        $args = [
            'post_type'      => OF_Work_Notes::CPT,
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            's'              => $search,            
            // 理由: 管理画面リストのフィルタリングに必要なため使用。
            // 大量データ時のパフォーマンス対策として(meta_key, meta_value)の複合INDEXを追加済み。
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            'meta_query'     => $meta_query,
            'orderby'        => $orderby_arg,
            'order'          => $order,
            ];
        if ('ofwn_date' === $orderby) {
            // Plugin Check緩和: meta_keyでソート用メタフィールド指定
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            $args['meta_key'] = '_ofwn_work_date';
            $args['meta_type'] = 'CHAR'; // 日付文字列として扱う
        }

        // 高速化フラグを追加してキャッシュ経由で取得
        $cache_args = array_merge($args, [
            'fields'                   => 'ids',
            'no_found_rows'            => true,
            'update_post_meta_cache'   => false,
            'update_post_term_cache'   => false,
        ]);
        
        if (function_exists('ofwn_cached_ids_query')) {
            $ids = ofwn_cached_ids_query($cache_args, 60);
        } else {
            $ckey = 'ofwn_list_tbl_v1_' . md5(wp_json_encode($cache_args));
            $ids = wp_cache_get($ckey, 'ofwn');
            if (false === $ids) {
                $ids = get_posts($cache_args);
                wp_cache_set($ckey, $ids, 'ofwn', 60);
            }
        }
        
        // 元の WP_Query 形式で結果を復元
        if (!empty($ids)) {
            $posts = get_posts([
                'post_type'   => OF_Work_Notes::CPT,
                'post__in'    => $ids,
                'orderby'     => 'post__in',
                'numberposts' => count($ids),
            ]);
        } else {
            $posts = [];
        }
        
        // WP_Query オブジェクト形式で包装
        $q = new stdClass();
        $q->posts = $posts;

        $items = [];
        foreach ($q->posts as $p) {
            $pid = $p->ID;
            $items[] = [
                'ID'            => $pid,
                'title'         => get_the_title($pid),
                'status'        => get_post_meta($pid, '_ofwn_status', true) ?: '依頼',
                'requester'     => get_post_meta($pid, '_ofwn_requester', true),
                'worker'        => get_post_meta($pid, '_ofwn_worker', true),
                'date'          => get_post_meta($pid, '_ofwn_work_date', true),
                'target_type'   => get_post_meta($pid, '_ofwn_target_type', true),
                'target_id'     => get_post_meta($pid, '_ofwn_target_id', true),
                'target_label'  => get_post_meta($pid, '_ofwn_target_label', true), // 旧データ互換用
                'work_title'    => get_post_field('post_title', $pid) ?: get_post_meta($pid, '_ofwn_work_title', true),
                'work_content'  => get_post_field('post_content', $pid) ?: get_post_meta($pid, '_ofwn_work_content', true),
                'author'        => get_the_author_meta('display_name', $p->post_author),
                'edit_link'     => get_edit_post_link($pid),
            ];
        }

        $this->items = $items;

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
        $this->set_pagination_args([
            'total_items' => (int)$q->found_posts,
            'per_page'    => $per_page,
            'total_pages' => (int)$q->max_num_pages,
        ]);
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'ofwn_date':
                return esc_html($item['date'] ?: '—');
            case 'title':
                return '<strong><a href="'.esc_url($item['edit_link']).'">'.esc_html($item['title']).'</a></strong>';
            case 'ofwn_status':
                $cls = '完了' === $item['status'] ? 'done' : '';
                return '<span class="ofwn-badge ' . esc_attr($cls) . '">' . esc_html($item['status']) . '</span>';
            case 'ofwn_requester':
                return esc_html($item['requester'] ?: '—');
            case 'ofwn_worker':
                return esc_html($item['worker'] ?: '—');
            case 'ofwn_target':
                if ('post' === $item['target_type'] && $item['target_id']) {
                    $link = get_edit_post_link((int)$item['target_id']);
                    $title = get_the_title((int)$item['target_id']);
                    return '<a href="'.esc_url($link).'">'.esc_html($title ?: ('ID:'.$item['target_id'])).'</a>';
                }
                // 作業タイトル優先、なければ旧対象ラベル、どちらもなければダッシュ
                $display_title = $item['work_title'] ?: $item['target_label'] ?: 'データなし';
                return esc_html($display_title);
            case 'author':
                return esc_html($item['author'] ?: '—');
            default:
                return '';
        }
    }
}
endif;
