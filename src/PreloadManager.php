<?php
// src/PreloadManager.php

namespace SmartyBundler;

class PreloadManager
{
    private string $currentTemplate = '';
    private bool $enabled;
    private static bool $headersSent = false;

    public function __construct(bool $enabled = true)
    {
        $this->enabled = $enabled && extension_loaded('apcu');
    }

    public function sendCachedPreloads(): void
    {
        if (!$this->enabled || self::$headersSent || headers_sent() || !$this->currentTemplate) {
            return;
        }

        $apcuKey = 'sab_template_' . md5($this->currentTemplate);
        $cached = \apcu_fetch($apcuKey);

        if ($cached !== false && is_array($cached)) {
            $headers = [];
            foreach ($cached as $bundle) {
                if (isset($bundle['url']) && isset($bundle['as'])) {
                    $headers[] = "<{$bundle['url']}>; rel=preload; as={$bundle['as']}";
                }
            }

            if (!empty($headers)) {
                header('Link: ' . implode(', ', $headers));
                self::$headersSent = true;
            }
        }
    }

    public function registerBundleForLearning(string $cacheKey, string $type, string $url, int $size): void
    {
        if (!$this->enabled || !$this->currentTemplate) {
            return;
        }

        $asType = $type === 'css' ? 'style' : 'script';
        $apcuKey = 'sab_template_' . md5($this->currentTemplate);

        $bundles = \apcu_fetch($apcuKey) ?: [];

        // Используем ассоциативный массив для быстрой проверки существования
        $bundlesByKey = [];
        foreach ($bundles as $bundle) {
            $bundlesByKey[$bundle['cache_key']] = $bundle;
        }

        // Добавляем только если еще не существует
        if (!isset($bundlesByKey[$cacheKey])) {
            $bundlesByKey[$cacheKey] = [
                'url' => $url,
                'as' => $asType,
                'cache_key' => $cacheKey,
                'size' => $size,
                'type' => $type,
            ];

            \apcu_store($apcuKey, array_values($bundlesByKey), 86400);
        }
    }

    public function setCurrentTemplate(string $template): void
    {
        $this->currentTemplate = $template;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getCurrentTemplate(): string
    {
        return $this->currentTemplate;
    }
}
