<?php

use App\Actions\Pipeline\ExtractCapMetadata;
use App\Models\Product;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A real Flash Flood Warning CAP payload, trimmed of unrelated BLOCKCHANNEL/
 * EAS-ORG parameters but otherwise verbatim from a captured live NWWS
 * session (KTWC issuing FFW). Includes the classic HAZARD/SOURCE/IMPACT
 * bullet text inside <description> -- CAP does not split these out, it
 * just wraps the same narrative prose in XML (see the design doc).
 */
function realCapAlertBody(): string
{
    return <<<'TEXT'
    480

    XOUS55 KWBC 142354

    CAPTWC

    <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
    <alert xmlns="urn:oasis:names:tc:emergency:cap:1.2">
        <identifier>urn:oid:2.49.0.1.840.0.01f300b1d26e1daada39c43e6a411499aacab5db.001.1</identifier>
        <sender>w-nws.webmaster@noaa.gov</sender>
        <sent>2026-09-14T16:54:00-07:00</sent>
        <status>Actual</status>
        <msgType>Alert</msgType>
        <source>211803 v2.3.0</source>
        <scope>Public</scope>
        <code>IPAWSv1.0</code>
        <info>
            <language>en-US</language>
            <category>Met</category>
            <event>Flash Flood Warning</event>
            <responseType>Avoid</responseType>
            <urgency>Immediate</urgency>
            <severity>Severe</severity>
            <certainty>Likely</certainty>
            <effective>2026-09-14T16:54:00-07:00</effective>
            <onset>2026-09-14T16:54:00-07:00</onset>
            <expires>2026-09-14T20:00:00-07:00</expires>
            <senderName>NWS Tucson AZ</senderName>
            <headline>Flash Flood Warning issued September 14 at 4:54PM MST until September 14 at 8:00PM MST by NWS Tucson AZ</headline>
            <description>FFWTWC

    The National Weather Service in Tucson has issued a

    * Flash Flood Warning for...
    Southeastern Pinal County in southeastern Arizona...

    HAZARD...Flash flooding caused by thunderstorms.

    SOURCE...Radar indicated.

    IMPACT...Flash flooding of small creeks and streams.
    </description>
            <instruction>Turn around, don't drown when encountering flooded roads.</instruction>
            <web>http://www.weather.gov</web>
            <parameter><valueName>AWIPSidentifier</valueName><value>FFWTWC</value></parameter>
            <parameter><valueName>WMOidentifier</valueName><value>WGUS55 KTWC 142354</value></parameter>
            <parameter><valueName>VTEC</valueName><value>/O.NEW.KTWC.FF.W.0209.260914T2354Z-260915T0300Z/</value></parameter>
            <area>
                <areaDesc>Pinal, AZ</areaDesc>
                <polygon>32.51,-110.56 32.57,-110.64 32.61,-110.58 32.51,-110.56</polygon>
                <geocode><valueName>SAME</valueName><value>004021</value></geocode>
                <geocode><valueName>UGC</valueName><value>AZC021</value></geocode>
            </area>
        </info>
    </alert>
    TEXT;
}

test('extracts CAP envelope and info fields', function () {
    // FF.W is part of the real catalog seeded by the products table
    // migration, so it's fetched rather than created via factory (which
    // would collide on the unique `code` constraint) -- see
    // ExtractProductMetadataTest for the established convention.
    Product::where('code', 'FF.W')->sole();
    $metadata = (new ExtractCapMetadata)(realCapAlertBody());

    expect($metadata['format'])->toBe('cap')
        ->and($metadata['msg_type'])->toBe('Alert')
        ->and($metadata['event'])->toBe('Flash Flood Warning')
        ->and($metadata['severity'])->toBe('Severe')
        ->and($metadata['urgency'])->toBe('Immediate')
        ->and($metadata['certainty'])->toBe('Likely')
        ->and($metadata['headline'])->toContain('Flash Flood Warning issued')
        ->and($metadata['description'])->toContain('HAZARD...Flash flooding')
        ->and($metadata['instruction'])->toContain("Turn around, don't drown");
});

test('resolves product_id via the embedded VTEC parameter, same as a text product would', function () {
    Carbon::setTestNow('2026-09-14T23:54:00Z');

    $flashFloodWarning = Product::where('code', 'FF.W')->sole();

    $metadata = (new ExtractCapMetadata)(realCapAlertBody());

    expect($metadata['product_id'])->toBe($flashFloodWarning->id)
        ->and($metadata['vtec_office'])->toBe('KTWC')
        ->and($metadata['vtec_phenomenon'])->toBe('FF')
        ->and($metadata['vtec_significance'])->toBe('W')
        ->and($metadata['vtec_event_number'])->toBe('0209')
        ->and($metadata['vtec_year'])->toBe(2026)
        ->and($metadata['vtec_action'])->toBe('NEW');
});

test('extracts the polygon into the same {lat, lon} array shape ExtractProductMetadata uses', function () {
    Product::where('code', 'FF.W')->sole();
    $metadata = (new ExtractCapMetadata)(realCapAlertBody());

    expect($metadata['polygon'])->toBe([
        ['lat' => 32.51, 'lon' => -110.56],
        ['lat' => 32.57, 'lon' => -110.64],
        ['lat' => 32.61, 'lon' => -110.58],
        ['lat' => 32.51, 'lon' => -110.56],
    ]);
});

test('extracts UGC codes from the area geocode, not the SAME/FIPS code', function () {
    Product::where('code', 'FF.W')->sole();
    $metadata = (new ExtractCapMetadata)(realCapAlertBody());

    expect($metadata['ugc_codes'])->toBe(['AZC021']);
});

test('extracts the correlation identifiers verbatim', function () {
    Product::where('code', 'FF.W')->sole();
    $metadata = (new ExtractCapMetadata)(realCapAlertBody());

    expect($metadata['awips_identifier'])->toBe('FFWTWC')
        ->and($metadata['wmo_identifier'])->toBe('WGUS55 KTWC 142354');
});

test('a Cancel-type CAP message with no polygon or area leaves those fields null', function () {
    // SV.W is likewise part of the real seeded catalog (see note above).
    Product::where('code', 'SV.W')->sole();
    $body = <<<'TEXT'
    479

    XOUS53 KWBC 142354

    CAPOAX

    <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
    <alert xmlns="urn:oasis:names:tc:emergency:cap:1.2">
        <identifier>urn:oid:2.49.0.1.840.0.f08e5b5780145a3dee03b18f3f5022a912a78da0.001.1</identifier>
        <sender>w-nws.webmaster@noaa.gov</sender>
        <sent>2026-09-14T18:54:00-05:00</sent>
        <status>Actual</status>
        <msgType>Cancel</msgType>
        <source>211803 v2.3.0</source>
        <scope>Public</scope>
        <code>IPAWSv1.0</code>
        <info>
            <language>en-US</language>
            <category>Met</category>
            <event>Severe Thunderstorm Warning</event>
            <responseType>AllClear</responseType>
            <urgency>Past</urgency>
            <severity>Minor</severity>
            <certainty>Observed</certainty>
            <effective>2026-09-14T18:54:00-05:00</effective>
            <expires>2026-09-14T19:09:52-05:00</expires>
            <senderName>NWS Omaha/Valley NE</senderName>
            <headline>The Severe Thunderstorm Warning has been cancelled.</headline>
            <description>The Severe Thunderstorm Warning has been cancelled and is no longer in effect.</description>
            <web>http://www.weather.gov</web>
            <parameter><valueName>AWIPSidentifier</valueName><value>SVSOAX</value></parameter>
            <parameter><valueName>WMOidentifier</valueName><value>WWUS53 KOAX 142354</value></parameter>
            <parameter><valueName>eventMotionDescription</valueName><value>2026-09-14T23:53:00-00:00...storm...231DEG...34KT...41.31,-96.18</value></parameter>
            <parameter><valueName>VTEC</valueName><value>/O.CAN.KOAX.SV.W.0413.000000T0000Z-260915T0015Z/</value></parameter>
        </info>
    </alert>
    TEXT;

    $metadata = (new ExtractCapMetadata)($body);

    expect($metadata['polygon'])->toBeNull()
        ->and($metadata['ugc_codes'])->toBeNull()
        ->and($metadata['description'])->not->toContain('HAZARD')
        ->and($metadata['event_motion_description'])->toBe('2026-09-14T23:53:00-00:00...storm...231DEG...34KT...41.31,-96.18')
        ->and($metadata['vtec_begin'])->toBeNull()
        ->and($metadata['vtec_action'])->toBe('CAN');
});

test('a VTEC phenomenon+significance with no matching catalog product resolves no product_id', function () {
    $body = str_replace('/O.NEW.KTWC.FF.W.0209.260914T2354Z-260915T0300Z/', '/O.NEW.KTWC.ZZ.Q.0001.260914T2354Z-260915T0300Z/', realCapAlertBody());

    $metadata = (new ExtractCapMetadata)($body);

    expect($metadata['product_id'])->toBeNull();
});
