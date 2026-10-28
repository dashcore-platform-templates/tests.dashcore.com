<?php

namespace App\Demo;

use Illuminate\Support\Facades\File;

/**
 * A stand-in screenshot for demo runs: a plain browser frame with the page's
 * address, what the step did and a "demo" stamp, drawn with GD as a JPEG
 * under the name the browser runner would have given it.
 */
class Placeholder
{
    public static function write(string $path, string $url, string $caption, bool $failed = false): void
    {
        File::ensureDirectoryExists(dirname($path));

        $image = imagecreatetruecolor(960, 600);
        $page = imagecolorallocate($image, 246, 246, 244);
        $bar = imagecolorallocate($image, 226, 226, 222);
        $ink = imagecolorallocate($image, 40, 40, 46);
        $muted = imagecolorallocate($image, 120, 120, 128);
        $stamp = $failed ? imagecolorallocate($image, 200, 48, 48) : imagecolorallocate($image, 186, 120, 20);

        imagefill($image, 0, 0, $page);
        imagefilledrectangle($image, 0, 0, 959, 44, $bar);
        imagestring($image, 3, 18, 15, mb_strimwidth($url, 0, 120, '...'), $muted);

        // Grey blocks where the page's content would be.
        foreach ([[60, 100, 520, 128], [60, 150, 900, 166], [60, 182, 840, 198], [60, 230, 420, 380], [460, 230, 900, 380]] as [$x1, $y1, $x2, $y2]) {
            imagefilledrectangle($image, $x1, $y1, $x2, $y2, $bar);
        }

        imagestring($image, 5, 60, 420, mb_strimwidth($caption, 0, 96, '...'), $ink);
        imagerectangle($image, 60, 470, 380, 530, $stamp);
        imagestring($image, 5, 76, 482, $failed ? 'DEMO - this step failed' : 'DEMO - placeholder', $stamp);
        imagestring($image, 2, 76, 506, 'made up by tests:demo', $muted);

        imagejpeg($image, $path, 70);
    }
}
