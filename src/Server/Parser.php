<?php

namespace nexphant\Server\Server;

class Parser {
    public static function parseRequest(string $buffer): ?array {
        return \nexphant\Server\HttpParser::parseRequest($buffer);
    }

    public static function peekPayloadLength(string $buffer): ?int {
        return \nexphant\Server\WebSocket::peekPayloadLength($buffer);
    }

    public static function decodeWebSocketFrame(string &$buffer): ?array {
        return \nexphant\Server\WebSocket::decode($buffer);
    }
}
