<?php
/**
 * Plugin Name:       Work Notes（作業メモ）
 * Description:       クライアント指示や更新作業のメモをWP内で記録。投稿や固定ページに紐づけ、一覧管理できます。依頼元/担当者のマスター管理＆セレクト、管理画面の「作業一覧」付き。
 * Version:           0.05
 * Author:            Netservice
 * Author URI:        https://netservice.jp/
 * License:           GPLv2 or later
 * Text Domain:       work-notes
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) exit;

define('OFWN_VER', '0.05');
define('OFWN_DIR', plugin_dir_path(__FILE__));
define('OFWN_URL', plugin_dir_url(__FILE__));

// 管理画面でのみ WP_List_Table を必要時に読み込み
if (is_admin() && !class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

// クラス読み込み
require_once OFWN_DIR . 'includes/class-of-work-notes.php';
require_once OFWN_DIR . 'includes/class-ofwn-list-table.php';

// テキストドメイン読み込み
add_action('plugins_loaded', function () {
    load_plugin_textdomain('work-notes', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// activate/deactivate フック
register_activation_hook(__FILE__, 'work_notes_activate');
register_deactivation_hook(__FILE__, 'work_notes_deactivate');

function work_notes_activate() {
    // 将来のための置き場：
    // - CPTリライトの反映（必要時）
    // - 初期オプションの用意
    // - capabilities 付与 など
    if (function_exists('flush_rewrite_rules')) {
        flush_rewrite_rules();
    }
}

function work_notes_deactivate() {
    // 将来のための置き場：
    // - cronの停止
    // - 一時データの掃除（削除はしない）
    if (function_exists('flush_rewrite_rules')) {
        flush_rewrite_rules();
    }
}

// 起動
add_action('plugins_loaded', function () {
    new OF_Work_Notes();
});
