# Japan Timeline Data

日本国内の過去の出来事を Wikidata から収集・正規化し、年月・地域別の JSON として蓄積・公開するためのデータ生成プロジェクトです。

このリポジトリは、OSM What's New Japan で試作していたニュース生成処理を表示アプリから分離し、TimeMapTravel Japan など複数のアプリから再利用できるデータ基盤にすることを目的としています。

## 役割

- Wikidata / Wikidata Query Service から過去の出来事候補を取得する
- 日付精度を確認し、月単位以上の精度を持つ項目を採用する
- 座標から都道府県をローカル判定する
- Wikidata のラベル・説明・Wikipedia リンクを付加する
- `title` を保持したまま、表示用の `headline` を生成する
- 全国ニュース候補を抽出し、重要度とカテゴリを使って最大10件を選ぶ
- 月別 JSON と月一覧 `data/index.json` を生成する

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

1ファイルを1か月とし、`data/YYYY-MM.json` に保存します。現在の月別データは schema version 6 です。

概略:

```json
{
  "year": 2010,
  "month": 3,
  "generated_at": "2026-08-30T00:00:00Z",
  "source": "Wikidata",
  "schema_version": 6,
  "national": [],
  "national_candidates": [],
  "regions": {
    "大阪府": [
      {
        "date": "2010-03-14",
        "date_precision": 11,
        "date_precision_label": "day",
        "date_property": "P571",
        "date_property_label": "inception",
        "event_type": "inception",
        "wikidata_id": "Q...",
        "latitude": 34.0,
        "longitude": 135.0,
        "query_sources": ["P571:direct"],
        "title": "...",
        "description": "...",
        "wikipedia_url": "https://ja.wikipedia.org/...",
        "wikipedia_language": "ja",
        "headline": "...",
        "headline_category": "...",
        "headline_rule": "..."
      }
    ]
  }
}
```

元の `title` と `description` は監査・再生成用に保持し、`headline`、`headline_category`、`headline_rule` は表示用の派生情報として扱います。

### 全国ニュース

`national` は、地域候補と全国検索候補をまとめて重要度を評価し、カテゴリの偏りを抑えながら最大10件を選んだ表示用配列です。

`national_candidates` は全国検索で見つかった候補のうち、地域配列と重複しない項目を監査用に保持します。

### 重複排除

地域イベントは QID だけではなく `QID + event_group` で重複排除します。

- `P585` / `P580` → `event`
- `P1619` / `P571` → `opening`
- `P3999` / `P576` → `closure`

このため、同じ Wikidata 項目に同月の「開業」と「閉鎖」が存在しても別イベントとして保持できます。同一 event group 内では日付精度、プロパティ優先度、座標取得方法を使って代表候補を選びます。

## 取得対象の日付プロパティ

- `P585` point in time
- `P580` start time
- `P1619` date of official opening
- `P571` inception
- `P3999` date of official closure
- `P576` dissolved, abolished or demolished date

Wikidata の時刻精度が年のみ (`timePrecision = 9`) の項目は月別 JSON には含めません。月 (`10`) または日 (`11`) の精度を持つ項目を対象にします。

## 都道府県判定

地域候補の座標は `reference/prefectures.min.geojson` に対して point-in-polygon 判定を行い、47都道府県へ分類します。Wikidata の行政階層をたどるクエリには依存しません。

別の境界ファイルを使う場合は環境変数で指定できます。

```bash
export JAPAN_TIMELINE_PREFECTURES=/path/to/prefectures.min.geojson
```

## 実行

PHP 8 と cURL 拡張が必要です。

```bash
php scripts/news-sync.php
```

引数を省略すると、2010-01 から現在の完了済み月までを調べ、未生成の最も古い月を1か月だけ生成します。現在進行中の月は対象にしません。

特定月を生成する場合:

```bash
php scripts/news-sync.php 2010-03
```

既存月を再生成する場合:

```bash
php scripts/news-sync.php 2010-03 --force
```

生成成功後、`data/index.json` の `months` も自動的に更新されます。月別 JSON と index は一時ファイルへ書き出してから `rename()` するため、途中結果を完成ファイルとして残しません。

Wikidata への User-Agent は環境変数で上書きできます。

```bash
export JAPAN_TIMELINE_USER_AGENT='JapanTimelineData/1.0 (+https://github.com/armd-02/Japan-Timeline-Data)'
```

### cron 例

1回につき1か月だけ進むため、例えば1日1回実行できます。

```cron
17 3 * * * cd /path/to/Japan-Timeline-Data && /usr/bin/php scripts/news-sync.php >> /var/log/japan-timeline-data.log 2>&1
```

同時実行は non-blocking `flock()` で抑止します。

## OSM What's New Japan からの移行

生成処理はこのリポジトリ内で完結します。実行時に OSM What's New Japan の PHP、設定ファイル、データファイルを読み込みません。

移行時は次のように切り替えます。

1. OSM What's New Japan 側の `www/whatsnew/news-sync.php` を実行している cron を停止する。
2. このリポジトリをデータ生成先に配置する。
3. `php scripts/news-sync.php` を cron から実行する。
4. 利用アプリは `data/index.json` と `data/YYYY-MM.json` を参照する。

都道府県境界 GeoJSON は移行時点の OSM What's New Japan 版をこのリポジトリへ複製していますが、実行時依存はありません。

## 方針

生成処理と表示処理を分離します。このリポジトリは基本的に「データを作る側」に限定し、地図表示やニュース UI は各利用アプリ側で実装します。

取得元の Wikidata 情報をできるだけ保持し、自動生成した見出しや重要度は派生データとして区別できる構造にします。

## ライセンス・出典

ソフトウェア部分はリポジトリの `LICENSE` に従います。生成 JSON の元データは Wikidata を出典とし、各 JSON 内でも `source` や Wikidata QID を保持します。
