# Natural Earth map data

`world-110m.geojson` is derived from Natural Earth's 1:110m Admin 0 Countries
dataset (version 5.1.1). Natural Earth map data is in the public domain.

- Source: https://www.naturalearthdata.com/downloads/110m-cultural-vectors/
- Terms: https://www.naturalearthdata.com/about/terms-of-use/
- Transformation: selected `ADMIN` and `ISO_A2`, converted to RFC 7946 GeoJSON,
  rounded coordinates to two decimal places, and renamed the two properties to
  `name` and `code`.

Boundaries are provided solely as geographic context and do not imply a position
on legal status or sovereignty.
