<?php
if (!defined('ABSPATH')) exit;

/**
 * Work Notes プラグイン用アップデートチェッカー（無効化済み）
 * 
 * WordPress.org配布基準に合わせて独自アップデート機能を削除。
 * Plugin Check の plugin_updater_detected / update_modification_detected 警告を解消。
 * 
 * 注記：
 * - pre_set_site_transient_update_plugins フックの使用は WordPress.org では禁止
 * - 独自アップデートURLやダウンロード機能も同様に削除が必要
 * - 開発環境でのみ更新通知が必要な場合は、別途安全な方法で実装すること
 */
class OFWN_Updater {
    private $plugin_file;
    private $plugin_slug;
    private $version;
    
    public function __construct($plugin_file, $version) {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->version = $version;
        
        // WordPress.org配布基準のため、独自アップデーター機能は無効化
        // add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']); // 削除済み
        // add_filter('plugins_api', [$this, 'plugin_info'], 10, 3); // 削除済み
        
        // 開発環境でのみ更新通知を表示する場合の例（安全な方法）
        if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options')) {
            add_action('admin_notices', [$this, 'dev_update_notice']);
        }
    }
    
    /**
     * 開発環境専用：更新通知（WordPress.org配布基準に準拠）
     */
    public function dev_update_notice() {
        // 開発環境でのみ表示される静的な更新確認メッセージ
        // 実際のアップデート処理は行わない
        if (get_transient('ofwn_dev_update_notice_dismissed')) {
            return;
        }
        
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p><strong>Work Notes (開発版)</strong>: ';
        echo 'プラグインの最新版は <a href="https://github.com/your-repo" target="_blank">GitHub</a> で確認できます。';
        echo '</p>';
        echo '</div>';
        
        // 24時間後に再表示
        set_transient('ofwn_dev_update_notice_dismissed', true, DAY_IN_SECONDS);
    }
    
    /* 
     * === 以下、WordPress.org配布基準により削除された機能 ===
     * 
     * check_for_update() - pre_set_site_transient_update_plugins フック使用のため削除
     * plugin_info() - plugins_api フックでの独自情報提供のため削除  
     * get_remote_version() - 独自アップデートURL取得のため削除
     * 
     * これらの機能が必要な場合は：
     * 1. WordPress.org以外での配布専用版で実装
     * 2. または管理画面での手動チェック機能として実装
     */
}