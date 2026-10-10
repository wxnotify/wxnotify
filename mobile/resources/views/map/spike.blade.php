<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<style>{!! $leafletCss !!}</style>
<style>html,body,#map{height:100%;margin:0}</style>
</head>
<body>
<div id="map"></div>
<script>{!! $leafletJs !!}</script>
<script>
var map = L.map('map').setView([39.74, -104.99], 8);
var topo = L.tileLayer('https://basemap.nationalmap.gov/arcgis/rest/services/USGSTopo/MapServer/tile/{z}/{y}/{x}', {maxZoom: 16, attribution: 'USGS The National Map'}).addTo(map);
var imagery = L.tileLayer('https://basemap.nationalmap.gov/arcgis/rest/services/USGSImageryTopo/MapServer/tile/{z}/{y}/{x}', {maxZoom: 16});
var radar = L.tileLayer.wms('https://opengeo.ncep.noaa.gov/geoserver/conus/conus_bref_qcd/ows', {layers: 'conus_bref_qcd', format: 'image/png', transparent: true, opacity: 0.65, version: '1.1.1'}).addTo(map);
var warn = L.geoJSON({type: 'Polygon', coordinates: [[[-105.5, 39.5], [-105.5, 40.5], [-104.5, 40.5], [-104.5, 39.5], [-105.5, 39.5]]]}, {style: {color: '#d32f2f', weight: 2, fillOpacity: 0.15}}).bindPopup(document.createTextNode('Spike polygon')).addTo(map);
var pin = L.circleMarker([39.74, -104.99], {radius: 8, color: '#1d4ed8', fillColor: '#3b82f6', fillOpacity: 0.9}).bindPopup(document.createTextNode('Spike place')).addTo(map);
L.control.layers({Topo: topo, Imagery: imagery}, {Radar: radar, Warnings: warn, Places: pin}).addTo(map);
</script>
</body>
</html>
