<?php

declare(strict_types=1);

namespace App\Services;

/**
 * User-agent classification: bot detection + friendly browser/OS/device labels.
 * Adapted from the Brionic Payments analytics classifier.
 */
final class UserAgent
{
    /** UA substrings → friendly bot name. */
    private const KNOWN_BOTS = [
        'googlebot' => 'Googlebot', 'storebot-google' => 'Googlebot',
        'bingbot' => 'Bingbot', 'adidxbot' => 'Bingbot',
        'slurp' => 'Yahoo! Slurp', 'duckduckbot' => 'DuckDuckBot',
        'baiduspider' => 'Baidu', 'yandexbot' => 'YandexBot',
        'sogou' => 'Sogou', 'exabot' => 'Exabot',
        'gptbot' => 'GPTBot', 'oai-searchbot' => 'OpenAI', 'chatgpt-user' => 'ChatGPT',
        'claudebot' => 'ClaudeBot', 'claude-web' => 'Claude', 'anthropic-ai' => 'Anthropic',
        'perplexitybot' => 'PerplexityBot', 'amazonbot' => 'Amazonbot',
        'applebot' => 'Applebot', 'bytespider' => 'Bytespider', 'ccbot' => 'CCBot',
        'ahrefsbot' => 'AhrefsBot', 'semrushbot' => 'SemrushBot', 'mj12bot' => 'MJ12bot',
        'dotbot' => 'DotBot', 'petalbot' => 'PetalBot', 'dataforseobot' => 'DataForSeo',
        'facebookexternalhit' => 'Facebook', 'meta-externalagent' => 'Meta',
        'twitterbot' => 'Twitterbot', 'linkedinbot' => 'LinkedInBot',
        'slackbot' => 'Slackbot', 'discordbot' => 'Discordbot',
        'telegrambot' => 'TelegramBot', 'whatsapp' => 'WhatsApp',
        'pingdom' => 'Pingdom', 'uptimerobot' => 'UptimeRobot', 'gtmetrix' => 'GTmetrix',
    ];

    private const BOT_HINTS = [
        'bot', 'crawl', 'spider', 'slurp', 'headless', 'curl', 'wget',
        'python-requests', 'python-httpx', 'go-http-client', 'okhttp', 'java/',
        'libwww', 'httpclient', 'feedfetcher', 'phantomjs', 'scrapy', 'axios/', 'node-fetch',
    ];

    /** @return array{0:bool,1:string} [isBot, friendlyName] */
    public static function classify(string $ua): array
    {
        $ua = trim($ua);
        if ($ua === '') {
            return [true, 'Unknown / no agent'];
        }
        $low = strtolower($ua);
        foreach (self::KNOWN_BOTS as $needle => $name) {
            if (str_contains($low, $needle)) {
                return [true, $name];
            }
        }
        foreach (self::BOT_HINTS as $hint) {
            if (str_contains($low, $hint)) {
                return [true, 'Other bot'];
            }
        }
        return [false, ''];
    }

    /** @return array{browser:string,os:string,device:string} */
    public static function parse(string $ua): array
    {
        $ua = trim($ua);
        if ($ua === '') {
            return ['browser' => 'Unknown', 'os' => 'Unknown', 'device' => 'Unknown'];
        }

        $os = 'Unknown';
        if (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            $os = 'iOS';
        } elseif (preg_match('/Android/i', $ua)) {
            $os = 'Android';
        } elseif (preg_match('/Windows/i', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/Mac OS X|Macintosh/i', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/CrOS/i', $ua)) {
            $os = 'ChromeOS';
        } elseif (preg_match('/Linux/i', $ua)) {
            $os = 'Linux';
        }

        $browser = 'Unknown';
        if (preg_match('#Edg(e|A|iOS)?/#i', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('#OPR/|Opera#i', $ua)) {
            $browser = 'Opera';
        } elseif (preg_match('/SamsungBrowser/i', $ua)) {
            $browser = 'Samsung Internet';
        } elseif (preg_match('#Firefox/#i', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('#Chrome/#i', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('#Version/.*Safari#i', $ua) || (preg_match('/Safari/i', $ua) && !preg_match('/Chrome/i', $ua))) {
            $browser = 'Safari';
        }

        $device = 'Desktop';
        if (preg_match('/iPad|Tablet/i', $ua)) {
            $device = 'Tablet';
        } elseif (preg_match('/Mobi|iPhone|Android.*Mobile|iPod/i', $ua)) {
            $device = 'Mobile';
        }

        return ['browser' => $browser, 'os' => $os, 'device' => $device];
    }
}
