<?php

declare(strict_types=1);

// Inspect tar headers, not filenames alone: PAX headers can carry host metadata.
$stream = gzopen($argv[1], 'rb');
if ($stream === false) {
    throw new RuntimeException('Cannot open release archive.');
}
try {
    while (!gzeof($stream)) {
        $header = gzread($stream, 512);
        if ($header === str_repeat("\0", 512)) {
            break;
        }
        if (strlen($header) !== 512) {
            throw new RuntimeException('Truncated tar header.');
        }
        if (!in_array($header[156], ["\0", '0', '5'], true)) {
            throw new RuntimeException('Unexpected tar entry type: metadata or non-regular entry.');
        }
        $name = rtrim(substr($header, 0, 100), "\0");
        if (preg_match('~(^|/)(\._[^/]*|\.DS_Store|__MACOSX)(/|$)~', $name)) {
            throw new RuntimeException('Unexpected macOS metadata file.');
        }
        $size = octdec(trim(substr($header, 124, 12), "\0 "));
        $remaining = (int)(ceil($size / 512) * 512);
        while ($remaining > 0) {
            $data = gzread($stream, min($remaining, 65536));
            if ($data === false || $data === '') {
                throw new RuntimeException('Truncated tar entry.');
            }
            $remaining -= strlen($data);
        }
    }
} finally {
    gzclose($stream);
}
echo "Release archive contains only regular files and directories; no extended metadata headers.\n";
