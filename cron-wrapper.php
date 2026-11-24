#!/usr/local/bin/php
<?php
/**
 * Cron mail wrapper for the WHM Backup Solutions scripts.
 *
 * Cron's own MAILTO delivery cannot work on this host: the sendmail binary is a
 * CageFS proxyexec wrapper and its exim handoff fails with
 *
 *   sendmail: domain ownership lookup failed:
 *   cannot open /etc/domainusers: permission denied
 *
 * so anything cron tries to mail is silently lost.
 *
 * This wrapper runs the command, captures stdout and stderr, and delivers the
 * result over authenticated SMTP using the same smtp_config.php the backup
 * script itself uses. Cron then has no output of its own to mail.
 *
 * Usage:
 *   php cron-wrapper.php "label" [--notify=always|on-error] [--cwd=DIR] -- <command> [args...]
 *
 * Behaviour:
 *   - output is always appended to logs/cron-wrapper.log for local forensics
 *   - routine "nothing happened" output is NOT emailed, to keep cron quiet
 *   - --notify=always (default) mails any non-benign output, mirroring what a
 *     working cron MAILTO would have sent
 *   - --notify=on-error mails only on a non-zero exit, stderr, or output that
 *     looks like a failure; use this for frequent jobs so a 13-minute cron does
 *     not send a message per account
 *   - identical output is not re-emailed within the dedupe window
 *   - exit code of the wrapped command is propagated
 *   - if the email cannot be sent, output is printed to stdout so cron's
 *     MAILTO has something to work with if the MTA is ever repaired
 *
 * The SMTP password is never logged, printed, or passed on a command line.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$args = array_slice($argv, 1);
if (count($args) < 2) {
    fwrite(STDERR, "usage: cron-wrapper.php \"label\" [--cwd=DIR] [--] <command> [args...]\n");
    exit(2);
}

$label = array_shift($args);

$notify_mode = 'always';
if (isset($args[0]) && strpos($args[0], '--notify=') === 0) {
    $notify_mode = substr(array_shift($args), strlen('--notify='));
    if (!in_array($notify_mode, array('always', 'on-error'), true)) {
        fwrite(STDERR, "cron-wrapper: --notify must be always or on-error\n");
        exit(2);
    }
}

$cwd = null;
if (isset($args[0]) && strpos($args[0], '--cwd=') === 0) {
    $cwd = substr(array_shift($args), strlen('--cwd='));
}
if (isset($args[0]) && $args[0] === '--') {
    array_shift($args);
}
if (count($args) === 0) {
    fwrite(STDERR, "cron-wrapper: no command given\n");
    exit(2);
}

$script_dir = __DIR__;
$log_dir    = $script_dir . '/logs';
$log_file   = $log_dir . '/cron-wrapper.log';
$state_file = $script_dir . '/temp/cron-wrapper-state.json';

/* ------------------------------------------------------------------ helpers */

function cw_log($file, $line)
{
    @file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND);
}

/**
 * Output that means "the cron ran and there was nothing to do". Matching these
 * keeps an every-13-minute cron from mailing all night.
 */
function cw_is_benign($output)
{
    $t = trim(preg_replace('/<br\s*\/?>/i', "\n", $output));
    if ($t === '') {
        return true;
    }
    $benign = array(
        'No Backups Required.',
        'Backup Already Started. To Generate A New Backup Use Force Variable.',
    );
    foreach ($benign as $b) {
        if ($t === $b) {
            return true;
        }
    }
    return false;
}

function cw_smtp_send($to, $subject, $body, $c)
{
    $err = function ($m) { return 'SMTP Error: ' . $m; };

    $fp = @stream_socket_client('tcp://' . $c['host'] . ':' . $c['port'], $en, $es, 20);
    if (!$fp) {
        return $err('connect to ' . $c['host'] . ':' . $c['port'] . ' failed (' . $es . ')');
    }
    stream_set_timeout($fp, 25);

    $read = function ($expect) use ($fp) {
        $out = '';
        while (($l = fgets($fp, 4096)) !== false) {
            $out .= $l;
            if (!isset($l[3]) || $l[3] === ' ') {
                break;
            }
        }
        $code = (int) substr(ltrim($out), 0, 3);
        return ($code === $expect) ? array(true, $out)
                                   : array(false, 'expected ' . $expect . ', got ' . trim($out));
    };
    $wr = function ($s) use ($fp) { fwrite($fp, $s . "\r\n"); };

    $r = $read(220); if (!$r[0]) { fclose($fp); return $err($r[1]); }
    $wr('EHLO ' . ($c['ehlo_host'] ?? 'localhost'));
    $r = $read(250); if (!$r[0]) { fclose($fp); return $err($r[1]); }

    if (($c['secure'] ?? '') === 'tls') {
        $wr('STARTTLS');
        $r = $read(220); if (!$r[0]) { fclose($fp); return $err($r[1]); }
        if (@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
            fclose($fp);
            return $err('TLS negotiation failed');
        }
        $wr('EHLO ' . ($c['ehlo_host'] ?? 'localhost'));
        $r = $read(250); if (!$r[0]) { fclose($fp); return $err($r[1]); }
    }

    $wr('AUTH LOGIN');                      $r = $read(334); if (!$r[0]) { fclose($fp); return $err($r[1]); }
    $wr(base64_encode($c['username']));     $r = $read(334); if (!$r[0]) { fclose($fp); return $err($r[1]); }
    $wr(base64_encode($c['password']));     $r = $read(235); if (!$r[0]) { fclose($fp); return $err('authentication rejected'); }

    $wr('MAIL FROM:<' . $c['from'] . '>');  $r = $read(250); if (!$r[0]) { fclose($fp); return $err($r[1]); }
    $wr('RCPT TO:<' . $to . '>');           $r = $read(250); if (!$r[0]) { fclose($fp); return $err($r[1]); }
    $wr('DATA');                            $r = $read(354); if (!$r[0]) { fclose($fp); return $err($r[1]); }

    $hdrs = 'From: ' . ($c['from_name'] ?? $c['from']) . ' <' . $c['from'] . ">\r\n"
          . 'To: <' . $to . ">\r\n"
          . 'Subject: ' . $subject . "\r\n"
          . 'Date: ' . date('r') . "\r\n"
          . "MIME-Version: 1.0\r\n"
          . "Content-Type: text/plain; charset=UTF-8\r\n"
          . "X-Mailer: cron-wrapper (WHM Backup Solutions)\r\n";

    $data = $hdrs . "\r\n" . str_replace("\n", "\r\n", str_replace("\r\n", "\n", $body));
    foreach (explode("\r\n", $data) as $line) {
        if (isset($line[0]) && $line[0] === '.') {
            $line = '.' . $line;              // dot-stuffing, RFC 5321
        }
        $wr($line);
    }
    $wr('.');
    $r = $read(250); if (!$r[0]) { fclose($fp); return $err($r[1]); }

    $wr('QUIT');
    @fclose($fp);
    return null;                              // null = success
}

/* --------------------------------------------------------------------- run */

$host   = gethostname();
$start  = microtime(true);
$cmdline = implode(' ', array_map('escapeshellarg', $args));

$descriptors = array(0 => array('file', '/dev/null', 'r'),
                     1 => array('pipe', 'w'),
                     2 => array('pipe', 'w'));

$proc = @proc_open($args, $descriptors, $pipes, $cwd);
if (!is_resource($proc)) {
    $msg = "cron-wrapper: could not execute: $cmdline";
    cw_log($log_file, "FAIL $label :: $msg");
    fwrite(STDERR, $msg . "\n");
    exit(127);
}

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($proc);
$secs = round(microtime(true) - $start, 2);

$stdout = (string) $stdout;
$stderr = (string) $stderr;

/* keep local forensics regardless of mail outcome */
cw_log($log_file, sprintf('RUN %s :: exit=%d :: %.2fs :: out=%dB err=%dB',
        $label, $exit, $secs, strlen($stdout), strlen($stderr)));

/* --------------------------------------------------------- decide + notify */

$benign   = cw_is_benign($stdout);
$failed   = ($exit !== 0) || ($stderr !== '')
            || preg_match('/ERROR:|FAILED|Unable To|Cannot |Permission denied/i', $stdout);

if ($notify_mode === 'on-error') {
    $notify = $failed;
} else {
    $notify = $failed || !$benign;
}

$sent     = false;
$send_err = null;

if ($notify) {
    $conf_file = $script_dir . '/smtp_config.php';
    if (!is_file($conf_file)) {
        $send_err = 'smtp_config.php not found';
    } else {
        $smtp_config = null;
        include $conf_file;
        if (!is_array($smtp_config)) {
            $send_err = 'smtp_config.php did not define $smtp_config';
        } elseif (empty($smtp_config['to'])) {
            $send_err = 'no recipient configured (set "to" in smtp_config.php)';
        } else {
            /* dedupe identical output so a repeating failure cannot spam */
            $digest   = sha1($label . '|' . $notify_mode . '|' . $exit . '|' . $stdout . '|' . $stderr);
            $last     = array();
            if (is_file($state_file)) {
                $last = json_decode((string) @file_get_contents($state_file), true);
                if (!is_array($last)) {
                    $last = array();
                }
            }
            $window   = 3600;   // seconds
            $recent   = (isset($last[$label]['digest'], $last[$label]['ts']))
                        && ($last[$label]['digest'] === $digest)
                        && ((time() - (int) $last[$label]['ts']) < $window);

            if ($recent) {
                cw_log($log_file, "SKIP-MAIL $label :: identical output within {$window}s");
            } else {
                $status = ($exit === 0) ? 'ok' : 'FAILED';
                $subject = sprintf('[%s] cron %s: %s (exit %d)', $host, $label, $status, $exit);

                $body  = "Cron job : $label\n";
                $body .= "Host     : $host\n";
                $body .= "Command  : $cmdline\n";
                $body .= 'Started  : ' . date('Y-m-d H:i:s T', (int) $start) . "\n";
                $body .= 'Duration : ' . $secs . "s\n";
                $body .= 'Exit code: ' . $exit . "\n";
                $body .= 'Notify   : ' . $notify_mode . "\n";
                $body .= str_repeat('-', 60) . "\n";

                $body .= "\nSTDOUT\n" . str_repeat('-', 60) . "\n";
                $body .= ($stdout === '' ? "(empty)\n" : preg_replace('/<br\s*\/?>/i', "\n", $stdout) . "\n");

                if ($stderr !== '') {
                    $body .= "\nSTDERR\n" . str_repeat('-', 60) . "\n" . $stderr . "\n";
                }

                $body .= "\n" . str_repeat('-', 60) . "\n";
                $body .= "This message was generated by cron-wrapper.php.\n";
                $body .= "Cron's own MAILTO delivery is unusable on this host (CageFS\n";
                $body .= "blocks sendmail from reading /etc/domainusers), so output is\n";
                $body .= "delivered over authenticated SMTP instead.\n";

                if (strlen($body) > 512000) {
                    $body = substr($body, 0, 512000) . "\n\n[truncated at 500 KB]\n";
                }

                $send_err = cw_smtp_send($smtp_config['to'], $subject, $body, $smtp_config);

                if ($send_err === null) {
                    $sent = true;
                    $last[$label] = array('digest' => $digest, 'ts' => time());
                    @file_put_contents($state_file, json_encode($last), LOCK_EX);
                    cw_log($log_file, "MAIL-SENT $label :: to {$smtp_config['to']} :: exit=$exit");
                } else {
                    cw_log($log_file, "MAIL-FAIL $label :: $send_err");
                }
            }
        }
    }

    if ($send_err !== null) {
        cw_log($log_file, "MAIL-ERROR $label :: $send_err");
    }
}

/* If we needed to notify and could not, surface to cron so MAILTO has a chance. */
if ($notify && !$sent && $send_err !== null) {
    echo "cron-wrapper: could not deliver notification ($send_err)\n";
    echo "Command: $cmdline\nExit: $exit\n";
    echo preg_replace('/<br\s*\/?>/i', "\n", $stdout);
    if ($stderr !== '') {
        echo "\nSTDERR:\n" . $stderr;
    }
}

exit($exit);
