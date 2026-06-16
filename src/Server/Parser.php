<?php

namespace Nexphant\Server\Server;

class Parser {
    public static function parseRequest(string $buffer): ?array {
        return \Nexphant\Server\HttpParser::parseRequest($buffer);
    }

    public static function peekPayloadLength(string $buffer): ?int {
        return \Nexphant\Server\WebSocket::peekPayloadLength($buffer);
    }

    public static function decodeWebSocketFrame(string &$buffer): ?array {
        return \Nexphant\Server\WebSocket::decode($buffer);
    }
}
