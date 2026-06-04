<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) Nexphlabs <https://github.com/nexphlabs>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexph\Server;

class ServerTUI {
    private static bool $isMainProcess = false;
    private static int $workerId = 0;
    private static int $workerCount = 1;
    private static bool $enabled = true;
    private static int $startTime = 0;
    private static string $host = '0.0.0.0';
    private static int $port = 8080;
    private static int $logCount = 0;

    /** inner width of the ╔═╗ box */
    private const W = 55;

    public static function setMainProcess(bool $isMain): void {
        self::$isMainProcess = $isMain;
    }

    public static function setWorkerInfo(int $workerId, int $workerCount): void {
        self::$workerId = $workerId;
        self::$workerCount = $workerCount;
    }

    public static function setEnabled(bool $enabled): void {
        self::$enabled = $enabled;
    }

    public static function serverStarted(string $host, int $port): void {
        if (!self::$enabled) return;
        if (self::$workerId !== 1) return;
        self::$startTime = time();
        self::$host = $host;
        self::$port = $port;
        self::$logCount = 0;
        self::clear();
        echo "\n";
        self::printTerminalPanel();
        echo "\n";
    }

    public static function supervisorStarted(int $workers): void {
        if (!self::$enabled || !self::$isMainProcess) return;
        self::$workerCount = $workers;
    }

    /** called every second by the event loop */
    public static function tick(): void {
        if (!self::$enabled || self::$startTime === 0) return;
        if (self::$workerId !== 1) return;
        if (!self::isTerminal()) return;

        $uptime = time() - self::$startTime;

        // uptime line is 6 rows above the anchor (position right after panel)
        // each log adds 1 row below anchor
        $up = 6 + self::$logCount;

        echo "\033[s";                   // save current cursor
        echo "\033[{$up}A";              // move up to uptime line
        echo "\033[K";                   // clear line
        echo self::renderUptimeLine($uptime);
        echo "\033[u";                   // restore cursor
    }

    public static function log(string $message, string $level = 'info'): void {
        if (!self::$enabled) return;
        if (self::$workerId !== 1 && $level !== 'error') return;

        $time = date('H:i:s');
        $icon = match($level) {
            'error' => self::colorize('✗', 'red'),
            'warn'  => self::colorize('⚠', 'yellow'),
            'success' => self::colorize('✓', 'green'),
            default => self::colorize('·', 'gray'),
        };

        echo "  {$icon} " . self::colorize($time, 'gray') . " {$message}\n";
        self::$logCount++;
    }

    // ── box primitives ──

    private static function top(): string {
        return '  ╔' . str_repeat('═', self::W) . '╗';
    }
    private static function mid(): string {
        return '  ║' . str_repeat(' ', self::W) . '║';
    }
    private static function bot(): string {
        return '  ╚' . str_repeat('═', self::W) . '╝';
    }

    /** line with exact inner content (plain must be ≤ W) */
    private static function line(string $content): string {
        $plain = preg_replace('/\033\[[0-9;]*m/', '', $content);
        $pad = max(0, self::W - mb_strlen($plain));
        return '  ║' . $content . str_repeat(' ', $pad) . '║';
    }

    // ── uptime rendering (shared by init + live tick) ──

    private static function renderUptimeLine(int $uptimeSeconds): string {
        $uptimeStr = self::formatUptime($uptimeSeconds);
        $dot   = self::colorize('●', 'green');
        $col4  = self::colorize('Uptime', 'cyan');
        $val4  = self::colorize("  {$uptimeStr}", 'white', true);
        $col4b = self::colorize('Mode', 'cyan');
        $val4b = self::colorize('  prod', 'white', true);
        return self::line("  {$dot}  " . self::renderTwoCol($col4, $val4, $col4b, $val4b)) . "\n";
    }

    private static function renderTwoCol(string $aLabel, string $aVal, string $bLabel, string $bVal): string {
        $aPlain = preg_replace('/\033\[[0-9;]*m/', '', $aLabel . $aVal);
        $bPlain = preg_replace('/\033\[[0-9;]*m/', '', $bLabel . $bVal);
        $aWidth = mb_strlen($aPlain);
        $gap = 2;
        $leftBlock = 30;
        $aPad = max(0, $leftBlock - $aWidth);
        return $aLabel . $aVal . str_repeat(' ', $aPad + $gap) . $bLabel . $bVal;
    }

    private static function formatUptime(int $sec): string {
        if ($sec < 60) return "{$sec}s";
        $m = intdiv($sec, 60);
        $s = $sec % 60;
        if ($m < 60) return "{$m}m {$s}s";
        $h = intdiv($m, 60);
        $m %= 60;
        return "{$h}h {$m}m";
    }

    // ── main panel ──

    private static function printTerminalPanel(): void {
        $logo = [
            '     ███╗   ██╗███████╗██╗  ██╗██████╗ ██╗  ██╗      ',
            '     ████╗  ██║██╔════╝╚██╗██╔╝██╔══██╗██║  ██║      ',
            '     ██╔██╗ ██║█████╗   ╚███╔╝ ██████╔╝███████║      ',
            '     ██║╚██╗██║██╔══╝   ██╔██╗ ██╔═══╝ ██╔══██║      ',
            '     ██║ ╚████║███████╗██╔╝ ██╗██║     ██║  ██║      ',
            '     ╚═╝  ╚═══╝╚══════╝╚═╝  ╚═╝╚═╝     ╚═╝  ╚═╝      ',
        ];

        $dot  = self::colorize('●', 'green');
        $col1 = self::colorize('Status', 'cyan');
        $val1 = self::colorize('Online', 'green', true);
        $col2 = self::colorize('Address', 'cyan');
        $url  = self::colorize('http://' . self::$host . ':' . self::$port, 'green', true);
        $col3 = self::colorize('Workers', 'cyan');
        $val3 = self::colorize((string) self::$workerCount, 'white', true);
        $col3b = self::colorize('PID', 'cyan');
        $val3b = self::colorize((string) getmypid(), 'white', true);
        $col4b = self::colorize('Mode', 'cyan');
        $val4b = self::colorize('prod', 'white', true);

        echo self::top() . "\n";
        echo self::mid() . "\n";
        foreach ($logo as $l) {
            echo self::line(self::colorize($l, 'cyan', true)) . "\n";
        }
        echo self::mid() . "\n";
        echo self::line("  {$dot}  {$col1}  {$val1}") . "\n";
        echo self::line("  {$dot}  {$col2}  {$url}") . "\n";
        echo self::line("  {$dot}  " . self::renderTwoCol($col3, "  {$val3}", $col3b, "  {$val3b}")) . "\n";
        echo self::renderUptimeLine(0); // initial, tick() updates live
        echo self::mid() . "\n";
        echo self::bot() . "\n";
        echo "\n";
        echo '  ' . self::colorize('Press Ctrl+C to stop the server', 'gray') . "\n";
    }

    private static function clear(): void {
        if (self::isTerminal()) echo "\033[2J\033[H";
    }

    private static function colorize(string $text, string $color, bool $bold = false): string {
        if (!self::isTerminal()) return $text;
        $colors = [
            'black'=>'30','red'=>'31','green'=>'32','yellow'=>'33',
            'blue'=>'34','magenta'=>'35','cyan'=>'36','gray'=>'37','white'=>'97',
        ];
        $code = $colors[$color] ?? '37';
        return "\033[" . ($bold ? '1;' : '') . $code . 'm' . $text . "\033[0m";
    }

    private static function isTerminal(): bool {
        return function_exists('posix_isatty') && @posix_isatty(STDOUT);
    }
}
