# Reference data

`news-sync.php` は、取得した座標を都道府県へ分類するために `prefectures.min.geojson` を利用します。

配置先:

```text
reference/prefectures.min.geojson
```

現在は OSM What's New Japan で利用している都道府県境界 GeoJSON と同じ形式を想定しています。

元ファイル:

https://github.com/K-Sakanoshita/osm-whatsnew-japan/blob/main/www/whatsnew/data/prefectures.min.geojson

将来的には、このリポジトリ側へ参照データを移し、生成処理が OSM What's New Japan に依存しない状態にします。

別のファイルを使う場合は環境変数で指定できます。

```bash
export JAPAN_TIMELINE_PREFECTURES=/path/to/prefectures.min.geojson
```
