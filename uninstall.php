<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

// オプションの削除
delete_option('ofwn_requesters');
delete_option('ofwn_workers');
delete_option('ofwn_update_channel');
delete_option('ofwn_migrated_version');

// 通知機能関連オプションの削除（マイグレーションで削除されているが念のため）
delete_option('of_worklog_target_user_ids');
delete_option('of_worklog_target_post_types'); 
delete_option('of_worklog_min_role');
delete_option('ofwn_worklog_mode');

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
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin uninstall cleanup: Safe prepared query with esc_like()
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin uninstall: No caching needed for cleanup operations
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
    $wpdb->esc_like('_ofwn_') . '%'
));

// 通知機能関連のメタデータも削除
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin uninstall cleanup: Safe prepared query with esc_like()
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin uninstall: No caching needed for cleanup operations
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
    $wpdb->esc_like('ofwn_worklog_') . '%'
));

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin uninstall cleanup: Safe prepared query with esc_like()
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin uninstall: No caching needed for cleanup operations
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
    $wpdb->esc_like('ofwn_worklog_') . '%'
));

// 通知機能関連のトランジェントを削除
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin uninstall cleanup: Safe prepared query with esc_like()
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin uninstall: No caching needed for cleanup operations
// Replace direct SQL with options scan + delete_option by prefix
$__ofwn_prefixes = array('_transient_ofwn_worklog_');
$__ofwn_all = function_exists('wp_load_alloptions') ? wp_load_alloptions() : array();
if ( is_array($__ofwn_all) ) {
    foreach ( array_keys($__ofwn_all) as $__k ) {
        foreach ( $__ofwn_prefixes as $__p ) {
            if ( 0 === strpos($__k, $__p) ) { delete_option($__k); }
        }
    }
}
// Replace direct SQL with options scan + delete_option by prefix
$__ofwn_prefixes = array('_transient_timeout_ofwn_worklog_');
$__ofwn_all2 = function_exists('wp_load_alloptions') ? wp_load_alloptions() : array();
if ( is_array($__ofwn_all2) ) {
    foreach ( array_keys($__ofwn_all2) as $__k ) {
        foreach ( $__ofwn_prefixes as $__p ) {
            if ( 0 === strpos($__k, $__p) ) { delete_option($__k); }
        }
    }
}
// プラグイン削除後のキャッシュクリア
wp_cache_flush();
