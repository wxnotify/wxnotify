<?php

use App\Models\IngestedProduct;
use App\Models\Product;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->withoutVite();
});

function pageProduct(array $attributes = []): IngestedProduct
{
    return IngestedProduct::factory()->create([
        'cccc' => 'kewx',
        'ttaaii' => 'fous54',
        'awipsid' => 'pfwewx',
        'wmo_ddhhmm' => '200625',
        'received_at' => '2026-09-20 06:25:53',
        'source' => 'nwws',
        'text' => "FOUS54 KEWX 200625\nPFWEWX\nHello from the product text\n",
        ...$attributes,
    ])->fresh();
}

test('the page shows the product text and its metadata', function () {
    $catalog = Product::factory()->create(['name' => 'Fire Weather Point Forecast']);
    $product = pageProduct(['product_id' => $catalog->id, 'headline' => 'A headline for the page']);

    $this->get(route('view-product.show', $product->view_slug))
        ->assertOk()
        ->assertSee('Fire Weather Point Forecast')
        ->assertSee('KEWX')
        ->assertSee('FOUS54 KEWX 200625')
        ->assertSee('PFWEWX')
        ->assertSee('2026-09-20 06:25 UTC')
        ->assertSee('NWWS')
        ->assertSee('A headline for the page')
        ->assertSee('Hello from the product text')
        ->assertSee('index,follow', false)
        ->assertSee($product->viewUrl(), false);
});

test('an EMWIN product is labelled with its source', function () {
    $product = pageProduct(['source' => 'emwin']);

    $this->get(route('view-product.show', $product->view_slug))
        ->assertOk()
        ->assertSee('EMWIN')
        ->assertDontSee('NWWS');
});

test('VTEC details are shown when the product has them', function () {
    $product = pageProduct([
        'vtec_action' => 'NEW', 'vtec_office' => 'KEWX', 'vtec_phenomenon' => 'TO',
        'vtec_significance' => 'W', 'vtec_event_number' => '0042', 'vtec_year' => 2026,
        'vtec_begin' => '2026-09-20 06:25:00', 'vtec_end' => '2026-09-20 07:00:00',
    ]);

    $this->get(route('view-product.show', $product->view_slug))
        ->assertOk()
        ->assertSee('NEW.KEWX.TO.W.0042')
        ->assertSee('2026-09-20 07:00 UTC');
});

test('the product text is escaped, never rendered as markup', function () {
    $product = pageProduct(['text' => "<script>alert(1)</script>\nplain line\n"]);

    $this->get(route('view-product.show', $product->view_slug))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

test('the headline and catalog product name are escaped', function () {
    $catalog = Product::factory()->create(['name' => '<script>alert("name")</script>']);
    $product = pageProduct(['product_id' => $catalog->id, 'headline' => '<script>alert("headline")</script>']);

    $this->get(route('view-product.show', $product->view_slug))
        ->assertOk()
        ->assertDontSee('<script>alert("headline")</script>', false)
        ->assertDontSee('<script>alert("name")</script>', false)
        ->assertSee('&lt;script&gt;alert(&quot;headline&quot;)&lt;/script&gt;', false)
        ->assertSee('&lt;script&gt;alert(&quot;name&quot;)&lt;/script&gt;', false);
});

test('the canonical url ignores the request host', function () {
    config(['wxnotify.product_domain' => null]);
    $product = pageProduct();
    $expected = rtrim(config('app.url'), '/').'/view-product/'.$product->view_slug;

    $response = $this->get("http://evil.example.test/view-product/{$product->view_slug}")->assertOk();

    $response->assertSee('<link rel="canonical" href="'.$expected.'">', false);
    preg_match('/<link rel="canonical"[^>]*>/', $response->getContent(), $canonical);
    expect($canonical[0])->not->toContain('evil.example.test');
    expect($product->viewUrl())->toBe($expected);
});

test('the page links to the dashboard login', function () {
    $product = pageProduct();

    $this->get(route('view-product.show', $product->view_slug))
        ->assertSee(rtrim(config('app.url'), '/').route('filament.dashboard.auth.login', absolute: false), false);
});

test('a found product is cacheable for a year with no cookie', function () {
    $product = pageProduct();

    $response = $this->get(route('view-product.show', $product->view_slug))->assertOk();

    expect($response->headers->get('Cache-Control'))
        ->toContain('public')->toContain('max-age=31536000')->toContain('immutable');
    $response->assertHeaderMissing('Set-Cookie');
});

test('an unknown slug is a short-cached 404', function () {
    $response = $this->get('/view-product/does-not-exist')->assertNotFound();

    expect($response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=60');
    $response->assertHeaderMissing('Set-Cookie');
});

test('a slug with disallowed characters never reaches the controller', function () {
    $this->get('/view-product/Not_A_Slug')->assertNotFound();
});

test('a test product is a 404 in production', function () {
    $product = pageProduct(['is_test' => true]);
    app()->instance('env', 'production');

    $this->get(route('view-product.show', $product->view_slug))->assertNotFound();
});

test('a test product outside production shows a banner and is not indexed', function () {
    $product = pageProduct(['is_test' => true]);

    $this->get(route('view-product.show', $product->view_slug))
        ->assertOk()
        ->assertSee('TEST PRODUCT')
        ->assertSee('noindex,nofollow', false)
        ->assertDontSee('index,follow', false);
});

test('a product without a slug has no view url', function () {
    $product = pageProduct();
    $product->forceFill(['view_slug' => null])->saveQuietly();

    expect($product->fresh()->viewUrl())->toBeNull();
});

test('the page is served only from the configured product domain', function () {
    $product = pageProduct();
    config(['wxnotify.product_domain' => 'products.example.test']);

    $routes = new RouteCollection;
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if ($route->getName() !== 'view-product.show') {
            $routes->add($route);
        }
    }
    Route::setRoutes($routes);
    require base_path('routes/public.php');
    app('router')->getRoutes()->refreshNameLookups();

    $this->get("http://products.example.test/view-product/{$product->view_slug}")->assertOk();
    $this->get("http://other.example.test/view-product/{$product->view_slug}")->assertNotFound();

    expect($product->fresh()->viewUrl())->toBe("https://products.example.test/view-product/{$product->view_slug}");
});

test('the page uses the dashboard panel theme, colors and Filament components', function () {
    $product = pageProduct();

    $this->get(route('view-product.show', $product->view_slug))
        ->assertOk()
        ->assertSee('fi-body', false)
        ->assertSee('--primary-500', false)
        ->assertSee('fi-section', false)
        ->assertSee('fi-badge', false)
        ->assertSee("localStorage.getItem('theme')", false)
        ->assertDontSee('livewire', false);
});

test('the test product banner and the not-found page use Filament components', function () {
    $test = pageProduct(['is_test' => true]);

    $this->get(route('view-product.show', $test->view_slug))->assertOk()->assertSee('fi-callout', false);
    $this->get('/view-product/does-not-exist')->assertNotFound()->assertSee('fi-empty-state', false);
});

test('the product details are in a collapsible section that starts collapsed, with the details still in the page', function () {
    $product = pageProduct();

    $html = $this->get(route('view-product.show', $product->view_slug))->assertOk()->getContent();

    expect($html)->toMatch('/<details class="fi-section[^"]*" data-product-details>\s*<summary/')
        ->not->toContain('data-product-details open')
        ->toContain('Product details')
        ->toContain('KEWX')
        ->toContain('AWIPS ID')
        ->and(substr_count($html, '<details'))->toBe(1);
});
