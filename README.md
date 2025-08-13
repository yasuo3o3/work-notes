# work-notes
作業メモプラグイン
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
- 「設定＞マスター管理」で依頼元/担当者を登録
- 「作業メモ」は各ページの最下部で記載
- 「作業一覧」これまでの作業一覧

---

## ⚙️ 動作環境
- WordPress 6.x
- PHP 7.4+（推奨8.1+）

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
├── uninstall.php
├── README.md         # このファイル
└── LICENSE           # ライセンスファイル
```

---

## 🔄 開発者向け

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

## 📜 ライセンス
このプロジェクトは **MIT License** のもとで公開されています。  
詳細は [LICENSE](LICENSE) ファイルをご覧ください。

