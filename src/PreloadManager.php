<?php

/**
 * This file is part of the CMS Donate Engine package.
 * @copyright (c) 2025-2026 Vital Smereka (https://cde.by)
 */

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
        $cached  = \apcu_fetch($apcuKey);

        if ($cached === false || !is_array($cached)) {
            return;
        }

        $headers = [];
        $valid   = [];
        $changed = false;

        foreach ($cached as $bundle) {
            if (!isset($bundle['url'], $bundle['as'])) {
                $changed = true;
                continue;
            }

            // Файла нет на диске → бандл пересобран с новым хешем,
            // запись в APCu устарела. Не отправляем preload (404 в консоли).
            if (empty($bundle['file']) || !is_file($bundle['file'])) {
                $changed = true;
                continue;
            }

            $headers[] = "<{$bundle['url']}>; rel=preload; as={$bundle['as']}";
            $valid[]   = $bundle;
        }

        // Чистим APCu один раз — при первом запросе после пересборки.
        // Дальше is_file гоняется только по валидным записям.
        if ($changed) {
            \apcu_store($apcuKey, $valid, 86400);
        }

        if (!empty($headers)) {
            header('Link: ' . implode(', ', $headers));
            self::$headersSent = true;
        }
    }

    /**
     * @param string $file Абсолютный путь к файлу бандла на диске.
     *                     Нужен, чтобы при следующем sendCachedPreloads()
     *                     отсеять записи для пересобранных (удалённых) бандлов.
     */
    public function registerBundleForLearning(
        string $cacheKey,
        string $type,
        string $url,
        int $size,
        string $file
    ): void {
        if (!$this->enabled || !$this->currentTemplate) {
            return;
        }

        $asType  = $type === 'css' ? 'style' : 'script';
        $apcuKey = 'sab_template_' . md5($this->currentTemplate);

        $bundles = \apcu_fetch($apcuKey) ?: [];

        $bundlesByKey = [];
        foreach ($bundles as $bundle) {
            $bundlesByKey[$bundle['cache_key']] = $bundle;
        }

        if (!isset($bundlesByKey[$cacheKey])) {
            $bundlesByKey[$cacheKey] = [
                'url'       => $url,
                'as'        => $asType,
                'cache_key' => $cacheKey,
                'type'      => $type,
                'file'      => $file,
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
