<?php

/**
 * Renders Fluid templates that use jw_watermark inside a booted TYPO3 and
 * checks the results, following the test checklist in AGENTS.md.
 *
 * Usage: php run-test.php <install-root> <pass-number>
 *
 * Pass 1 generates the processed files, pass 2 must serve them from cache.
 */
$root = rtrim(str_replace('\\', '/', $argv[1]), '/');
$pass = (int)($argv[2] ?? 1);

// ---------------------------------------------------------------- bootstrap
$classLoader = require $root . '/vendor/autoload.php';
chdir($root . '/public');

use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

SystemEnvironmentBuilder::run(0, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
$container = Bootstrap::init($classLoader);

$version = class_exists(\TYPO3\CMS\Core\Information\Typo3Version::class)
    ? (new \TYPO3\CMS\Core\Information\Typo3Version())->getVersion()
    : 'unknown';

$results = [];
$fail = 0;
$warn = 0;

function check(string $name, bool $ok, string $detail = '', bool $warnOnly = false): void
{
    global $results, $fail, $warn;
    $state = $ok ? 'ok' : ($warnOnly ? 'warn' : 'FAIL');
    $results[] = [$state, $name, $detail];
    if (!$ok) {
        if ($warnOnly) {
            $warn++;
        } else {
            $fail++;
        }
    }
}

// ------------------------------------------------------------------ request
$request = (new ServerRequest('http://localhost/', 'GET'))
    ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
$GLOBALS['TYPO3_REQUEST'] = $request;

// ----------------------------------------------------------------- template
$tplDir = $root . '/public/fileadmin/jw_watermark_test/Templates';
if (!is_dir($tplDir)) {
    mkdir($tplDir, 0777, true);
}

$ns = 'xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"'
    . ' xmlns:jw="http://typo3.org/ns/JwTue/Watermark/ViewHelpers"'
    . ' data-namespace-typo3-fluid="true"';

$wm = 'fileadmin/jw_watermark_test/watermark.png';
$formats = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'avif' => 'image/avif'];

$tpl = '<html ' . $ns . '>' . "\n";
foreach (array_keys($formats) as $ext) {
    $src = 'fileadmin/jw_watermark_test/base.' . $ext;
    // Tag ViewHelper, with an arbitrary attribute set and a deliberately empty alt.
    $tpl .= 'TAG-' . $ext . ':<jw:watermarkedImage src="' . $src . '" watermarkSrc="' . $wm . '"'
        . ' width="300" alt="" class="wm-img" data-format="' . $ext . '" loading="lazy" title="t" />' . "\n";
    // URI ViewHelper.
    $tpl .= 'URI-' . $ext . ':{jw:uri.watermarkedImage(src: \'' . $src . '\', watermarkSrc: \'' . $wm
        . '\', width: \'300\')}' . "\n";
}
// Responsive srcset from several URI calls on the same base image.
$tpl .= 'SRCSET:';
foreach ([300, 600, 900] as $i => $w) {
    $tpl .= ($i ? ', ' : '')
        . '{jw:uri.watermarkedImage(src: \'fileadmin/jw_watermark_test/base.png\', watermarkSrc: \''
        . $wm . '\', width: \'' . $w . '\')} ' . $w . 'w';
}
$tpl .= "\n" . '</html>';

$tplFile = $tplDir . '/Test.html';
file_put_contents($tplFile, $tpl);

// --------------------------------------------------------------- render it
$output = null;
$renderError = null;
try {
    if (interface_exists(\TYPO3\CMS\Core\View\ViewFactoryInterface::class)) {
        $data = new \TYPO3\CMS\Core\View\ViewFactoryData(
            templatePathAndFilename: $tplFile,
            request: $request,
        );
        $view = $container->get(\TYPO3\CMS\Core\View\ViewFactoryInterface::class)->create($data);
        $output = $view->render();
        $viewApi = 'ViewFactoryInterface';
    } else {
        $view = GeneralUtility::makeInstance(\TYPO3\CMS\Fluid\View\StandaloneView::class);
        $view->setTemplatePathAndFilename($tplFile);
        if (method_exists($view, 'setRequest')) {
            $view->setRequest($request);
        }
        $output = $view->render();
        $viewApi = 'StandaloneView';
    }
} catch (\Throwable $e) {
    $renderError = get_class($e) . ': ' . $e->getMessage();
    $viewApi = 'failed';
}

echo "== TYPO3 $version  (View: $viewApi, Pass $pass)\n";
echo '   GFX processor: ' . ($GLOBALS['TYPO3_CONF_VARS']['GFX']['processor'] ?? '-')
    . ', imagefile_ext: ' . ($GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] ?? '-') . "\n";

if ($renderError !== null) {
    echo "   RENDER FAILED: $renderError\n";
    exit(1);
}

// ------------------------------------------------------------------ asserts
// Pixel probes, in the coordinates of the 300x200 output:
//   (40,40)   inside the watermark, over the opaque blue half -> must be red-ish
//   (100,150) opaque blue half, below the watermark           -> must stay blue
//   (250,150) transparent half, below the watermark           -> alpha must survive
function probe(string $file): array
{
    $im = @imagecreatefromstring((string)file_get_contents($file));
    if (!$im) {
        return [];
    }
    imagealphablending($im, false);
    imagesavealpha($im, true);
    $at = static function ($x, $y) use ($im) {
        $c = imagecolorat($im, $x, $y);
        return [
            'r' => ($c >> 16) & 0xFF,
            'g' => ($c >> 8) & 0xFF,
            'b' => $c & 0xFF,
            'a' => ($c & 0x7F000000) >> 24,
        ];
    };
    // Probe points are relative to the actual size, because a format the image
    // processor cannot read is passed through unresized.
    $w = imagesx($im);
    $h = imagesy($im);
    return [
        'size' => [$w, $h],
        // The watermark sits at 0,0 at its native 160x80, so this is absolute.
        'wm' => $at(40, 40),
        'blue' => $at((int)($w * 0.33), (int)($h * 0.75)),
        'clear' => $at((int)($w * 0.83), (int)($h * 0.75)),
    ];
}

$publicDir = $root . '/public/';
$uris = [];
$tagSrc = [];
$tags = [];
$probes = [];
foreach ($formats as $ext => $mime) {
    // --- tag ViewHelper
    if (preg_match('/TAG-' . $ext . ':(<img[^>]*>)/', $output, $m)) {
        $tag = $m[1];
        check("$ext tag: renders an <img> tag", true);
        check(
            "$ext tag: arbitrary attributes pass through",
            str_contains($tag, 'class="wm-img"') && str_contains($tag, 'data-format="' . $ext . '"')
            && str_contains($tag, 'loading="lazy"') && str_contains($tag, 'title="t"'),
            $tag
        );
        check("$ext tag: empty alt preserved", (bool)preg_match('/alt=""/', $tag), $tag);
        $tags[$ext] = $tag;
        if (preg_match('/src="([^"]+)"/', $tag, $s)) {
            $tagSrc[$ext] = $s[1];
        }
    } else {
        check("$ext tag: renders an <img> tag", false, 'no <img> found');
    }

    // --- uri ViewHelper
    if (preg_match('/URI-' . $ext . ':(\S+)/', $output, $m)) {
        $uri = $m[1];
        $uris[$ext] = $uri;
        $file = $publicDir . ltrim(parse_url($uri, PHP_URL_PATH) ?? '', '/');
        // The site base includes a subdirectory; strip it if present.
        if (!file_exists($file)) {
            $file = $publicDir . preg_replace('#^.*?/(fileadmin|typo3temp)/#', '$1/', $uri);
        }
        check("$ext uri: processed file exists on disk", file_exists($file), $uri);
        if (file_exists($file)) {
            $info = @getimagesize($file);
            // The invariant that matters: whatever format TYPO3 picked for the
            // processed file, the bytes must match the extension it is served
            // under. TYPO3 converts formats missing from GFX/imagefile_ext or
            // GFX/imageFileConversionFormats to PNG, so the extension is not
            // always the source's.
            $byExt = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                'webp' => 'image/webp', 'avif' => 'image/avif'];
            $actualExt = strtolower(pathinfo(parse_url($uri, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
            check(
                "$ext uri: content matches the extension it is served as (.$actualExt)",
                $info && isset($byExt[$actualExt]) && $info['mime'] === $byExt[$actualExt],
                ($info ? $info['mime'] : 'unreadable') . ' in a .' . $actualExt . ' file'
            );
            check(
                "$ext uri: format preserved (no silent conversion)",
                $info && $info['mime'] === $mime,
                ($info ? $info['mime'] : 'unreadable') . ' (TYPO3 converts formats it cannot process)',
                true
            );
            $p = probe($file);
            if ($p) {
                // A converted extension means TYPO3 could not process the source
                // format, so it never resized it either. Expect the original
                // size in that case instead of asserting a fixed one.
                $converted = $actualExt !== $ext && !($ext === 'jpg' && $actualExt === 'jpeg');
                $probes[$ext] = $p;
                if ($converted) {
                    check(
                        "$ext uri: not resized, format is not processable here",
                        $p['size'] === [600, 400],
                        implode('x', $p['size']),
                        true
                    );
                } else {
                    check(
                        "$ext uri: resized to 300x200",
                        $p['size'] === [300, 200],
                        implode('x', $p['size'])
                    );
                }
                check(
                    "$ext uri: watermark applied (red at 40,40)",
                    $p['wm']['r'] > 120 && $p['wm']['r'] > $p['wm']['b'] + 30,
                    sprintf('rgba(%d,%d,%d,%d)', $p['wm']['r'], $p['wm']['g'], $p['wm']['b'], $p['wm']['a'])
                );
                check(
                    "$ext uri: untouched area still blue (100,150)",
                    $p['blue']['b'] > 120 && $p['blue']['b'] > $p['blue']['r'] + 30,
                    sprintf('rgba(%d,%d,%d,%d)', $p['blue']['r'], $p['blue']['g'], $p['blue']['b'], $p['blue']['a'])
                );
                if ($ext !== 'jpg') {
                    check(
                        "$ext uri: alpha preserved (250,150 transparent)",
                        $p['clear']['a'] >= 100,
                        'alpha=' . $p['clear']['a']
                    );
                }
            }
        }
    } else {
        check("$ext uri: returns a URL", false, 'no URL found');
    }
}

// --- the tag's width/height must describe the image it actually points at
foreach ($formats as $ext => $mime) {
    if (!isset($tags[$ext], $probes[$ext])) {
        continue;
    }
    [$w, $h] = $probes[$ext]['size'];
    check(
        "$ext tag: width/height match the delivered image ({$w}x{$h})",
        str_contains($tags[$ext], 'width="' . $w . '"') && str_contains($tags[$ext], 'height="' . $h . '"'),
        $tags[$ext]
    );
}

// --- the two ViewHelpers must agree on the same input
echo "   tag vs uri source:\n";
foreach ($formats as $ext => $mime) {
    printf("     %-5s tag=%-46s uri=%s\n", $ext, $tagSrc[$ext] ?? '-', $uris[$ext] ?? '-');
    if (isset($tagSrc[$ext], $uris[$ext])) {
        check(
            "$ext: tag and uri resolve to the same processed file",
            basename($tagSrc[$ext]) === basename($uris[$ext]),
            'tag=' . basename($tagSrc[$ext]) . ' uri=' . basename($uris[$ext])
        );
    }
}

// --- srcset
if (preg_match('/SRCSET:(.+)/', $output, $m)) {
    $set = trim($m[1]);
    $parts = array_map('trim', explode(',', $set));
    check('srcset: three distinct URLs', count($parts) === 3 && count(array_unique($parts)) === 3, $set);
    $widthsOk = true;
    foreach ([300, 600, 900] as $i => $w) {
        if (!isset($parts[$i])) {
            $widthsOk = false;
            continue;
        }
        [$u] = explode(' ', $parts[$i]);
        $f = $publicDir . preg_replace('#^.*?/(fileadmin|typo3temp)/#', '$1/', $u);
        $info = file_exists($f) ? @getimagesize($f) : null;
        if (!$info || $info[0] !== $w) {
            $widthsOk = false;
        }
    }
    check('srcset: each URL really has its width', $widthsOk, $set);
} else {
    check('srcset: rendered', false, 'not found');
}

// --- cache bookkeeping, written to a file so pass 2 can compare
$stateFile = sys_get_temp_dir() . '/jw_wm_state_' . basename($root) . '.json';
$state = [];
foreach ($uris as $ext => $uri) {
    $f = $publicDir . preg_replace('#^.*?/(fileadmin|typo3temp)/#', '$1/', $uri);
    $state[$ext] = ['uri' => $uri, 'mtime' => file_exists($f) ? filemtime($f) : 0,
        'sha' => file_exists($f) ? hash_file('sha256', $f) : ''];
}
$rows = (int)GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class)
    ->getConnectionForTable('sys_file_processedfile')
    ->executeQuery('SELECT COUNT(*) FROM sys_file_processedfile')->fetchOne();
$state['_rows'] = $rows;

if ($pass === 1) {
    file_put_contents($stateFile, json_encode($state));
    echo "   sys_file_processedfile rows: $rows\n";
} else {
    $before = json_decode((string)file_get_contents($stateFile), true) ?: [];
    check(
        'cache: no new processed-file records on second render',
        ($before['_rows'] ?? -1) === $rows,
        'before=' . ($before['_rows'] ?? '?') . ' after=' . $rows
    );
    foreach ($uris as $ext => $uri) {
        check(
            "cache: $ext URL identical on second render",
            ($before[$ext]['uri'] ?? null) === $uri,
            $uri
        );
        check(
            "cache: $ext file not rewritten",
            ($before[$ext]['mtime'] ?? -1) === ($state[$ext]['mtime'] ?? -2)
            && ($before[$ext]['sha'] ?? 'x') === ($state[$ext]['sha'] ?? 'y'),
            'mtime ' . ($before[$ext]['mtime'] ?? '?') . ' -> ' . ($state[$ext]['mtime'] ?? '?')
        );
    }
}

// ------------------------------------------------------------------- report
foreach ($results as [$state, $name, $detail]) {
    printf("   [%-4s] %s%s\n", $state, $name, $detail !== '' && $state !== 'ok' ? "  <- $detail" : '');
}
printf("   %d checks, %d failed, %d warnings\n", count($results), $fail, $warn);
exit($fail === 0 ? 0 : 1);
