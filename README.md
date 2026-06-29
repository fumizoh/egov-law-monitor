# egov-law-monitor

## 目的

e-Govの法令更新情報を毎日自動収集し、
行政書士が短時間で改正内容を把握できる
ダッシュボードを構築する。

## 最終目標

・GitHub Actionsによる毎朝の自動実行
・GitHub Pagesによる更新情報の公開
・メール通知
・AIによる改正内容・実務影響の要約

## アーキテクチャ図

          GitHub Actions
                 │
                 ▼
      更新法令XML取得
                 │
                 ▼
          XML解析
                 │
                 ▼
           laws.json
          ┌────┴────┐
          ▼         ▼
   GitHub Pages   メール通知