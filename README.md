# Japan Timeline Data

日本国内の過去の出来事を Wikidata から収集・正規化し、年月・地域別の JSON として蓄積・公開するためのデータ生成プロジェクトです。

このリポジトリは、OSM What's New Japan で試作していたニュース生成処理を表示アプリから分離し、TimeMapTravel Japan など複数のアプリから再利用できるデータ基盤にすることを目的としています。

## 役割

- Wikidata / Wikidata Query Service から過去の出来事候補を取得する
- 日付精度を確認し、月単位以上の精度を持つ項目を採用する
- 座標から都道府県を判定する
- Wikidata のラベル・説明・Wikipediaリンクを付加する
- `title` を保持したまま、表示用の `headline` を生成する
- 月別 JSON を生成する
- 将来的に全国ニュース候補の抽出・重要度付けも行う

## 想定利用先

- [TimeMapTravel Japan](https://github.com/armd-02/TimeMapTravel_Japan)
- OSM What's New Japan（必要な場合のみ関連情報として利用）
- その他、過去の日本の出来事を地図・タイムラインで扱うアプリ

## ディレクトリ構成

```text
Japan-Timeline-Data/
├── data/
│   ├── index.json
│   └── YYYY-MM.json
├── reference/
│   └── prefectures.min.geojson
├── scripts/
│   └── news-sync.php
├── LICENSE
└── README.md
```

## JSON

1ファイルを1か月とし、`data/YYYY-MM.json` に保存します。

概略:

```json
{
  "year": 2010,
  "month": 3,
  "generated_at": "2026-08-30T00:00:00Z",
  "source": "Wikidata",
  "schema_version": 4,
  "regions": {
    "大阪府": [
      {
        "date": "2010-03-14",
        "date_precision": 11,
        "date_property": "P571",
        "event_type": "inception",
        "wikidata_id": "Q...",
        "latitude": 34.0,
        "longitude": 135.0,
        "title": "...",
        "description": "...",
        "headline": "...",
        "wikipedia_url": "https://ja.wikipedia.org/..."
      }
    ]
  }
}
```

元の `title` と `description` は監査・再生成用に保持し、`headline` は表示用の派生情報として扱います。

## 取得対象の日付プロパティ

現在の生成処理では主に次を対象にします。

- `P585` point in time
- `P580` start time
- `P1619` date of official opening
- `P571` inception
- `P3999` date of official closure
- `P576` dissolved, abolished or demolished date

Wikidata の時刻精度が年のみ (`timePrecision = 9`) の項目は月別 JSON には含めません。月 (`10`) または日 (`11`) の精度を持つ項目を対象にします。

## 実行

PHP 8 と cURL 拡張が必要です。

```bash
php scripts/news-sync.php
```

未生成の最も古い完了月を1か月だけ生成します。

特定月を生成する場合:

```bash
php scripts/news-sync.php 2010-03
```

既存月を再生成する場合:

```bash
php scripts/news-sync.php 2010-03 --force
```

Wikidata への User-Agent は環境変数で上書きできます。

```bash
export JAPAN_TIMELINE_USER_AGENT='JapanTimelineData/0.1 (+https://github.com/armd-02/Japan-Timeline-Data)'
```

都道府県境界 GeoJSON の場所を変更する場合:

```bash
export JAPAN_TIMELINE_PREFECTURES=/path/to/prefectures.min.geojson
```

## 方針

生成処理と表示処理を分離します。このリポジトリは基本的に「データを作る側」に限定し、地図表示やニュースUIは各利用アプリ側で実装します。

取得元の Wikidata 情報をできるだけ保持し、自動生成した見出しや重要度は派生データとして区別できる構造にします。

## ライセンス・出典

ソフトウェア部分はリポジトリの `LICENSE` に従います。生成 JSON の元データは Wikidata を出典とし、各 JSON 内でも `source` や Wikidata QID を保持します。
