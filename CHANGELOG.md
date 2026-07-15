# Changelog

このプロジェクトの主な変更履歴です。

## [0.6.0] - 2026-07-15

### Added

- Introduced the Public Law model (`laws.json`)
- Added expandable law update history on GitHub Pages
- Added grouped law view generated from the canonical Event model

### Changed

- Refactored the processing pipeline around a canonical Event model
- Migrated the Law Updates page to use `laws.json`
- Improved the GitHub Pages UI for law updates

### Improved

- Group multiple updates for the same law into a single Law model
- Display update status (active / pending)
- Publish a reusable public data model for external applications

### Internal

- Added `models.py`
- Added `law_view.py`
- Refactored data storage for canonical Event and public Law models

---

## [0.5.0] - 2026-07-14

### Added

- Added support for e-Gov Public Comment RSS.
- Added Public Comments page to GitHub Pages.
- Added source-specific statistics.

### Changed

- Introduced a common data model for all sources.
- Refactored the processing pipeline for multi-source architecture.
- Updated GitHub Pages to support multiple information sources.
- Renamed dashboard and law update frontend files for consistency.

### Internal

- Separated source-specific JSON files.
- Improved project structure for future source expansion.

---

## [0.4.1] - 2026-07-14

### Fixed

- Updated application version information displayed on the dashboard.

---

## [0.4.0] - 2026-07-13

### Added

- Email notifications for law updates
- Keyword highlighting in email notifications
- Automated email delivery from GitHub Actions

### Changed

- Redesigned updates page with grouped law cards
- Improved law update summary display
- Group duplicate law updates
- Added links to GitHub Pages in email notifications

---

## [0.2.0] - 2026-06-30

### 追加

- GitHub Pages対応
- 更新法令詳細ページ追加
- 法令種別集計追加
- e-Gov本文リンク追加
- statistics.json生成
- app_info.json生成

### 改善

- プロジェクト構成整理
- JSON生成処理整理
- UI改善
- GitHub Actions自動更新対応

---

## [0.1.0] - 2026-06-26

### 初回リリース

- e-Gov更新情報取得
- CSV解析
- 更新法令一覧生成
- GitHub Actions対応