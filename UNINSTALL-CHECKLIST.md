# WordPress プラグイン Uninstall チェックリスト

## 概要
新機能追加時にuninstall.phpを適切に更新するためのチェックリストです。
データ削除漏れを防ぎ、クリーンなプラグイン削除を保証します。

## チェック項目

### ✅ オプション（wp_options テーブル）
新しいオプションを追加した際は、uninstall.php に削除処理を追加する。

```php
// 例：新オプション追加時
register_setting('my_settings', 'my_new_option', [...]);

// uninstall.php に追加必須
delete_option('my_new_option');
```

**現在登録済みオプション:**
- `ofwn_requesters` ✅ 
- `ofwn_workers` ✅
- `of_worklog_target_user_ids` ✅
- `of_worklog_target_post_types` ✅
- `of_worklog_min_role` ✅

### ✅ メタデータ（wp_postmeta テーブル）
新しいメタキーを追加した際は、uninstall.php のワイルドカード削除に含める。

```php
// 例：新メタキー追加時
update_post_meta($post_id, 'my_prefix_new_meta', $value);

// uninstall.php で確認・追加
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
    'my_prefix_%'
));
```

**現在削除対象メタキー:**
- `_ofwn_%` （作業メモ関連メタデータ）✅
- `work_log_%` （作業ログ関連メタデータ）✅

### ✅ カスタム投稿タイプ（wp_posts テーブル）
新しいCPTを追加した際は、バッチ削除処理に含める。

**現在削除対象CPT:**
- `of_work_note` ✅

### ✅ ユーザーメタ（wp_usermeta テーブル）
ユーザーメタを使用する場合は削除処理を追加。

**現状:** 使用なし ✅

### ✅ カスタムテーブル
独自テーブルを作成する場合は DROP TABLE 処理を追加。

**現状:** 使用なし ✅

### ✅ Transient（キャッシュ）
set_transient() を使用する場合は削除処理を追加。

**現状:** 使用なし ✅

### ✅ Cron Job
wp_schedule_event() を使用する場合は停止処理を追加。

**現状:** 使用なし ✅

### ✅ Capabilities（権限）
add_role() や add_cap() を使用する場合は削除処理を追加。

**現状:** 使用なし ✅

## 新機能追加時の手順

1. **機能実装時**
   - このチェックリストを確認
   - 該当する項目があれば uninstall.php への追加をメモ

2. **uninstall.php 更新**
   - 新しいオプション/メタデータ/CPT などを削除処理に追加
   - バッチ処理や LIKE クエリの対象範囲を確認

3. **テスト**
   - テスト環境でプラグイン削除を実行
   - データベース内に残留データがないことを確認

## 残すべきデータ（意図的に削除しない）

現在のところ、すべてのプラグインデータを削除する方針です。
将来的にユーザーデータを残す必要がある場合は、このセクションに明記してください。

## 確認用SQLクエリ

プラグイン削除後の残留データ確認用：

```sql
-- オプション確認
SELECT * FROM wp_options WHERE option_name LIKE '%ofwn%' OR option_name LIKE '%worklog%';

-- メタデータ確認  
SELECT * FROM wp_postmeta WHERE meta_key LIKE '_ofwn_%' OR meta_key LIKE 'work_log_%';

-- カスタム投稿確認
SELECT * FROM wp_posts WHERE post_type = 'of_work_note';
```

## 更新履歴

- 2025-01-26: 初版作成（作業ログ促し機能追加に伴い）
- 作業ログ関連オプション3項目とメタデータ削除処理を追加