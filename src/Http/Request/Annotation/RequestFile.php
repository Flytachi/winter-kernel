<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Annotation;

use Attribute;

/**
 * Injects an uploaded file from a multipart/form-data request.
 *
 * Type must be array (raw file info) or string (file contents).
 *
 * Single file:
 *   public function upload(#[RequestFile('avatar')] array $file): ResponseEntity
 *   // $file = ['name'=>'photo.jpg', 'type'=>'image/jpeg', 'tmp_name'=>'...', 'size'=>12345, 'error'=>0]
 *
 * File contents as string:
 *   public function upload(#[RequestFile('avatar')] string $bytes): ResponseEntity
 *
 * All files (no name → full map):
 *   public function upload(#[RequestFile] array $files): ResponseEntity
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class RequestFile
{
    public function __construct(public ?string $name = null) {}
}
