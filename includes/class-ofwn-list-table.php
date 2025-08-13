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
            'ofwn_date'   => '実施日',
            'title'       => 'タイトル',
            'ofwn_status' => 'ステータス',
            'ofwn_requester' => '依頼元',
            'ofwn_worker' => '担当者',
            'ofwn_target' => '対象',
            'author'      => '作成者',
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
        echo '<label>ステータス <select name="status"><option value="">すべて</option>';
        foreach (['依頼','対応中','完了'] as $s) {
            printf('<option value="%1$s"%2$s>%1$s</option>', esc_attr($s), selected($status,$s,false));
        }
        echo '</select></label>';

        echo '<label>依頼元 <select name="requester"><option value="">すべて</option>';
        foreach ($this->requesters as $r) {
            printf('<option value="%1$s"%2$s>%1$s</option>', esc_attr($r), selected($requester,$r,false));
        }
        echo '</select></label>';

        echo '<label>担当者 <select name="worker"><option value="">すべて</option>';
        foreach ($this->workers as $w) {
            printf('<option value="%1$s"%2$s>%1$s</option>', esc_attr($w), selected($worker,$w,false));
        }
        echo '</select></label>';

        echo '<label>対象タイプ <select name="target_type">
                <option value="">すべて</option>
                <option value="post" '.selected($tt,'post',false).'>投稿/固定ページ</option>
                <option value="site" '.selected($tt,'site',false).'>サイト全体/設定/テーマ</option>
                <option value="other" '.selected($tt,'other',false).'>その他</option>
              </select></label>';

        echo '<label>実施日: <input type="date" name="date_from" value="'.esc_attr($from).'"> 〜 ';
        echo '<input type="date" name="date_to" value="'.esc_attr($to).'"></label>';

        submit_button('絞り込み', 'secondary', '', false);
        echo '</div>';
    }

    public function get_views() {
        $base_url = remove_query_arg(['status','paged']);
        $counts = $this->status_counts();
        $views = [];
        $statuses = ['' => 'すべて', '完了' => '完了', '対応中' => '対応中', '依頼' => '依頼'];
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
        $out = ['all'=>0,'依頼'=>0,'対応中'=>0,'完了'=>0];
        $q = new WP_Query([
            'post_type' => OF_Work_Notes::CPT,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);
        $out['all'] = $q->post_count;
        if ($q->posts) {
            foreach ($q->posts as $pid) {
                $s = get_post_meta($pid, '_ofwn_status', true) ?: '依頼';
                if (isset($out[$s])) $out[$s]++;
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
        if ($orderby === 'ofwn_date') {
            $orderby_arg = 'meta_value';
        } elseif ($orderby === 'title') {
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
        if ($orderby === 'ofwn_date') {
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
                $cls = $item['status']==='完了' ? 'done' : '';
                return '<span class="ofwn-badge '.$cls.'">'.esc_html($item['status']).'</span>';
            case 'ofwn_requester':
                return esc_html($item['requester'] ?: '—');
            case 'ofwn_worker':
                return esc_html($item['worker'] ?: '—');
            case 'ofwn_target':
                if ($item['target_type']==='post' && $item['target_id']) {
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
