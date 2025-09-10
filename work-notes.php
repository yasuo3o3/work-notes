<?php
/**
 * Plugin Name:       Work Notes
 * Description:       クライアント指示や更新作業のメモをWP内で記録。投稿や固定ページに紐づけ、一覧管理できます。依頼元/担当者のマスター管理＆セレクト、管理画面の「作業一覧」付き。
 * Version:           1.0.2
 * Author:            Netservice
 * Author URI:        https://netservice.jp/
 * License:           GPL-2.0-or-later
 * Text Domain:       work-notes
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) exit;

define('OFWN_VER', '1.0.2');
define('OFWN_DIR', plugin_dir_path(__FILE__));
define('OFWN_URL', plugin_dir_url(__FILE__));

/**
 * デバッグログ出力ヘルパー関数
 */
function ofwn_log($message) {
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        error_log('[OFWN] ' . $message);
    }
}

// 管理画面でのみ WP_List_Table を必要時に読み込み
if (is_admin() && !class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

// クラス読み込み
require_once OFWN_DIR . 'includes/class-of-work-notes.php';
require_once OFWN_DIR . 'includes/class-ofwn-list-table.php';
// 自動アップデータはWP.org配布では同梱しない（.gitattributesでexport-ignore）
// require_once OFWN_DIR . 'includes/class-ofwn-updater.php';

// テキストドメイン読み込み（WP4.6+ではWP.org配布で自動ロード。Plugin Check対応のため削除）
// add_action('plugins_loaded', function () {
//     load_plugin_textdomain('work-notes', false, dirname(plugin_basename(__FILE__)) . '/languages');
// });

// activate/deactivate フック
register_activation_hook(__FILE__, 'work_notes_activate');
register_deactivation_hook(__FILE__, 'work_notes_deactivate');

function work_notes_activate() {
    // プラグイン有効化時の初期化
    // CPT登録のみで独自リライトルールは使用しないため flush_rewrite_rules() は不要
    // 将来的に独自URLパターンが必要になった場合のみ追加すること
}

function work_notes_deactivate() {
    // プラグイン無効化時のクリーンアップ
    // CPTのみなので特別な処理は不要
    // 将来的にcronやキャッシュを使用する場合はここで停止/削除を行う
}

// 起動
add_action('plugins_loaded', function () {
    new OF_Work_Notes();
    
    // アップデートチェッカー初期化（配布物では除外）
    // if (is_admin() && class_exists('OFWN_Updater')) {
    //     new OFWN_Updater(__FILE__, OFWN_VER);
    // }
});
