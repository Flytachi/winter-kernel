<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Request\Annotation;

use Attribute;

/**
 * Injects an uploaded file from a multipart/form-data request.
 *
 * Single file (array info):
 * ```
 *   #[RequestFile('avatar')] array $file
 *   // ['name'=>'photo.jpg', 'type'=>'image/jpeg', 'tmp_name'=>'...', 'size'=>12345, 'error'=>0]
 * ```
 *
 * Single file (contents as string):
 * ```
 *   #[RequestFile('avatar')] string $bytes
 * ```
 *
 * Multiple files (images[]):
 * ```
 *   #[RequestFile('images', multiple: true)] array $images
 *   // [['name'=>'a.jpg',...], ['name'=>'b.jpg',...]]
 * ```
 *
 * All uploaded files (no name):
 * ```
 *   #[RequestFile] array $files
 *   // ['avatar' => [...], 'images' => [[...],[...]]]
 * ```
 *
 * With constraints:
 * ```
 *   #[RequestFile('avatar', maxSize: '5MB', accept: ['image/jpeg', 'image/png'])]
 *   #[RequestFile('images', multiple: true, maxSize: '2MB', accept: ['image/*'])]
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class RequestFile
{
    /**
     * @param string|null $name     Form field name from the multipart request.
     *                              Omit (null) to receive the full uploaded files map.
     *
     * @param bool        $multiple Set to true when the field sends several files via bracket
     *                              notation (e.g. <input name="images[]" multiple>).
     *                              Returns a list of file info arrays; a single uploaded file
     *                              is automatically normalized to a one-element list.
     *                              Default false returns a single file info array.
     *
     * @param string|null $maxSize  Maximum allowed file size. Accepts human-readable units:
     *                              '500KB', '5MB', '1.5MB', '1GB', or plain bytes '2048'.
     *                              Uses binary units (1KB = 1024 bytes).
     *                              Throws 400 if file size exceeds the limit.
     *                              When combined with multiple: true, applied to each file.
     *
     * @param array       $accept   Allowed file types. Throws 400 if none match.
     *                              Three formats supported:
     *                              - Exact MIME:    'image/jpeg', 'application/pdf'
     *                              - Wildcard MIME: 'image/*', 'video/*'
     *                              - Extension:     '.jpg', '.pdf', '.docx'
     *                              MIME is detected from file magic bytes via finfo —
     *                              not from the browser-supplied Content-Type.
     */
    public function __construct(
        public ?string $name = null,
        public bool $multiple = false,
        public ?string $maxSize = null,
        public array $accept = [],
    ) {
    }
}
