<?php

/**
 * Creates the test fixtures in an installation's fileadmin.
 *
 * Base images: left half opaque blue, right half fully transparent, plus a
 * white marker block at 10,10. The transparent half is what proves alpha
 * survives; the marker block proves we are looking at the right image.
 *
 * Watermark: red 160x80 with an alpha ramp (opaque left -> nearly transparent
 * right), so both the colour and the alpha handling show up in the result.
 */
$root = rtrim(str_replace('\\', '/', $argv[1]), '/');
$dir = $root . '/public/fileadmin/jw_watermark_test';
if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
    fwrite(STDERR, "cannot create $dir\n");
    exit(1);
}

function baseImage()
{
    $im = imagecreatetruecolor(600, 400);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefilledrectangle($im, 0, 0, 599, 399, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagefilledrectangle($im, 0, 0, 299, 399, imagecolorallocatealpha($im, 0, 80, 200, 0));
    imagefilledrectangle($im, 10, 10, 40, 40, imagecolorallocatealpha($im, 255, 255, 255, 0));
    return $im;
}

function watermarkImage()
{
    $im = imagecreatetruecolor(160, 80);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    for ($x = 0; $x < 160; $x++) {
        $a = (int)round(($x / 159) * 100);
        imagefilledrectangle($im, $x, 0, $x, 79, imagecolorallocatealpha($im, 230, 20, 20, $a));
    }
    return $im;
}

$base = baseImage();
imagejpeg($base, $dir . '/base.jpg', 92);
imagepng($base, $dir . '/base.png');
imagewebp($base, $dir . '/base.webp', 90);
imageavif($base, $dir . '/base.avif', 70);
imagepng(watermarkImage(), $dir . '/watermark.png');

foreach (['base.jpg', 'base.png', 'base.webp', 'base.avif', 'watermark.png'] as $f) {
    $p = $dir . '/' . $f;
    $i = @getimagesize($p);
    printf("  %-14s %8d B  %s\n", $f, filesize($p), $i ? $i[0] . 'x' . $i[1] . ' ' . $i['mime'] : 'UNREADABLE');
}
