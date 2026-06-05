<?php

namespace Nexph\Server\Server;

class Parser {
    public static function parseRequest(string $buffer): ?array {
        return \Nexph\Server\HttpParser::parseRequest($buffer);
    }

    public static function peekPayloadLength(string $buffer): ?int {
        return \Nexph\Server\WebSocket::peekPayloadLength($buffer);
    }

    public static function decodeWebSocketFrame(string &$buffer): ?array {
        return \Nexph\Server\WebSocket::decode($buffer);
    }
}
