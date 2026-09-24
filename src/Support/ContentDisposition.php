<?php

namespace Spatie\MediaLibrary\Support;

use Illuminate\Support\Str;

/** @internal */
class ContentDisposition
{
    /**
     * The file name in ascii, where a quote or backslash would end or escape it, and in utf-8
     * (RFC 6266) when that is not the same name.
     */
    public static function header(string $type, string $fileName): string
    {
        $asciiFileName = str_replace(['"', '\\'], ['\'', '_'], (string) preg_replace('/[\x00-\x1f\x7f]/', '', Str::ascii($fileName)));

        $header = "{$type}; filename=\"{$asciiFileName}\"";

        if ($asciiFileName !== $fileName) {
            $header .= "; filename*=utf-8''".rawurlencode($fileName);
        }

        return $header;
    }
}
