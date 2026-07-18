<?php

namespace App\Services;

/**
 * Turns an arbitrary uploaded image into a standardized profile-photo asset:
 * a genuine-image check via magic bytes (not just extension/declared MIME),
 * then a center-square crop + resize to 250x250 + WebP re-encode. Re-encoding
 * through a fresh GD truecolor canvas never carries EXIF forward, so the
 * output is metadata-stripped as a side effect of the resize step — no
 * separate EXIF-scrubbing call is needed.
 */
class AvatarProcessingService
{
    private const AVATAR_SIZE = 250;

    private const WEBP_QUALITY = 82;

    private const ALLOWED_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /**
     * Reads the real image header (magic bytes), independent of whatever
     * extension or Content-Type the client claims. Returns the detected
     * IMAGETYPE_* constant, or null if the bytes aren't a genuine JPEG/PNG/WebP.
     */
    public function sniffType(string $binary): ?int
    {
        $info = @getimagesizefromstring($binary);

        if ($info === false || ! in_array($info[2], self::ALLOWED_TYPES, true)) {
            return null;
        }

        return $info[2];
    }

    /**
     * Decodes, center-crops to a square, resizes to 250x250, and re-encodes
     * as WebP. Throws if the bytes can't be decoded as an image at all —
     * callers should have already validated via sniffType() first.
     */
    public function toAvatarWebp(string $binary): string
    {
        $source = imagecreatefromstring($binary);

        if ($source === false) {
            throw new \RuntimeException('Unable to decode image for avatar processing.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $srcX = intdiv($width - $side, 2);
        $srcY = intdiv($height - $side, 2);

        $dest = imagecreatetruecolor(self::AVATAR_SIZE, self::AVATAR_SIZE);
        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
        imagefill($dest, 0, 0, $transparent);

        imagecopyresampled(
            $dest,
            $source,
            0,
            0,
            $srcX,
            $srcY,
            self::AVATAR_SIZE,
            self::AVATAR_SIZE,
            $side,
            $side,
        );

        imagedestroy($source);

        ob_start();
        imagewebp($dest, null, self::WEBP_QUALITY);
        $webp = ob_get_clean();

        imagedestroy($dest);

        return $webp;
    }
}
