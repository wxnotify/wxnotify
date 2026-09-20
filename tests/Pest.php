<?php

use Filament\Schemas\Components\Wizard;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
| Pest helper names are global but file load order isn't guaranteed, so helpers shared by
| more than one test file live here rather than in whichever file happened to need them
| first — otherwise running a single file on its own hits an undefined function.
|
*/

function something()
{
    // ..
}

/**
 * Wizard::getCurrentStepIndex() lives on a plain runtime property, not
 * Livewire's persisted state — every Testable::call()/fillForm() rehydrates
 * the page fresh and resets it to the start step. goToNextWizardStep()
 * trusts that (reset) value to pick which step it's advancing, so a second
 * chained call silently re-validates step 0 instead of the step the test is
 * actually on. Driving nextStep() with an explicit index sidesteps it.
 */
function goToConnectChannelStep(Testable $component, int $fromIndex): Testable
{
    $schema = $component->instance()->{$component->instance()->getDefaultTestingSchemaName()};
    $wizard = $schema->getComponent(fn ($c) => $c instanceof Wizard);

    return $component->call('callSchemaComponentMethod', $wizard->getKey(), 'nextStep', [$fromIndex]);
}

/**
 * Fake the endpoint a Spatie webhook call POSTs to, and return the recorded
 * request history. Spatie's CallWebhookJob resolves its HTTP client with
 * app(GuzzleHttp\Client::class), so this binds that — Http::fake() does NOT
 * intercept it.
 *
 * @param  array<int, mixed>  $responses  Guzzle MockHandler queue entries (Response or Throwable).
 * @return ArrayObject<int, array{request: RequestInterface, response: mixed}>
 */
function chanFakeWebhookEndpoint(array $responses): ArrayObject
{
    $history = new ArrayObject;
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    app()->bind(Client::class, fn () => new Client(['handler' => $stack]));

    return $history;
}
