<?php
// 管理画面メニュー追加
add_action('admin_menu', function() {
    add_menu_page(
        '作業メモ一覧',
        '作業メモ',
        'manage_options',
        'work-notes',
        'work_notes_page_html',
        'dashicons-edit',
        26
    );
});

function work_notes_page_html() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    echo '<div class="wrap"><h1>作業メモ</h1><p>ここに一覧やフォームを表示</p></div>';
}
