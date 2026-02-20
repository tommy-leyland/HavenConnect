<?php

if (!defined('ABSPATH')) exit;

/**
 * HavenConnect_Logger
 *
 * Simple line-based logger that stores output in a transient so the admin UI
 * can display the most recent run results. Buffer messages with ->log(), then
 * call ->save() when the process completes.
 */
class HavenConnect_Logger {

    /** @var string Transient key to store the last log output */
    private $transient_key;

    /** @var array In-memory buffer of log lines */
    private $buffer = [];

    /** @var int Lifetime of transient in seconds (default: 1 hour) */
    private $ttl = 3600;

    public function __construct(string $transient_key = 'havenconnect_log', int $ttl = 3600) {
        $this->transient_key = $transient_key;
        $this->ttl = max(60, (int) $ttl);
    }

    /**
     * Append a message to the in-memory buffer with a timestamp prefix.
     * @param string $message
     * @return void
     */
    public function log(string $message): void {
        $ts = gmdate('Y-m-d H:i:s');
        $this->buffer[] = "[$ts] $message";
    }

    /**
     * Clear the existing buffer and remove any previously saved log transient.
     * @return void
     */
    public function clear(): void {
        $this->buffer = [];
        delete_transient($this->transient_key);
    }

    /**
     * Save current buffer to the transient (overwrites previous).
     * @return void
     */
    public function save(): void {
        $text = implode("\n", $this->buffer);
        set_transient($this->transient_key, $text, $this->ttl);
    }

    /**
     * Retrieve the last saved log text (or empty string).
     * @return string
     */
    public function get(): string {
        $text = get_transient($this->transient_key);
        return is_string($text) ? $text : '';
    }
}