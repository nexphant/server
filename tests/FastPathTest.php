<?php

namespace Nexph\Tests\Server;

use Nexph\Server\FastPathRegistry;
use Nexph\Server\RawResponseBuilder;
use PHPUnit\Framework\TestCase;

class FastPathTest extends TestCase {
    public function testRawResponseBuilderJson() {
        $response = RawResponseBuilder::json(200, ['status' => 'ok']);
        $this->assertStringContainsString('HTTP/1.1 200 OK', $response);
        $this->assertStringContainsString('Content-Type: application/json', $response);
        $this->assertStringContainsString('Content-Length: 15', $response);
        $this->assertStringContainsString('Connection: keep-alive', $response);
        $this->assertStringContainsString('{"status":"ok"}', $response);
    }

    public function testRawResponseBuilderText() {
        $response = RawResponseBuilder::text(200, 'hello');
        $this->assertStringContainsString('HTTP/1.1 200 OK', $response);
        $this->assertStringContainsString('Content-Type: text/plain', $response);
        $this->assertStringContainsString('Content-Length: 5', $response);
        $this->assertStringContainsString('Connection: keep-alive', $response);
        $this->assertStringContainsString('hello', $response);
    }

    public function testFastPathRegistry() {
        $registry = new FastPathRegistry();
        $rawResponse = "HTTP/1.1 200 OK\r\n\r\n";
        $registry->register('GET', '/test', $rawResponse);
        $this->assertTrue($registry->has('GET', '/test'));
        $this->assertEquals($rawResponse, $registry->get('GET', '/test'));
        $this->assertNull($registry->get('GET', '/other'));
        $this->assertFalse($registry->has('POST', '/test'));
    }
}
