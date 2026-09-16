<?php

class Relay
{
    private string $relayUrl;
    private string $secret;
    private $logger;

    private int $timeout = 5;
    private bool $enabled = true;

    public function __construct(string $relayUrl, string $secret, $logger = null)
    {
        $this->relayUrl = $relayUrl;
        $this->secret = $secret;
        $this->enabled = $relayUrl !== '' && $secret !== '';
        $this->logger = $logger;
    }

    /**
     * Главный универсальный транспорт
     */
    public function request(
        string $url,
        string $method = 'POST',
        array $headers = [],
        ?string $body = null
    ) {
        // пробуем relay
        if ($this->enabled) {
            $response = $this->sendViaRelay($url, $method, $headers, $body);

            if ($response !== false) {
                return $response;
            }

            $this->notifyRelayFailed($url);
        }

        // fallback
        return $this->sendDirect($url, $method, $headers, $body);
    }

    /**
     * ======================
     * RELAY REQUEST
     * ======================
     */
    private function sendViaRelay($url, $method, $headers, $body)
    {
        $payload = [
            'secret' => $this->secret,
            'url' => $url,
            'method' => $method,
            'headers' => $headers,
            'body' => $body
        ];

        $ch = curl_init($this->relayUrl);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            $this->log("Relay CURL error: " . curl_error($ch));
            curl_close($ch);
            return false;
        }

        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http !== 200) {
            $this->log("Relay HTTP error: $http");
            return false;
        }

        return $response;
    }

    /**
     * ======================
     * DIRECT TELEGRAM REQUEST
     * ======================
     */
    private function sendDirect($url, $method, $headers, $body)
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $this->normalizeHeaders($headers),
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);

        if ($response === false) {
            $this->log("Direct CURL error: " . curl_error($ch));
        }

        curl_close($ch);

        return $response;
    }

    private function normalizeHeaders(array $headers): array
    {
        $out = [];

        foreach ($headers as $k => $v) {
            $out[] = "$k: $v";
        }

        return $out;
    }

    /**
     * Multipart лучше отправлять напрямую
     */
    public function isRelaySafeForMultipart(): bool
    {
        return false;
    }

    /**
     * Место под уведомления
     */
    private function notifyRelayFailed($url)
    {
        // TODO:
        // отправка алерта админу
        // telegram notify
        // sentry
        // metrics

        $this->log("Relay failed. Fallback used for: $url");
    }

    private function log($msg)
    {
        if ($this->logger) {
            $this->logger->log("[Relay] " . $msg);
        }
    }
}
// Уточнена ответственность компонента: передача запросов.
