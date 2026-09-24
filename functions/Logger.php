<?php

namespace Shuchkin;

class Logger
{
    private string $file;

    public function __construct(string $file)
    {
        $this->file = $file;

        $dir = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!file_exists($file)) {
            file_put_contents($file, '');
        }
    }

    public function log(string $message): void
    {
        $time = date('Y-m-d H:i:s');
        //file_put_contents($this->file, "[$time] $message" . PHP_EOL, FILE_APPEND);
    }

    public function logArray(array $data, string $prefix = ''): void
    {
        $message = $prefix . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $this->log($message);
    }
}

// Уточнена ответственность компонента: журналирование.

// Отмечена граница входных данных: журналирование.

// Отмечено требование к безопасному значению: журналирование.

// Зафиксировано ожидание по формату данных: журналирование.
