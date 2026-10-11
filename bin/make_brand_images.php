<?php
declare(strict_types=1);
/** Draws Restaurant Inventory's brand images with GD (the logo, the abbreviated mark, the favicon, the PWA icons): php bin/make_brand_images.php. Rerun after changing the mark. */
$dir = dirname(__DIR__) . '/html/assets/images';
$font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
$indigo = [163, 74, 40];                                         // Restaurant Inventory's terracotta (the variable keeps the kit's name)
function mark(int $size, bool $rounded = true): GdImage
{
    global $indigo;
    $im = imagecreatetruecolor($size, $size);
    imagesavealpha($im, true);
    imagealphablending($im, false);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagealphablending($im, true);
    $bg = imagecolorallocate($im, ...$indigo);
    $r = $rounded ? (int) round($size * 0.22) : 0;
    imagefilledrectangle($im, $r, 0, $size - 1 - $r, $size - 1, $bg);
    imagefilledrectangle($im, 0, $r, $size - 1, $size - 1 - $r, $bg);
    foreach ([[$r, $r], [$size - 1 - $r, $r], [$r, $size - 1 - $r], [$size - 1 - $r, $size - 1 - $r]] as [$cx, $cy]) {
        imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $bg);
    }
    // a cloche: a white dome on a plate with a knob — the kitchen
    $white = imagecolorallocate($im, 255, 255, 255);
    $cx = (int) round($size / 2);
    $plateY = (int) round($size * 0.72);
    $px0 = (int) round($size * 0.14); $px1 = $size - 1 - $px0;
    $t = max(2, (int) round($size * 0.05));
    imagefilledrectangle($im, $px0, $plateY, $px1, $plateY + $t, $white);                      // the plate
    $domeW = (int) round($size * 0.56); $domeH = (int) round($size * 0.44);
    imagefilledellipse($im, $cx, $plateY - (int) round($t * 0.5), $domeW, $domeH * 2, $white); // the dome (an ellipse, its lower half under the plate)
    imagefilledrectangle($im, $px0, $plateY + $t + 1, $px1, $size - 1, imagecolorallocatealpha($im, 0, 0, 0, 127)); // clear below the plate
    imagefilledrectangle($im, 0, $plateY + $t + 1, $size - 1, $size - 1, $bg);                  // the background back under it
    imagefilledellipse($im, $cx, $plateY - $domeH + (int) round($t * 0.2), (int) round($size * 0.12), (int) round($size * 0.12), $white); // the knob
    imagesetthickness($im, 1);
    return $im;
}
foreach (['favicon.png' => 64, 'logo-abbr.png' => 128, 'apple-touch-icon.png' => 180, 'icon-192.png' => 192, 'icon-512.png' => 512] as $file => $size) {
    $im = mark($size, $file !== 'apple-touch-icon.png');
    if ($file === 'apple-touch-icon.png') {                                   // iOS rounds it itself: an opaque square
        $flat = imagecreatetruecolor($size, $size);
        imagefill($flat, 0, 0, imagecolorallocate($flat, ...$indigo));
        imagecopy($flat, $im, 0, 0, 0, 0, $size, $size);
        $im = $flat;
    }
    imagepng($im, $dir . '/' . $file);
}
// the full logo: the mark and the name
$w = 420; $h = 90;
$im = imagecreatetruecolor($w, $h);
imagesavealpha($im, true);
imagealphablending($im, false);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagealphablending($im, true);
$m = mark(70);
imagecopy($im, $m, 4, 10, 0, 0, 70, 70);
$dark = imagecolorallocate($im, 33, 37, 41);
imagettftext($im, 17, 0, 86, 55, $dark, $font, 'Restaurant Inventory');
imagepng($im, $dir . '/logo-full.png');
echo "brand images drawn in $dir\n";
