<?php

namespace Camoo\Enkap\WooCommerce\Logger;

use WC_Logger;

defined('ABSPATH') || exit; // Exit if accessed directly

if (!class_exists(Logger::class)) {
    class Logger
    {
        private $id;

        private bool $enabled;

        private ?WC_Logger $logger;

        public function __construct($id, bool $enabled = false)
        {
            $this->id = $id;
            $this->logger = null;
            $this->enabled = $enabled;

            add_action('plugins_loaded', [$this, 'initLogger'], 1);
        }

        public function initLogger(): void
        {
            if (function_exists('wc_get_logger') && is_null($this->logger)) {
                $this->logger = wc_get_logger();
            }
        }

        public function setEnabled($enabled): void
        {
            $this->enabled = $enabled;
        }

        public function log($level, $file, $line, $message): void
        {
            $this->initLogger();

            if (!is_object($this->logger) || !$this->enabled) {
                return;
            }

            $this->logger->log(
                $level,
                $this->getMessage(sanitize_text_field($file), absint($line), sanitize_textarea_field($message)),
                ['source' => $this->id]
            );
        }

        public function debug($file, $line, $message): void
        {
            $this->log('debug', $file, $line, $message);
        }

        public function info($file, $line, $message): void
        {
            $this->log('info', $file, $line, $message);
        }

        public function notice($file, $line, $message): void
        {
            $this->log('notice', $file, $line, $message);
        }

        public function warning($file, $line, $message): void
        {
            $this->log('warning', $file, $line, $message);
        }

        public function error($file, $line, $message): void
        {
            $this->log('error', $file, $line, $message);
        }

        public function critical($file, $line, $message): void
        {
            $this->log('critical', $file, $line, $message);
        }

        public function alert($file, $line, $message): void
        {
            $this->log('alert', $file, $line, $message);
        }

        public function emergency($file, $line, $message): void
        {
            $this->log('emergency', $file, $line, $message);
        }

        private function getMessage($file, $line, $message): string
        {
            return sprintf('[%s:%s] %s', basename($file), $line, $message);
        }
    }
}
