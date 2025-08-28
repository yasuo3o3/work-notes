<?php
if (!defined('ABSPATH')) exit;

/**
 * Work Notes プラグイン用アップデートチェッカー
 * /updates/ 仮想ルートを使用してアップデートを確認
 */
class OFWN_Updater {
    private $plugin_file;
    private $plugin_slug;
    private $version;
    
    public function __construct($plugin_file, $version) {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->version = $version;
        
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
    }
    
    /**
     * アップデートチェック
     */
    public function check_for_update($transient) {
        if (empty($transient->checked[$this->plugin_slug])) {
            return $transient;
        }
        
        $remote_version = $this->get_remote_version();
        if (!$remote_version) {
            return $transient;
        }
        
        if (version_compare($this->version, $remote_version['version'], '<')) {
            $transient->response[$this->plugin_slug] = (object) [
                'slug' => dirname($this->plugin_slug),
                'plugin' => $this->plugin_slug,
                'new_version' => $remote_version['version'],
                'url' => $remote_version['details_url'] ?? home_url(),
                'package' => $remote_version['download_url'],
                'tested' => $remote_version['tested'] ?? get_bloginfo('version'),
                'requires' => $remote_version['requires'] ?? '6.0',
                'requires_php' => $remote_version['requires_php'] ?? '8.0',
            ];
        }
        
        return $transient;
    }
    
    /**
     * プラグイン情報取得
     */
    public function plugin_info($result, $action, $args) {
        if ('plugin_information' !== $action || dirname($this->plugin_slug) !== $args->slug) {
            return $result;
        }
        
        $remote_version = $this->get_remote_version();
        if (!$remote_version) {
            return $result;
        }
        
        return (object) [
            'name' => $remote_version['name'] ?? 'Work Notes',
            'slug' => dirname($this->plugin_slug),
            'version' => $remote_version['version'],
            'author' => $remote_version['author'] ?? 'Netservice',
            'author_profile' => $remote_version['author_profile'] ?? 'https://netservice.jp/',
            'homepage' => $remote_version['homepage'] ?? home_url(),
            'requires' => $remote_version['requires'] ?? '6.0',
            'requires_php' => $remote_version['requires_php'] ?? '8.0',
            'tested' => $remote_version['tested'] ?? get_bloginfo('version'),
            'downloaded' => 0,
            'last_updated' => $remote_version['last_updated'] ?? date('Y-m-d'),
            'sections' => [
                'description' => $remote_version['description'] ?? __('作業メモ管理プラグイン', 'work-notes'),
                'changelog' => $remote_version['changelog'] ?? __('更新履歴は README をご確認ください。', 'work-notes'),
            ],
            'download_link' => $remote_version['download_url'],
        ];
    }
    
    /**
     * リモートバージョン情報を取得
     */
    private function get_remote_version() {
        $channel = get_option(OF_Work_Notes::OPT_UPDATE_CHANNEL, 'stable');
        $cache_key = 'ofwn_update_info_' . $channel;
        
        // キャッシュから取得 (1時間)
        $cached = get_transient($cache_key);
        if (false !== $cached) {
            return $cached;
        }
        
        // 仮想配布ルートから取得 (最優先)
        $update_url = home_url('/updates/' . $channel . '.json');
        $response = wp_remote_get($update_url, [
            'timeout' => 10,
            'sslverify' => false,
        ]);
        
        $update_data = null;
        
        if (!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
            $body = wp_remote_retrieve_body($response);
            $update_data = json_decode($body, true);
        } else {
            // フォールバック: プラグイン内 updates/ ディレクトリ
            $fallback_file = OFWN_DIR . 'updates/' . $channel . '.json';
            if (file_exists($fallback_file)) {
                $body = file_get_contents($fallback_file);
                $update_data = json_decode($body, true);
            }
        }
        
        if ($update_data && isset($update_data['version'])) {
            // キャッシュに保存 (1時間)
            set_transient($cache_key, $update_data, HOUR_IN_SECONDS);
            return $update_data;
        }
        
        // エラー時は短時間キャッシュ (5分)
        set_transient($cache_key, false, 5 * MINUTE_IN_SECONDS);
        return false;
    }
}