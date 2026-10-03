<script>
    // Shared by every Leaflet map widget (Traccar devices, Locations). Defined once per page.
    // pinIcon() needs Leaflet (window.L): call it only inside window.whenLeafletReady callbacks.
    if (! window.wxMapHelpers) {
        window.wxMapHelpers = {
            // The only markup built as HTML: a pin whose colour comes from caller-side constants.
            pinIcon: function (color) {
                return L.divIcon({
                    className: '',
                    html: '<svg xmlns="http://www.w3.org/2000/svg" width="26" height="36" viewBox="0 0 26 36">'
                        + '<path d="M13 0C5.8 0 0 5.8 0 13c0 9.5 13 23 13 23s13-13.5 13-23C26 5.8 20.2 0 13 0z" fill="' + color + '" stroke="#ffffff" stroke-width="2"/>'
                        + '<circle cx="13" cy="13" r="5" fill="#ffffff"/></svg>',
                    iconSize: [26, 36],
                    iconAnchor: [13, 36],
                    popupAnchor: [0, -32],
                });
            },

            relativeTime: function (date) {
                const seconds = Math.round((date.getTime() - Date.now()) / 1000);
                const abs = Math.abs(seconds);
                const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

                if (abs < 60) { return rtf.format(seconds, 'second'); }
                if (abs < 3600) { return rtf.format(Math.round(seconds / 60), 'minute'); }
                if (abs < 86400) { return rtf.format(Math.round(seconds / 3600), 'hour'); }

                return rtf.format(Math.round(seconds / 86400), 'day');
            },

            formatTimestamp: function (iso) {
                if (! iso) { return 'unknown'; }

                const date = new Date(iso);

                if (isNaN(date.getTime())) { return 'unknown'; }

                const absolute = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);

                return absolute + ' · ' + window.wxMapHelpers.relativeTime(date);
            },
        };
    }
</script>
