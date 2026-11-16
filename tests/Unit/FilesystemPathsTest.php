<?php

namespace Tests\Unit;

use App\Support\FilesystemPaths;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class FilesystemPathsTest extends TestCase
{
    public function test_windows_iterator_paths_use_portable_nested_archive_names(): void
    {
        $root = 'C:\\Users\\Runner/App/private';
        $path = 'C:\\Users\\Runner\\App\\private\\administration\\files\\alt\\belge.txt';
        $this->assertSame('administration/files/alt/belge.txt', FilesystemPaths::relativeTo($root, $path));
        $this->assertSame(FilesystemPaths::normalize($path), FilesystemPaths::normalize($root).'/'.FilesystemPaths::relativeTo($root, $path));
    }

    public function test_unix_paths_and_unicode_file_names_remain_unchanged(): void
    {
        $this->assertSame('alt/İĞÜŞÖÇ.txt', FilesystemPaths::relativeTo('/tmp/files/', '/tmp/files/alt/İĞÜŞÖÇ.txt'));
    }

    public function test_a_sibling_directory_is_not_a_child_of_the_root(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FilesystemPaths::relativeTo('/tmp/files', '/tmp/files-other/document.txt');
    }
}
