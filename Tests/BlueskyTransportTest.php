<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Notifier\Bridge\Bluesky\Tests;

use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Notifier\Bridge\Bluesky\BlueskyTransport;
use Symfony\Component\Notifier\Exception\LogicException;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Test\TransportTestCase;
use Symfony\Component\Notifier\Tests\Transport\DummyMessage;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class BlueskyTransportTest extends TransportTestCase
{
    public static function createTransport(?HttpClientInterface $client = null): BlueskyTransport
    {
        $blueskyTransport = new BlueskyTransport('username', 'password', new NullLogger(), $client ?? new MockHttpClient());
        $blueskyTransport->setHost('bsky.social');

        return $blueskyTransport;
    }

    public static function toStringProvider(): iterable
    {
        yield ['bluesky://bsky.social', self::createTransport()];
    }

    public static function supportedMessagesProvider(): iterable
    {
        yield [new ChatMessage('Hello!')];
    }

    public static function unsupportedMessagesProvider(): iterable
    {
        yield [new SmsMessage('+33612345678', 'Hello!')];
        yield [new DummyMessage()];
    }

    public function testExceptionIsThrownWhenNoMessageIsSent()
    {
        $transport = self::createTransport();

        $this->expectException(LogicException::class);
        $transport->send($this->createMock(MessageInterface::class));
    }

    /**
     * Example from
     * - https://atproto.com/blog/create-post
     * - https://github.com/bluesky-social/atproto-website/blob/main/examples/create_bsky_post.py.
     */
    public function testParseFacets()
    {
        $input = '✨ example mentioning @atproto.com the URL 👨‍❤️‍👨 https://en.wikipedia.org/wiki/CBOR.';
        $expected =
            [
                [
                    'index' => ['byteStart' => 23, 'byteEnd' => 35],
                    'features' => [
                        ['$type' => 'app.bsky.richtext.facet#mention', 'did' => 'did=>plc=>ewvi7nxzyoun6zhxrhs64oiz'],
                    ],
                ],
                [
                    'index' => ['byteStart' => 65, 'byteEnd' => 99],
                    'features' => [
                        ['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://en.wikipedia.org/wiki/CBOR'],
                    ],
                ],
            ];
        $output = $this->parseFacets($input, new MockHttpClient(new JsonMockResponse(['did' => 'did=>plc=>ewvi7nxzyoun6zhxrhs64oiz'])));
        $this->assertEquals($expected, $output);
    }

    /**
     * Example from https://github.com/bluesky-social/atproto-website/blob/main/examples/create_bsky_post.py.
     */
    public function testParseFacetsMultipleHandles()
    {
        $input = 'prefix @handle.example.com @handle.com suffix';
        $expected = [
            [
                'index' => ['byteStart' => 7, 'byteEnd' => 26],
                'features' => [
                    ['$type' => 'app.bsky.richtext.facet#mention', 'did' => 'did1'],
                ],
            ],
            [
                'index' => ['byteStart' => 27, 'byteEnd' => 38],
                'features' => [
                    ['$type' => 'app.bsky.richtext.facet#mention', 'did' => 'did2'],
                ],
            ],
        ];
        $output = $this->parseFacets($input, new MockHttpClient([new JsonMockResponse(['did' => 'did1']), new JsonMockResponse(['did' => 'did2'])]));
        $this->assertEquals($expected, $output);
    }

    /**
     * Example from https://github.com/bluesky-social/atproto-website/blob/main/examples/create_bsky_post.py.
     */
    public function testParseFacetsNoHandles()
    {
        $input = 'handle.example.com';
        $expected = [];
        $output = $this->parseFacets($input, new MockHttpClient([new JsonMockResponse(['did' => 'no_value'])]));
        $this->assertEquals($expected, $output);
    }

    /**
     * Example from https://github.com/bluesky-social/atproto-website/blob/main/examples/create_bsky_post.py.
     */
    public function testParseFacetsInvalidHandle()
    {
        $input = '@bare';
        $expected = [];
        $output = $this->parseFacets($input, new MockHttpClient([new JsonMockResponse(['did' => 'no_value'])]));
        $this->assertEquals($expected, $output);

        $input = 'email@example.com';
        $expected = [];
        $output = $this->parseFacets($input, new MockHttpClient([new JsonMockResponse(['did' => 'no_value'])]));
        $this->assertEquals($expected, $output);
    }

    /**
     * Example from https://github.com/bluesky-social/atproto-website/blob/main/examples/create_bsky_post.py.
     */
    public function testParseFacetsMentionWithEmoji()
    {
        $input = '💩💩💩 @handle.example.com';
        $expected = [
            [
                'index' => ['byteStart' => 13, 'byteEnd' => 32],
                'features' => [
                    ['$type' => 'app.bsky.richtext.facet#mention', 'did' => 'did0'],
                ],
            ],
        ];
        $output = $this->parseFacets($input, new MockHttpClient([new JsonMockResponse(['did' => 'did0'])]));
        $this->assertEquals($expected, $output);
    }

    /**
     * Example from https://github.com/bluesky-social/atproto-website/blob/main/examples/create_bsky_post.py.
     */
    public function testParseFacetsWithEmail()
    {
        $input = 'cc:@example.com';
        $expected = [
            [
                'index' => ['byteStart' => 3, 'byteEnd' => 15],
                'features' => [
                    ['$type' => 'app.bsky.richtext.facet#mention', 'did' => 'did0'],
                ],
            ],
        ];
        $output = $this->parseFacets($input, new MockHttpClient([new JsonMockResponse(['did' => 'did0'])]));
        $this->assertEquals($expected, $output);
    }

    /**
     * Example from https://github.com/bluesky-social/atproto-website/blob/main/examples/create_bsky_post.py.
     */
    public function testParseFacetsUrl()
    {
        $input = 'prefix https://example.com/index.html http://bsky.app suffix';
        $expected = [
            [
                'index' => ['byteStart' => 7, 'byteEnd' => 37],
                'features' => [
                    ['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://example.com/index.html'],
                ],
            ],
            [
                'index' => ['byteStart' => 38, 'byteEnd' => 53],
                'features' => [
                    ['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'http://bsky.app'],
                ],
            ],
        ];
        $output = $this->parseFacets($input);
        $this->assertEquals($expected, $output);
    }

    /**
     * Example from https://github.com/bluesky-social/atproto-website/blob/main/examples/create_bsky_post.py.
     */
    public function testParseFacetsNoUrls()
    {
        $input = 'example.com';
        $expected = [];
        $output = $this->parseFacets($input);
        $this->assertEquals($expected, $output);

        $input = 'runonhttp://blah.comcontinuesafter';
        $expected = [];
        $output = $this->parseFacets($input);
        $this->assertEquals($expected, $output);
    }

    /**
     * Example from https://github.com/bluesky-social/atproto-website/blob/main/examples/create_bsky_post.py.
     */
    public function testParseFacetsUrlWithEmoji()
    {
        $input = '💩💩💩 http://bsky.app';
        $expected = [
            [
                'index' => ['byteStart' => 13, 'byteEnd' => 28],
                'features' => [
                    ['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'http://bsky.app']],
            ],
        ];
        $output = $this->parseFacets($input);
        $this->assertEquals($expected, $output);
    }

    /**
     * Example from https://github.com/bluesky-social/atproto-website/blob/main/examples/create_bsky_post.py.
     */
    public function testParseFacetsUrlWithTrickyRegex()
    {
        $input = 'ref [https://bsky.app]';
        $expected = [
            [
                'index' => ['byteStart' => 5, 'byteEnd' => 21],
                'features' => [
                    ['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://bsky.app']],
            ],
        ];
        $this->assertEquals($expected, $this->parseFacets($input));

        $input = 'ref (https://bsky.app/)';
        $expected = [
            [
                'index' => ['byteStart' => 5, 'byteEnd' => 22],
                'features' => [
                    ['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://bsky.app/']],
            ],
        ];
        $this->assertEquals($expected, $this->parseFacets($input));

        $input = 'ends https://bsky.app. what else?';
        $expected = [
            [
                'index' => ['byteStart' => 5, 'byteEnd' => 21],
                'features' => [
                    ['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://bsky.app']],
            ],
        ];
        $this->assertEquals($expected, $this->parseFacets($input));
    }

    /**
     * A small helper function to test BlueskyTransport::parseFacets().
     */
    private function parseFacets(string $input, ?HttpClientInterface $httpClient = null): array
    {
        $class = new \ReflectionClass(BlueskyTransport::class);
        $method = $class->getMethod('parseFacets');
        $method->setAccessible(true);

        $object = $class->newInstance('user', 'pass', new NullLogger(), $httpClient ?? new MockHttpClient([]));

        return $method->invoke($object, $input);
    }
}
