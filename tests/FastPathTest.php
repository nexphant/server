<?php

namespace Nexph\Tests\Server;

use Nexph\Server\FastPathRegistry;
use Nexph\Server\RawResponseBuilder;
use Nexph\Server\BufferSlab;
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

    public function testBufferSlabOffsetOptimization() {
        $slab = new BufferSlab();
        $slab->append('GET /path HTTP/1.1');
        $this->assertEquals(18, $slab->length());
        $slab->consume(4);
        $this->assertEquals(14, $slab->length());
        $this->assertEquals('/path HTTP/1.1', $slab->get());
        $slab->consume(6);
        $this->assertEquals(8, $slab->length());
        $this->assertEquals('HTTP/1.1', $slab->get());
    }

    public function testBufferSlabCompaction() {
        $slab = new BufferSlab();
        $data = str_repeat('x', 10000);
        $slab->append($data);
        $slab->consume(9000);
        $this->assertEquals(1000, $slab->length());
        $slab->append('more');
        $this->assertEquals(1004, $slab->length());
    }
}
