# work-notes
作業メモプラグイン v1.0.3
（このリポジトリは作業メモのためのWordPressプラグインです。）

---

<img width="1155" height="570" alt="image" src="https://github.com/user-attachments/assets/25df5376-536f-4970-9b54-d25ce85a0e98" />

---

## 📌 特徴
- ワードプレスの投稿や固定ページ、設定やテーマなんかを変更するときに、クライアントからの簡単な作業依頼書をつけたかった
- 作業依頼書といっても記載するのは作業者で、作業メモの様なものです
- いつ・誰が・どんな指示を出したから、この作業をいつ・どうしたかをワードプレス上で残すためのものです
<img width="1159" height="554" alt="image" src="https://github.com/user-attachments/assets/f4a55cdc-bfe4-49e3-9e07-cb4830667b5b" />

---

## 🚀 インストール方法
- 必要なファイルを wp-content/plugins/work-notes/ に配置
- またはZIPでアップロード
- その後、有効化

---

## 💻 使い方
- 「作業ログ設定」で依頼元/担当者を登録（統合された設定画面）
- **投稿・固定ページ（Gutenberg）**: 右サイドバー公開ステータス直下の「作業メモ属性」パネルで入力
  - **作業タイトル**: 2行のテキストエリアで作業概要を記載
- **投稿・固定ページ（Classic）**: 各ページの下部メタボックスで記載
  - **作業タイトル**: 2行のテキストエリアで作業概要を記載
- **作業メモCPT**: 従来通りのサイドバーまたは下部メタボックス
- 「作業一覧」これまでの作業一覧（統合された作業タイトル表示）

### データ統合について
- v1.0.3以降、「対象ラベル」は廃止され「作業タイトル」に統合されました
- 既存データは自動的に移行され、初回編集時に作業タイトルに統合されます

---

## ⚙️ 動作環境
- WordPress 6.x
- PHP 8.0+（推奨8.1+）

---

## 📂 ディレクトリ構成
```
リポジトリ名/
├── work-notes.php
├── includes/
├───── admin-menu.php
├───── class-ofwn-list-table.php
├───── class-of-work-notes.php
├── assets/
├───── admin.css
├───── admin.js
├───── js/
├─────── gutenberg-sidebar.js
├── uninstall.php
├── README.md         # このファイル
└── LICENSE           # ライセンスファイル
```

---

## 🔄 開発者向け

### 開発環境セットアップ

#### 必要な環境
- PHP 8.0 以上（推奨: PHP 8.1+）
- WordPress 6.0 以上
- Composer（依存関係管理用）
- Git（ソース管理用）

#### セットアップ手順

1. **リポジトリをクローン**
```bash
git clone [repository-url] work-notes
cd work-notes
```

2. **開発依存関係をインストール**
```bash
# Composer 依存関係のインストール
composer install

# composer.lock が未生成の場合
composer update
```

3. **WordPress環境への配置**
```bash
# WordPressのpluginsディレクトリにシンボリックリンクを作成（推奨）
ln -s $(pwd) /path/to/wordpress/wp-content/plugins/work-notes

# または直接ファイルをコピー
cp -r . /path/to/wordpress/wp-content/plugins/work-notes/
```

4. **WordPressでプラグインを有効化**
- WordPress管理画面 > プラグイン > Work Notes を有効化

#### 開発ワークフロー

1. **コード品質チェック**
```bash
# PHP構文チェック
find . -name "*.php" -not -path "./vendor/*" -print0 | xargs -0 -n1 -P4 php -l

# WordPress コーディング標準チェック（PHPCS設定済み）
vendor/bin/phpcs

# 自動修正（可能な場合）
vendor/bin/phpcbf
```

2. **GitHubでのCI確認**
- プッシュ・プルリクエスト時に自動で以下が実行されます:
  - PHP 8.0-8.3 での構文チェック
  - ファイルエンコーディング・改行コードチェック
  - WordPress基本互換性チェック

3. **翻訳ファイル更新**
```bash
# .pot ファイルの更新（WP-CLI使用）
wp i18n make-pot . languages/work-notes.pot
```

#### 配布用ZIP作成

```bash
# .gitattributes の export-ignore 設定に基づいて配布用ZIPを作成
git archive --format=zip --prefix=work-notes/ HEAD > work-notes.zip
```

このコマンドにより、開発専用ファイル（.github/, *.md, composer.json等）を除外した配布用ZIPが生成されます。

#### デバッグ設定

開発時は wp-config.php に以下を追加することを推奨：

```php
// デバッグモード有効化
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);

// スクリプト・スタイルの圧縮無効化
define('SCRIPT_DEBUG', true);
```

### Changelog自動更新
コミットメッセージからCHANGELOG.mdを自動更新するスクリプトが利用できます。

```bash
# 基本的な使用方法（今日の日付で未リリース版）
./update-changelog.sh

# 特定の日付・バージョンで更新
./update-changelog.sh "2025-08-13" "0.1.1"

# 特定の日付から未リリース版として更新
./update-changelog.sh "2025-08-10"
```

スクリプトは指定日付以降のコミットメッセージを取得し、CHANGELOG.mdに自動挿入します。

---

## 📋 新機能：作業ログ促し（Snackbar）機能

### 概要
投稿・固定ページ保存後に自動で作業ログ記録を促すSnackbar（通知）を表示する機能を追加しました。複数ユーザー対象設定に対応し、リビジョンベースでの重複表示を防止します。

### 主な特徴
- **保存完了後のみ表示**: 保存処理完了後にのみSnackbarが表示され、編集作業を妨げません
- **一回の変更に一回ログ**: 同一リビジョンに対して複数回促すことはありません
- **複数ユーザー対応**: 設定画面で対象ユーザーを複数選択可能
- **投稿タイプ設定**: 投稿・固定ページごとに対象設定可能
- **権限管理**: 設定変更権限を管理者・編集者から選択可能

### 使用方法

#### 1. 設定
「作業メモ > 作業ログ設定」から以下を設定：
- **対象ユーザー**: ユーザー検索で追加・削除
- **対象投稿タイプ**: 投稿・固定ページを選択
- **設定変更権限**: 管理者のみ / 編集者以上

#### 2. 動作フロー
1. 対象ユーザーが対象投稿タイプを保存
2. 新しいリビジョンが作成された場合、Snackbarが表示
3. 「今すぐ書く」→ 作業メモ入力欄へ自動スクロール
4. 「今回はスルー」→ スルー回数をカウント
5. 10秒後に自動消滅

#### 3. データ管理
保存されるメタデータ：
- `work_log_text`: 作業ログ本文
- `work_log_author_user_id`: 記録者のユーザーID
- `work_log_author_login`: 記録時のuser_login（表示用）
- `work_log_author_display_name`: 記録時の表示名
- `work_log_datetime`: 記録日時
- `work_log_last_revision`: 最後にログを記録したリビジョンID
- `work_log_skipped_count`: スルー回数

### フィルター・アクション
カスタマイズ用のWordPressフック：
- `of_worklog_min_cap`: 設定変更の最小権限
- `of_worklog_should_prompt`: 促し表示条件のカスタマイズ
- `of_worklog_snackbar_message`: Snackbar表示メッセージの変更
- `of_worklog_saved`: 作業ログ保存時に実行（アクション）

---

## 🔧 修正履歴・不具合対応レポート

### 2025-01-26: 管理画面一覧改善 + メタ保存バグ修正

#### 不具合原因
1. **ブロックエディタ対応不備**: register_post_meta による REST API 対応が欠落
2. **一覧列の不備**: 依頼元・担当者列が表示されていなかった
3. **ソート機能なし**: 管理画面でのカラムソートができなかった
4. **メタ保存の権限チェック不足**: Quick Edit での上書き対策やデバッグログなし
5. **本番環境での保存問題**: ブロックエディタからのメタ保存が失敗することがあった

#### 修正内容
1. **管理画面一覧の改善**
   - 依頼元（ofwn_requester）列を追加
   - 担当者（ofwn_assignee）列を追加（内部的には _ofwn_worker メタキーを使用）
   - 列の文字列ソート機能を実装
   
2. **ブロックエディタ対応強化**
   - register_post_meta で REST API 対応を追加
   - 全メタフィールドに show_in_rest=true を設定
   - 適切な権限チェック（auth_callback）を実装
   
3. **保存処理の改善**
   - 段階的なエラーハンドリング（ノンス、権限、投稿タイプチェック）
   - Quick Edit での意図しない上書きを防止
   - デバッグログ機能を追加（WP_DEBUG_LOG 有効時）
   - 保存前後の値検証ログ

#### 修正対象ファイル
- `includes/class-of-work-notes.php`: メイン機能改修

#### 再発防止策
- register_post_meta による確実なブロックエディタ対応
- デバッグログによる本番環境での問題追跡可能性向上
- Quick Edit チェックによる意図しない上書き防止

#### テスト手順
1. 新規作業メモ作成→保存→再編集で値が残ることを確認
2. 既存投稿の編集→保存→値が残ることを確認
3. ブロックエディタ・旧エディタ両方で動作確認
4. 管理画面一覧で依頼元・担当者列が表示され、ソートが動作することを確認
5. Quick Edit 時に値が勝手に消去されないことを確認
6. 本番・テスト両環境での再現テスト

### 2025-01-26: 作業ログ促し（Snackbar）機能の実装

#### 背景・目的
投稿・固定ページの編集後に作業ログの記録を促進するため、保存完了時にSnackbar通知を表示する機能を実装。複数ユーザーへの対応とリビジョンベースでの重複防止により、効率的な作業ログ管理を実現。

#### 実装内容
1. **設定管理機能**
   - 複数ユーザー選択（検索・追加・削除）
   - 投稿タイプ設定（投稿・固定ページ）
   - 権限管理（管理者・編集者レベル）

2. **メタデータ管理**
   - 作業ログ本文、作成者情報、日時の保存
   - リビジョンベースの重複防止機能
   - スキップ回数のカウント機能

3. **Gutenbergエディタ統合**
   - 保存完了後の自動Snackbar表示
   - 作業メモ入力欄への自動スクロール
   - 代替入力ダイアログ機能

4. **REST API対応**
   - メタフィールドのREST API登録
   - AJAX による状態取得・保存・スキップ処理

#### 追加ファイル
- `includes/class-worklog-settings.php`: 設定画面管理
- `includes/class-worklog-meta.php`: メタデータ管理
- `assets/worklog-settings.js`: 設定画面JavaScript
- `assets/worklog-settings.css`: 設定画面CSS
- `assets/worklog-editor.js`: エディタ統合JavaScript

#### 技術仕様
- WordPress 5.0+ Gutenberg対応
- PHP 8.0-8.2 互換
- WordPress データストア（@wordpress/data）使用
- セキュリティ：nonce検証、権限チェック、サニタイズ実装

#### テスト手順（新機能）
1. 設定画面でユーザー追加・投稿タイプ選択
2. 対象ユーザーで投稿を保存→Snackbar表示確認
3. 「今すぐ書く」→作業メモ入力欄への自動スクロール確認
4. 「今回はスルー」→スキップカウント増加確認
5. 同一リビジョンで再保存→Snackbar非表示確認
6. 対象外ユーザー・投稿タイプでSnackbar非表示確認

---

## 📜 ライセンス

このプロジェクトは **GPL-2.0-or-later** (GNU General Public License v2.0 or later) の下でライセンスされています。

- **自由な使用**: 個人・商用問わず自由に使用可能
- **改変・再配布**: ソースコードの改変・再配布が可能
- **コピーレフト**: 改変版も同じライセンス下で公開する必要があります
- **免責事項**: 無保証での提供となります

詳細なライセンス条項については、[LICENSE](LICENSE) ファイルをご確認ください。


