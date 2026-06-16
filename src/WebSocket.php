<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace nexphant\Server;

class WebSocket
{
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    public const TEXT = 0x01;
    public const BINARY = 0x02;
    public const CLOSE = 0x08;
    public const PING = 0x09;
    public const PONG = 0x0A;

    public static function isUpgrade(ServerRequest $request): bool
    {
        return strtolower($request->header('upgrade', '')) === 'websocket' &&
            str_contains(strtolower($request->header('connection', '')), 'upgrade');
    }

    public static function handshake(ServerRequest $request, ServerResponse $response): bool
    {
        $key = $request->header('sec-websocket-key');
        if (!$key || strlen(base64_decode($key, true) ?: '') !== 16) {
            return false;
        }

        $response->status(101)
            ->header('Upgrade', 'websocket')
            ->header('Connection', 'Upgrade')
            ->header('Sec-WebSocket-Accept', base64_encode(sha1($key . self::GUID, true)));
        return true;
    }

    public static function encode(string $payload, int $opcode = self::TEXT): string
    {
        $len = strlen($payload);
        $frame = chr(0x80 | ($opcode & 0x0F));

        if ($len < 126) {
            return $frame . chr($len) . $payload;
        }
        if ($len < 65536) {
            return $frame . chr(126) . pack('n', $len) . $payload;
        }

        $hi = intdiv($len, 4294967296);
        $lo = $len % 4294967296;
        return $frame . chr(127) . pack('N2', $hi, $lo) . $payload;
    }

    public static function decode(string $data): ?array
    {
        $size = strlen($data);
        if ($size < 2) {
            return null;
        }

        $byte1 = ord($data[0]);
        $byte2 = ord($data[1]);
        $len = $byte2 & 0x7F;
        $offset = 2;

        if ($len === 126) {
            if ($size < 4)
                return null;
            $len = unpack('n', substr($data, 2, 2))[1];
            $offset = 4;
        } elseif ($len === 127) {
            if ($size < 10)
                return null;
            $parts = unpack('Nhi/Nlo', substr($data, 2, 8));
            $len = ($parts['hi'] * 4294967296) + $parts['lo'];
            $offset = 10;
        }

        $masked = ($byte2 & 0x80) !== 0;
        if ($masked) {
            if ($size < $offset + 4)
                return null;
            $mask = substr($data, $offset, 4);
            $offset += 4;
        } else {
            $mask = '';
        }

        if ($size < $offset + $len) {
            return null;
        }

        $payload = substr($data, $offset, $len);
        if ($masked) {
            for ($i = 0; $i < $len; $i++) {
                $payload[$i] = $payload[$i] ^ $mask[$i % 4];
            }
        }

        return [
            'fin' => ($byte1 & 0x80) !== 0,
            'opcode' => $byte1 & 0x0F,
            'payload' => $payload,
            'length' => $offset + $len,
        ];
    }

    public static function peekPayloadLength(string $data): ?int
    {
        $size = strlen($data);
        if ($size < 2) {
            return null;
        }

        $byte2 = ord($data[1]);
        $len = $byte2 & 0x7F;
        if ($len === 126) {
            if ($size < 4) {
                return null;
            }
            return unpack('n', substr($data, 2, 2))[1];
        }
        if ($len === 127) {
            if ($size < 10) {
                return null;
            }
            $parts = unpack('Nhi/Nlo', substr($data, 2, 8));
            return (int) (($parts['hi'] * 4294967296) + $parts['lo']);
        }

        return $len;
    }
}
