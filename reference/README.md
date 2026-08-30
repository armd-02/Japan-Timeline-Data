# Reference data

`news-sync.php` は、取得した座標を都道府県へ分類するために `prefectures.min.geojson` を利用します。

配置先:

```text
reference/prefectures.min.geojson
```

このファイルは、OSM What's New Japan で利用していた都道府県境界 GeoJSON を Japan Timeline Data へ複製したものです。

移行元:

https://github.com/K-Sakanoshita/osm-whatsnew-japan/blob/main/www/whatsnew/data/prefectures.min.geojson

生成処理はこのリポジトリ内のファイルだけを参照するため、実行時に OSM What's New Japan へ依存しません。

別のファイルを使う場合は環境変数で指定できます。

```bash
export JAPAN_TIMELINE_PREFECTURES=/path/to/prefectures.min.geojson
```

置き換える場合も、47都道府県の `name` プロパティを持つ FeatureCollection であることを確認してください。
