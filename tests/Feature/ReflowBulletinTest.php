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
