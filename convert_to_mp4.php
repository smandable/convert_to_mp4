#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * ============================================================
 *  Console helpers
 * ============================================================
 */
function stderr(string $msg): void
{
    // Ensure earlier STDOUT lines don't appear after this STDERR line
    if (is_resource(STDOUT)) {
        @fflush(STDOUT);
    }

    fwrite(STDERR, $msg . PHP_EOL);

    if (is_resource(STDERR)) {
        @fflush(STDERR);
    }
}

// Keep a bigger tail for logic (corruption sniffing), but print MUCH less.
const ERR_TAIL_BYTES     = 100000; // keep what we already agreed on
const ERR_CONSOLE_LINES  = 12;     // how many lines max to print
const ERR_CONSOLE_BYTES  = 4096;   // hard cap for terminal output

function compactErrForConsole(string $err): string
{
    $err = trim($err);
    if ($err === '') return '';

    $err = preg_replace("/\r\n?/", "\n", $err) ?? $err;
    $lines = array_values(array_filter(
        array_map('trim', explode("\n", $err)),
        fn($l) => $l !== ''
    ));
    if (!$lines) return '';

    // Prefer "interesting" lines if present (ffmpeg tends to spam repeated decode lines)
    $interestingRe = '/(header|damaged|invalid|error|failed|corrupt|decode|packet|moov|timestamp|truncat|end of file|could not|unsupported)/i';

    $picked = [];
    $prev = null;
    foreach ($lines as $ln) {
        if (preg_match($interestingRe, $ln)) {
            if ($ln !== $prev) $picked[] = $ln; // de-dupe consecutive repeats
            $prev = $ln;
        }
    }

    // If we didn’t find enough signal, just take the last N lines.
    if (count($picked) < 3) {
        $picked = array_slice($lines, -ERR_CONSOLE_LINES);
    } else {
        $picked = array_slice($picked, -ERR_CONSOLE_LINES);
    }

    // Enforce byte cap by dropping oldest lines first
    while (count($picked) > 1 && strlen(implode("\n", $picked)) > ERR_CONSOLE_BYTES) {
        array_shift($picked);
    }

    $out = implode("\n", $picked);
    if (strlen($out) > ERR_CONSOLE_BYTES) {
        $out = substr($out, -ERR_CONSOLE_BYTES);
    }

    return $out;
}

/**
 * ============================================================
 *  Shell / process helpers
 * ============================================================
 */

function hasCommand(string $cmd): bool
{
    $check = sprintf('command -v %s >/dev/null 2>&1', escapeshellarg($cmd));
    exec($check, $_, $code);
    return $code === 0;
}

function runCommand(array $args, ?int $timeoutSec = null): array
{
    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    // IMPORTANT: pass args array so we don't spawn /bin/sh -c ...
    $proc = proc_open(
        $args,
        $descriptorspec,
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );

    if (!is_resource($proc)) return [1, '', 'Failed to start process'];

    fclose($pipes[0]);

    // Non-blocking read so we can enforce a timeout.
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderrOut = '';
    $start = microtime(true);

    $killed = false;

    while (true) {
        $stdout    .= stream_get_contents($pipes[1]) ?: '';
        $stderrOut .= stream_get_contents($pipes[2]) ?: '';

        $status = proc_get_status($proc);
        if (!$status['running']) {
            break;
        }

        if ($timeoutSec !== null && (microtime(true) - $start) > $timeoutSec) {
            $killed = true;

            // Try graceful terminate, then hard kill.
            @proc_terminate($proc);
            usleep(200000);

            $status2 = proc_get_status($proc);
            if (!empty($status2['running']) && !empty($status2['pid']) && function_exists('posix_kill')) {
                @posix_kill((int)$status2['pid'], 9);
                usleep(100000);
            }

            // Drain any remaining output
            $stdout    .= stream_get_contents($pipes[1]) ?: '';
            $stderrOut .= stream_get_contents($pipes[2]) ?: '';
            break;
        }

        usleep(50000);
    }

    // Drain anything left after exit/kill
    $stdout    .= stream_get_contents($pipes[1]) ?: '';
    $stderrOut .= stream_get_contents($pipes[2]) ?: '';

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = @proc_close($proc);

    if ($killed) {
        // Standard "timeout" exit code
        return [124, $stdout, $stderrOut . PHP_EOL . "TIMED OUT after {$timeoutSec}s"];
    }

    return [$exitCode, $stdout, $stderrOut];
}

/**
 * Stream ffmpeg progress to terminal and return [exitCode, stderrTail]
 * Parses: out_time, total_size (bytes), speed
 * $label: e.g. "Processing: Converting"
 * $timeoutSec: optional wall-clock timeout for the whole ffmpeg run (null = no timeout)
 */
function runFfmpegWithProgress(array $args, ?float $durationSec, string $label, ?int $timeoutSec = null): array
{
    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'], // progress
        2 => ['pipe', 'w'], // errors
    ];

    $proc = proc_open(
        $args,
        $descriptorspec,
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($proc)) return [1, 'Failed to start ffmpeg'];

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $cols = termCols();
    $lastColsTs = 0.0; // throttle terminal-width refresh

    $stderrBuf = '';
    $outTimeSec = null;
    $totalSizeBytes = null;
    $speedStr = null;

    $lastRenderTs = 0.0;
    $lastPctPrinted = -1.0;
    $lastSizePrinted = -1;

    $lineBuf = '';

    $useAnsi = function_exists('stream_isatty') ? stream_isatty(STDOUT) : false;
    $clrEol  = $useAnsi ? "\033[K"  : "";
    $clrLine = $useAnsi ? "\033[2K" : "";

    $start = microtime(true);
    $killed = false;
    $killedMsg = '';

    $render = function (bool $force = false) use (
        &$outTimeSec,
        $durationSec,
        &$totalSizeBytes,
        &$speedStr,
        &$lastPctPrinted,
        &$lastSizePrinted,
        &$lastRenderTs,
        $label,
        $clrEol,
        $clrLine,
        &$cols,
        &$lastColsTs
    ): void {
        $now = microtime(true);
        // Optional: handle terminal resize (refresh width occasionally)
        if ($force || ($now - $lastColsTs) > 0.5) {
            $cols = termCols();
            $lastColsTs = $now;
        }
        if (!$force && ($now - $lastRenderTs) < 0.12) return;
        $lastRenderTs = $now;

        $sizeNow = $totalSizeBytes ?? -1;

        if ($durationSec !== null && $durationSec > 0.0) {
            $t = $outTimeSec ?? 0.0;
            $pct = min(100.0, max(0.0, ($t / $durationSec) * 100.0));
            if (!$force) {
                $pctDelta = abs($pct - $lastPctPrinted);
                $sizeDelta = abs($sizeNow - $lastSizePrinted);
                if ($pctDelta < 0.2 && $sizeDelta < (256 * 1024) && $pct < 99.9) return;
            }
            $lastPctPrinted = $pct;
            $lastSizePrinted = $sizeNow;

            $bar = renderBar($pct);
            $cur = formatSeconds($outTimeSec);
            $tot = formatSeconds($durationSec);
            $size = humanBytes($totalSizeBytes);
            $spd  = $speedStr ?? '--';

            $line = "{$label} [$bar] " . sprintf("%6.2f%%", $pct) . "  $cur/$tot  $size  $spd";
            $line = truncateToCols($line, max(20, $cols - 3)); // extra margin to avoid wrap
            echo "\r{$clrLine}{$line}{$clrEol}";
        } else {
            if (!$force) {
                $sizeDelta = abs($sizeNow - $lastSizePrinted);
                if ($sizeDelta < (256 * 1024)) return;
            }
            $lastSizePrinted = $sizeNow;

            $cur  = formatSeconds($outTimeSec);
            $size = humanBytes($totalSizeBytes);
            $spd  = $speedStr ?? '--';

            $line = "{$label}  $cur  $size  $spd";
            $line = truncateToCols($line, max(20, $cols - 3)); // extra margin to avoid wrap
            echo "\r{$clrLine}{$line}{$clrEol}";
        }
        flush();
    };

    $render(true);

    while (true) {
        // ---- Timeout check (wall-clock) ----
        if ($timeoutSec !== null && (microtime(true) - $start) > $timeoutSec) {
            $killed = true;
            $killedMsg = "TIMED OUT after {$timeoutSec}s";

            // Try graceful terminate
            @proc_terminate($proc);
            usleep(200000);

            // Hard kill if still running
            $st = proc_get_status($proc);
            if (!empty($st['running']) && !empty($st['pid']) && function_exists('posix_kill')) {
                @posix_kill((int)$st['pid'], 9);
                usleep(150000);
            }

            // Drain anything we can and break
            $stderrBuf .= stream_get_contents($pipes[2]) ?: '';
            $max = ERR_TAIL_BYTES;
            if (strlen($stderrBuf) > $max) $stderrBuf = substr($stderrBuf, -$max);
            break;
        }

        // ---- Read available output ----
        $read = [];
        if (is_resource($pipes[1])) $read[] = $pipes[1];
        if (is_resource($pipes[2])) $read[] = $pipes[2];

        $write = null;
        $except = null;
        $numChanged = @stream_select($read, $write, $except, 0, 200000);
        if ($numChanged === false) {
            // If select fails, we still want to bail safely
            break;
        }

        foreach ($read as $r) {
            $chunk = stream_get_contents($r);
            if ($chunk === false || $chunk === '') continue;

            if ($r === $pipes[2]) {
                $stderrBuf .= $chunk;

                // Keep only the last ~100KB so memory/terminal doesn't get nuked
                $max = ERR_TAIL_BYTES;
                if (strlen($stderrBuf) > $max) {
                    $stderrBuf = substr($stderrBuf, -$max);
                }
                continue;
            }

            // progress pipe
            $lineBuf .= $chunk;
            while (($pos = strpos($lineBuf, "\n")) !== false) {
                $line = trim(substr($lineBuf, 0, $pos));
                $lineBuf = substr($lineBuf, $pos + 1);

                if ($line === '' || strpos($line, '=') === false) continue;
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v);

                if ($k === 'out_time') {
                    $t = timeToSeconds($v);
                    if ($t !== null) $outTimeSec = $t;
                } elseif ($k === 'out_time_ms' || $k === 'out_time_us') {
                    if (is_numeric($v)) $outTimeSec = (float)$v / 1000000.0; // ffmpeg progress uses microseconds here
                } elseif ($k === 'total_size') {
                    if (is_numeric($v)) {
                        $totalSizeBytes = (int)$v;
                        $render();
                    }
                } elseif ($k === 'speed') {
                    $speedStr = $v;
                    $render();
                } elseif ($k === 'progress' && ($v === 'end' || $v === 'done')) {
                    $render(true);
                }
            }
        }

        // ---- Exit check ----
        $status = proc_get_status($proc);
        if (!$status['running']) break;
    }

    // Parse any trailing progress line without \n (optional but nice)
    if ($lineBuf !== '') {
        $lineBuf .= "\n";
        while (($pos = strpos($lineBuf, "\n")) !== false) {
            $line = trim(substr($lineBuf, 0, $pos));
            $lineBuf = substr($lineBuf, $pos + 1);
            if ($line === '' || strpos($line, '=') === false) continue;

            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);

            if ($k === 'out_time') {
                $t = timeToSeconds($v);
                if ($t !== null) $outTimeSec = $t;
            } elseif ($k === 'out_time_ms' || $k === 'out_time_us') {
                if (is_numeric($v)) $outTimeSec = (float)$v / 1000000.0;
            } elseif ($k === 'total_size') {
                if (is_numeric($v)) $totalSizeBytes = (int)$v;
            } elseif ($k === 'speed') {
                $speedStr = $v;
            }
        }
    }

    // Final drain
    $stderrBuf .= stream_get_contents($pipes[2]) ?: '';
    $max = ERR_TAIL_BYTES;
    if (strlen($stderrBuf) > $max) $stderrBuf = substr($stderrBuf, -$max);

    if (is_resource($pipes[1])) fclose($pipes[1]);
    if (is_resource($pipes[2])) fclose($pipes[2]);

    $exitCode = proc_close($proc);

    if ($killed) {
        $exitCode = 124;
        $stderrBuf = rtrim($stderrBuf) . PHP_EOL . $killedMsg;
    }

    // Replace the progress line with a completion message (no blank line)
    $doneMsg = ($exitCode === 0) ? "Completed" : (($exitCode === 124) ? "FAILED (timeout)" : "FAILED");

    if ($useAnsi) {
        echo "\r{$clrLine}{$doneMsg}{$clrEol}" . PHP_EOL;
    } else {
        echo $doneMsg . PHP_EOL;
    }
    flush();

    return [$exitCode, $stderrBuf, $outTimeSec];
}

/**
 * ============================================================
 *  Formatting helpers (time/bytes/progress/table alignment)
 * ============================================================
 */
function timeToSeconds(string $t): ?float
{
    $t = trim($t);
    if ($t === '' || $t === 'N/A') return null;
    if (preg_match('/^(\d+):([0-5]\d):([0-5]\d)(?:\.(\d+))?$/', $t, $m)) {
        $h = (int)$m[1];
        $min = (int)$m[2];
        $s = (int)$m[3];
        $frac = isset($m[4]) ? (float)('0.' . $m[4]) : 0.0;
        return ($h * 3600) + ($min * 60) + $s + $frac;
    }
    return is_numeric($t) ? (float)$t : null;
}

function formatSeconds(?float $sec): string
{
    if ($sec === null || $sec < 0) return '--:--';
    $secInt = (int)floor($sec);
    $h = intdiv($secInt, 3600);
    $m = intdiv($secInt % 3600, 60);
    $s = $secInt % 60;
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s);
}

function renderBar(float $pct, int $width = 26): string
{
    $pct = max(0.0, min(100.0, $pct));
    $filled = (int)round(($pct / 100.0) * $width);
    return str_repeat('█', $filled) . str_repeat('░', max(0, $width - $filled));
}

function humanBytes(?int $bytes): string
{
    if ($bytes === null || $bytes < 0) return '--';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $b = (float)$bytes;
    $i = 0;
    while ($b >= 1024 && $i < count($units) - 1) {
        $b /= 1024;
        $i++;
    }
    return ($i === 0) ? sprintf('%d%s', (int)$b, $units[$i]) : sprintf('%.1f%s', $b, $units[$i]);
}

// For summary: MB, or GB if >= 1024MB
function humanMBorGB(int $bytes): string
{
    $mb = $bytes / 1024 / 1024;
    if ($mb >= 1024) {
        $gb = $mb / 1024;
        return sprintf('%.2fGB', $gb);
    }
    return sprintf('%.1fMB', $mb);
}

function formatHoursMinutes(float $seconds): string
{
    $totalMinutes = (int)floor($seconds / 60);
    $hours = intdiv($totalMinutes, 60);
    $minutes = $totalMinutes % 60;

    // Always show both hours and minutes (e.g., "0h 07m")
    return sprintf("%dh %02dm", $hours, $minutes);
}

function displayWidth(string $s): int
{
    // If we ever add ANSI colors later, this strips them safely:
    $s = preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $s) ?? $s;

    return function_exists('mb_strwidth')
        ? mb_strwidth($s, 'UTF-8')
        : strlen($s);
}

function termCols(): int
{
    $env = getenv('COLUMNS');
    if (is_string($env) && ctype_digit($env) && (int)$env > 0) {
        return (int)$env;
    }

    $out = @shell_exec('stty size < /dev/tty 2>/dev/null');
    if (is_string($out)) {
        $out = trim($out);
        if (preg_match('/^\d+\s+(\d+)$/', $out, $m)) {
            $c = (int)$m[1];
            if ($c > 0) return $c;
        }
    }

    $out = @shell_exec('tput cols < /dev/tty 2>/dev/null');
    if (is_string($out)) {
        $out = trim($out);
        if (ctype_digit($out) && (int)$out > 0) return (int)$out;
    }

    return 120;
}

function truncateToCols(string $s, int $cols): string
{
    if ($cols <= 0) return $s;
    if (displayWidth($s) <= $cols) return $s;

    $ellipsis = '...';
    $target = max(0, $cols - displayWidth($ellipsis));

    // Build until we hit target width (UTF-8 safe)
    $out = '';
    $len = function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $ch = function_exists('mb_substr') ? mb_substr($s, $i, 1, 'UTF-8') : $s[$i];
        if (displayWidth($out . $ch) > $target) break;
        $out .= $ch;
    }

    return $out . $ellipsis;
}

function padRight(string $s, int $width): string
{
    $len = displayWidth($s);
    if ($len >= $width) return $s;
    return $s . str_repeat(' ', $width - $len);
}

/**
 * ============================================================
 *  Filesystem helpers (dirs, moves, temp paths)
 * ============================================================
 */
function ensureDir(string $dir): bool
{
    if (is_dir($dir)) return true;
    return @mkdir($dir, 0777, true) || is_dir($dir);
}

function uniqueDestPath(string $dir, string $filename): string
{
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $ext  = pathinfo($filename, PATHINFO_EXTENSION);
    $ext  = $ext !== '' ? '.' . $ext : '';

    $dest = $dir . DIRECTORY_SEPARATOR . $filename;
    if (!file_exists($dest)) return $dest;

    $n = 1;
    while (true) {
        $candidate = $dir . DIRECTORY_SEPARATOR . $base . ".__corrupt{$n}__" . $ext;
        if (!file_exists($candidate)) return $candidate;
        $n++;
    }
}

function moveFile(string $src, string $dst): bool
{
    if (@rename($src, $dst)) return true;

    // fallback (rare, but safe): copy then unlink
    if (@copy($src, $dst)) {
        @unlink($src);
        return !file_exists($src) && file_exists($dst);
    }
    return false;
}

function moveToCorrupt(string $srcPath, string $corruptDir): ?string
{
    clearstatcache(true, $srcPath);

    // If the file is already gone (user moved/deleted), do NOT create _corrupt.
    if (!is_file($srcPath)) {
        return null;
    }

    if (!ensureDir($corruptDir)) {
        stderr("WARNING: Could not create corrupt folder: $corruptDir");
        return null;
    }

    $dest = uniqueDestPath($corruptDir, basename($srcPath));
    if (moveFile($srcPath, $dest)) return $dest;

    stderr("WARNING: Failed to move to corrupt folder: " . basename($srcPath));
    return null;
}

function expandTilde(string $path): string
{
    if ($path === '' || $path[0] !== '~') return $path;
    $home = getenv('HOME') ?: '';
    if ($home === '') return $path;
    if ($path === '~') return $home;
    if (str_starts_with($path, '~/')) return $home . substr($path, 1);
    return $path;
}

function diskFreeBytes(string $dir): ?int
{
    $b = @disk_free_space($dir);
    return ($b === false) ? null : (int)$b;
}

function pickTempDir(
    ?string $wanted,
    string $fallbackDir,
    ?int $needBytes,
    int $minFreeBytes
): string {
    if (!$wanted) return $fallbackDir;

    $expanded = expandTilde($wanted);
    $dir = ($expanded === DIRECTORY_SEPARATOR) ? DIRECTORY_SEPARATOR : rtrim($expanded, DIRECTORY_SEPARATOR);

    if (!ensureDir($dir)) return $fallbackDir;
    if ($dir === '') return $fallbackDir;

    if (!is_writable($dir)) return $fallbackDir;

    $free = diskFreeBytes($dir);
    if ($free === null) return $fallbackDir;

    // Require a baseline free space, plus whatever we estimate we need for this file.
    $required = max($minFreeBytes, $needBytes ?? 0);

    return ($free >= $required) ? $dir : $fallbackDir;
}

function makeTmpPath(string $dir, string $base): string
{
    // Unique-ish tmp name so we never collide.
    $rand = bin2hex(random_bytes(4));
    return $dir . DIRECTORY_SEPARATOR . $base . ".__tmp_{$rand}__.mp4";
}

/**
 * ============================================================
 *  Video/FFmpeg analysis helpers (junk detection, bitrate calc, validation)
 * ============================================================
 */

function hasFirstAudioStream(string $path): bool
{
    // Fast check: does a:0 exist?
    [$code, $out, $_err] = runCommand([
        'ffprobe',
        '-v',
        'error',
        '-select_streams',
        'a:0',
        '-show_entries',
        'stream=index',
        '-of',
        'csv=p=0',
        $path
    ], 10);

    return $code === 0 && trim((string)$out) !== '';
}

/**
 * Decode a tiny slice at a timestamp for just video OR just audio.
 * $which: 'video' | 'audio'
 * Returns: [bool ok, int exitCode, string stderrTail]
 */
function decodeStreamProbeAt(string $path, float $seekSec, string $which, int $timeoutSec = 25): array
{
    [$preSeek, $postSeek] = splitSeek($seekSec, 10.0);

    $args = [
        'ffmpeg',
        '-hide_banner',
        '-nostdin',
        '-v',
        'error',

        // STRICT: do NOT discard corrupt during diagnosis
        '-err_detect',
        'explode',
        '-xerror',

        '-analyzeduration',
        '5000000',
        '-probesize',
        '5000000',

        '-ss',
        sprintf('%.2f', $preSeek),
        '-i',
        $path,
        '-ss',
        sprintf('%.2f', $postSeek),
    ];

    if ($which === 'audio') {
        // Important: replicate the real failure mode (decode + encode)
        $args = array_merge($args, [
            '-map',
            '0:a:0',
            '-vn',
            '-sn',
            '-c:a',
            'aac',
            '-b:a',
            '192k',        // or pass in your chosen kbps
        ]);
    } else {
        $args = array_merge($args, [
            '-map',
            '0:v:0',
            '-an',
            '-sn',
            '-c:v',
            'rawvideo',    // force decode
        ]);
    }

    $args = array_merge($args, [
        '-t',
        '3',               // a bit longer than 1s
        '-f',
        'null',
        '-',
    ]);

    [$code, $_out, $err] = runCommand($args, $timeoutSec);

    $err = (string)$err;
    if (strlen($err) > ERR_TAIL_BYTES) $err = substr($err, -ERR_TAIL_BYTES);

    return [$code === 0, $code, trim($err)];
}



function quickDecodeCheck(string $path, ?float $durationSec, bool $hasAudio, int $timeoutSec = 20): array
{
    // Sample multiple points to catch "corrupt later" files.
    $seekPoints = [0.0];

    if ($durationSec !== null && $durationSec > 1.0) {
        $seekPoints = [
            0.0,
            $durationSec * 0.25,
            $durationSec * 0.50,
            $durationSec * 0.75,
            $durationSec * 0.90,
            max(0.0, $durationSec - 10.0),
        ];
    } else {
        $seekPoints = [0.0, 30.0];
    }

    // De-dupe + clamp
    $seekPoints = array_values(array_unique(array_map(
        fn($x) => round(max(0.0, (float)$x), 2),
        $seekPoints
    )));

    foreach ($seekPoints as $seekSec) {
        $preSeek = 0.0;
        $postSeek = 0.0;

        if ($seekSec > 0.0) {
            $preSeek  = max(0.0, $seekSec - 10.0);
            $postSeek = $seekSec - $preSeek;
        }

        // Combined A/V probe (cheap, main gate)
        $args = [
            'ffmpeg',
            '-hide_banner',
            '-nostdin',
            '-v',
            'error',
            '-analyzeduration',
            '5000000',
            '-probesize',
            '5000000',
            '-fflags',
            '+discardcorrupt',
            '-err_detect',
            'explode',
        ];

        if ($preSeek > 0.0) {
            $args[] = '-ss';
            $args[] = sprintf('%.2f', $preSeek);
        }

        $args = array_merge($args, ['-i', $path]);

        if ($postSeek > 0.0) {
            $args[] = '-ss';
            $args[] = sprintf('%.2f', $postSeek);
        }

        $args = array_merge($args, [
            '-map',
            '0:v:0',
            '-map',
            '0:a:0?',   // Decode audio too (if present)
            '-sn',
            '-t',
            '5',
            '-f',
            'null',
            '-',
        ]);

        [$code, $_out, $err] = runCommand($args, $timeoutSec);

        if ($code !== 0) {
            // Tail-cap the combined error
            $err = (string)$err;
            $max = ERR_TAIL_BYTES;
            if (strlen($err) > $max) $err = substr($err, -$max);
            $err = trim($err);

            // If caller guessed audio presence from ffprobe, great.
            // If they said "no audio" but the file actually has it, fix it only on failure.
            if (!$hasAudio) {
                $hasAudio = hasFirstAudioStream($path);
            }

            // Run stream-specific probes ONLY on failure to label cause.
            [$vOk, $vCode, $vErr] = decodeStreamProbeAt($path, (float)$seekSec, 'video', $timeoutSec);

            $aOk = true;
            $aCode = 0;
            $aErr = '';
            if ($hasAudio) {
                [$aOk, $aCode, $aErr] = decodeStreamProbeAt($path, (float)$seekSec, 'audio', $timeoutSec);
            }

            $cause = 'unknown';
            if (!$vOk && $hasAudio && !$aOk) $cause = 'audio+video';
            elseif (!$vOk)                  $cause = 'video';
            elseif ($hasAudio && !$aOk)     $cause = 'audio';

            $msg = "Decode check failed at ~{$seekSec}s (exit {$code})\n"
                . "Cause: {$cause}\n";

            if (!$vOk) {
                $msg .= "Video probe failed (exit {$vCode})";
                if ($vErr !== '') $msg .= "\n" . $vErr;
                $msg .= "\n";
            }

            if ($hasAudio && !$aOk) {
                $msg .= "Audio probe failed (exit {$aCode})";
                if ($aErr !== '') $msg .= "\n" . $aErr;
                $msg .= "\n";
            }

            if ($err !== '') {
                $msg .= "Combined probe stderr (tail):\n{$err}";
            }

            return [false, $code, $cause, trim($msg)];
        }
    }

    return [true, 0, '', ''];
}

function mean(array $vals): ?float
{
    $n = 0;
    $sum = 0.0;
    foreach ($vals as $v) {
        if (!is_numeric($v)) continue;
        $sum += (float)$v;
        $n++;
    }
    return $n ? ($sum / $n) : null;
}

function analyzeNoiseSample(string $path, float $ss, int $fps, int $timeoutSec): array
{
    // Returns: [bool ok, ?float entropyAvg, ?float ystdAvg, string details]
    $ss = max(0.0, $ss);

    // NOTE: metadata=print may land in stdout or stderr depending on build/loglevel, so we parse both.
    $vf = sprintf(
        'fps=%d,scale=160:-1:flags=fast_bilinear,format=gray,signalstats,entropy,metadata=print:file=-',
        max(1, $fps)
    );

    [$pre, $post] = splitSeek($ss, 10.0);


    $args = [
        'ffmpeg',
        '-hide_banner',
        '-nostdin',
        '-loglevel',
        'info',
        '-ss',
        sprintf('%.2f', $pre),
        '-i',
        $path,
        '-ss',
        sprintf('%.2f', $post),
        '-map',
        '0:v:0',
        '-an',
        '-t',
        '2',
        '-vf',
        $vf,
        '-f',
        'null',
        '-',
    ];

    [$code, $out, $err] = runCommand($args, $timeoutSec);
    $text = (string)$out . "\n" . (string)$err;

    // Parse entropy (0..8 for 8-bit grayscale)
    preg_match_all('/(?:lavfi\.entropy\.entropy|entropy)\s*=\s*([0-9]+(?:\.[0-9]+)?)/i', $text, $em);
    $entropyVals = array_map('floatval', $em[1] ?? []);

    // Parse luma std-dev (0..128-ish)
    preg_match_all('/lavfi\.signalstats\.YSTD\s*=\s*([0-9]+(?:\.[0-9]+)?)/i', $text, $sm);
    $ystdVals = array_map('floatval', $sm[1] ?? []);

    $entropyAvg = mean($entropyVals);
    $ystdAvg    = mean($ystdVals);

    // If ffmpeg itself failed/timed out, treat as not-ok (but caller can decide what to do)
    if ($code !== 0) {
        $why = ($code === 124) ? "timeout" : "exit {$code}";
        return [false, $entropyAvg, $ystdAvg, "noise sample failed ({$why}) at ss={$ss}s"];
    }

    // If we couldn’t extract metrics, treat as not-ok (unknown)
    if ($entropyAvg === null && $ystdAvg === null) {
        return [false, null, null, "noise sample produced no metrics at ss={$ss}s"];
    }

    return [true, $entropyAvg, $ystdAvg, ""];
}

function detectJunkStaticVideo(
    string $path,
    ?float $durationSec,
    int $fps,
    int $timeoutSec,
    float $entropyThr,
    float $ystdThr
): array {
    // Returns: [bool isJunk, ?float entropyAvg, ?float ystdAvg, string detail]
    // Sample 3 points (start/mid/near-end), compute averages.
    $samples = [];

    if ($durationSec !== null && $durationSec > 1.0) {
        $samples = [
            0.0,
            $durationSec * 0.25,
            $durationSec * 0.50,
            $durationSec * 0.75,
            $durationSec * 0.90,
            max(0.0, $durationSec - 10.0),
        ];
    } else {
        $samples = [0.0, 15, 30, 45];
    }

    // De-dupe + clamp
    $uniq = [];
    foreach ($samples as $s) {
        $k = sprintf('%.2f', max(0.0, (float)$s));
        $uniq[$k] = (float)$k;
    }
    $samples = array_values($uniq);

    $ents = [];
    $ystds = [];
    $okCount = 0;

    foreach ($samples as $ss) {
        [$ok, $eAvg, $yAvg, $detail] = analyzeNoiseSample($path, (float)$ss, $fps, $timeoutSec);
        if ($ok) {
            $okCount++;
            if ($eAvg !== null) $ents[] = $eAvg;
            if ($yAvg !== null) $ystds[] = $yAvg;

            // any-hit decision
            $hit = false;
            if ($eAvg !== null && $yAvg !== null) {
                $hit = ($eAvg >= $entropyThr && $yAvg >= $ystdThr);
            } elseif ($eAvg !== null) {
                $hit = ($eAvg >= ($entropyThr + 0.15));
            }

            if ($hit) {
                $ts = formatSeconds((float)$ss);
                $msg = "junk at {$ts} (entropy " . ($eAvg !== null ? sprintf('%.2f', $eAvg) : 'n/a') .
                    ", ystd " . ($yAvg !== null ? sprintf('%.0f', $yAvg) : 'n/a') . ")";
                return [true, $eAvg, $yAvg, $msg];
            }
        }
        // If one sample fails, keep going — other samples may succeed.
    }

    $entropyAvg = mean($ents);
    $ystdAvg    = mean($ystds);

    if ($okCount === 0) {
        return [false, null, null, 'junk check unavailable (no samples succeeded)'];
    }

    // Decision logic:
    // - Primary: BOTH very high entropy AND high YSTD => “snow/static”
    // - Fallback: if YSTD missing but entropy is extremely high, still call it junk (rare for real video)
    $isJunk = false;

    if ($entropyAvg !== null && $ystdAvg !== null) {
        if ($entropyAvg >= $entropyThr && $ystdAvg >= $ystdThr) $isJunk = true;
    } elseif ($entropyAvg !== null) {
        if ($entropyAvg >= ($entropyThr + 0.15)) $isJunk = true;
    }

    $detail = '';
    if ($entropyAvg !== null) $detail .= sprintf('entropy %.2f', $entropyAvg);
    if ($ystdAvg !== null)    $detail .= ($detail !== '' ? ' ' : '') . sprintf('ystd %.0f', $ystdAvg);

    return [$isJunk, $entropyAvg, $ystdAvg, $detail !== '' ? $detail : ''];
}

function decodeSampleAt(string $path, float $ss, int $timeoutSec = 25): array
{

    [$pre, $post] = splitSeek($ss, 10.0);

    $args = [
        'ffmpeg',
        '-hide_banner',
        '-nostdin',
        '-v',
        'error',

        // keep probing bounded
        '-analyzeduration',
        '5000000',
        '-probesize',
        '5000000',

        '-fflags',
        '+discardcorrupt',
        '-err_detect',
        'explode',

        '-ss',
        sprintf('%.2f', $pre),
        '-i',
        $path,
        '-ss',
        sprintf('%.2f', $post),

        '-map',
        '0:v:0',
        '-an',
        // keep it cheap
        '-t',
        '1',
        '-f',
        'null',
        '-',
    ];

    [$code, $_out, $err] = runCommand($args, $timeoutSec);
    return [$code === 0, $code, trim((string)$err)];
}

function validateMp4Output(string $outPath, ?float $expectedDurationSec): array
{
    // Returns: [bool ok, string why, string details]
    // 1) ffprobe: ensure we have a video stream + sane duration
    [$pCode, $pOut, $pErr] = runCommand([
        'ffprobe',
        '-v',
        'error',
        '-analyzeduration',
        '5000000',
        '-probesize',
        '5000000',
        '-show_entries',
        'format=duration:stream=codec_type,codec_name,width,height',
        '-of',
        'json',
        $outPath
    ], 25);

    if ($pCode !== 0) {
        $why = ($pCode === 124) ? 'ffprobe timeout on output' : 'ffprobe failed on output';
        return [false, $why, trim((string)$pErr)];
    }

    $probe = json_decode($pOut, true);
    if (!is_array($probe) || !isset($probe['streams']) || !is_array($probe['streams'])) {
        return [false, 'bad ffprobe JSON on output', ''];
    }

    $hasVideo = false;
    $outDuration = null;

    foreach ($probe['streams'] as $s) {
        if (($s['codec_type'] ?? '') === 'video') {
            $hasVideo = true;
            break;
        }
    }
    if (isset($probe['format']['duration']) && is_numeric($probe['format']['duration'])) {
        $outDuration = (float)$probe['format']['duration'];
    }

    if (!$hasVideo) return [false, 'output has no video stream', ''];
    if ($outDuration === null || $outDuration <= 0.1) return [false, 'output duration is missing/invalid', ''];

    // 2) Decode samples at multiple points
    $checks = [];

    $checks[] = 0.0;
    $checks[] = max(0.0, $outDuration / 2.0);
    $checks[] = max(0.0, $outDuration - 10.0);

    // de-dupe timestamps (short clips)
    $checks = array_values(array_unique(array_map(fn($x) => round($x, 2), $checks)));

    foreach ($checks as $ss) {
        [$ok, $code, $err] = decodeSampleAt($outPath, (float)$ss, 25);
        if (!$ok) {
            $detail = "decode sample failed at ss={$ss}s (exit {$code})";
            if ($err !== '') $detail .= PHP_EOL . $err;

            $why = ($code === 124)
                ? 'output decode check timed out'
                : 'output decode check failed';

            return [false, $why, $detail];
        }
    }

    // Optional: compare duration vs expected (if we have it)
    if ($expectedDurationSec !== null && $expectedDurationSec > 1.0) {
        // allow generous drift; just catch extreme truncation
        if ($outDuration < ($expectedDurationSec * 0.50)) {
            return [false, 'output seems severely truncated', "expected ~{$expectedDurationSec}s, got {$outDuration}s"];
        }
    }

    return [true, 'ok', ''];
}

function pickVideoBitrateKbps(?int $w, ?int $h): int
{
    if (!$w || !$h) return 6500; // safe default
    $p = min($w, $h);

    if ($p >= 2160) return 20000; // 4K
    if ($p >= 1440) return 12000;
    if ($p >= 1080) return 6500;  // 1080p
    if ($p >= 720)  return 3500;  // 720p
    if ($p >= 576)  return 2000;
    return 1500;
}

function detectInterlacedViaIdet(string $inputPath, ?float $durationSec): ?bool
{
    $seekSec = 0.0;
    if ($durationSec !== null && $durationSec > 120.0) {
        $seekSec = min(60.0, $durationSec / 2.0);
    }

    $args = [
        'ffmpeg',
        '-hide_banner',
        '-nostdin',
        '-loglevel',
        'info',
        '-analyzeduration',
        '5000000',
        '-probesize',
        '5000000',
        '-fflags',
        '+discardcorrupt',
        '-err_detect',
        'ignore_err',
    ];

    if ($seekSec > 0.0) {
        $args[] = '-ss';
        $args[] = sprintf('%.2f', $seekSec);
    }

    $args = array_merge($args, [
        '-i',
        $inputPath,
        '-an',
        '-filter:v',
        'idet',
        '-frames:v',
        '400',
        '-f',
        'null',
        '-',
    ]);

    [$code, $out, $err] = runCommand($args, 20);
    if ($code === 124) return null;
    $text = (string)$out . "\n" . (string)$err;
    if (trim($text) === '') return null;

    if (!preg_match(
        '/(?:Multi|Single) frame detection:\s*TFF:\s*(\d+)\s*BFF:\s*(\d+)\s*Progressive:\s*(\d+)\s*Undetermined:\s*(\d+)/i',
        $text,
        $m
    )) {
        return null;
    }

    $tff = (int)$m[1];
    $bff = (int)$m[2];
    $prog = (int)$m[3];

    $inter = $tff + $bff;

    if ($inter >= 20 && $inter > $prog) return true;
    if ($prog >= 50 && $prog >= ($inter * 2)) return false;

    return false;
}

function clampInt(int $v, int $min, int $max): int
{
    return max($min, min($max, $v));
}

function minMaxVideoKbpsForQuality(?int $w, ?int $h): array
{
    if (!$w || !$h) return [2500, 20000];

    $p = min($w, $h);

    if ($p >= 2160) return [12000, 40000];
    if ($p >= 1440) return [9000,  25000];
    if ($p >= 1080) return [5500,  18000];
    if ($p >= 720)  return [3500,  12000];
    if ($p >= 576)  return [1800,  6000];
    return [1200,  4000];
}

function pickVideoBitrateToMatchSourceSize(
    ?int $inBytes,
    ?float $durationSec,
    int $audioOutKbps,
    ?int $w,
    ?int $h
): ?int {
    if (!$inBytes || !$durationSec || $durationSec <= 0) return null;

    $totalKbps = (int)round(($inBytes * 8) / ($durationSec * 1000));
    $overheadK = 80;

    $videoKbps = $totalKbps - $audioOutKbps - $overheadK;
    if ($videoKbps < 500) $videoKbps = 500;

    [$minK, $maxK] = minMaxVideoKbpsForQuality($w, $h);

    return clampInt($videoKbps, $minK, $maxK);
}

function capVideoKbpsByMaxGrowMB(
    int $videoKbps,
    ?int $inBytes,
    ?float $durationSec,
    int $audioOutKbps,
    int $maxGrowMB,
    int $overheadK = 80
): int {
    if (!$inBytes || !$durationSec || $durationSec <= 0) return $videoKbps;
    if ($maxGrowMB <= 0) return $videoKbps;

    $maxBytes = $inBytes + ($maxGrowMB * 1024 * 1024);
    $maxTotalKbps = (int)round(($maxBytes * 8) / ($durationSec * 1000));
    $maxVideoKbps = $maxTotalKbps - $audioOutKbps - $overheadK;

    if ($maxVideoKbps < 500) $maxVideoKbps = 500;

    return min($videoKbps, $maxVideoKbps);
}

function estimateAudioOutKbps(
    array $audioCodecs,
    array $audioBitrates,
    array $copyAudioStream,
    bool $needsAudioTranscode,
    string $audioMode,
    string $audioBitrate
): int {
    if (count($audioCodecs) === 0) return 0;

    $userK = (int)substr($audioBitrate, 0, -1); // "256k" => 256
    $sumK = 0;

    foreach ($audioCodecs as $ai => $ac) {
        $srcBps = $audioBitrates[$ai] ?? null;
        $srcK = (is_int($srcBps) && $srcBps > 0) ? (int)round($srcBps / 1000) : null;

        if (!empty($copyAudioStream[$ai]) && !$needsAudioTranscode) {
            $sumK += $srcK ?? 256;
            continue;
        }

        if (!empty($copyAudioStream[$ai]) && $needsAudioTranscode) {
            $sumK += $srcK ?? 256;
            continue;
        }

        if ($audioMode === 'aac') {
            $useK = ($srcK !== null && $srcK > 0) ? min($userK, $srcK) : $userK;
            $sumK += max(64, $useK);
        } else {
            $sumK += $srcK ?? 900;
        }
    }

    return $sumK;
}

function splitSeek(float $ss, float $window = 8.0): array
{
    $ss = max(0.0, $ss);
    $pre = max(0.0, $ss - $window);   // fast seek to near target
    $post = $ss - $pre;               // accurate seek within that window
    return [$pre, $post];
}

function probeDurationSec(string $path): ?float
{
    [$code, $out, $_err] = runCommand([
        'ffprobe',
        '-v',
        'error',
        '-show_entries',
        'format=duration',
        '-of',
        'default=nk=1:nw=1',
        $path
    ], 15);

    if ($code !== 0) return null;
    $v = trim((string)$out);
    return is_numeric($v) ? (float)$v : null;
}

function diagnoseFailureStream(string $path, ?float $seekSec, bool $hasAudio, int $timeoutSec = 25): array
{
    $seekSec = ($seekSec !== null) ? max(0.0, (float)$seekSec) : 0.0;

    // Probe slightly AFTER where ffmpeg stopped, plus one more forward point
    // (corruption is often just after the last reported out_time)
    $points = [
        $seekSec + 5.0,
        $seekSec + 15.0,
        max(0.0, $seekSec - 2.0),
    ];

    // de-dupe
    $points = array_values(array_unique(array_map(fn($x) => round((float)$x, 2), $points)));

    $lastVErr = '';
    $lastAErr = '';
    $lastPoint = $seekSec;

    foreach ($points as $p) {
        $lastPoint = $p;

        [$vOk, $vCode, $vErr] = decodeStreamProbeAt($path, $p, 'video', $timeoutSec);

        $aOk = true;
        $aCode = 0;
        $aErr = '';
        if ($hasAudio) {
            [$aOk, $aCode, $aErr] = decodeStreamProbeAt($path, $p, 'audio', $timeoutSec);
        }

        $lastVErr = $vErr;
        $lastAErr = $aErr;

        $cause = 'unknown';
        if (!$vOk && $hasAudio && !$aOk) $cause = 'audio+video';
        elseif (!$vOk)                  $cause = 'video';
        elseif ($hasAudio && !$aOk)     $cause = 'audio';

        if ($cause !== 'unknown') {
            $detail = "Cause: {$cause} (probe at " . formatSeconds($p) . ")";

            if ($cause === 'video' && $vErr !== '') {
                $detail .= PHP_EOL . compactErrForConsole($vErr);
            } elseif ($cause === 'audio' && $aErr !== '') {
                $detail .= PHP_EOL . compactErrForConsole($aErr);
            } elseif ($cause === 'audio+video') {
                if ($vErr !== '') $detail .= PHP_EOL . "Video:" . PHP_EOL . compactErrForConsole($vErr);
                if ($aErr !== '') $detail .= PHP_EOL . "Audio:" . PHP_EOL . compactErrForConsole($aErr);
            }

            return [$cause, $detail];
        }
    }

    // If all probes still "pass", include whatever stderr we saw (sometimes non-fatal text exists)
    $detail = "Cause: unknown (probes OK at "
        . implode(', ', array_map('formatSeconds', $points))
        . ")";

    if (trim($lastVErr) !== '' || trim($lastAErr) !== '') {
        if (trim($lastVErr) !== '') $detail .= PHP_EOL . "Video (last probe stderr):" . PHP_EOL . compactErrForConsole($lastVErr);
        if (trim($lastAErr) !== '') $detail .= PHP_EOL . "Audio (last probe stderr):" . PHP_EOL . compactErrForConsole($lastAErr);
    }

    return ['unknown', $detail];
}


/**
 * ============================================================
 *  Usage / CLI
 * ============================================================
 */
function usage(): void
{
    $script = basename(__FILE__);
    echo <<<TXT
Usage:
  php $script [--dry-run] [--keep] [--bitrate=256k] [--audio=aac|alac] [--preset=NAME]

Options:
  --dry-run            Print what would happen, but don't run ffmpeg or delete anything
  --keep               Keep original source files (do not delete after successful conversion)
  --bitrate=NNNk       AAC bitrate when audio must be converted (default: 256k)
  --audio=aac|alac     Audio transcode codec when needed: AAC (lossy) or ALAC (lossless). Default: aac
  --preset=NAME        x264 preset when re-encoding (default: medium)
  --deint=auto|on|off  Deinterlace when re-encoding (default: auto)
  --max-grow-mb=MB     Cap output growth vs input when re-encoding (default: 1000). 0 disables.
  --junk=auto|on|off   Detect “static/junk” video and quarantine (default: auto)
  --junk-entropy=F     Entropy threshold (0..8). Higher = more “random noise” (default: 7.70)
  --junk-ystd=F        Luma std-dev threshold (0..128-ish). Higher = noisier (default: 55)
  --junk-sample-fps=N  Sample FPS for analysis (default: 2)
  --junk-timeout=S     Timeout per sample probe (default: 25s)
  --vbitrate=KBPS      Force video bitrate when re-encoding (e.g., 3500, 6500). If omitted, bitrate is chosen by resolution.
  --temp-dir=PATH      Write temp .tmp.mp4 to this directory (recommended: internal SSD)
  --temp-min-free-mb=MB  Minimum free space required to use temp-dir (default: 4096)
  --ffmpeg-timeout-min=N  Timeout per file in minutes (default: 30). 0 disables.
  --verbose-errors     Print full ffmpeg/ffprobe stderr tails on failures (VERY noisy)
  --help               Show this help

Notes:
  - Processes: .mkv .wmv .mov .avi .m4v .mpg .mpeg .vob .webm .flv .ts .m2ts (current directory only)
  - Subtitles are discarded.
  - Video is copied when MP4-compatible; otherwise video is re-encoded via libx264 with a bitrate target (capped by --max-grow-mb).

TXT . PHP_EOL;
}

/**
 * ============================================================
 *  Config defaults
 * ============================================================
 */
$DEFAULTS = [
    'deint' => 'auto',               // auto|on|off
    'audio' => 'aac',                // aac|alac
    'bitrate' => '256k',             // AAC bitrate when transcoding
    'preset' => 'medium',            // x264 preset
    'max_grow_mb' => 1000,           // cap output growth vs input when re-encoding
    'junk' => 'auto',                // auto|on|off
    'junk_entropy' => 7.70,
    'junk_ystd' => 55.0,
    'junk_sample_fps' => 2,
    'junk_timeout' => 25,
    'ffmpeg_timeout_min' => 30,
    'temp_min_free_mb' => 4096,
];

/**
 * ============================================================
 *  Parse CLI options
 * ============================================================
 */
$options = getopt('', [
    'dry-run',
    'temp-dir:',
    'temp-min-free-mb:',
    'keep',
    'bitrate:',
    'audio:',
    'preset:',
    'deint:',
    'vbitrate:',
    'max-grow-mb:',
    'junk:',
    'junk-entropy:',
    'junk-ystd:',
    'junk-sample-fps:',
    'junk-timeout:',
    'ffmpeg-timeout-min:',
    'verbose-errors',
    'help'
]);

// IMPORTANT: handle --help immediately (so help works even if ffmpeg is missing)
if (isset($options['help'])) {
    usage();
    exit(0);
}

/**
 * ============================================================
 *  Resolve options (apply defaults)
 * ============================================================
 */
$dryRun = isset($options['dry-run']);
$keep   = isset($options['keep']);

$deintMode = strtolower((string)($options['deint'] ?? $DEFAULTS['deint'])); // auto|on|off

$tempDirOpt     = isset($options['temp-dir']) ? (string)$options['temp-dir'] : null;
$tempMinFreeMB  = isset($options['temp-min-free-mb']) ? (int)$options['temp-min-free-mb'] : (int)$DEFAULTS['temp_min_free_mb'];

$audioMode    = strtolower((string)($options['audio'] ?? $DEFAULTS['audio']));
$audioBitrate = (string)($options['bitrate'] ?? $DEFAULTS['bitrate']);

$x264Preset  = (string)($options['preset'] ?? $DEFAULTS['preset']);
$userVBitrate = isset($options['vbitrate']) ? (int)$options['vbitrate'] : null; // kbps

$maxGrowMB = isset($options['max-grow-mb']) ? (int)$options['max-grow-mb'] : (int)$DEFAULTS['max_grow_mb'];

$junkMode       = strtolower((string)($options['junk'] ?? $DEFAULTS['junk'])); // auto|on|off
$junkEntropyThr = isset($options['junk-entropy']) ? (float)$options['junk-entropy'] : (float)$DEFAULTS['junk_entropy'];
$junkYstdThr    = isset($options['junk-ystd'])    ? (float)$options['junk-ystd']    : (float)$DEFAULTS['junk_ystd'];
$junkSampleFps  = isset($options['junk-sample-fps']) ? (int)$options['junk-sample-fps'] : (int)$DEFAULTS['junk_sample_fps'];
$junkTimeoutSec = isset($options['junk-timeout']) ? (int)$options['junk-timeout'] : (int)$DEFAULTS['junk_timeout'];

$verboseErrors = isset($options['verbose-errors']);

/**
 * ============================================================
 *  Validate options / clamp values
 * ============================================================
 */
if (!in_array($deintMode, ['auto', 'on', 'off'], true)) {
    stderr("Invalid --deint value. Use auto, on, or off. Defaulting to auto.");
    $deintMode = 'auto';
}

$tempMinFreeMB = max(256, (int)$tempMinFreeMB); // don’t let it get silly small
$tempMinFreeBytes = $tempMinFreeMB * 1024 * 1024;

if (!in_array($audioMode, ['aac', 'alac'], true)) {
    stderr("Invalid --audio value. Use aac or alac. Defaulting to aac.");
    $audioMode = 'aac';
}

if (!preg_match('/^\d+k$/', $audioBitrate)) {
    stderr("Invalid --bitrate value '$audioBitrate' (expected like 192k, 256k). Using 256k.");
    $audioBitrate = '256k';
}

if ($userVBitrate !== null && ($userVBitrate < 500 || $userVBitrate > 50000)) {
    stderr("Invalid --vbitrate value '{$options['vbitrate']}'. Expected kbps like 3500 or 6500.");
    exit(1);
}

if ($maxGrowMB < 0 || $maxGrowMB > 50000) {
    stderr("Invalid --max-grow-mb value '{$options['max-grow-mb']}'. Expected 0..50000 (MB). Defaulting to 1000.");
    $maxGrowMB = 1000;
}

if (!in_array($junkMode, ['auto', 'on', 'off'], true)) {
    stderr("Invalid --junk value. Use auto, on, or off. Defaulting to auto.");
    $junkMode = 'auto';
}

$junkSampleFps  = max(1, min(10, (int)$junkSampleFps));
$junkTimeoutSec = max(5, min(120, (int)$junkTimeoutSec));

$ffmpegTimeoutMin = isset($options['ffmpeg-timeout-min'])
    ? (int)$options['ffmpeg-timeout-min']
    : (int)$DEFAULTS['ffmpeg_timeout_min'];

if ($ffmpegTimeoutMin < 0 || $ffmpegTimeoutMin > 24 * 60) {
    stderr("Invalid --ffmpeg-timeout-min value. Expected 0..1440. Defaulting to 30.");
    $ffmpegTimeoutMin = 30;
}

$ffmpegTimeoutSec = ($ffmpegTimeoutMin === 0) ? null : ($ffmpegTimeoutMin * 60);


/**
 * ============================================================
 *  Environment checks
 * ============================================================
 */
if (!hasCommand('ffmpeg') || !hasCommand('ffprobe')) {
    stderr("Error: ffmpeg and/or ffprobe not found in PATH.");
    stderr("Install (macOS Homebrew): brew install ffmpeg");
    exit(1);
}

/**
 * ============================================================
 *  Runtime setup
 * ============================================================
 */
// Keep macOS awake while this script runs (prevents idle/system/disk sleep)
// (Allows the display to sleep / screen to go off)
if (PHP_OS_FAMILY === 'Darwin' && hasCommand('caffeinate')) {
    $pid = getmypid();
    exec('caffeinate -ims -w ' . (int)$pid . ' >/dev/null 2>&1 &');
}

$cwd = getcwd();
if ($cwd === false) {
    stderr("Error: could not determine current working directory.");
    exit(1);
}

$corruptDir = $cwd . DIRECTORY_SEPARATOR . '_corrupt';

/**
 * ============================================================
 *  File discovery settings
 * ============================================================
 */
$exts = ['mkv', 'wmv', 'mov', 'avi', 'm4v', 'mpg', 'mpeg', 'vob', 'webm', 'flv', 'ts', 'm2ts'];

// Only run idet on containers that commonly carry interlaced sources
$idetExts = ['wmv', 'mpg', 'mpeg', 'avi', 'ts', 'm2ts', 'vob'];

// Collect target files (non-recursive)
$files = [];
foreach (new DirectoryIterator($cwd) as $fi) {
    if (!$fi->isFile()) continue;
    $ext = strtolower($fi->getExtension());
    if (in_array($ext, $exts, true)) $files[] = $fi->getPathname();
}

usort($files, fn(string $a, string $b): int => strnatcasecmp(basename($a), basename($b)));

if (count($files) === 0) {
    echo "No target files found in: $cwd" . PHP_EOL;
    exit(0);
}

echo PHP_EOL . "Found " . count($files) . " file(s) to convert in: $cwd" . PHP_EOL . PHP_EOL;

$mp4VideoCopyOk = ['h264', 'hevc', 'h265', 'mpeg4']; // conservative
$audioCopyOk = ['aac', 'mp3', 'alac'];

$results = [];
$runStart = microtime(true);

foreach ($files as $i => $inputPath) {
    $fileStart = microtime(true);
    $inName = basename($inputPath);
    $inExt = strtolower(pathinfo($inputPath, PATHINFO_EXTENSION));
    $base = pathinfo($inputPath, PATHINFO_FILENAME);
    $outPath = $cwd . DIRECTORY_SEPARATOR . $base . '.mp4';
    $outName = basename($outPath);

    if ($i > 0) echo PHP_EOL; // one blank line between files
    echo "=== (" . ($i + 1) . "/" . count($files) . ") $inName ===" . PHP_EOL;

    clearstatcache(true, $inputPath);
    if (!is_file($inputPath)) {
        stderr("SKIP: input missing (moved/deleted): {$inName}");
        $results[] = [
            'mode' => 'Skipped',
            'status' => 'SKIPPED (missing input)',
            'in' => $inName,
            'out' => $outName,
            'inBytes' => null,
            'outBytes' => null,
        ];
        continue;
    }

    $inBytes = @filesize($inputPath);
    $inBytes = ($inBytes === false) ? null : (int)$inBytes;

    // Estimate how big temp output could get (very conservative)
    $needBytes = null;
    if (is_int($inBytes) && $inBytes > 0) {
        $needBytes = $inBytes + ($maxGrowMB * 1024 * 1024) + (512 * 1024 * 1024);
    }

    $tmpDir = pickTempDir($tempDirOpt, $cwd, $needBytes, $tempMinFreeBytes);
    $tmpPath = makeTmpPath($tmpDir, $base . '.tmp');

    // Date modified
    $srcMTime = @filemtime($inputPath);
    $srcATime = @fileatime($inputPath);
    if ($srcATime === false) $srcATime = $srcMTime;

    // echo PHP_EOL . "=== (" . ($i + 1) . "/" . count($files) . ") $inName ===" . PHP_EOL;

    // Print temp dir if user asked for one OR if it differs from cwd.
    // Also show whether it was a fallback.
    $tempLine = null;
    if ($tempDirOpt !== null) {
        $tempLine = ($tmpDir === $cwd)
            ? "Temp dir: {$tmpDir} (fallback to cwd)"
            : "Temp dir: {$tmpDir}";
    }

    if (file_exists($outPath)) {
        echo "SKIP: Output already exists: $outName" . PHP_EOL . PHP_EOL;
        $outBytes = @filesize($outPath);
        $outBytes = ($outBytes === false) ? null : (int)$outBytes;

        $results[] = [
            'status' => 'EXISTS',
            'mode' => 'Skipped',
            'in' => $inName,
            'out' => $outName,
            'inBytes' => $inBytes,
            'outBytes' => $outBytes,
        ];
        continue;
    }

    echo "Reading file metadata..." . PHP_EOL;

    [$pCode, $pOut, $pErr] = runCommand([
        'ffprobe',
        '-v',
        'error',
        '-analyzeduration',
        '5000000',
        '-probesize',
        '5000000',
        '-show_entries',
        'format=duration:stream=index,codec_type,codec_name,bit_rate,pix_fmt,width,height',
        '-of',
        'json',
        $inputPath
    ], 30);

    if ($pCode !== 0) {
        $why = ($pCode === 124) ? 'ffprobe timeout' : 'ffprobe failed';
        $movedTo = moveToCorrupt($inputPath, $corruptDir);
        $movedMsg = $movedTo ? ('MOVED to _corrupt/' . basename($movedTo)) : 'NOT MOVED';

        stderr("SKIP: {$why}: {$inName}  ({$movedMsg})");
        if (trim($pErr) !== '') {
            stderr($verboseErrors ? trim($pErr) : compactErrForConsole((string)$pErr));
        }

        $results[] = [
            'mode' => 'Skipped',
            'status' => 'SKIPPED (' . $why . ')',
            'movedTo' => $movedTo,
            'in' => $inName,
            'out' => $outName,
            'inBytes' => $inBytes,
            'outBytes' => null
        ];
        continue;
    }

    $probe = json_decode($pOut, true);
    if (!is_array($probe) || !isset($probe['streams']) || !is_array($probe['streams'])) {
        $movedTo = moveToCorrupt($inputPath, $corruptDir);
        $movedMsg = $movedTo ? ('MOVED to _corrupt/' . basename($movedTo)) : 'NOT MOVED';

        stderr("SKIP: bad ffprobe JSON: {$inName}  ({$movedMsg})");

        $results[] = [
            'mode' => 'Skipped',
            'status' => 'SKIPPED (bad ffprobe json)',
            'movedTo' => $movedTo,
            'in' => $inName,
            'out' => $outName,
            'inBytes' => $inBytes,
            'outBytes' => null
        ];
        continue;
    }

    $durationSec = null;
    if (isset($probe['format']['duration']) && is_numeric($probe['format']['duration'])) {
        $durationSec = (float)$probe['format']['duration'];
    }

    $videoCodec = null;
    $videoPixFmt = null;
    $audioCodecs = [];
    $audioBitrates = [];
    $hasSubtitles = false;
    $subtitleCodecs = [];

    $videoWidth = null;
    $videoHeight = null;

    foreach ($probe['streams'] as $s) {
        $type = $s['codec_type'] ?? '';
        $codec = strtolower((string)($s['codec_name'] ?? ''));

        if ($type === 'video' && $videoCodec === null) {
            $videoCodec = $codec ?: null;
            $videoPixFmt = isset($s['pix_fmt']) ? (string)$s['pix_fmt'] : null;
            $videoWidth = isset($s['width']) ? (int)$s['width'] : null;
            $videoHeight = isset($s['height']) ? (int)$s['height'] : null;
        } elseif ($type === 'audio') {
            $audioCodecs[] = $codec ?: 'unknown';
            $audioBitrates[] = (isset($s['bit_rate']) && is_numeric($s['bit_rate']))
                ? (int)$s['bit_rate']
                : null;
        } elseif ($type === 'subtitle') {
            $hasSubtitles = true;
            $subtitleCodecs[] = $codec ?: 'unknown';
        }
    }

    if ($videoCodec === null) {
        stderr("SKIP: No video stream found.");
        $movedTo = moveToCorrupt($inputPath, $corruptDir);
        $results[] = [
            'mode' => 'Skipped',
            'status' => 'SKIPPED (no video stream)',
            'movedTo' => $movedTo,
            'in' => $inName,
            'out' => $outName,
            'inBytes' => $inBytes,
            'outBytes' => null
        ];
        continue;
    }

    echo "Sanity check (corruption/decode test)..." . PHP_EOL;
    $hasAudio = (count($audioCodecs) > 0);
    [$okDecode, $dCode, $dCause, $dErr] = quickDecodeCheck($inputPath, $durationSec, $hasAudio, 25);

    if (!$okDecode) {
        $movedTo = moveToCorrupt($inputPath, $corruptDir);
        $movedMsg = $movedTo ? ('MOVED to _corrupt/' . basename($movedTo)) : 'NOT MOVED';

        stderr("SKIP: decode check failed ({$dCause}, {$dCode}): {$inName}  ({$movedMsg})");
        if ($dErr !== '') {
            stderr($verboseErrors ? $dErr : compactErrForConsole((string)$dErr));
        }

        $results[] = [
            'mode' => 'Skipped',
            'status' => 'SKIPPED (decode check failed: ' . $dCause . ')',
            'movedTo' => $movedTo,
            'in' => $inName,
            'out' => $outName,
            'inBytes' => $inBytes,
            'outBytes' => null
        ];
        continue;
    }

    // ----------
    // Junk/static preflight check (optional)
    // ----------
    $shouldJunkCheck = false;

    if ($junkMode === 'on') {
        $shouldJunkCheck = true;
    } elseif ($junkMode === 'auto') {
        if (in_array($inExt, ['wmv', 'avi', 'mpg', 'mpeg', 'vob'], true)) $shouldJunkCheck = true;
        if (in_array($videoCodec, ['wmv1', 'wmv2'], true)) $shouldJunkCheck = true;
    }

    if ($shouldJunkCheck) {
        echo "Junk check (static / snow / junk video)..." . PHP_EOL;

        [$isJunk, $eAvg, $yAvg, $junkDetail] = detectJunkStaticVideo(
            $inputPath,
            $durationSec,
            $junkSampleFps,
            $junkTimeoutSec,
            $junkEntropyThr,
            $junkYstdThr
        );

        if ($isJunk) {
            $movedTo = moveToCorrupt($inputPath, $corruptDir);
            $movedMsg = $movedTo ? ('MOVED to _corrupt/' . basename($movedTo)) : 'NOT MOVED';

            $statusDetail = $junkDetail !== '' ? (': ' . $junkDetail) : '';
            stderr("SKIP: junk/static video{$statusDetail}: {$inName}  ({$movedMsg})");

            $results[] = [
                'mode' => 'Skipped',
                'status' => 'SKIPPED (junk video' . ($junkDetail !== '' ? ': ' . $junkDetail : '') . ')',
                'movedTo' => $movedTo,
                'in' => $inName,
                'out' => $outName,
                'inBytes' => $inBytes,
                'outBytes' => null
            ];
            continue;
        }
    }

    // -------------------------------------------------------------------------
    // Everything below here main conversion logic
    // -------------------------------------------------------------------------

    $canCopyVideo = in_array($videoCodec, $mp4VideoCopyOk, true);
    $videoReencoded = !$canCopyVideo;

    $needsAudioTranscode = false;
    $copyAudioStream = [];
    foreach ($audioCodecs as $ai => $ac) {
        if (in_array($ac, $audioCopyOk, true)) {
            $copyAudioStream[$ai] = true;
        } else {
            $copyAudioStream[$ai] = false;
            $needsAudioTranscode = true;
        }
    }

    if (count($audioCodecs) === 0) {
        $audioNote = 'no audio';
    } elseif (!$needsAudioTranscode) {
        $audioNote = 'audio copied';
    } else {
        $audioNote = ($audioMode === 'aac') ? 'audio→AAC' : 'audio→ALAC';
    }

    $args = [
        'ffmpeg',
        '-hide_banner',
        '-loglevel',
        'error',
        '-nostdin',
        '-y',

        '-fflags',
        '+genpts+igndts+discardcorrupt',
        '-err_detect',
        'ignore_err',

        '-avoid_negative_ts',
        'make_zero',

        '-i',
        $inputPath,

        '-map',
        '0:v:0',
        '-map',
        '0:a?',

        '-map_metadata',
        '0',
        '-map_chapters',
        '0',
        '-sn',

        '-max_muxing_queue_size',
        '4096',
        '-max_interleave_delta',
        '0',
    ];

    $doDeint = false;
    $deintReason = '';

    $audioOutKbpsEst = estimateAudioOutKbps(
        $audioCodecs,
        $audioBitrates,
        $copyAudioStream,
        $needsAudioTranscode,
        $audioMode,
        $audioBitrate
    );

    $vbK = null;
    $capped = false; // keep defined

    if ($canCopyVideo) {
        $args[] = '-c:v';
        $args[] = 'copy';
        if ($videoCodec === 'hevc' || $videoCodec === 'h265') {
            $args[] = '-tag:v';
            $args[] = 'hvc1';
        }
    } else {
        $args[] = '-c:v';
        $args[] = 'libx264';

        $vbK = $userVBitrate
            ?? pickVideoBitrateToMatchSourceSize($inBytes, $durationSec, $audioOutKbpsEst, $videoWidth, $videoHeight)
            ?? pickVideoBitrateKbps($videoWidth, $videoHeight);

        $vbKBeforeCap = $vbK;

        $vbK = capVideoKbpsByMaxGrowMB(
            $vbK,
            $inBytes,
            $durationSec,
            $audioOutKbpsEst,
            $maxGrowMB
        );

        $capped = ($vbK !== $vbKBeforeCap);

        $args[] = '-b:v';
        $args[] = "{$vbK}k";

        $args[] = '-maxrate';
        $args[] = (int)round($vbK * 1.25) . 'k';

        $args[] = '-bufsize';
        $args[] = (int)round($vbK * 2.0) . 'k';

        $args[] = '-preset';
        $args[] = $x264Preset;

        $args[] = '-pix_fmt';
        $args[] = 'yuv420p';

        $filters = [];

        if ($deintMode === 'on') {
            $doDeint = true;
            $deintReason = 'forced';
        } elseif ($deintMode === 'auto') {
            if (in_array($inExt, $idetExts, true)) {
                $isInterlaced = detectInterlacedViaIdet($inputPath, $durationSec);
                if ($isInterlaced === true) {
                    $doDeint = true;
                    $deintReason = 'idet';
                } elseif ($isInterlaced === null) {
                    $deintReason = 'idet unavailable';
                } else {
                    $deintReason = 'not interlaced';
                }
            } else {
                $deintReason = "skipped idet for .$inExt";
            }
        }

        if ($doDeint) {
            $filters[] = 'yadif=0:-1:0';
        }

        if ($videoWidth !== null && $videoHeight !== null && (($videoWidth % 2) || ($videoHeight % 2))) {
            $filters[] = 'scale=trunc(iw/2)*2:trunc(ih/2)*2';
        }

        if (!empty($filters)) {
            $args[] = '-vf';
            $args[] = implode(',', $filters);
        }
    }

    if ($vbK !== null) {
        $capNote = $capped ? " (capped to +{$maxGrowMB}MB)" : "";
        echo "Video target: {$vbK}k (audio est: {$audioOutKbpsEst}k){$capNote}" . PHP_EOL;
    }

    $audioAction = 'none';
    $audioPlan = [];
    $aacChosenK = [];
    $aacSrcK = [];

    if (count($audioCodecs) > 0) {
        if (!$needsAudioTranscode) {
            $args[] = '-c:a';
            $args[] = 'copy';
            $audioAction = 'copy all';

            foreach ($audioCodecs as $ai => $ac) {
                $audioPlan[] = "a{$ai}:{$ac} copy";
            }
        } else {
            if ($audioMode === 'alac') {
                $args[] = '-c:a';
                $args[] = 'alac';
                $audioAction = 'transcode to alac (lossless)';

                foreach ($audioCodecs as $ai => $ac) {
                    if (!empty($copyAudioStream[$ai])) {
                        $args[] = "-c:a:$ai";
                        $args[] = 'copy';
                        $audioPlan[] = "a{$ai}:{$ac} copy";
                    } else {
                        $audioPlan[] = "a{$ai}:{$ac}→alac";
                    }
                }
            } else {
                $args[] = '-c:a';
                $args[] = 'aac';

                $userK = (int)substr($audioBitrate, 0, -1);
                $audioAction = "transcode to aac (max {$audioBitrate}; clamped if src lower)";

                foreach ($audioCodecs as $ai => $ac) {
                    if (!empty($copyAudioStream[$ai])) {
                        $args[] = "-c:a:$ai";
                        $args[] = 'copy';
                        $audioPlan[] = "a{$ai}:{$ac} copy";
                        continue;
                    }

                    $srcBps = $audioBitrates[$ai] ?? null;
                    $srcK = (is_int($srcBps) && $srcBps > 0) ? (int)round($srcBps / 1000) : null;

                    $useK = ($srcK !== null && $srcK > 0)
                        ? min($userK, $srcK)
                        : $userK;

                    $args[] = "-b:a:$ai";
                    $args[] = "{$useK}k";

                    $aacChosenK[$ai] = $useK;
                    $aacSrcK[$ai] = $srcK;

                    $audioPlan[] = ($srcK !== null && $srcK > 0)
                        ? "a{$ai}:{$ac}→aac {$useK}k (src {$srcK}k)"
                        : "a{$ai}:{$ac}→aac {$useK}k";
                }
            }
        }
    } else {
        $audioPlan[] = "none";
    }

    $args[] = '-movflags';
    $args[] = '+faststart';
    $args[] = '-progress';
    $args[] = 'pipe:1';
    $args[] = '-nostats';
    $args[] = $tmpPath;

    $videoActionLabel = $canCopyVideo
        ? 'copy (no re-encode)'
        : "re-encode (x264 " . ($vbK ?? '?') . "k, preset {$x264Preset})";

    // $shouldPrintDeint = $videoReencoded && (
    //     $doDeint
    //     || in_array($inExt, $idetExts, true)
    //     || $deintMode !== 'auto'
    // );

    // $deintLabel = $doDeint
    //     ? 'yes (yadif)'
    //     : ('no' . (!empty($deintReason) ? " ($deintReason)" : ''));

    echo "Video codec: $videoCodec  ($videoActionLabel)" . PHP_EOL;
    if (!empty($videoPixFmt)) echo "Video pix_fmt: $videoPixFmt" . PHP_EOL;
    if ($videoWidth !== null && $videoHeight !== null) {
        echo "Resolution: {$videoWidth}x{$videoHeight}" . PHP_EOL;
    }
    // if ($shouldPrintDeint) {
    //     echo "Deinterlace: {$deintLabel}" . PHP_EOL;
    // }
    echo "Duration: " . formatSeconds($durationSec) . PHP_EOL;
    if ($doDeint) {
        $why = ($deintReason !== '') ? " ({$deintReason})" : '';
        echo "Deinterlace: yes (yadif){$why}" . PHP_EOL;
    }


    // --- Clean audio display (no "unknown"; omit a0 when single-stream) ---
    if (count($audioCodecs) === 0) {
        echo "Audio: none" . PHP_EOL;
    } else {
        $isMulti = count($audioCodecs) > 1;

        // helper to get source kbps if known
        $srcKbps = function (int $ai) use ($audioBitrates): ?int {
            $bps = $audioBitrates[$ai] ?? null;
            if (is_int($bps) && $bps > 0) return (int)round($bps / 1000);
            return null;
        };

        $capK = (int)substr($audioBitrate, 0, -1); // "256k" => 256

        // Build per-stream lines
        $lines = [];
        foreach ($audioCodecs as $ai => $ac) {
            $prefix = $isMulti ? "a{$ai} " : "";

            // prefer the srcK already computed earlier (aacSrcK), else derive from bit_rate
            $srcK = $aacSrcK[$ai] ?? $srcKbps($ai);

            // no "unknown" — just omit bitrate if we don't know it
            $srcPart = ($srcK !== null) ? " {$srcK}k" : "";

            // copied (no transcode needed at all)
            if (!empty($copyAudioStream[$ai]) && !$needsAudioTranscode) {
                $lines[] = "{$prefix}{$ac}{$srcPart} (copied)";
                continue;
            }

            // audio transcode path
            if ($audioMode === 'alac') {
                // if this stream is copyable (when other streams force transcode), show it as copied
                if (!empty($copyAudioStream[$ai])) {
                    $lines[] = "{$prefix}{$ac}{$srcPart} (copied)";
                } else {
                    $lines[] = "{$prefix}{$ac}{$srcPart} → alac (lossless)";
                }
            } else {
                // AAC
                if (!empty($copyAudioStream[$ai])) {
                    $lines[] = "{$prefix}{$ac}{$srcPart} (copied)";
                } else {
                    // chosen kbps (prefer precomputed), else clamp to src if known, else use cap
                    $useK = $aacChosenK[$ai] ?? (($srcK !== null) ? min($capK, $srcK) : $capK);
                    $lines[] = "{$prefix}{$ac}{$srcPart} → aac {$useK}k";
                }
            }
        }

        // Headline + formatting
        if (!$needsAudioTranscode) {
            // All copied
            if (!$isMulti) {
                echo "Audio: " . $lines[0] . PHP_EOL; // no a0 prefix here
            } else {
                echo "Audio: copied" . PHP_EOL;
                foreach ($lines as $ln) echo "       {$ln}" . PHP_EOL;
            }
        } else {
            if ($audioMode === 'alac') {
                if (!$isMulti) {
                    echo "Audio: " . $lines[0] . PHP_EOL;
                } else {
                    echo "Audio: → alac (lossless)" . PHP_EOL;
                    foreach ($lines as $ln) echo "       {$ln}" . PHP_EOL;
                }
            } else {
                if (!$isMulti) {
                    // single-stream: compact one-liner; include cap once
                    echo "Audio: " . $lines[0] . " (cap {$audioBitrate})" . PHP_EOL;
                } else {
                    echo "Audio: → aac (cap {$audioBitrate})" . PHP_EOL;
                    foreach ($lines as $ln) echo "       {$ln}" . PHP_EOL;
                }
            }
        }
    }

    if ($hasSubtitles) {
        $subLabel = count($subtitleCodecs) ? implode(', ', array_unique($subtitleCodecs)) : 'unknown';
        echo "Subtitles: discarded ($subLabel)" . PHP_EOL;
    }
    if ($inBytes !== null) echo "Input size: " . humanMBorGB($inBytes) . PHP_EOL;

    if ($dryRun) {
        $label = $videoReencoded ? 'DRY RUN (Re-encode)' : 'DRY RUN (Convert)';
        echo "$label: would write $outName then " . ($keep ? "keep" : "delete") . " original" . PHP_EOL;
        $results[] = [
            'status' => 'DRY RUN',
            'mode' => $videoReencoded ? 'Re-encoded' : 'Converted',
            'audioNote' => $audioNote,
            'in' => $inName,
            'out' => $outName,
            'inBytes' => $inBytes,
            'outBytes' => null
        ];
        continue;
    }

    if (file_exists($tmpPath)) @unlink($tmpPath);

    $progressLabel = $videoReencoded ? 'Processing: Re-encoding' : 'Processing: Converting';

    [$fCode, $fErr, $lastOutTimeSec] = runFfmpegWithProgress($args, $durationSec, $progressLabel, $ffmpegTimeoutSec);

    if ($fCode !== 0 || !file_exists($tmpPath) || filesize($tmpPath) === 0) {
        if (file_exists($tmpPath)) @unlink($tmpPath);

        $probeAt = (isset($lastOutTimeSec) && is_numeric($lastOutTimeSec))
            ? max(0.0, (float)$lastOutTimeSec + 5.0)
            : null;

        [$cause, $causeDetail] = diagnoseFailureStream($inputPath, $probeAt, $hasAudio, 25);

        // Always print the one-line cause
        stderr($causeDetail);

        // Distinguish timeouts cleanly in status/summary
        $isTimeout = ($fCode === 124) || (stripos((string)$fErr, 'TIMED OUT') !== false);
        $statusStr = $isTimeout ? 'FAILED (timeout)' : 'FAILED';

        // Quarantine only if stderr smells like true corruption (and NOT a timeout)
        $looksCorrupt = !$isTimeout && preg_match(
            '/header damaged|Invalid data found|Error submitting packet|Input packet size too small|Decoding error/i',
            (string)$fErr
        ) === 1;

        $movedTo = null;
        if ($looksCorrupt) {
            $movedTo = moveToCorrupt($inputPath, $corruptDir);
        }

        $movedMsg = $movedTo ? (' (MOVED to _corrupt/' . basename($movedTo) . ')') : '';
        stderr("{$statusStr}: ffmpeg did not create a valid MP4.$movedMsg");

        if (trim($fErr) !== '') {
            stderr($verboseErrors ? trim($fErr) : compactErrForConsole((string)$fErr));
        }

        $results[] = [
            'status' => $statusStr,
            'mode' => 'Failed',
            'audioNote' => $audioNote,
            'movedTo' => $movedTo,
            'in' => $inName,
            'out' => $outName,
            'inBytes' => $inBytes,
            'outBytes' => null
        ];
        continue;
    }

    if (!@rename($tmpPath, $outPath)) {
        if (!@copy($tmpPath, $outPath)) {
            @unlink($tmpPath);
            stderr("FAILED: Could not move temp output to final: $outName");
            $results[] = [
                'status' => 'FAILED (rename)',
                'mode' => 'Failed',
                'audioNote' => $audioNote,
                'in' => $inName,
                'out' => $outName,
                'inBytes' => $inBytes,
                'outBytes' => null
            ];
            continue;
        }
        @unlink($tmpPath);
    }

    echo "Validating output..." . PHP_EOL;
    [$okOut, $whyOut, $detailOut] = validateMp4Output($outPath, $durationSec);

    if (!$okOut) {
        if ($whyOut === 'output decode check timed out') {
            stderr("WARNING: output validation timed out; keeping files in place: {$outName}");
            if ($detailOut !== '') {
                stderr($verboseErrors ? $detailOut : compactErrForConsole((string)$detailOut));
            }

            $outBytes = @filesize($outPath);
            $outBytes = ($outBytes === false) ? null : (int)$outBytes;

            // Treat as “success-ish” for reporting time/size
            echo "OK (validation timeout): created $outName" . PHP_EOL;
            if ($outBytes !== null) echo "Output size: " . humanMBorGB($outBytes) . PHP_EOL;

            $fileElapsed = microtime(true) - $fileStart;
            echo "Time to process: " . formatSeconds($fileElapsed) . PHP_EOL;
            if ($tempLine !== null) echo $tempLine . PHP_EOL;

            $results[] = [
                'status' => 'DONE (validation timeout)',
                'mode' => $videoReencoded ? 'Re-encoded' : 'Converted',
                'audioNote' => $audioNote ?? '',
                'in' => $inName,
                'out' => $outName,
                'inBytes' => $inBytes,
                'outBytes' => $outBytes,
            ];
            continue;
        }

        $movedOutTo = moveToCorrupt($outPath, $corruptDir);
        $movedSrcTo = moveToCorrupt($inputPath, $corruptDir);

        $outMsg = $movedOutTo ? ('MOVED out→_corrupt/' . basename($movedOutTo)) : 'OUT NOT MOVED';
        $srcMsg = $movedSrcTo ? ('MOVED src→_corrupt/' . basename($movedSrcTo)) : 'SRC NOT MOVED';

        stderr("FAILED: output validation ({$whyOut}): {$outName}  ({$outMsg}; {$srcMsg})");
        if ($detailOut !== '') {
            stderr($verboseErrors ? $detailOut : compactErrForConsole((string)$detailOut));
        }

        $results[] = [
            'status' => 'FAILED (output invalid)',
            'mode' => 'Failed',
            'audioNote' => $audioNote ?? '',
            'movedTo' => $movedSrcTo,
            'in' => $inName,
            'out' => $outName,
            'inBytes' => $inBytes,
            'outBytes' => null
        ];
        continue;
    }

    if ($junkMode !== 'off') {
        echo "Junk check (output)..." . PHP_EOL;
        $outDur = probeDurationSec($outPath) ?? $durationSec;
        [$isJunkOut, $eOut, $yOut, $junkOutDetail] = detectJunkStaticVideo(
            $outPath,
            $outDur,
            $junkSampleFps,
            $junkTimeoutSec,
            $junkEntropyThr,
            $junkYstdThr
        );

        if ($isJunkOut) {
            $movedOutTo = moveToCorrupt($outPath, $corruptDir);
            $movedSrcTo = moveToCorrupt($inputPath, $corruptDir);

            $outMsg = $movedOutTo ? ('MOVED out→_corrupt/' . basename($movedOutTo)) : 'OUT NOT MOVED';
            $srcMsg = $movedSrcTo ? ('MOVED src→_corrupt/' . basename($movedSrcTo)) : 'SRC NOT MOVED';

            $detail = ($junkOutDetail !== '') ? " ({$junkOutDetail})" : '';
            stderr("FAILED: output is junk/static{$detail}: {$outName}  ({$outMsg}; {$srcMsg})");

            $results[] = [
                'status' => 'FAILED (output junk/static)',
                'mode' => 'Failed',
                'audioNote' => $audioNote ?? '',
                'movedTo' => $movedSrcTo,
                'in' => $inName,
                'out' => $outName,
                'inBytes' => $inBytes,
                'outBytes' => null
            ];
            continue;
        }
    }

    if ($srcMTime !== false && file_exists($outPath)) {
        @touch($outPath, $srcMTime, $srcATime ?: $srcMTime);
    }

    $outBytes = @filesize($outPath);
    $outBytes = ($outBytes === false) ? null : (int)$outBytes;

    echo "OK: created $outName" . PHP_EOL;
    if ($outBytes !== null) echo "Output size: " . humanMBorGB($outBytes) . PHP_EOL;
    $fileElapsed = microtime(true) - $fileStart;
    echo "Time to process: " . formatSeconds($fileElapsed) . PHP_EOL;
    if ($tempLine !== null) echo $tempLine . PHP_EOL;
    $deleteFailed = false;

    if (!$keep) {
        clearstatcache(true, $inputPath);

        if (file_exists($inputPath)) {
            if (@unlink($inputPath)) {
                clearstatcache(true, $inputPath);
            } else {
                clearstatcache(true, $inputPath);
                if (file_exists($inputPath)) {
                    $deleteFailed = true;
                    stderr("WARNING: Could not delete original file: $inName");
                }
            }
        }
    } else {
        echo "Kept original (per --keep)" . PHP_EOL;
    }

    $results[] = [
        'status' => 'DONE',
        'deleteFailed' => $deleteFailed,
        'mode' => $videoReencoded ? 'Re-encoded' : 'Converted',
        'audioNote' => $audioNote,
        'in' => $inName,
        'out' => $outName,
        'inBytes' => $inBytes,
        'outBytes' => $outBytes
    ];
}

/**
 * ============================================================
 *  Summary output
 * ============================================================
 */
echo PHP_EOL . "====================" . PHP_EOL;
echo "Summary" . PHP_EOL;
echo "====================" . PHP_EOL;

$convertedCount = 0;
$failedCount = 0;
$skippedCount = 0;

foreach ($results as $r) {
    $status = (string)($r['status'] ?? '');
    $mode   = (string)($r['mode'] ?? '');

    if (str_starts_with($status, 'DONE') && ($mode === 'Converted' || $mode === 'Re-encoded')) {
        $convertedCount++;
    } elseif ($status === 'FAILED' || str_starts_with($status, 'FAILED')) {
        $failedCount++;
    } elseif ($status === 'EXISTS' || str_starts_with($status, 'SKIPPED') || $status === 'DRY RUN') {
        $skippedCount++;
    }
}

$processedCount = count($results);

echo "Files processed: {$processedCount}" . PHP_EOL;
echo "Converted: {$convertedCount}  |  Failed: {$failedCount}  |  Skipped: {$skippedCount}" . PHP_EOL;

$runElapsed = microtime(true) - $runStart;
echo "Total elapsed: " . formatHoursMinutes($runElapsed) . PHP_EOL;
echo PHP_EOL;

clearstatcache();

$rows = [];
$wMode = 0;
$wStatus = 0;
$wInCol = 0;

$threshold = 50 * 1024 * 1024; // 50MB

foreach ($results as $r) {
    $mode = $r['mode'] ?? '';
    $status = $r['status'] ?? '';
    $audioNote = $r['audioNote'] ?? '';

    $modeLabel = $mode;
    if (in_array($mode, ['Converted', 'Re-encoded'], true) && $audioNote !== '') {
        $modeLabel .= " ($audioNote)";
    }

    $deleteFailed = !empty($r['deleteFailed']);
    $statusBase = $status !== '' ? rtrim($status, ':') : '';

    if ($status === 'DONE' && $deleteFailed) {
        $statusBase = 'DONE (could not delete src)';
    }

    $movedTo = $r['movedTo'] ?? null;
    if (is_string($movedTo) && $movedTo !== '') {
        $statusBase .= ' (moved→_corrupt/' . basename($movedTo) . ')';
    }

    $statusLabel = ($statusBase !== '') ? ($statusBase . ':') : '';

    $sizes = '';
    if (
        isset($r['inBytes'], $r['outBytes']) &&
        is_int($r['inBytes']) &&
        is_int($r['outBytes']) &&
        ($r['outBytes'] - $r['inBytes']) > $threshold
    ) {
        $sizes = ' (' . humanMBorGB($r['inBytes']) . ' -> ' . humanMBorGB($r['outBytes']) . ')';
    }

    $in = $r['in'] ?? '';
    $out = $r['out'] ?? '';
    $inCol = $in . $sizes;

    $rows[] = [$modeLabel, $statusLabel, $inCol, $out];

    $wMode   = max($wMode,   displayWidth($modeLabel));
    $wStatus = max($wStatus, displayWidth($statusLabel));
    $wInCol  = max($wInCol,  displayWidth($inCol));
}

foreach ($rows as [$modeLabel, $statusLabel, $inCol, $out]) {
    echo "- "
        . padRight($modeLabel, $wMode) . " "
        . padRight($statusLabel, $wStatus) . "  "
        . padRight($inCol, $wInCol)
        . "  ->  {$out}"
        . PHP_EOL;
}

echo PHP_EOL . "Done." . PHP_EOL;
