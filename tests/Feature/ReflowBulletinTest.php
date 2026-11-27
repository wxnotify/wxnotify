<?php

use App\Support\ReflowBulletin;

dataset('reading view fixtures', function (): array {
    $fixtures = [];

    foreach (glob(__DIR__.'/../Fixtures/reading-view/*.expected.txt') as $expectedPath) {
        $name = basename($expectedPath, '.expected.txt');
        $fixtures[$name] = [
            file_get_contents(__DIR__."/../Fixtures/reading-view/{$name}.txt"),
            file_get_contents($expectedPath),
        ];
    }

    return $fixtures;
});

test('each fixture reflows to its hand-written reading text', function (string $source, string $expected) {
    expect(ReflowBulletin::reflow($source))->toBe($expected);
})->with('reading view fixtures');

test('every fixture keeps its words in order and its column-spaced lines unchanged', function (string $source) {
    $reflowed = ReflowBulletin::reflow($source);
    $reflowedLines = explode("\n", $reflowed);

    expect(ReflowBulletin::sameWords($source, $reflowed))->toBeTrue();

    foreach (explode("\n", $source) as $line) {
        if (preg_match('/\S {3,}\S/', $line) === 1) {
            expect($reflowedLines)->toContain($line);
        }
    }
})->with('reading view fixtures');

test('the tide category rows stay on two lines', function () {
    $reflowed = ReflowBulletin::reflow(file_get_contents(base_path('tests/Fixtures/reading-view/coastal-flood-tide-table.txt')));

    expect($reflowed)->toContain(
        "Cape Fear River at Wilmington\n"
        ."MLLW Categories - Minor 5.5 ft, Moderate 6.8 ft, Major 10.0 ft\n"
        ."MHHW Categories - Minor 0.8 ft, Moderate 2.1 ft, Major 5.3 ft\n"
    );
});

test('a UGC zone block and a county-name list that wrap over several lines keep their line breaks', function () {
    $reflowed = ReflowBulletin::reflow(file_get_contents(base_path('tests/Fixtures/reading-view/high-wind-warning-zone-lists.txt')));

    expect($reflowed)->toContain(
        "PAZ012-017>019-025>028-034>036-041-042-045-046-049>053-056-063>065-\n"
        ."270100-\n"
        ."/O.EXP.KCTP.WI.Y.0009.000000T0000Z-261127T0000Z/\n"
        ."Northern Clinton-Clearfield-Northern Centre-Southern Centre-Blair-\n"
        ."Huntingdon-Mifflin-Juniata-Bedford-Fulton-Franklin-Northern Lycoming-\n"
        ."Sullivan-Southern Clinton-Southern Lycoming-Union-Snyder-Montour-\n"
        ."Northumberland-Columbia-Perry-Cumberland-Adams-York-\n"
        ."Including the cities of Clearfield, Newport, Renovo, Lewistown, Mount\n"
        ."Union, Bedford, Gettysburg, Danville, Selinsgrove, State College,\n"
    );
});

test('a wrapped sentence that mentions several numbers is still joined', function () {
    $source = ".TODAY...Mostly cloudy. Highs 50 to 55 with lows 30 to 35 and\n"
        ."winds 10 to 20 mph, gusts 30 to 40 mph and seas 4 to 6 feet by\n"
        ."evening.\n";

    expect(ReflowBulletin::reflow($source))->toBe(
        ".TODAY...Mostly cloudy. Highs 50 to 55 with lows 30 to 35 and winds 10 to 20 mph, gusts 30 to 40 mph and seas 4 to 6 feet by evening.\n"
    );
});

test('CRLF and CR line endings give the same result as LF', function () {
    $source = file_get_contents(base_path('tests/Fixtures/reading-view/afd-key-messages.txt'));
    $expected = ReflowBulletin::reflow($source);

    expect(ReflowBulletin::reflow(str_replace("\n", "\r\n", $source)))->toBe($expected)
        ->and(ReflowBulletin::reflow(str_replace("\n", "\r", $source)))->toBe($expected);
});

test('an empty string and a single line are returned unchanged', function () {
    expect(ReflowBulletin::reflow(''))->toBe('')
        ->and(ReflowBulletin::reflow('The wind is diminishing.'))->toBe('The wind is diminishing.');
});

test('sameWords ignores whitespace but not a changed, dropped or reordered word', function () {
    $original = "Winds 20 to 30 mph\nwith gusts up to 50 mph.";

    expect(ReflowBulletin::sameWords($original, 'Winds 20 to 30 mph with  gusts up to 50 mph.'))->toBeTrue()
        ->and(ReflowBulletin::sameWords($original, 'Winds 20 to 30 mph with gusts up to 60 mph.'))->toBeFalse()
        ->and(ReflowBulletin::sameWords($original, 'Winds 20 to 30 mph with gusts up to mph.'))->toBeFalse()
        ->and(ReflowBulletin::sameWords($original, 'Winds 30 to 20 mph with gusts up to 50 mph.'))->toBeFalse();
});

test('a rule that would change a word returns the text as sent, line endings normalised', function () {
    $faulty = new class extends ReflowBulletin
    {
        protected static function joinWrappedLines(string $text): string
        {
            return str_replace('50', '60', $text);
        }
    };

    expect($faulty::reflow("Gusts up to 50 mph are expected through the afternoon and into\r\nthe evening."))
        ->toBe("Gusts up to 50 mph are expected through the afternoon and into\nthe evening.");
});

test('an error while reflowing returns the text as sent, line endings normalised', function () {
    $faulty = new class extends ReflowBulletin
    {
        protected static function joinWrappedLines(string $text): string
        {
            throw new RuntimeException('Rule failed.');
        }
    };

    expect($faulty::reflow("Gusts up to 50 mph are expected through the afternoon and into\rthe evening."))
        ->toBe("Gusts up to 50 mph are expected through the afternoon and into\nthe evening.");
});

test('a several-hundred-line product reflows in under 100 ms', function () {
    $sourcePaths = array_filter(
        glob(base_path('tests/Fixtures/reading-view/*.txt')),
        fn (string $path): bool => ! str_ends_with($path, '.expected.txt'),
    );
    $source = implode("\n", array_map(fn (string $path): string => file_get_contents($path), $sourcePaths));

    expect(substr_count($source, "\n"))->toBeGreaterThan(500);

    $started = hrtime(true);
    ReflowBulletin::reflow($source);
    $elapsedMilliseconds = (hrtime(true) - $started) / 1_000_000;

    expect($elapsedMilliseconds)->toBeLessThan(100);
});

test('rows of a list that each end a sentence stay on their own lines', function () {
    $highways = ReflowBulletin::reflow(file_get_contents(__DIR__.'/../Fixtures/reading-view/cap-svr-highways.txt'));
    $rivers = ReflowBulletin::reflow(file_get_contents(__DIR__.'/../Fixtures/reading-view/cap-flood-warning-river-rows.txt'));
    $outlook = ReflowBulletin::reflow(file_get_contents(__DIR__.'/../Fixtures/reading-view/afd-outlook-list.txt'));

    expect($highways)->toContain(
        "This includes the following highways...\n"
        ."Interstate 80 in Illinois between mile markers 6 and 32.\n"
        ."Interstate 74 in Illinois between mile markers 8 and 14.\n"
    )->and($rivers)->toContain(
        "Alafia River At Lithia affecting Hillsborough County.\n"
        ."Manatee River Near Myakka Head at SR 64 affecting Manatee County.\n"
        ."Peace River At Arcadia at SR 70 affecting DeSoto County.\n"
    )->and($outlook)->toContain(
        "Friday: Mainly IFR, with areas MVFR possible. Chance RA.\n"
        ."Friday Night: Mainly IFR, with local MVFR possible. Definite SHRA.\n"
        ."Saturday: Mainly IFR, with areas MVFR possible. Chance SHRA.\n"
    );
});

test('a closed headline and a short introduction line end the block before them', function () {
    $headline = ReflowBulletin::reflow(file_get_contents(__DIR__.'/../Fixtures/reading-view/cap-flood-warning-headline.txt'));
    $impact = ReflowBulletin::reflow(file_get_contents(__DIR__.'/../Fixtures/reading-view/cap-svr-impact-locations.txt'));

    expect($headline)->toStartWith(
        "...The National Weather Service in Wilmington has issued a Flood Warning for the following rivers in North Carolina...\n"
        ."Northeast Cape Fear River Near Burgaw affecting Pender County.\n"
    )->and($impact)->toContain(
        "IMPACT...Expect damage to roofs, siding, and trees.\n"
        ."Locations impacted include...\n"
    );
});

test('storm surge rows stay on their own lines (synthetic rows in the NHC public advisory format)', function () {
    $source = "Englewood to Bonita Beach, including Charlotte Harbor...12-18 ft\n"
        ."Bonita Beach to Chokoloskee...8-12 ft\n"
        ."Anclote River to Englewood, including Tampa Bay...5-10 ft\n";

    expect(ReflowBulletin::reflow($source))->toBe($source);
});

test('tropical cyclone coded lines and SPC outlook point lists are never joined', function (string $name) {
    $source = file_get_contents(__DIR__."/../Fixtures/reading-view/{$name}.txt");
    $reflowed = explode("\n", ReflowBulletin::reflow($source));

    foreach (explode("\n", $source) as $line) {
        if (preg_match('/\b\d{1,2}\.\d[NS]\s+\d{1,3}\.\d[EW]\b|\b\d+ [NESW]{1,3} [A-Z0-9]{3}\b/', $line) === 1) {
            expect($reflowed)->toContain($line);
        }
    }
})->with(['tcm-forecast-advisory', 'tcp-storm-surge', 'spc-day1-outlook-points']);

test('legend rows, bracketed notes and SHEF comment lines stay as sent (excerpts of real AFM, PFW and PNS products)', function (string $source) {
    expect(ReflowBulletin::reflow($source))->toBe($source);
})->with([
    'AFM 844855 legend' => "If a hurricane is expected then the following applies...\n"
        ."0-24 hours before landfall...the actual wind speeds will be used.\n"
        ."25-60 hours before landfall...the code \"HU\" will be used for winds\n"
        ."                              and \"HG\" for wind gusts.\n"
        ."61-120 hours before landfall...the code \"HU\" will only be used.\n",
    'PFW 781932 note' => "BELOW IS A WEATHER ELEMENT KEY FOR THIS PRODUCT.\n"
        ."(NOTE...WINDS DO NOT REFLECT LOCAL TERRAIN EFFECTS.)\n",
    'PNS 686308 comments' => ":.......COOPERATIVE OBSERVATIONS.......\n"
        .":\n"
        .":Data are for the 24 hours ending around 7 AM. In some weather\n"
        .":situations...reported low temperatures may reflect conditions\n"
        .":from the previous day.\n",
]);

test('a starred headline ends at its closing stars (excerpt of HLS 441925)', function () {
    $source = "**HURRICANE HUNTERS FIND THAT ISAIAS NOW HAS 100 MPH WINDS** \n"
        ."**PREPARATIONS TO PROTECT LIFE AND PROPERTY FROM STORM SURGE AND\n"
        ."DAMAGING WINDS IN THE WARNING AREA SHOULD BE RUSHED TO COMPLETION\n"
        ."TODAY**\n";

    expect(ReflowBulletin::reflow($source))->toBe(
        "**HURRICANE HUNTERS FIND THAT ISAIAS NOW HAS 100 MPH WINDS** \n"
        ."**PREPARATIONS TO PROTECT LIFE AND PROPERTY FROM STORM SURGE AND DAMAGING WINDS IN THE WARNING AREA SHOULD BE RUSHED TO COMPLETION TODAY**\n"
    );
});
