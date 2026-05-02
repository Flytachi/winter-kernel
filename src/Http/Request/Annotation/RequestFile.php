<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Annotation;

use Attribute;

/**
 * Injects an uploaded file from a multipart/form-data request.
 *
 * Single file (array info):
 *   #[RequestFile('avatar')] array $file
 *   // ['name'=>'photo.jpg', 'type'=>'image/jpeg', 'tmp_name'=>'...', 'size'=>12345, 'error'=>0]
 *
 * Single file (contents as string):
 *   #[RequestFile('avatar')] string $bytes
 *
 * Multiple files (images[]):
 *   #[RequestFile('images', multiple: true)] array $images
 *   // [['name'=>'a.jpg',...], ['name'=>'b.jpg',...]]
 *
 * All uploaded files (no name):
 *   #[RequestFile] array $files
 *   // ['avatar' => [...], 'images' => [[...],[...]]]
 *
 * With constraints:
 *   #[RequestFile('avatar', maxSize: '5MB', accept: ['image/jpeg', 'image/png'])]
 *   #[RequestFile('images', multiple: true, maxSize: '2MB', accept: ['image/*'])]
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class RequestFile
{
    public function __construct(
        public ?string $name     = null,
        public bool    $multiple = false,
        public ?string $maxSize  = null,
        public array   $accept   = [],
    ) {}
}
