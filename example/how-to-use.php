<?php

require_once __DIR__ . '/vendor/autoload.php';

use SmartyBundler\AssetBundle;
use SmartyBundler\BundleExtension;

$combiner = new AssetBundle([
    'cache_dir'     => __DIR__ . '/cache/assets',
    'cache_url'     => '/cache/assets',
    'document_root' => __DIR__,
    'enable_apcu'   => extension_loaded('apcu'),
]);

$combiner->sendCachedPreloads('template.tpl');

$smarty = new Smarty\Smarty();
$smarty->setCompileDir(__DIR__ . '/cache/templates_c');
$smarty->addExtension(new BundleExtension($combiner));

echo $smarty->fetch('template.tpl');
