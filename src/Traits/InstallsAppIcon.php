<?php

namespace Native\Mobile\Traits;

use Illuminate\Support\Facades\File;

trait InstallsAppIcon
{
    /** @var array<string, array{0:int,1:int,2:int,3:int}> */
    private array $opaqueBoundsCache = [];

    public function installIosIcon()
    {
        $iconPath = public_path('icon.png');

        if (! File::exists($iconPath)) {
            return;
        }

        if ($this->validateIosIcon($iconPath)) {
            @copy($iconPath, base_path('nativephp/ios/NativePHP/Assets.xcassets/AppIcon.appiconset/icon.png'));
        }
    }

    private function validateIosIcon(string $iconPath): bool
    {
        if (! $image = @imagecreatefrompng($iconPath)) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width !== $height) {
            imagedestroy($image);

            return false;
        }

        if ($width < 1024) {
            imagedestroy($image);

            return false;
        }

        $hasTransparency = false;

        if (imageistruecolor($image) && imagecolortransparent($image) == -1) {
            $samplePoints = [
                [0, 0], [$width - 1, 0], [0, $height - 1], [$width - 1, $height - 1],
                [$width / 2, $height / 2],
                [$width / 4, $height / 4], [$width * 3 / 4, $height * 3 / 4],
            ];

            foreach ($samplePoints as [$x, $y]) {
                $rgba = imagecolorat($image, (int) $x, (int) $y);
                $alpha = ($rgba & 0x7F000000) >> 24;

                if ($alpha > 0) {
                    $hasTransparency = true;
                    break;
                }
            }
        }

        if ($hasTransparency) {
            imagedestroy($image);

            return false;
        }

        imagedestroy($image);

        return true;
    }

    public function installAndroidIcon(): void
    {
        $this->logToFile('Installing Android icon...');
        $iconPath = public_path('icon.png');

        if (! File::exists($iconPath)) {
            $this->logToFile('  No icon.png found at public/icon.png, skipping');

            return;
        }

        $this->logToFile("  Source icon: $iconPath");

        // Adaptive icons are two layers: a background drawable and a foreground
        // that the launcher masks to its own shape. The foreground is meant to be
        // the logo alone on transparency — deriving it from icon.png embeds that
        // icon's own background, which shows up as a square seam inside the mask.
        // An app can opt out by shipping public/icon-foreground.png instead.
        $foregroundPath = public_path('icon-foreground.png');
        $hasForeground = File::exists($foregroundPath);

        if ($hasForeground) {
            $this->logToFile("  Adaptive foreground: $foregroundPath");
        }

        $resDir = base_path('nativephp/android/app/src/main/res/');

        $sizes = [
            'mipmap-mdpi' => 48,
            'mipmap-hdpi' => 72,
            'mipmap-xhdpi' => 96,
            'mipmap-xxhdpi' => 144,
            'mipmap-xxxhdpi' => 192,
        ];

        $adaptiveSizes = [
            'mipmap-mdpi' => 108,
            'mipmap-hdpi' => 162,
            'mipmap-xhdpi' => 216,
            'mipmap-xxhdpi' => 324,
            'mipmap-xxxhdpi' => 432,
        ];

        $targets = [
            'ic_launcher.png',
            'ic_launcher_round.png',
            'ic_launcher_foreground.png',
        ];

        $this->logToFile('  Generating icon sizes: '.implode(', ', array_keys($sizes)));

        foreach ($sizes as $folder => $size) {
            $dstDir = $resDir.$folder;
            File::ensureDirectoryExists($dstDir);

            foreach ($targets as $filename) {
                $dstPath = $dstDir.'/'.$filename;

                $webpPath = str_replace('.png', '.webp', $dstPath);
                if (File::exists($webpPath)) {
                    File::delete($webpPath);
                }

                $isForeground = $filename === 'ic_launcher_foreground.png';
                $targetSize = $isForeground ? $adaptiveSizes[$folder] : $size;

                if ($isForeground && $hasForeground) {
                    $this->renderAdaptiveForeground($foregroundPath, $dstPath, $targetSize);

                    continue;
                }

                $this->resizePng($iconPath, $dstPath, $targetSize, $targetSize);
            }
        }

        $this->logToFile('  Android icon installed');
    }

    /**
     * Draw a transparent foreground artwork onto an adaptive-icon canvas.
     *
     * The artwork is measured and scaled to fit rather than trusting however it
     * was framed, and it is measured by the radius of its furthest opaque pixel
     * rather than by its bounding box. Fitting the box only works for artwork
     * whose extremities sit on the axes; anything reaching into its own corners
     * (a rosette, a diagonal wordmark) then overflows the mask by up to the
     * box's half-diagonal.
     *
     * The target diameter is a visual choice, not the 66dp safe-content circle:
     * filling that circle is allowed but reads oversized next to platform icons,
     * whose glyphs sit around 70-75% of the mask.
     */
    private function renderAdaptiveForeground(string $src, string $dst, int $size): void
    {
        $srcImage = imagecreatefrompng($src);

        if (! $srcImage) {
            return;
        }

        [$left, $top, $right, $bottom, $radius] = $this->opaqueBounds($src, $srcImage);

        $contentWidth = $right - $left + 1;
        $contentHeight = $bottom - $top + 1;

        $diameter = (float) (config('nativephp.android.launcher_foreground_size') ?: 52);
        $diameter = max(1.0, min(66.0, $diameter));

        $safeRadius = $size * ($diameter / 2 / 108);
        $scale = $radius > 0 ? $safeRadius / $radius : 1.0;

        $drawWidth = (int) round($contentWidth * $scale);
        $drawHeight = (int) round($contentHeight * $scale);

        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        imagecopyresampled(
            $canvas, $srcImage,
            (int) round(($size - $drawWidth) / 2), (int) round(($size - $drawHeight) / 2),
            $left, $top,
            $drawWidth, $drawHeight,
            $contentWidth, $contentHeight
        );

        imagepng($canvas, $dst, 6);
        imagedestroy($canvas);
        imagedestroy($srcImage);
    }

    /**
     * Extent of the non-transparent pixels: [left, top, right, bottom, radius],
     * where radius is the distance from the box's centre to the furthest opaque
     * pixel.
     *
     * Scanning a large PNG pixel by pixel is slow enough to be worth doing once
     * per source rather than once per density bucket.
     *
     * @return array{0:int,1:int,2:int,3:int,4:float}
     */
    private function opaqueBounds(string $cacheKey, \GdImage $image): array
    {
        if (isset($this->opaqueBoundsCache[$cacheKey])) {
            return $this->opaqueBoundsCache[$cacheKey];
        }

        $width = imagesx($image);
        $height = imagesy($image);

        $left = $width;
        $top = $height;
        $right = -1;
        $bottom = -1;
        $opaque = [];

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                // GD alpha: 0 = opaque, 127 = fully transparent.
                if ((imagecolorat($image, $x, $y) >> 24 & 0x7F) >= 120) {
                    continue;
                }

                if ($x < $left) {
                    $left = $x;
                }
                if ($x > $right) {
                    $right = $x;
                }
                if ($y < $top) {
                    $top = $y;
                }
                if ($y > $bottom) {
                    $bottom = $y;
                }

                // Only the outermost pixel of each row can be the furthest one.
                $opaque[$y] ??= [$x, $x];
                $opaque[$y][1] = $x;
            }
        }

        // Fully transparent artwork: fall back to the whole canvas.
        if ($right < 0) {
            return $this->opaqueBoundsCache[$cacheKey] = [0, 0, $width - 1, $height - 1, $width / 2];
        }

        $centreX = ($left + $right) / 2;
        $centreY = ($top + $bottom) / 2;
        $radius = 0.0;

        foreach ($opaque as $y => [$rowLeft, $rowRight]) {
            foreach ([$rowLeft, $rowRight] as $x) {
                $distance = hypot($x - $centreX, $y - $centreY);

                if ($distance > $radius) {
                    $radius = $distance;
                }
            }
        }

        return $this->opaqueBoundsCache[$cacheKey] = [$left, $top, $right, $bottom, $radius];
    }

    private function resizePng(string $src, string $dst, int $width, int $height): void
    {
        $srcImage = imagecreatefrompng($src);
        $srcWidth = imagesx($srcImage);
        $srcHeight = imagesy($srcImage);

        $resized = imagecreatetruecolor($width, $height);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);

        $isAndroidForeground = str_contains($dst, 'ic_launcher_foreground');
        // Android adaptive icons: 108dp canvas with 66dp safe zone (61%)
        // Use 0.55 to ensure icon stays within safe zone with padding for all mask shapes
        $scaleFactor = $isAndroidForeground ? 0.69 : 1.0;

        $srcRatio = $srcWidth / $srcHeight;
        $dstRatio = $width / $height;

        if ($srcRatio > $dstRatio) {
            $newWidth = (int) ($width * $scaleFactor);
            $newHeight = (int) (($width * $scaleFactor) / $srcRatio);
            $offsetX = (int) (($width - $newWidth) / 2);
            $offsetY = (int) (($height - $newHeight) / 2);
        } else {
            $newWidth = (int) (($height * $scaleFactor) * $srcRatio);
            $newHeight = (int) ($height * $scaleFactor);
            $offsetX = (int) (($width - $newWidth) / 2);
            $offsetY = (int) (($height - $newHeight) / 2);
        }

        imagecopyresampled(
            $resized, $srcImage,
            $offsetX, $offsetY, 0, 0,
            $newWidth, $newHeight,
            $srcWidth, $srcHeight
        );

        imagepng($resized, $dst, 0);
        imagedestroy($resized);
        imagedestroy($srcImage);
    }
}
