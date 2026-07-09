<?php
declare(strict_types=1);

final class HttpClient
{
    public function get(string $url, array $headers = []): string
    {
        $lastError = null;
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            try {
                return $this->getOnce($url, $headers);
            } catch (RuntimeException $e) {
                $lastError = $e;
                if (str_contains($e->getMessage(), 'HTTP request failed: 429')) {
                    sleep(min(45, 5 * $attempt * $attempt));
                } else {
                    usleep(250000 * $attempt);
                }
            }
        }
        throw $lastError ?: new RuntimeException('HTTP request failed');
    }

    private function getOnce(string $url, array $headers = []): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'psilocybin-research-publication-tracker/1.0',
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($body === false || $code >= 400) {
                throw new RuntimeException('HTTP request failed: ' . $code . ' ' . $error);
            }
            return (string)$body;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'header' => "User-Agent: psilocybin-research-publication-tracker/1.0\r\n" . implode("\r\n", $headers),
            ],
        ]);
        $body = file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('HTTP request failed');
        }
        return $body;
    }
}
