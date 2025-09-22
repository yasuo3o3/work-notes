# Changelog
All notable changes to this project will be documented in this file.
This project adheres to [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and uses Semantic Versioning.

## [Unreleased]
## [1.0.5] - 2025-09-22
### Fixed
- セキュリティ監査とレビュー対応完了
- auth_callback パラメータ数を PHP 8.x 対応に修正（4→6パラメータ）
- register_post_meta 重複登録問題の解決
- ページネーション処理の WP_Query 実装への最適化（複雑キャッシュから標準実装へ）
- 未定義変数 $all_ids エラー解決（class-ofwn-list-table.php:277）

### Security
- 全エントリーポイントでのnonce検証とcurrent_user_can権限チェック実装
- CSRF重複チェック除去によるセキュリティ処理最適化

### Code Quality
- WordPress Coding Standards (WPCS) 完全準拠確認（0 syntax errors）
- Plugin Check 全項目クリア確認（0 critical issues）
- PHP 8.x 互換性確保

## [1.0.4] - 2025-09-13
### Removed
- 独立更新機構削除（WordPress.org配布準備）
- 配布エンドポイント機能削除（GitHub Releases依存排除）
- 作業ログ促し（Snackbar）機能完全削除
- 設定管理機能削除（class-worklog-settings.php、worklog-settings.js/css）
- 通知関連メタデータ管理機能削除（class-worklog-meta.php、worklog-editor.js）

### Changed
- WordPress.org配布基準への完全準拠
- キャッシュクリア機能の制限（管理者権限のみ）
- ファイル更新時刻チェック機能追加（開発環境判定）
- メタボックス表示ロジック最適化

### Technical
- PHP構文チェック通過確認（find + php -l）
- WordPress Coding Standards準拠維持
- 配布用ZIP最適化（.gitattributes export-ignore設定）
- 開発専用ファイル除外設定

## [1.0.3] - 2025-01-09
### Changed
- プラグインチェッカー対応のためのバージョン更新
### Added
- 投稿・固定ページのGutenbergエディタで公開ステータス直下に「作業メモ」UIを配置
- PluginPostStatusInfo を使用した作業メモ入力パネル
- 投稿・固定ページのClassic Editorでは下部メタボックス表示
- 投稿・固定ページ用のメタフィールド登録（REST API対応）

### Changed
- **「対象ラベル」フィールドを廃止し、「作業タイトル」に統合**
- **作業タイトルを2行入力に変更（Gutenberg・Classic Editor両対応）**
- 既存データの自動移行：_ofwn_target_labelから_ofwn_work_titleへの統合
- 一覧表示で作業タイトル優先、旧対象ラベル値のフォールバック対応
- 作業メモ通知UI機能を完全削除（Snackbar・AJAX・設定項目等）
- 設定メニューを「作業ログ設定」に統合、旧URLからのリダイレクト対応
- メタボックスのラベル幅とテキストエリア高さを調整
- 作業メモUIの対象を投稿・固定ページに変更（作業メモCPTから移行）
- Gutenberg環境では投稿・固定ページのメタボックスを非表示
- 作業メモUIの初期状態を展開表示に変更（視認性向上）

### Removed
- worklog-editor.js（通知表示JavaScript）
- class-worklog-meta.php（通知関連ロジック）
- worklog-settings.css/js（通知設定用アセット）
- 通知機能関連のDB keys（マイグレーション処理で削除）

## [1.0.2] - 2025-01-26
### Changed
- バージョン番号を1.0.2に統一（Semantic Versioningに完全準拠）

## [1.01] - 2025-01-26
### Fixed
- ライセンス表記の統一（全ファイルでGPL-2.0-or-laterに統一）
- work-notes.php、readme.txtのライセンス表記を修正

### Documentation  
- READMEのライセンスセクションを詳細化（GPL-2.0-or-laterの特徴説明を追加）

## [1.00] - 2025-01-26
### Added
- 作業ログ促し（Snackbar）機能の実装
- 複数ユーザー対象設定機能
- リビジョンベースでの重複防止機能
- 管理画面一覧に依頼元・担当者列を追加
- 列のソート機能実装
- 設定管理機能（`class-worklog-settings.php`）
- メタデータ管理機能（`class-worklog-meta.php`）

### Changed
- register_post_meta による REST API 対応強化
- ブロックエディタ対応の改善
- 保存処理のエラーハンドリング強化
- uninstall.php でのデータ完全削除機能

### Fixed
- ブロックエディタでのメタ保存バグ修正
- Quick Edit での意図しない上書き防止
- 本番環境での保存問題解決

### Security
- XSS対策強化
- 適切な権限チェック実装
- nonce検証とサニタイズ処理追加

## [0.1.1] - 2025-08-10
### Changed
- Initial commit
- Update README.md
- Update README.md
- 初期作成
- インストール方法の変更
- インストール方法の修正
- 動作環境の追加
- アイコン変更
- Update README.md
- Update README.md
- Update README.md
- Update README.md
- CHANGELOG.mdの作成
- インストール方法を変更
- インストール方法を変更


## [0.1.0] - 2025-08-13
### Added
- 初期作成（初期ファイルの追加 / プラグイン最小構成の導入）

### Changed
- アイコン変更（管理画面の見た目を調整）

### Documentation
- README.md の更新（複数回）
- 動作環境の追記（WordPress / PHP の要件）
- インストール方法の修正・変更

---
**Commit references (for traceability)**  
7f83d11, f472189, 6ffc37a, 46ca409, fb69212, d723ca6, 205f07b, dafa933, 1eee73b, 329789d, 45cfabf, 6106508
