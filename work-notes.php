<?php
/**
 * Plugin Name:       Work Notes（作業メモ）
 * Description:       クライアント指示や更新作業のメモをWP内で記録。投稿や固定ページに紐づけ、一覧管理できます。依頼元/担当者のマスター管理＆セレクト、管理画面の「作業一覧」付き。
 * Version:           0.05
 * Author:            Netservice
 * Author URI:        https://netservice.jp/
 * License:           GPLv2 or later
 * Text Domain:       work-notes
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

// 起動
add_action('plugins_loaded', function () {
    new OF_Work_Notes();
});
