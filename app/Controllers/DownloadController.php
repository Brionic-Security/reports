<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Site;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;
use ZipArchive;

final class DownloadController
{
    /**
     * Build a WordPress plugin ZIP with this site's key + tracker URL baked in,
     * so the user can upload-and-activate with no configuration.
     */
    public function wordpressPlugin(Request $request, array $params): Response
    {
        $site = Site::find((int) ($params['id'] ?? 0));
        if ($site === null) {
            throw HttpException::notFound('Site not found.');
        }

        $source = base_path('plugins/wordpress/brionic-analytics');
        if (!is_dir($source) || !class_exists(ZipArchive::class)) {
            throw HttpException::notFound('Plugin package is unavailable.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'brz');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw HttpException::notFound('Could not build the plugin package.');
        }

        $replacements = [
            '__SITE_KEY__'    => (string) $site['public_id'],
            '__TRACKER_SRC__' => app_url('b.js'),
        ];

        /** @var \SplFileInfo[] $files */
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $local = 'brionic-analytics/' . ltrim(substr($path, strlen($source)), '/\\');
            $contents = (string) file_get_contents($path);
            if (str_ends_with($path, '.php') || str_ends_with($path, '.txt')) {
                $contents = strtr($contents, $replacements);
            }
            $zip->addFromString($local, $contents);
        }
        $zip->close();

        $body = (string) file_get_contents($tmp);
        @unlink($tmp);

        // Name the download after the site + plugin version, e.g. socalsc-brionic-plugin-1.5.1.zip
        $version = plugin_version();
        $slug = trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) $site['name'])), '-');
        if ($slug === '') {
            $slug = 'site';
        }
        $filename = $slug . '-brionic-plugin' . ($version !== '' ? '-' . $version : '') . '.zip';

        return new Response($body, 200, [
            'Content-Type'        => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length'      => (string) strlen($body),
        ]);
    }
}
