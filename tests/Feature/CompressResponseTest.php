<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CompressResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_test/big', fn () => response(str_repeat('<p>لیست حقوق</p>', 500)));
        Route::middleware('web')->get('/_test/small', fn () => response('<p>ok</p>'));
        Route::middleware('web')->get('/_test/json', fn () => response()->json(['rows' => array_fill(0, 300, 'ردیف')]));
        Route::middleware('web')->get('/_test/file', fn () => response(str_repeat('a', 5000), 200, ['Content-Type' => 'text/csv']));
    }

    public function test_large_html_and_json_are_gzipped_for_browsers_that_accept_it(): void
    {
        $response = $this->get('/_test/big', ['Accept-Encoding' => 'gzip, deflate, br']);
        $response->assertOk()->assertHeader('Content-Encoding', 'gzip');
        $this->assertStringContainsString('Accept-Encoding', (string) $response->headers->get('Vary'));
        $this->assertSame(str_repeat('<p>لیست حقوق</p>', 500), gzdecode($response->getContent()));
        $this->assertSame((string) strlen($response->getContent()), $response->headers->get('Content-Length'));

        $json = $this->get('/_test/json', ['Accept-Encoding' => 'gzip']);
        $json->assertHeader('Content-Encoding', 'gzip');
        $this->assertCount(300, json_decode(gzdecode($json->getContent()), true)['rows']);
    }

    public function test_left_alone_without_gzip_support_small_bodies_other_types_or_when_disabled(): void
    {
        $this->get('/_test/big')->assertHeaderMissing('Content-Encoding');
        $this->get('/_test/small', ['Accept-Encoding' => 'gzip'])->assertHeaderMissing('Content-Encoding');
        $this->get('/_test/file', ['Accept-Encoding' => 'gzip'])->assertHeaderMissing('Content-Encoding');

        config(['tuka.gzip' => false]);
        $this->get('/_test/big', ['Accept-Encoding' => 'gzip'])->assertHeaderMissing('Content-Encoding');
    }
}
