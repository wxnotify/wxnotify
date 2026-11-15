---
paths:
  - 'app/Actions/Shapefiles/**'
---

# Shapefiles

## Import shapefiles one record at a time; never load a whole layer
ImportShapefileVersion reads the .dbf for each record's natural key first (no geometry), then streams the .shp through ShapefilePolygonReader, which turns each record straight from its binary layout into compact rings (WKT plus clockwise flag) and inserts every unique record at once; only the rings of keys split across several records wait until the end. Do not use phpmyadmin/shapefile's loadFromFile() or ShapeRecord point arrays for a layer: they cost ~400 bytes per point, took over a gigabyte for Public Zones and about 200 MB for a single High Seas record, and killed the 256 MB containers on a fresh install's first scan. A new layer type must keep the per-layer peak well under 256 MB (ImportShapefileVersionTest has a large-layer memory bound).
