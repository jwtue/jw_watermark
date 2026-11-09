<?php

/**
 * Checks where the watermark actually lands for the various offset arguments.
 *
 * Usage: php offset-test.php <install-root>
 *
 * The watermark is red, the base image blue and transparent, and the white
 * marker block is excluded by requiring red to dominate blue — so the top-left
 * corner of the red bounding box is the watermark's position. The alpha ramp
 * makes the watermark's right edge fade out, which is why only the top-left
 * corner is measured; that is where it is most opaque.
 */
$root = rtrim(str_replace('\\', '/', $argv[1]), '/');

$classLoader = require $root . '/vendor/autoload.php';
chdir($root . '/public');

use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;

SystemEnvironmentBuilder::run(0, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
$container = Bootstrap::init($classLoader);

$version = (new \TYPO3\CMS\Core\Information\Typo3Version())->getVersion();

$request = (new ServerRequest('http://localhost/', 'GET'))
    ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
$GLOBALS['TYPO3_REQUEST'] = $request;

// The base image is 600x400 and is resized to 300x200 here, so a relative
// offset of 10% is 30px on width, 20px on height, 20px on short and 30px on long.
$cases = [
    'plain pixel offset on both axes' => [
        'args' => "watermarkOffset: '20'",
        'expect' => [20, 20],
    ],
    'separate pixel offsets per axis' => [
        'args' => "watermarkOffsetX: '30', watermarkOffsetY: '10'",
        'expect' => [30, 10],
    ],
    'relative X only, Y unset' => [
        'args' => "watermarkOffsetX: '10', watermarkOffsetXRelative: 'width'",
        'expect' => [30, 0],
    ],
    // Each axis must use its own unit. A general watermarkOffsetRelative is only
    // a fallback and must not shadow an axis that states its own.
    'per-axis units win over the general unit' => [
        'args' => "watermarkOffsetX: '10', watermarkOffsetXRelative: 'width',"
            . " watermarkOffsetY: '10', watermarkOffsetYRelative: 'height',"
            . " watermarkOffsetRelative: 'height'",
        'expect' => [30, 20],
    ],
    // The specific argument wins over the general one; the general one still
    // covers the axis that has no value of its own.
    'axis offset wins over the general offset' => [
        'args' => "watermarkOffset: '50', watermarkOffsetX: '10'",
        'expect' => [10, 50],
    ],
    'general unit applies to an axis without its own' => [
        'args' => "watermarkOffset: '10', watermarkOffsetRelative: 'width'",
        'expect' => [30, 30],
    ],
];

$ns = 'xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"'
    . ' xmlns:jw="http://typo3.org/ns/JwTue/Watermark/ViewHelpers"'
    . ' data-namespace-typo3-fluid="true"';
$src = 'fileadmin/jw_watermark_test/base.png';
$wm = 'fileadmin/jw_watermark_test/watermark.png';

$tpl = '<html ' . $ns . '>' . "\n";
foreach (array_keys($cases) as $i => $name) {
    $tpl .= 'CASE' . $i . ':{jw:uri.watermarkedImage(src: \'' . $src . '\', watermarkSrc: \'' . $wm
        . '\', width: \'300\', ' . $cases[$name]['args'] . ')}' . "\n";
}
$tpl .= '</html>';

$tplDir = $root . '/public/fileadmin/jw_watermark_test/Templates';
if (!is_dir($tplDir)) {
    mkdir($tplDir, 0777, true);
}
$tplFile = $tplDir . '/Offsets.html';
file_put_contents($tplFile, $tpl);

if (interface_exists(\TYPO3\CMS\Core\View\ViewFactoryInterface::class)) {
    $view = $container->get(\TYPO3\CMS\Core\View\ViewFactoryInterface::class)->create(
        new \TYPO3\CMS\Core\View\ViewFactoryData(templatePathAndFilename: $tplFile, request: $request)
    );
} else {
    $view = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Fluid\View\StandaloneView::class);
    $view->setTemplatePathAndFilename($tplFile);
    $view->setRequest($request);
}
$output = $view->render();

/** Top-left corner of the area where red dominates, i.e. the watermark. */
function redCorner(string $file): ?array
{
    $im = @imagecreatefromstring((string)file_get_contents($file));
    if (!$im) {
        return null;
    }
    imagealphablending($im, false);
    imagesavealpha($im, true);
    $minX = PHP_INT_MAX;
    $minY = PHP_INT_MAX;
    for ($x = 0; $x < imagesx($im); $x++) {
        for ($y = 0; $y < imagesy($im); $y++) {
            $c = imagecolorat($im, $x, $y);
            $r = ($c >> 16) & 0xFF;
            $b = $c & 0xFF;
            $a = ($c & 0x7F000000) >> 24;
            if ($a < 110 && $r > 110 && $r > $b + 40) {
                $minX = min($minX, $x);
                $minY = min($minY, $y);
            }
        }
    }
    return $minX === PHP_INT_MAX ? null : [$minX, $minY];
}

echo "== TYPO3 $version — watermark position per offset arguments\n";
$fail = 0;
foreach (array_keys($cases) as $i => $name) {
    if (!preg_match('/CASE' . $i . ':(\S+)/', $output, $m)) {
        printf("   [FAIL] %-42s no URL rendered\n", $name);
        $fail++;
        continue;
    }
    $file = $root . '/public/' . preg_replace('#^.*?/(fileadmin|typo3temp)/#', '$1/', $m[1]);
    $got = file_exists($file) ? redCorner($file) : null;
    $want = $cases[$name]['expect'];
    // One pixel of slack: the offsets go through round() and the watermark's own
    // edge pixels are partly transparent.
    $ok = $got !== null && abs($got[0] - $want[0]) <= 1 && abs($got[1] - $want[1]) <= 1;
    printf(
        "   [%s] %-42s want %s, got %s\n",
        $ok ? 'ok  ' : 'FAIL',
        $name,
        implode(',', $want),
        $got === null ? 'no watermark found' : implode(',', $got)
    );
    if (!$ok) {
        $fail++;
    }
}
printf("   %d cases, %d failed\n", count($cases), $fail);
exit($fail === 0 ? 0 : 1);
