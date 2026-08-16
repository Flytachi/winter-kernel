<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Response;

use Flytachi\Winter\Kernel\Core\KernelConfig;
use Flytachi\Winter\Kernel\Http\Response\RenderContext;
use Flytachi\Winter\Kernel\Http\Response\ResponseView;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FakeRequest;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FakeResponse;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * What a template actually sees while {@see ResponseView} renders it.
 *
 * The `wr*` helpers are free functions, so the only way they can reach the render in
 * progress is {@see RenderContext}. These tests drive a full send() and assert on the
 * HTML the client would receive — the helpers, the $data variables, and the fact that
 * the context does not outlive the response.
 */
final class ResponseViewRenderTest extends TestCase
{
    private string $root = '';
    private ?string $originalRoot = null;

    protected function setUp(): void
    {
        $prop = new ReflectionProperty(KernelConfig::class, 'pathResource');
        $this->originalRoot = $prop->isInitialized() ? $prop->getValue() : null;

        $this->root = sys_get_temp_dir() . '/wk_render_' . getmypid() . '_' . bin2hex(random_bytes(4));
        @mkdir($this->root . '/views/layouts', 0777, true);
        @mkdir($this->root . '/views/user', 0777, true);
        @mkdir($this->root . '/views/partials', 0777, true);

        file_put_contents(
            $this->root . '/views/user/profile.php',
            '<p><?= $name ?></p><?php wrImport("partials/badge"); ?>'
        );
        file_put_contents(
            $this->root . '/views/partials/badge.php',
            '<b><?= $name ?>:<?= implode(",", array_keys($data)) ?></b>'
        );
        file_put_contents(
            $this->root . '/views/layouts/main.php',
            '<html><title><?= wrData("name") ?></title>'
            . '<a class="<?= wrIsActiveLink("/user/7") ?>">x</a>'
            . '<?php wrContent(); ?></html>'
        );
        file_put_contents(
            $this->root . '/views/user/boom.php',
            'half<?php throw new \RuntimeException("view blew up"); ?>'
        );
        // Keys named after the framework's own locals — see the collision test below.
        $echoLocals = 'path=<?= $path ?> resourceName=<?= $resourceName ?> filePath=<?= $filePath ?>';
        file_put_contents(
            $this->root . '/views/user/locals.php',
            'PAGE ' . $echoLocals . '<?php wrImport("partials/locals"); ?>'
        );
        file_put_contents($this->root . '/views/partials/locals.php', ' PARTIAL ' . $echoLocals);

        KernelConfig::$pathResource = $this->root;
        ResponseView::setBasePath('');
    }

    protected function tearDown(): void
    {
        foreach (
            [
                '/views/user/profile.php',
                '/views/user/boom.php',
                '/views/user/locals.php',
                '/views/partials/badge.php',
                '/views/partials/locals.php',
                '/views/layouts/main.php',
            ] as $file
        ) {
            @unlink($this->root . $file);
        }
        foreach (['/views/layouts', '/views/partials', '/views/user', '/views'] as $dir) {
            @rmdir($this->root . $dir);
        }
        @rmdir($this->root);

        ResponseView::setBasePath('');
        if ($this->originalRoot !== null) {
            KernelConfig::$pathResource = $this->originalRoot;
        }
    }

    private function send(ResponseView $view, string $uri = '/user/7'): FakeResponse
    {
        $response = new FakeResponse();
        $view->send($response, new FakeRequest('GET', $uri));

        return $response;
    }

    public function test_a_page_renders_with_its_data_as_variables(): void
    {
        $response = $this->send(ResponseView::view('user/profile', ['name' => 'Ada']));

        self::assertStringContainsString('<p>Ada</p>', (string) $response->body);
        self::assertSame('text/html; charset=utf-8', $response->header_('Content-Type'));
    }

    public function test_a_layout_wraps_the_page_through_wr_content(): void
    {
        $response = $this->send(ResponseView::render('layouts/main', 'user/profile', ['name' => 'Ada']));

        self::assertStringContainsString('<title>Ada</title>', (string) $response->body, 'wrData() in the layout');
        self::assertStringContainsString('<p>Ada</p>', (string) $response->body, 'the page, via wrContent()');
    }

    /**
     * Regression: extract() takes its array by reference, so extracting $this->data —
     * a readonly property — is a fatal error, and every wrImport() hit it.
     */
    public function test_an_imported_partial_sees_the_same_data(): void
    {
        $response = $this->send(ResponseView::view('user/profile', ['name' => 'Ada', 'n' => 7]));

        self::assertStringContainsString('<b>Ada:name,n</b>', (string) $response->body);
    }

    public function test_wr_is_active_link_follows_the_request_uri(): void
    {
        $matching = $this->send(ResponseView::render('layouts/main', 'user/profile', ['name' => 'Ada']), '/user/7');
        $other    = $this->send(ResponseView::render('layouts/main', 'user/profile', ['name' => 'Ada']), '/elsewhere');

        self::assertStringContainsString('class="active"', (string) $matching->body);
        self::assertStringContainsString('class=""', (string) $other->body);
    }

    /**
     * An include inherits every local of the method it sits in, and EXTR_SKIP refuses to
     * overwrite what is already there — so a $data key colliding with one of those locals
     * used to be replaced, silently, by a filesystem path. Which keys were unusable also
     * differed between a page and a partial.
     */
    public function test_data_keys_named_after_framework_locals_survive(): void
    {
        $response = $this->send(ResponseView::view('user/locals', [
            'path'         => '/settings/profile',
            'resourceName' => 'Profile',
            'filePath'     => '/uploads/report.pdf',
        ]));

        $expected = 'path=/settings/profile resourceName=Profile filePath=/uploads/report.pdf';
        self::assertSame("PAGE $expected PARTIAL $expected", (string) $response->body);
        self::assertStringNotContainsString($this->root, (string) $response->body, 'no server path leaks out');
    }

    public function test_the_context_does_not_outlive_the_response(): void
    {
        $this->send(ResponseView::view('user/profile', ['name' => 'Ada']));

        self::assertNull(RenderContext::current());
    }

    public function test_the_context_is_released_even_when_a_template_throws(): void
    {
        try {
            $this->send(ResponseView::view('user/boom'));
            self::fail('the template was expected to throw');
        } catch (\RuntimeException $e) {
            self::assertSame('view blew up', $e->getMessage());
        }

        self::assertNull(RenderContext::current(), 'a failed render must not leak its context');
    }
}
