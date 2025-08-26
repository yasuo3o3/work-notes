<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

// オプションの削除
delete_option('ofwn_requesters');
delete_option('ofwn_workers');

// 作業ログ促し機能関連オプションの削除
delete_option('of_worklog_target_user_ids');
delete_option('of_worklog_target_post_types'); 
delete_option('of_worklog_min_role');

// カスタム投稿タイプとメタデータのバッチ削除
global $wpdb;

$batch_size = 100;
$offset = 0;

// バッチ処理で全ての作業メモ投稿を削除
do {
    $posts = get_posts([
        'post_type' => 'of_work_note',
        'posts_per_page' => $batch_size,
        'offset' => $offset,
        'post_status' => 'any',
        'fields' => 'ids'
    ]);
    
    if (empty($posts)) {
        break;
    }
    
    // 投稿とメタデータを完全削除
    foreach ($posts as $post_id) {
        wp_delete_post($post_id, true);
    }
    
    $offset += $batch_size;
    
    // メモリ不足対策でガベージコレクション実行
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }
    
} while (count($posts) === $batch_size);

// 念のため残ったメタデータを削除
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
    '_ofwn_%'
));

// 作業ログメタデータも削除
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
    'work_log_%'
));
