<?php
if (!defined('ABSPATH')) exit;

/**
 * HavenConnect_Logger (append-safe, multi-request)
 *
 * - Persists logs to: wp-uploads/havenconnect/hcn_log.txt
 * - save() APPENDS by default (critical for AJAX multi-request runs)
 * - clear() truncates once at the beginning of a job
 * - get() returns the whole file for admin display
 * - Uses flock() to avoid concurrent write corruption
 */
class HavenConnect_Logger {

    /** @var string Absolute path to log file */
    private $file;

    /** @var array<string> In-memory buffer for current request */
    private $buffer = [];

    /** @var string Log file basename */
    private $basename;

    public function __construct(string $basename = 'hcn_log') {
        $this->basename = preg_replace('/[^a-z0-9_\-]/i', '_', $basename);

        $upload = wp_upload_dir();
        $dir    = trailingslashit($upload['basedir']) . 'havenconnect';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $this->file = trailingslashit($dir) . $this->basename . '.txt';

        // Ensure the file exists
        if (!file_exists($this->file)) {
            @touch($this->file);
        }
    }

    /**
     * Add a one-line message with timestamp to the in-memory buffer.
     */
    public function log(string $message): void {
        $line = sprintf("[%s] %s", gmdate('Y-m-d H:i:s'), $message);
        $this->buffer[] = $line;
    }

    /**
     * Save current buffer to disk.
     * - By default, we APPEND so AJAX “single” requests add lines instead of overwriting.
     * - Pass $append=false ONLY when you want to overwrite the entire log (rare).
     */
    public function save(bool $append = true): void {
        if (empty($this->buffer)) return;

        $mode = $append ? 'a' : 'w';
        $fp   = @fopen($this->file, $mode);
        if ($fp) {
            // Acquire an exclusive lock, write, then release
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, implode(PHP_EOL, $this->buffer) . PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
            fclose($fp);
        }

        // Clear per-request buffer after writing
        $this->buffer = [];
    }

    /**
     * Truncate the log file + clear the in-memory buffer.
     * Call this ONCE at the start of a job (AJAX “start” handler already does this).
     */
    public function clear(): void {
        // Clear current buffer
        $this->buffer = [];

        // Truncate on disk
        $fp = @fopen($this->file, 'w');
        if ($fp) {
            if (flock($fp, LOCK_EX)) {
                // write nothing; truncate to zero
                fflush($fp);
                flock($fp, LOCK_UN);
            }
            fclose($fp);
        }
    }

    /**
     * Return the entire log as a string (for admin page viewer).
     */
    public function get(): string {
        if (!file_exists($this->file)) return '';
        $contents = @file_get_contents($this->file);
        return is_string($contents) ? $contents : '';
    }

    /**
     * Retrieve the path in case you want to expose/download it.
     */
    public function path(): string {
        return $this->file;
    }
}