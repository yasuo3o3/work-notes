# Changelog
All notable changes to this project will be documented in this file.
This project adheres to [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and uses Semantic Versioning.

## [Unreleased]

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
