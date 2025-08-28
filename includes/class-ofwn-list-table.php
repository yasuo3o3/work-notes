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

        $status   = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $requester= isset($_GET['requester']) ? sanitize_text_field($_GET['requester']) : '';
        $worker   = isset($_GET['worker']) ? sanitize_text_field($_GET['worker']) : '';
        $tt       = isset($_GET['target_type']) ? sanitize_text_field($_GET['target_type']) : '';
        $from     = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $to       = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';

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
            $class = (isset($_GET['status']) ? $_GET['status'] : '') === $key ? 'class="current"' : '';
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
        
        // 全件数
        $all_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
            OF_Work_Notes::CPT,
            'publish'
        ));
        $out['all'] = (int)$all_count;
        
        // ステータス別件数（LEFT JOINでメタデータと結合）
        $status_counts = $wpdb->get_results($wpdb->prepare(
            "SELECT COALESCE(pm.meta_value, %s) as status, COUNT(*) as count 
             FROM {$wpdb->posts} p 
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_type = %s AND p.post_status = %s
             GROUP BY COALESCE(pm.meta_value, %s)",
            $default_status, '_ofwn_status', OF_Work_Notes::CPT, 'publish', $default_status
        ));
        
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
        $paged = max(1, (int)($_GET['paged'] ?? 1));
        $orderby = $_GET['orderby'] ?? 'date';
        $order   = (strtolower($_GET['order'] ?? 'DESC') === 'asc') ? 'ASC' : 'DESC';
        $search  = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

        $meta_query = ['relation'=>'AND'];

        if (!empty($_GET['status'])) {
            $meta_query[] = ['key'=>'_ofwn_status','value'=>sanitize_text_field($_GET['status'])];
        }
        if (!empty($_GET['requester'])) {
            $meta_query[] = ['key'=>'_ofwn_requester','value'=>sanitize_text_field($_GET['requester'])];
        }
        if (!empty($_GET['worker'])) {
            $meta_query[] = ['key'=>'_ofwn_worker','value'=>sanitize_text_field($_GET['worker'])];
        }
        if (!empty($_GET['target_type'])) {
            $meta_query[] = ['key'=>'_ofwn_target_type','value'=>sanitize_text_field($_GET['target_type'])];
        }
        $from = !empty($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $to   = !empty($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        if ($from) $meta_query[] = ['key'=>'_ofwn_work_date','value'=>$from,'compare'=>'>='];
        if ($to)   $meta_query[] = ['key'=>'_ofwn_work_date','value'=>$to,  'compare'=>'<='];

        $orderby_arg = 'date';
        if ('ofwn_date' === $orderby) {
            $orderby_arg = 'meta_value';
        } elseif ('title' === $orderby) {
            $orderby_arg = 'title';
        }

        $args = [
            'post_type'      => OF_Work_Notes::CPT,
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            's'              => $search,
            'meta_query'     => $meta_query,
            'orderby'        => $orderby_arg,
            'order'          => $order,
        ];
        if ('ofwn_date' === $orderby) {
            $args['meta_key'] = '_ofwn_work_date';
        }

        $q = new WP_Query($args);

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
                'target_label'  => get_post_meta($pid, '_ofwn_target_label', true),
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
                return esc_html($item['target_label'] ?: '—');
            case 'author':
                return esc_html($item['author'] ?: '—');
            default:
                return '';
        }
    }
}
endif;
