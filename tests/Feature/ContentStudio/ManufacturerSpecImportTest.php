<?php

namespace Tests\Feature\ContentStudio;

use App\Models\ContentStudio\RacketSpec;
use App\Services\ContentStudio\Sources\SafeHttpFetcher;
use App\Services\ContentStudio\Sources\Specs\ManufacturerSpecImporter;
use App\Services\ContentStudio\Sources\Specs\RobotsRules;
use App\Services\ContentStudio\Sources\Specs\VictorSpecParser;
use App\Services\ContentStudio\Sources\Specs\YonexSpecParser;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\UsesContentStudioDatabase;
use Tests\TestCase;

class ManufacturerSpecImportTest extends TestCase
{
    use UsesContentStudioDatabase;

    private function yonexPage(string $title = 'ASTROX 99 PRO', string $weight = '4U5/6, 3U4/5/6'): string
    {
        return <<<HTML
<html><head><title>x</title></head><body><h1> {$title} </h1>
<table id="product-attribute-specs-table"><tbody>
<tr><th>Flex</th><td>STIFF</td></tr>
<tr><th>Frame</th><td>HM Graphite + CFR</td></tr>
<tr><th>Weight / Grip</th><td>{$weight}</td></tr>
<tr><th>Stringing Advice</th><td>4U: 20 - 28 lbs<br>3U: 21 - 29 lbs</td></tr>
</tbody></table></body></html>
HTML;
    }

    private function victorPage(string $name = 'THRUSTER K 100'): string
    {
        $table = '<table><tbody><tr><td>Weight / Grip Size</td><td>3U / G4</td></tr><tr><td>Frame Material</td><td>Graphite + Resin</td></tr>'
            .'<tr><td>Length</td><td>675 mm</td></tr><tr><td>Stiffness</td><td>S ○○○●○ F</td></tr></tbody></table>';
        $json = json_encode(['props' => ['pageProps' => ['product' => ['product' => ['name' => $name, 'infoContent' => ['wysiwygContent' => $table]]]]]]);

        return '<html><body><script id="__NEXT_DATA__" type="application/json">'.$json.'</script></body></html>';
    }

    private function importer(array &$sleeps = []): ManufacturerSpecImporter
    {
        $this->app->instance(SafeHttpFetcher::class, new SafeHttpFetcher(fn () => ['93.184.216.34']));

        return new ManufacturerSpecImporter(app(SafeHttpFetcher::class), function (int $s) use (&$sleeps) {
            $sleeps[] = $s;
        });
    }

    private function brand(): array
    {
        return ['host' => 'www.yonex.com', 'sitemaps' => ['https://www.yonex.com/sitemap.xml'], 'path_pattern' => '#^/[a-z0-9-]+$#'];
    }

    public function test_yonex_table_is_read_verbatim(): void
    {
        $r = (new YonexSpecParser)->parse($this->yonexPage());

        $this->assertSame('ASTROX 99 PRO', $r['model']);
        $this->assertSame('STIFF', $r['specs']['Flex']);
        $this->assertSame('4U5/6, 3U4/5/6', $r['specs']['Weight / Grip']);
        $this->assertSame('4U: 20 - 28 lbs / 3U: 21 - 29 lbs', $r['specs']['Stringing Advice']);
    }

    public function test_pages_that_are_not_rackets_are_ignored(): void
    {
        $this->assertNull((new YonexSpecParser)->parse('<html><body><h1>Shuttlecock</h1></body></html>'));
        $this->assertNull((new VictorSpecParser)->parse('<html><body>no data</body></html>'));
        // a table without weight/frame is not a racket
        $this->assertNull((new YonexSpecParser)->parse('<h1>Bag</h1><table id="product-attribute-specs-table"><tr><th>Color</th><td>Red</td></tr></table>'));
    }

    public function test_victor_spec_table_is_read_from_next_data(): void
    {
        $r = (new VictorSpecParser)->parse($this->victorPage());

        $this->assertSame('THRUSTER K 100', $r['model']);
        $this->assertSame('3U / G4', $r['specs']['Weight / Grip Size']);
        $this->assertSame('675 mm', $r['specs']['Length']);
    }

    public function test_victor_line_breaks_do_not_fuse_values(): void
    {
        $table = '<table><tr><td>Weight / Grip Size</td><td>3U / G4</br>4U / G5</td></tr><tr><td>Frame Material</td><td>Graphite</td></tr></table>';
        $json = json_encode(['props' => ['pageProps' => ['product' => ['product' => ['name' => 'X', 'infoContent' => ['wysiwygContent' => $table]]]]]]);

        $r = (new VictorSpecParser)->parse('<script id="__NEXT_DATA__">'.$json.'</script>');

        $this->assertSame('3U / G4 / 4U / G5', $r['specs']['Weight / Grip Size']);
    }

    public function test_robots_rules(): void
    {
        $rules = RobotsRules::parse("User-agent: Nutch\nDisallow: /\n\nUser-agent: *\nDisallow: /account\nDisallow: /private/\nAllow: /private/ok\nCrawl-delay: 5\n");

        $this->assertTrue($rules->allows('/product/x'));
        $this->assertFalse($rules->allows('/account/orders'));
        $this->assertFalse($rules->allows('/private/a'));
        $this->assertTrue($rules->allows('/private/ok'), 'longest match wins');
        $this->assertSame(5, $rules->crawlDelay);
        // the Nutch group must not leak into "*"
        $this->assertTrue(RobotsRules::parse("User-agent: Nutch\nDisallow: /\n")->allows('/anything'));
    }

    public function test_import_stores_specs_with_source_url_and_respects_robots_and_delay(): void
    {
        Http::swap(new Factory);
        $sitemap = '<urlset><url><loc>https://www.yonex.com/astrox-99-pro</loc></url><url><loc>https://www.yonex.com/customer-area</loc></url>'
            .'<url><loc>https://www.yonex.com/badminton/racquets</loc></url><url><loc>https://other.example/astrox</loc></url>'
            .'<url><loc>https://www.yonex.com/nanoflare-555</loc></url></urlset>';
        Http::fake([
            'https://www.yonex.com/robots.txt' => Http::response("User-agent: *\nDisallow: /customer-area\nCrawl-delay: 4\n", 200, ['Content-Type' => 'text/plain']),
            'https://www.yonex.com/sitemap.xml' => Http::response($sitemap, 200, ['Content-Type' => 'application/xml']),
            'https://www.yonex.com/astrox-99-pro' => Http::response($this->yonexPage('ASTROX 99 PRO'), 200, ['Content-Type' => 'text/html']),
            'https://www.yonex.com/nanoflare-555' => Http::response('<html><h1>Nanoflare</h1></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $sleeps = [];
        $report = $this->importer($sleeps)->import('yonex', $this->brand(), 10);

        $this->assertSame(3, $report['urls'], 'the other host and the multi-segment path are dropped at discovery; robots filters at fetch time');
        $this->assertSame(1, $report['saved']);
        $this->assertSame(1, $report['not_racket']);
        $this->assertSame(1, $report['skipped_robots']);
        $this->assertSame([], $report['errors']);
        $this->assertSame([4], $sleeps, 'Crawl-delay 4 beats the configured 3 seconds');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'customer-area') && ! str_contains($r->url(), 'robots'));

        $row = RacketSpec::first();
        $this->assertSame(['yonex', 'ASTROX 99 PRO', 'https://www.yonex.com/astrox-99-pro'], [$row->brand, $row->model_name, $row->source_url]);
        $this->assertSame('STIFF', $row->specs_json['Flex']);
    }

    public function test_second_run_does_not_duplicate_and_changed_specs_are_updated(): void
    {
        $sitemap = '<urlset><url><loc>https://www.yonex.com/astrox-99-pro</loc></url></urlset>';
        $run = function (string $weight): array {
            Http::swap(new Factory);
            Http::fake([
                '*robots.txt' => Http::response('User-agent: *', 200, ['Content-Type' => 'text/plain']),
                '*sitemap.xml' => Http::response('<urlset><url><loc>https://www.yonex.com/astrox-99-pro</loc></url></urlset>', 200, ['Content-Type' => 'application/xml']),
                '*astrox-99-pro' => Http::response($this->yonexPage('ASTROX 99 PRO', $weight), 200, ['Content-Type' => 'text/html']),
            ]);

            return $this->importer()->import('yonex', $this->brand(), 5, null, 0);
        };

        $this->assertSame(1, $run('3U')['saved']);
        $again = $run('3U');
        $this->assertSame([0, 1], [$again['saved'], $again['unchanged']]);
        $this->assertSame(1, $run('4U')['saved']);
        $this->assertSame(1, RacketSpec::count());
        $this->assertSame('4U', RacketSpec::first()->specs_json['Weight / Grip']);
        $this->assertNotEmpty($sitemap);
    }

    public function test_limit_and_dry_run(): void
    {
        $urls = '';
        foreach (['a', 'b', 'c'] as $s) {
            $urls .= "<url><loc>https://www.yonex.com/racket-{$s}</loc></url>";
        }
        Http::swap(new Factory);
        Http::fake([
            '*robots.txt' => Http::response('', 200, ['Content-Type' => 'text/plain']),
            '*sitemap.xml' => Http::response("<urlset>{$urls}</urlset>", 200, ['Content-Type' => 'application/xml']),
            '*racket-*' => Http::response($this->yonexPage(), 200, ['Content-Type' => 'text/html']),
        ]);

        $report = $this->importer()->import('yonex', $this->brand(), 2, null, 0, true);

        $this->assertSame(2, $report['fetched'], 'the page cap is honoured');
        $this->assertSame(0, RacketSpec::count(), 'dry run stores nothing');
    }

    public function test_a_failing_sitemap_is_reported_not_thrown(): void
    {
        Http::swap(new Factory);
        Http::fake(['*robots.txt' => Http::response('', 200, ['Content-Type' => 'text/plain']), '*' => Http::response('nope', 500)]);

        $report = $this->importer()->import('yonex', $this->brand(), 5, null, 0);

        $this->assertNotEmpty($report['errors']);
        $this->assertSame(0, $report['saved']);
    }
}
