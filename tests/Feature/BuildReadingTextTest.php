<?php

use App\Actions\Products\BuildReadingText;
use App\Enums\ProductFamily;
use App\Models\IngestedProduct;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->xml = "\n408\nXOUS51 KWBC 270114\nCAPBGM\n<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<alert xmlns=\"urn:oasis:names:tc:emergency:cap:1.2\">\n<info><headline>x</headline></info>\n</alert>\n";

    // Two lines hard-wrapped as NWS sends them, and the same sentence reflowed.
    $this->wrapped = "A strong cold front will move across the region this evening and\nbring gusty winds to all of the area.";
    $this->joined = 'A strong cold front will move across the region this evening and bring gusty winds to all of the area.';

    $this->cap = fn (array $overrides = []) => IngestedProduct::factory()->create([
        'format' => 'cap', 'text' => $this->xml, 'headline' => null, 'description' => null, 'instruction' => null,
        ...$overrides,
    ]);

    $this->build = fn (IngestedProduct $product) => app(BuildReadingText::class)($product);

    $this->readable = fn (string $text) => ['text' => $text, 'reading_text' => $text, 'has_original' => false];
});

test('a CAP alert reads as the reflowed description and then the instructions', function () {
    $product = ($this->cap)([
        'headline' => 'Wind Advisory issued November 26',
        'description' => $this->wrapped,
        'instruction' => str_replace("\n", "\r\n", $this->wrapped),
    ]);

    expect(($this->build)($product))->toBe(($this->readable)("{$this->joined}\n\nInstructions\n{$this->joined}"));
});

test('a CAP alert shows whichever readable fields it has, never the XML', function (array $fields, string $expected) {
    $product = ($this->cap)($fields);

    expect(($this->build)($product))->toBe(($this->readable)($expected));
})->with([
    'description only' => [['headline' => 'Flood Warning', 'description' => 'The river is rising.'], 'The river is rising.'],
    'headline only' => [['headline' => 'Flood Warning'], 'Flood Warning'],
    'headline in place of a missing description' => [['headline' => 'Flood Warning', 'instruction' => 'Move to higher ground.'], "Flood Warning\n\nInstructions\nMove to higher ground."],
    'instruction only' => [['instruction' => 'Move to higher ground.'], "Instructions\nMove to higher ground."],
    'nothing' => [[], 'This alert has no text to show.'],
    'blank fields' => [['headline' => ' ', 'description' => "\n\n", 'instruction' => ''], 'This alert has no text to show.'],
]);

test('a CAP field that itself contains XML is left out', function () {
    $product = ($this->cap)([
        'headline' => 'Flood Warning',
        'description' => $this->xml,
        'instruction' => '<alert xmlns="urn:oasis:names:tc:emergency:cap:1.2">',
    ]);

    expect(($this->build)($product))->toBe(($this->readable)('Flood Warning'));
});

test('XML text is treated as CAP whatever the format says', function (string $text) {
    $product = ($this->cap)(['format' => 'text', 'text' => $text]);

    expect(($this->build)($product))->toBe(($this->readable)('This alert has no text to show.'));
})->with([
    'after the sequence and header lines' => ["\n408\nXOUS51 KWBC 270114\nCAPBGM\n<?xml version=\"1.0\"?>\n<alert>\n</alert>"],
    'after CRLF header lines with a correction group' => ["408 \r\r\nXOUS51 KWBC 270114 CCA\r\r\nCAPBGM\r\r\n<?xml version=\"1.0\"?>\r\r\n<alert></alert>"],
    'at the start' => ["  \n<?xml version=\"1.0\"?><alert></alert>"],
    'an alert element with no declaration' => ["Some preamble text\n<alert xmlns=\"urn:oasis:names:tc:emergency:cap:1.2\"></alert>"],
]);

test('a bulletin that only mentions XML further down is still a text product', function () {
    $text = "FXUS64 KMOB 111355\nAFDMOB\n\nThe feed now starts each file with <?xml as before.";
    $product = IngestedProduct::factory()->create(['text' => $text]);

    expect(($this->build)($product)['text'])->toBe($text);
});

test('a text product keeps its text as stored and reads reflowed', function () {
    $stored = str_replace("\n", "\r\n", $this->wrapped);
    $product = IngestedProduct::factory()->create(['text' => $stored]);

    expect(($this->build)($product))->toBe(['text' => $stored, 'reading_text' => $this->joined, 'has_original' => true]);
});

test('a text product with nothing to join offers no original layout, whatever its line endings', function () {
    $stored = "The river is rising.\r\n\r\nMove to higher ground.\r\n";
    $product = IngestedProduct::factory()->create(['text' => $stored]);

    expect(($this->build)($product))->toBe([
        'text' => $stored,
        'reading_text' => "The river is rising.\n\nMove to higher ground.\n",
        'has_original' => false,
    ]);
});

test('an aviation product is never reflowed', function () {
    $stored = str_replace("\n", "\r\n", $this->wrapped);
    $product = IngestedProduct::factory()->create([
        'text' => $stored,
        'product_id' => Product::factory()->create(['family' => ProductFamily::Aviation])->id,
    ]);

    expect(($this->build)($product))->toBe(($this->readable)($stored));
});

test('a failure on a text product falls back to the stored text and logs only the exception class', function () {
    Log::spy();

    $product = new class extends IngestedProduct
    {
        public function getAttribute($key): mixed
        {
            return $key === 'product' ? throw new RuntimeException('private detail') : parent::getAttribute($key);
        }
    };
    $product->forceFill(['format' => 'text', 'text' => $this->wrapped]);

    expect(($this->build)($product))->toBe(($this->readable)($this->wrapped));

    Log::shouldHaveReceived('warning')->once()->with('Reading text failed', ['exception' => RuntimeException::class]);
});

test('a failure on a CAP alert falls back to the fixed sentence', function () {
    Log::spy();

    $product = new class extends IngestedProduct
    {
        public function getAttribute($key): mixed
        {
            return $key === 'description' ? throw new RuntimeException('private detail') : parent::getAttribute($key);
        }
    };
    $product->forceFill(['format' => 'cap', 'text' => $this->xml]);

    expect(($this->build)($product))->toBe(($this->readable)('This alert has no text to show.'));

    Log::shouldHaveReceived('warning')->once()->with('Reading text failed', ['exception' => RuntimeException::class]);
});
