<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data: {{ implode(' ', $imageHosts) }}">
<style>{!! $leafletCss !!}</style>
<style>
html, body, #map { height: 100%; margin: 0; }
.leaflet-control-layers-toggle { background-image: none; width: auto; height: auto; padding: 6px 10px; }
.leaflet-control-layers-toggle::after { content: "Layers"; font: 600 13px sans-serif; color: #111; }
.leaflet-popup-content { font: 13px/1.4 sans-serif; }
</style>
</head>
<body>
<div id="map"></div>
<script>{!! $leafletJs !!}</script>
<script>
(function () {
    var data = {!! $json !!};

    function text(parent, tag, value, style) {
        var el = document.createElement(tag);
        el.appendChild(document.createTextNode(value));
        if (style) { el.style.cssText = style; }
        parent.appendChild(el);
        return el;
    }

    function popup(title, lines) {
        var box = document.createElement('div');
        text(box, 'strong', title);
        lines.forEach(function (line) { if (line) { text(box, 'div', line); } });
        return box;
    }

    function until(iso) {
        if (!iso) { return 'No end time given'; }
        var d = new Date(iso);
        return 'Until ' + d.toLocaleString([], { weekday: 'short', hour: 'numeric', minute: '2-digit' });
    }

    var map = L.map('map', { zoomControl: true, attributionControl: true });
    map.attributionControl.setPrefix('');

    var bases = {};
    var firstBase = null;
    data.basemaps.forEach(function (base) {
        var layer = L.tileLayer(base.url, { maxZoom: base.max_zoom, attribution: data.attribution });
        bases[base.label] = layer;
        if (!firstBase) { firstBase = layer; }
    });
    if (firstBase) { firstBase.addTo(map); }

    var overlays = {};

    if (data.radar) {
        var radar = L.tileLayer.wms(data.radar.wms_url, { layers: data.radar.layer, format: 'image/png', transparent: true, opacity: 0.65, version: '1.1.1' }).addTo(map);
        overlays['Radar'] = radar;
        setInterval(function () { radar.setParams({ _: Date.now() }, false); radar.redraw(); }, 120000);
    }

    var warnings = L.layerGroup();
    data.warnings.forEach(function (warning) {
        L.geoJSON(warning.geometry, { style: { color: warning.color, weight: 2, fillColor: warning.color, fillOpacity: 0.15 } })
            .bindPopup(popup(warning.event, [until(warning.expires), warning.headline]))
            .addTo(warnings);
    });
    warnings.addTo(map);
    overlays['Warnings'] = warnings;

    var places = L.layerGroup();
    data.places.forEach(function (place) {
        L.circleMarker([place.lat, place.lng], { radius: 7, color: '#1d4ed8', weight: 2, fillColor: '#3b82f6', fillOpacity: place.stale ? 0.3 : 0.9, opacity: place.stale ? 0.5 : 1 })
            .bindPopup(popup(place.label, [place.stale ? 'No recent update' : null]))
            .addTo(places);
    });
    if (data.phone) {
        L.circleMarker([data.phone.lat, data.phone.lng], { radius: 9, color: '#065f46', weight: 3, fillColor: '#10b981', fillOpacity: data.phone.stale ? 0.4 : 1 })
            .bindPopup(popup('You', [data.phone.stale ? 'No recent update' : null]))
            .addTo(places);
    }
    places.addTo(map);
    overlays['Places'] = places;

    L.control.layers(bases, overlays, { collapsed: true }).addTo(map);

    if (data.center && data.center.lat !== null) {
        map.setView([data.center.lat, data.center.lng], 8);
    } else {
        map.fitBounds([[24, -125], [50, -66]]);
    }
})();
</script>
</body>
</html>
