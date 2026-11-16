<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Front;

use Flytachi\Winter\Kernel\App\FrontSite;
use Flytachi\Winter\Kernel\Http\Front\StaticFiles;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FakeRequest;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FakeResponse;
use PHPUnit\Framework\TestCase;

/** A frontend's built files, as the HTTP server sends them. */
final class StaticFilesTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wk_static_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/spa/_nuxt', 0777, true);
        mkdir($this->dir . '/ssg/about', 0777, true);
        file_put_contents($this->dir . '/spa/200.html', '<html>shell</html>');
        file_put_contents($this->dir . '/spa/_nuxt/app.123.js', 'console.log(1)');
        file_put_contents($this->dir . '/spa/_nuxt/app.123.js.br', 'BROTLI');
        file_put_contents($this->dir . '/spa/favicon.svg', '<svg/>');
        file_put_contents($this->dir . '/ssg/index.html', '<html>home</html>');
        file_put_contents($this->dir . '/ssg/about/index.html', '<html>about</html>');
        file_put_contents($this->dir . '/secret', 'outside the build');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function spa(mixed ...$more): FrontSite
    {
        return new FrontSite(...['name' => 'frontend', 'root' => $this->dir . '/spa', 'assets' => '/_nuxt', ...$more]);
    }

    /** @param array<string, string> $headers */
    private function serve(FrontSite $site, string $path, array $headers = [], string $method = 'GET', bool $shell = true): FakeResponse
    {
        $response = new FakeResponse();
        StaticFiles::serve($site, new FakeRequest($method, $path, $headers), $response, $shell);
        return $response;
    }

    public function test_an_existing_file_is_sent_with_its_type(): void
    {
        $response = $this->serve($this->spa(), '/favicon.svg');

        self::assertSame(realpath($this->dir . '/spa/favicon.svg'), $response->sentFile);
        self::assertSame('image/svg+xml', $response->header_('Content-Type'));
        self::assertSame('nosniff', $response->header_('X-Content-Type-Options'));
    }

    public function test_hashed_assets_are_immutable_and_the_rest_no_cache(): void
    {
        self::assertSame('public, max-age=31536000, immutable', $this->serve($this->spa(), '/_nuxt/app.123.js')->header_('Cache-Control'));
        self::assertSame('text/javascript; charset=utf-8', $this->serve($this->spa(), '/_nuxt/app.123.js')->header_('Content-Type'));
        self::assertSame('no-cache', $this->serve($this->spa(), '/favicon.svg')->header_('Cache-Control'));
    }

    public function test_an_etag_match_answers_304_without_a_body(): void
    {
        $etag = (string) $this->serve($this->spa(), '/favicon.svg')->header_('ETag');
        $again = $this->serve($this->spa(), '/favicon.svg', ['If-None-Match' => $etag]);

        self::assertNotSame('', $etag);
        self::assertSame(304, $again->status);
        self::assertNull($again->sentFile);
        self::assertSame('', $again->body);
    }

    public function test_a_precompressed_file_is_sent_when_accepted(): void
    {
        $response = $this->serve($this->spa(), '/_nuxt/app.123.js', ['Accept-Encoding' => 'gzip, br']);

        self::assertSame(realpath($this->dir . '/spa/_nuxt/app.123.js.br'), $response->sentFile);
        self::assertSame('br', $response->header_('Content-Encoding'));
        self::assertSame('Accept-Encoding', $response->header_('Vary'));
        self::assertSame('text/javascript; charset=utf-8', $response->header_('Content-Type'), 'the type of the original');
    }

    public function test_a_missing_hashed_asset_is_404_no_store(): void
    {
        $response = $this->serve($this->spa(), '/_nuxt/old.999.js');

        self::assertSame(404, $response->status);
        self::assertSame('no-store', $response->header_('Cache-Control'));
        self::assertNull($response->sentFile, 'not the shell under a script name');
    }

    public function test_an_unknown_page_gets_the_shell_when_allowed(): void
    {
        $response = $this->serve($this->spa(), '/employees/5');

        self::assertSame(realpath($this->dir . '/spa/200.html'), $response->sentFile);
        self::assertSame('no-cache', $response->header_('Cache-Control'));
        self::assertSame('text/html; charset=utf-8', $response->header_('Content-Type'));
    }

    public function test_an_unknown_page_is_404_when_the_shell_is_not_allowed(): void
    {
        $response = $this->serve($this->spa(), '/employees/5', shell: false);

        self::assertSame(404, $response->status);
        self::assertNull($response->sentFile);
    }

    public function test_ssg_pages_resolve_to_their_index(): void
    {
        $site = new FrontSite('site', $this->dir . '/ssg', shell: null);

        self::assertSame(realpath($this->dir . '/ssg/index.html'), $this->serve($site, '/')->sentFile);
        self::assertSame(realpath($this->dir . '/ssg/about/index.html'), $this->serve($site, '/about')->sentFile);
        self::assertSame(realpath($this->dir . '/ssg/about/index.html'), $this->serve($site, '/about/')->sentFile);
        self::assertSame(404, $this->serve($site, '/nope')->status, 'no shell: a page of its own or 404');
    }

    public function test_a_site_without_a_build_answers_503(): void
    {
        $response = $this->serve(new FrontSite('frontend', $this->dir . '/none'), '/');

        self::assertSame(503, $response->status);
        self::assertSame('5', $response->header_('Retry-After'));
        self::assertSame('the frontend is not built', $response->body);
    }

    public function test_a_site_under_a_path_strips_it(): void
    {
        $site = $this->spa(path: '/admin');

        self::assertSame(realpath($this->dir . '/spa/favicon.svg'), $this->serve($site, '/admin/favicon.svg')->sentFile);
        self::assertSame(realpath($this->dir . '/spa/200.html'), $this->serve($site, '/admin')->sentFile);
    }

    public function test_nothing_outside_the_build_is_ever_sent(): void
    {
        symlink($this->dir . '/secret', $this->dir . '/spa/link.txt');

        foreach (['/../secret', '/%2e%2e/secret', '/_nuxt/..%2f..%2fsecret', "/a%00.js", '/link.txt', '/..\\secret'] as $path) {
            $response = $this->serve($this->spa(), $path, shell: false);
            self::assertNull($response->sentFile, $path);
            self::assertSame(404, $response->status, $path);
        }
    }

    public function test_a_rebuilt_file_changes_its_etag(): void
    {
        $file = $this->dir . '/spa/favicon.svg';
        $before = (string) $this->serve($this->spa(), '/favicon.svg')->header_('ETag');

        file_put_contents($file, '<svg version="2"/>');
        touch($file, time() + 5);
        clearstatcache();
        $after = $this->serve($this->spa(), '/favicon.svg', ['If-None-Match' => $before]);

        self::assertNotSame($before, $after->header_('ETag'));
        self::assertNotSame(304, $after->status);
        self::assertSame(realpath($file), $after->sentFile);
    }

    public function test_head_announces_the_length(): void
    {
        $response = $this->serve($this->spa(), '/favicon.svg', method: 'HEAD');

        self::assertSame((string) strlen('<svg/>'), $response->header_('Content-Length'));
        self::assertSame('image/svg+xml', $response->header_('Content-Type'));
    }

    public function test_a_file_added_by_a_rebuild_is_found_once_the_marker_changes(): void
    {
        self::assertSame(realpath($this->dir . '/spa/200.html'), $this->serve($this->spa(), '/new.js', shell: true)->sentFile, 'not there yet: the shell');

        file_put_contents($this->dir . '/spa/new.js', 'new');
        file_put_contents($this->dir . '/spa/200.html', '<html>rebuilt</html>');
        touch($this->dir . '/spa/200.html', time() + 10);
        clearstatcache();

        self::assertSame(realpath($this->dir . '/spa/new.js'), $this->serve($this->spa(), '/new.js')->sentFile);
    }

    public function test_a_build_removed_while_serving_answers_503(): void
    {
        self::assertNotNull($this->serve($this->spa(), '/favicon.svg')->sentFile);

        exec('rm -rf ' . escapeshellarg($this->dir . '/spa'));
        clearstatcache();

        self::assertSame(503, $this->serve($this->spa(), '/favicon.svg')->status);
    }

    public function test_head_under_accept_encoding_keeps_its_length(): void
    {
        $response = $this->serve($this->spa(), '/favicon.svg', ['Accept-Encoding' => 'gzip, br'], 'HEAD');

        self::assertSame((string) strlen('<svg/>'), $response->header_('Content-Length'));
        self::assertSame('identity', $response->header_('Content-Encoding'), 'or Swoole drops the length it was given');
    }

    public function test_an_unreadable_folder_is_skipped(): void
    {
        mkdir($this->dir . '/spa/locked');
        file_put_contents($this->dir . '/spa/locked/x.js', 'x');
        chmod($this->dir . '/spa/locked', 0000);
        try {
            $response = $this->serve($this->spa(), '/favicon.svg');
        } finally {
            chmod($this->dir . '/spa/locked', 0755);
        }

        self::assertSame(realpath($this->dir . '/spa/favicon.svg'), $response->sentFile);
    }

    public function test_a_build_still_being_written_is_walked_again(): void
    {
        self::assertNotNull($this->serve($this->spa(), '/favicon.svg')->sentFile, 'the index is built now');

        file_put_contents($this->dir . '/spa/late.js', 'written after the marker');
        sleep(2);

        self::assertSame(realpath($this->dir . '/spa/late.js'), $this->serve($this->spa(), '/late.js', shell: false)->sentFile);
    }
}
