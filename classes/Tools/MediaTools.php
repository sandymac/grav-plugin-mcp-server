<?php

declare(strict_types=1);

namespace Grav\Plugin\McpServer\Tools;

use Grav\Plugin\McpServer\ApiBridge;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;

/**
 * Media domain: tools over grav-plugin-api's page-media and site-media
 * endpoints. Nyholm classes are only touched inside handlers.
 */
final class MediaTools
{
    /** Upload bounds, enforced before base64_decode so a payload is never materialised twice. */
    public const int MAX_UPLOAD_FILES = 20;
    /** Base64 length that decodes to the api plugin's 64 MiB per-file cap. */
    public const int MAX_BASE64_BYTES = 90_000_000;

    /** @return array<string, array{descriptor: array, permission: ?string, handler: callable}> */
    public static function tools(): array
    {
        return [
            'list_media' => [
                'permission' => 'api.media.read',
                'descriptor' => [
                    'name' => 'list_media',
                    'title' => 'List Media',
                    'description' => 'List media files. Give "route" to list the media attached to that page (filename, type, size, dimensions, thumbnail URLs); leave "route" out to browse the site media library instead, where "path" picks a subfolder and search/type/page/per_page filter the listing. One or the other — passing both is an error. [Requires: api.media.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'route' => ['type' => 'string', 'description' => 'Page route (e.g. "/blog/my-post") — lists that page\'s media'],
                            'path' => ['type' => 'string', 'description' => 'Site media subfolder to browse (e.g. "images/2024"); only without "route"'],
                            'search' => ['type' => 'string', 'description' => 'Search files by name (site media only)'],
                            'type' => ['type' => 'string', 'enum' => ['image', 'video', 'audio', 'document'], 'description' => 'Filter by file type (site media only)'],
                            'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page number (default: 1, site media only)'],
                            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Items per page (default: 50, site media only)'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    if (!empty($args['route']) && !empty($args['path'])) {
                        return ApiBridge::toolJson(['error' => 'Pass route (page media) or path (site media), not both']);
                    }

                    if (!empty($args['route'])) {
                        return ApiBridge::fromResponse($api->request('GET', '/pages/' . ApiBridge::path($args) . '/media'));
                    }

                    return ApiBridge::fromResponse($api->request('GET', '/media', [
                        'path' => $args['path'] ?? null,
                        'search' => $args['search'] ?? null,
                        'type' => $args['type'] ?? null,
                        'page' => $args['page'] ?? 1,
                        'per_page' => $args['per_page'] ?? 50,
                    ]));
                },
            ],

            'upload_media' => [
                'permission' => 'api.media.write',
                'descriptor' => [
                    'name' => 'upload_media',
                    'title' => 'Upload Media',
                    'description' => 'Upload media files. Give "route" to attach the files to that page; leave "route" out to upload into the site media library, where "path" names an optional subfolder. Each file needs a filename and base64-encoded content. Supports images, videos, documents, and other file types. [Requires: api.media.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'files' => self::filesSchema('MIME type (auto-detected from extension if omitted)'),
                            'route' => ['type' => 'string', 'description' => 'Page route to upload media to'],
                            'path' => ['type' => 'string', 'description' => 'Site media subfolder to upload to; only without "route"'],
                        ],
                        'required' => ['files'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    try {
                        $files = self::uploadedFiles($args['files'] ?? []);
                    } catch (\InvalidArgumentException $e) {
                        return ApiBridge::toolError($e->getMessage());
                    }

                    if (!empty($args['route'])) {
                        return ApiBridge::fromResponse($api->request(
                            'POST',
                            '/pages/' . ApiBridge::path($args) . '/media',
                            [],
                            null,
                            [],
                            ['files' => $files]
                        ));
                    }

                    // MediaController reads the subfolder from the query string
                    // (`$request->getQueryParams()['path']`), so `path` goes there.
                    return ApiBridge::fromResponse($api->request(
                        'POST',
                        '/media',
                        ['path' => $args['path'] ?? null],
                        null,
                        [],
                        ['files' => $files]
                    ));
                },
            ],

            'delete_media' => [
                'permission' => 'api.media.write',
                'descriptor' => [
                    'name' => 'delete_media',
                    'title' => 'Delete Media',
                    'description' => 'Delete a media file. Give "route" plus "filename" to delete a page\'s media; leave "route" out and give "path" to delete from the site media library. [Requires: api.media.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'route' => ['type' => 'string', 'description' => 'Page route'],
                            'filename' => ['type' => 'string', 'description' => 'Filename to delete (e.g. "photo.jpg") — required with "route"'],
                            'path' => ['type' => 'string', 'description' => 'Relative path to a site media file, including filename (e.g. "images/photo.jpg")'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    if (!empty($args['route'])) {
                        if (empty($args['filename'])) {
                            return ApiBridge::toolJson(['error' => 'filename is required when route is given']);
                        }

                        return ApiBridge::fromResponse(
                            $api->request('DELETE', '/pages/' . ApiBridge::path($args) . '/media/' . ApiBridge::path($args, 'filename')),
                            successMessage: sprintf('Deleted "%s" from "%s".', (string) $args['filename'], (string) $args['route'])
                        );
                    }

                    if (empty($args['path'])) {
                        return ApiBridge::toolJson(['error' => 'path is required for site media, or route plus filename for page media']);
                    }

                    return ApiBridge::fromResponse(
                        $api->request('DELETE', '/media/' . ApiBridge::path($args, 'path')),
                        successMessage: sprintf('Deleted "%s".', (string) $args['path'])
                    );
                },
            ],

            'manage_media_folder' => [
                'permission' => 'api.media.write',
                'descriptor' => [
                    'name' => 'manage_media_folder',
                    'title' => 'Manage Media Folder',
                    'description' => 'Create, rename, or delete a subfolder in the site media directory, or set the display order of files within one. For rename, provide both the current path and the new path. For order, provide "order" as the filenames in the desired order ("path" "" means the media root); an empty list removes the custom order. [Requires: api.media.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => ['type' => 'string', 'enum' => ['create', 'rename', 'delete', 'order'], 'description' => 'Action to perform'],
                            'path' => ['type' => 'string', 'description' => 'Folder path (e.g. "images/2024"); the current path for rename; "" means the media root for order'],
                            'new_path' => ['type' => 'string', 'description' => 'New folder path (required for rename)'],
                            'order' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Filenames in the desired display order (required for order action); unlisted files trail; an empty array removes the custom order'],
                        ],
                        'required' => ['action', 'path'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    $action = $args['action'] ?? null;

                    if ($action === 'create') {
                        return ApiBridge::fromResponse($api->request(
                            'POST',
                            '/media/folders',
                            [],
                            ['path' => $args['path'] ?? '']
                        ));
                    }

                    if ($action === 'rename') {
                        if (empty($args['new_path'])) {
                            // Matches the reference server: this is a normal (non-error)
                            // tool result carrying an `error` field, not an isError result.
                            return ApiBridge::toolJson(['error' => 'new_path is required for rename action']);
                        }

                        return ApiBridge::fromResponse($api->request(
                            'POST',
                            '/media/folders/rename',
                            [],
                            // The MCP-facing names are path/new_path; the endpoint reads from/to.
                            ['from' => $args['path'] ?? '', 'to' => $args['new_path']]
                        ));
                    }

                    if ($action === 'order') {
                        if (!isset($args['order']) || !\is_array($args['order'])) {
                            return ApiBridge::toolJson(['error' => 'order (an array of filenames) is required for order action']);
                        }

                        return ApiBridge::fromResponse($api->request(
                            'POST',
                            '/media/order',
                            [],
                            ['path' => $args['path'] ?? '', 'order' => $args['order']]
                        ));
                    }

                    if ($action !== 'delete') {
                        return ApiBridge::toolError('Invalid action. Must be one of: create, rename, delete, order');
                    }

                    return ApiBridge::fromResponse(
                        $api->request('DELETE', '/media/folders/' . ApiBridge::path($args, 'path')),
                        successMessage: sprintf('Folder "%s" deleted.', $args['path'] ?? '')
                    );
                },
            ],

            'get_media_meta' => [
                'permission' => 'api.media.read',
                'descriptor' => [
                    'name' => 'get_media_meta',
                    'title' => 'Get Media Metadata',
                    'description' => 'Get a media file\'s metadata. Give "route" plus "filename" for a page\'s media; give "path" for site media. Returns a fields list (key/label/type/value for the managed keys) plus "extra" for any other keys in the sidecar. [Requires: api.media.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'route' => ['type' => 'string', 'description' => 'Page route — requires "filename"'],
                            'filename' => ['type' => 'string', 'description' => 'Filename to inspect (e.g. "photo.jpg") — used with "route"'],
                            'path' => ['type' => 'string', 'description' => 'Relative path to a site media file, including filename (e.g. "images/photo.jpg")'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    if (!empty($args['route']) && !empty($args['path'])) {
                        return ApiBridge::toolJson(['error' => 'Pass route (page media) or path (site media), not both']);
                    }

                    if (!empty($args['route'])) {
                        if (empty($args['filename'])) {
                            return ApiBridge::toolJson(['error' => 'filename is required when route is given']);
                        }

                        return ApiBridge::fromResponse($api->request(
                            'GET',
                            '/pages/' . ApiBridge::path($args) . '/media/' . ApiBridge::path($args, 'filename') . '/meta'
                        ));
                    }

                    if (empty($args['path'])) {
                        return ApiBridge::toolJson(['error' => 'path is required for site media, or route plus filename for page media']);
                    }

                    return ApiBridge::fromResponse($api->request('GET', '/media/meta', ['path' => $args['path']]));
                },
            ],

            'update_media_meta' => [
                'permission' => 'api.media.write',
                'descriptor' => [
                    'name' => 'update_media_meta',
                    'title' => 'Update Media Metadata',
                    'description' => 'Set or clear a media file\'s metadata. Target exactly one of: "route" plus "filename" (page media), "path" (single site media file), or "paths" (batch, up to 50 site media files, set only). For "set", "fields" is merged into the existing metadata: an empty string (or empty tag list) removes that key; keys outside the site-configured managed list (default alt, title, caption, description, tags) are ignored; "tags" accepts a list or comma string. "clear" resets the managed keys and works on one file at a time. [Requires: api.media.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => ['type' => 'string', 'enum' => ['set', 'clear'], 'description' => 'Action to perform'],
                            'route' => ['type' => 'string', 'description' => 'Page route — requires "filename"'],
                            'filename' => ['type' => 'string', 'description' => 'Filename to update (e.g. "photo.jpg") — used with "route"'],
                            'path' => ['type' => 'string', 'description' => 'Relative path to a single site media file, including filename'],
                            'paths' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 50, 'description' => 'Relative paths to up to 50 site media files, for batch "set"'],
                            'fields' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Metadata fields to merge (required for "set")'],
                        ],
                        'required' => ['action'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    $action = $args['action'] ?? null;

                    $targets = array_filter([!empty($args['route']), !empty($args['path']), !empty($args['paths'])]);
                    if (\count($targets) !== 1) {
                        return ApiBridge::toolJson(['error' => 'Target exactly one of: route (with filename), path, or paths']);
                    }

                    if (!empty($args['route']) && empty($args['filename'])) {
                        return ApiBridge::toolJson(['error' => 'filename is required when route is given']);
                    }

                    if (!empty($args['paths']) && $action === 'clear') {
                        return ApiBridge::toolJson(['error' => 'clear works on one file at a time; use path (or route+filename), not paths']);
                    }

                    if ($action === 'set') {
                        if (empty($args['fields'])) {
                            return ApiBridge::toolJson(['error' => 'fields is required for set action']);
                        }

                        if (!empty($args['route'])) {
                            return ApiBridge::fromResponse($api->request(
                                'PATCH',
                                '/pages/' . ApiBridge::path($args) . '/media/' . ApiBridge::path($args, 'filename') . '/meta',
                                [],
                                ['fields' => $args['fields']]
                            ));
                        }

                        if (!empty($args['path'])) {
                            return ApiBridge::fromResponse($api->request(
                                'PATCH',
                                '/media/meta',
                                ['path' => $args['path']],
                                ['fields' => $args['fields']]
                            ));
                        }

                        // The MCP-facing name is "paths"; the batch endpoint reads "files".
                        return ApiBridge::fromResponse($api->request(
                            'POST',
                            '/media/batch/meta',
                            [],
                            ['files' => $args['paths'], 'fields' => $args['fields']]
                        ));
                    }

                    if ($action !== 'clear') {
                        return ApiBridge::toolError('Invalid action. Must be one of: set, clear');
                    }

                    if (!empty($args['route'])) {
                        return ApiBridge::fromResponse(
                            $api->request('DELETE', '/pages/' . ApiBridge::path($args) . '/media/' . ApiBridge::path($args, 'filename') . '/meta'),
                            successMessage: sprintf('Cleared metadata for "%s" on "%s".', (string) $args['filename'], (string) $args['route'])
                        );
                    }

                    return ApiBridge::fromResponse(
                        $api->request('DELETE', '/media/meta', ['path' => $args['path']]),
                        successMessage: sprintf('Cleared metadata for "%s".', (string) $args['path'])
                    );
                },
            ],

            'rename_media' => [
                'permission' => 'api.media.write',
                'descriptor' => [
                    'name' => 'rename_media',
                    'title' => 'Rename Media',
                    'description' => 'Rename or move a site media file (not page media). "new_path" may be in another folder — it is created if needed. The filename is sanitized and the source file\'s extension is always kept. Refuses to overwrite an existing file. [Requires: api.media.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => ['type' => 'string', 'description' => 'Current relative path, including filename (e.g. "images/old.jpg")'],
                            'new_path' => ['type' => 'string', 'description' => 'New relative path, including filename (e.g. "images/2024/new.jpg")'],
                        ],
                        'required' => ['path', 'new_path'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    return ApiBridge::fromResponse($api->request(
                        'POST',
                        '/media/rename',
                        [],
                        // The MCP-facing names are path/new_path; the endpoint reads from/to.
                        ['from' => $args['path'] ?? '', 'to' => $args['new_path'] ?? '']
                    ));
                },
            ],
        ];
    }

    /** The `files` array input-schema fragment for upload_media. */
    private static function filesSchema(string $contentTypeDescription): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'filename' => ['type' => 'string', 'description' => 'Filename with extension (e.g. "photo.jpg")'],
                    'content_base64' => ['type' => 'string', 'description' => 'Base64-encoded file content'],
                    'content_type' => ['type' => 'string', 'description' => $contentTypeDescription],
                ],
                'required' => ['filename', 'content_base64'],
                'additionalProperties' => false,
            ],
            'minItems' => 1,
            'maxItems' => self::MAX_UPLOAD_FILES,
            'description' => 'Array of files to upload',
        ];
    }

    /**
     * {filename, content_base64, content_type?}[] -> UploadedFileInterface[].
     *
     * Built from a Stream, never a file path: UploadedFile::moveTo() branches on
     * PHP_SAPI and calls real move_uploaded_file() outside CLI (i.e. exactly this
     * plugin's HTTP runtime), which fails for anything not a genuine upload. The
     * stream constructor path does a manual fopen/copy loop instead.
     *
     * @param array<int, array{filename?: string, content_base64?: string, content_type?: string}> $files
     * @return list<UploadedFile>
     * @throws \InvalidArgumentException when a file's content_base64 is not valid base64
     */
    private static function uploadedFiles(array $files): array
    {
        if (count($files) > self::MAX_UPLOAD_FILES) {
            throw new \InvalidArgumentException(sprintf('At most %d files per upload.', self::MAX_UPLOAD_FILES));
        }

        $out = [];
        foreach ($files as $file) {
            $filename = (string) ($file['filename'] ?? '');
            if (strlen((string) ($file['content_base64'] ?? '')) > self::MAX_BASE64_BYTES) {
                throw new \InvalidArgumentException(sprintf('content_base64 for "%s" exceeds the 64 MiB per-file limit.', $filename));
            }
            $content = base64_decode((string) ($file['content_base64'] ?? ''), true);
            if ($content === false) {
                throw new \InvalidArgumentException(sprintf('content_base64 for "%s" is not valid base64.', $filename));
            }

            $out[] = new UploadedFile(
                Stream::create($content),
                \strlen($content),
                \UPLOAD_ERR_OK,
                $filename,
                (string) ($file['content_type'] ?? self::guessMimeType($filename))
            );
        }

        return $out;
    }

    /**
     * Extension -> MIME type fallback map.
     * The one MIME fallback for every upload tool — BlueprintsTools uses it too.
     */
    public static function guessMimeType(string $filename): string
    {
        static $map = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
            'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
            'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav',
            'pdf' => 'application/pdf', 'zip' => 'application/zip',
            'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'json' => 'application/json', 'xml' => 'application/xml',
            'txt' => 'text/plain', 'md' => 'text/markdown', 'csv' => 'text/csv',
            'html' => 'text/html', 'css' => 'text/css', 'js' => 'application/javascript',
        ];

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return $map[$ext] ?? 'application/octet-stream';
    }
}
