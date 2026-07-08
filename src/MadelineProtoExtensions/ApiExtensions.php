<?php

namespace TelegramApiServer\MadelineProtoExtensions;

use Amp\ByteStream\Pipe;
use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableStream;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use AssertionError;
use danog\MadelineProto\API;
use danog\MadelineProto\EventHandler\Message;
use danog\MadelineProto\FileCallback;
use danog\MadelineProto\FileCallbackInterface;
use danog\MadelineProto\LocalFile;
use danog\MadelineProto\StrTools;
use InvalidArgumentException;
use Revolt\EventLoop;
use TelegramApiServer\Client;
use TelegramApiServer\EventObservers\EventHandler;
use TelegramApiServer\Exceptions\NoMediaException;
use function Amp\async;
use function Amp\delay;

final class ApiExtensions
{
    public function getHistoryHtml(API $madelineProto, array|int|string|null $peer = null, int|null $offset_id = 0, int|null $offset_date = 0, int|null $add_offset = 0, int|null $limit = 0, int|null $max_id = 0, int|null $min_id = 0, array $hash = [], ?int $floodWaitLimit = null, ?string $queueId = null): array
    {
        $response = $madelineProto->messages->getHistory(
            peer: $peer,
            offset_id: $offset_id,
            offset_date: $offset_date, add_offset: $add_offset,
            limit: $limit,
            max_id: $max_id,
            min_id: $min_id,
            hash: $hash,
            floodWaitLimit: $floodWaitLimit,
            queueId: $queueId,
        );
        if (!empty($response['messages'])) {
            foreach ($response['messages'] as &$message) {
                $message['message'] = StrTools::entitiesToHtml(
                    $message['message'] ?? '',
                    $message['entities'] ?? [],
                    true
                );
            }
            unset($message);
        }

        return $response;
    }

    /**
     * Проверяет есть ли подходящие медиа у сообщения.
     */
    private static function hasMedia(array $message = [], bool $allowWebPage = false): bool
    {
        $mediaType = $message['media']['_'] ?? null;
        if ($mediaType === null) {
            return false;
        }
        if (
            $mediaType === 'messageMediaWebPage' &&
            ($allowWebPage === false || empty($message['media']['webpage']['photo']))
        ) {
            return false;
        }

        return true;
    }

    /**
     * Пересылает сообщения без ссылки на оригинал.
     */
    public function copyMessages(API $madelineProto, string|int $from_peer, string|int $to_peer, array|int $id)
    {
        $response = $madelineProto->channels->getMessages(
            channel: $from_peer,
            id: (array)$id,
        );
        $result = [];
        if (!$response || !\is_array($response) || !\array_key_exists('messages', $response)) {
            return $result;
        }

        foreach ($response['messages'] as $key => $message) {
            $messageData = [
                'message' => $message['message'] ?? '',
                'peer' => $to_peer,
                'entities' => $message['entities'] ?? [],
            ];
            if (self::hasMedia($message, false)) {
                $messageData['media'] = $message; //MadelineProto сама достанет все media из сообщения.
                $result[] = $madelineProto->messages->sendMedia(...$messageData);
            } else {
                $result[] = $madelineProto->messages->sendMessage(...$messageData);
            }
            if ($key > 0) {
                delay(\random_int(300, 2000) / 1000);
            }
        }

        return $result;
    }

    /**
     * Загружает медиафайл из указанного сообщения в поток.
     */
    public function getMedia(API $madelineProto, Request $request, string|int $peer = '', array|int $id = 0, array $message = []): Response
    {
        $message = $message ?: ($this->getMessages($madelineProto, $peer, (array)$id))['messages'][0] ?? null;
        if (!$message || $message['_'] === 'messageEmpty') {
            throw new NoMediaException('Empty message');
        }

        if (!self::hasMedia($message, true)) {
            throw new NoMediaException('Message has no media');
        }

        if ($message['media']['_'] !== 'messageMediaWebPage') {
            $info = $madelineProto->getDownloadInfo($message);
        } else {
            $webpage = $message['media']['webpage'];
            if (!empty($webpage['embed_url'])) {
                return new Response(302, ['Location' => $webpage['embed_url']]);
            } elseif (!empty($webpage['document'])) {
                $info = $madelineProto->getDownloadInfo($webpage['document']);
            } elseif (!empty($webpage['photo'])) {
                $info = $madelineProto->getDownloadInfo($webpage['photo']);
            } else {
                return $this->getMediaPreview($madelineProto, $request, $peer, $id, $message);
            }
        }

        return $madelineProto->downloadToResponse(messageMedia: $info, request: $request);
    }

    /**
     * Загружает превью медиафайла из указанного сообщения в поток.
     *
     */
    public function getMediaPreview(API $madelineProto, Request $request, string|int $peer = '', array|int $id = 0, array $message = []): Response
    {
        $message = $message ?: ($this->getMessages($madelineProto, $peer, (array)$id))['messages'][0] ?? null;
        if (!$message || $message['_'] === 'messageEmpty') {
            throw new NoMediaException('Empty message');
        }

        if (!self::hasMedia($message, true)) {
            throw new NoMediaException('Message has no media');
        }

        $media = match ($message['media']['_']) {
            'messageMediaPhoto' => $message['media']['photo'],
            'messageMediaDocument' => $message['media']['document'],
            'messageMediaWebPage' => $message['media']['webpage'],
        };

        $thumb = null;
        switch (true) {
            case isset($media['sizes']):
                foreach ($media['sizes'] as $size) {
                    if ($size['_'] === 'photoSize') {
                        $thumb = $size;
                    }
                }
                break;
            case isset($media['thumb']['size']):
                $thumb = $media['thumb'];
                break;
            case !empty($media['thumbs']):
                foreach ($media['thumbs'] as $size) {
                    if ($size['_'] === 'photoSize') {
                        $thumb = $size;
                    }
                }
                break;
            case isset($media['photo']['sizes']):
                foreach ($media['photo']['sizes'] as $size) {
                    if ($size['_'] === 'photoSize') {
                        $thumb = $size;
                    }
                }
                break;
            default:
                throw new NoMediaException('Message has no preview');

        }
        if (null === $thumb) {
            throw new NoMediaException('Empty preview');
        }
        $info = $madelineProto->getDownloadInfo($thumb);

        if ($media['_'] === 'webPage') {
            $media = $media['photo'];
        }

        //Фикс для LAYER 100+
        //TODO: Удалить, когда снова станет доступна загрузка photoSize
        if (isset($info['thumb_size'])) {
            $infoFull = $madelineProto->getDownloadInfo($media);
            $infoFull['InputFileLocation']['thumb_size'] = $info['thumb_size'];
            $infoFull['size'] = $info['size'];
            $infoFull['mime'] = $info['mime'] ?? 'image/jpeg';
            $infoFull['name'] = 'thumb';
            $infoFull['ext'] = '.jpeg';
            $info = $infoFull;
        }

        return $madelineProto->downloadToResponse(messageMedia: $info, request: $request);
    }

    public function getMessages(API $madelineProto, string|int $peer, array|int $id): array
    {
        $peerInfo = $madelineProto->getInfo($peer);
        if (\in_array($peerInfo['type'], ['channel', 'supergroup'])) {
            $response = $madelineProto->channels->getMessages(
                channel:$peer,
                id: $id,
            );
        } else {
            $response = $madelineProto->messages->getMessages(id: $id);
        }

        return $response;
    }

    /**
     * Адаптер для стандартного метода.
     *
     */
    public function downloadToResponse(API $madelineProto, FileCallbackInterface|Message|array|string $messageMedia, Request $request, ?callable $cb = null, ?int $size = null, ?string $mime = null, ?string $name = null): Response
    {
        return $madelineProto->downloadToResponse(
            messageMedia: $messageMedia,
            request: $request,
            cb: $cb,
            size: $size,
            mime: $mime,
            name: $name,
        );
    }

    /**
     * Адаптер для стандартного метода.
     *
     */
    public function downloadToBrowser(API $madelineProto, FileCallbackInterface|Message|array|string $messageMedia, Request $request, ?callable $cb = null, ?int $size = null, ?string $mime = null, ?string $name = null): Response
    {
        return $this->downloadToResponse(
            madelineProto: $madelineProto,
            messageMedia: $messageMedia,
            request: $request,
            cb: $cb,
            size: $size,
            mime: $mime,
            name: $name,
        );
    }

    /**
     * Upload file from POST request.
     * Response can be passed to 'media' field in messages.sendMedia.
     *
     * @throws NoMediaException
     * @deprecated use SendDocument
     */
    public function uploadMediaForm(API $madelineProto, ReadableStream $file, string $mimeType, ?string $fileName): array
    {
        if ($fileName === null) {
            throw new AssertionError("No file name was provided!");
        }
        $inputFile = $madelineProto->uploadFromStream(
            stream: $file,
            size: 0,
            mime: $mimeType,
            fileName:  $fileName ?? '',
        );
        $inputFile['id'] = \unpack('P', $inputFile['id'])['1'];
        return [
            'media' => [
                '_' => 'inputMediaUploadedDocument',
                'file' => $inputFile,
                'attributes' => [
                    ['_' => 'documentAttributeFilename', 'file_name' => $fileName]
                ]
            ]
        ];
    }

    public function setEventHandler(API $madelineProto): void
    {
        Client::getWrapper($madelineProto)->getAPI()->setEventHandler(EventHandler::class);
        Client::getWrapper($madelineProto)->serialize();
    }

    public function serialize(API $madelineProto): void
    {
        Client::getWrapper($madelineProto)->serialize();
    }

    public function getUpdates(API $madelineProto, array $params): array
    {
        foreach ($params as $key => $value) {
            $params[$key] = match ($key) {
                'offset', 'limit' => (int)$value,
                'timeout' => (float)$value,
                default => throw new InvalidArgumentException("Unknown parameter: {$key}"),
            };
        }

        return $madelineProto->getUpdates($params);
    }

    public function unsubscribeFromUpdates(API $madelineProto, ?string $channel = null): array
    {
        $inputChannelId = null;
        if ($channel) {
            $id = (string)$madelineProto->getId($channel);

            $inputChannelId = (int)\str_replace(['-100', '-'], '', $id);
            if (!$inputChannelId) {
                throw new InvalidArgumentException('Invalid id');
            }
        }
        $counter = 0;
        foreach (Client::getWrapper($madelineProto)->getAPI()->feeders as $channelId => $_) {
            if ($channelId === 0) {
                continue;
            }
            if ($inputChannelId && $inputChannelId !== $channelId) {
                continue;
            }
            Client::getWrapper($madelineProto)->getAPI()->feeders[$channelId]->stop();
            Client::getWrapper($madelineProto)->getAPI()->updaters[$channelId]->stop();
            unset(
                Client::getWrapper($madelineProto)->getAPI()->feeders[$channelId],
                Client::getWrapper($madelineProto)->getAPI()->updaters[$channelId]
            );
            Client::getWrapper($madelineProto)->getAPI()->getChannelStates()->remove($channelId);
            $counter++;
        }

        return [
            'disabled_update_loops' => $counter,
            'current_update_loops' => \count(Client::getWrapper($madelineProto)->getAPI()->feeders),
        ];
    }

    // ──────────────────────────────────────────────
    // Direct chunked upload + send to Telegram
    // ──────────────────────────────────────────────

    private static function getUploadChunkDir(): string
    {
        $dir = ROOT_DIR . '/upload-chunks';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Initiate a chunked upload session.
     * POST /api/uploadChunkInit
     */
    public function uploadChunkInit(
        API $madelineProto,
        string $peer,
        int $fileSize,
        string $mimeType,
        int $totalChunks,
        string $fileName,
        string $caption = '',
        ?int $topicId = null,
        string $mediaKind = 'document',
        ?int $width = null,
        ?int $height = null,
        ?int $durationSeconds = null,
    ): array {
        $uploadId = \bin2hex(\random_bytes(16));
        $chunkDir = self::getUploadChunkDir() . "/{$uploadId}";
        \mkdir($chunkDir, 0755, true);

        \file_put_contents(
            "{$chunkDir}/.meta.json",
            \json_encode([
                'peer' => $peer,
                'fileSize' => $fileSize,
                'mimeType' => $mimeType,
                'totalChunks' => $totalChunks,
                'fileName' => $fileName,
                'caption' => $caption,
                'topicId' => $topicId,
                'mediaKind' => $mediaKind,
                'width' => $width,
                'height' => $height,
                'durationSeconds' => $durationSeconds,
                'createdAt' => \time(),
            ], JSON_THROW_ON_ERROR)
        );

        return [
            'uploadId' => $uploadId,
            'chunkSize' => 4 * 1024 * 1024,
        ];
    }

    /**
     * Upload a single chunk.
     * POST /api/uploadChunk (multipart: uploadId, chunkIndex, file)
     */
    public function uploadChunk(
        API $madelineProto,
        string $uploadId,
        int $chunkIndex,
        ?ReadableStream $file = null,
        ?int $totalChunks = null,
        ?string $filename = null,
        ?string $fileName = null,
        ?string $mimeType = null,
    ): array {
        $chunkDir = self::getUploadChunkDir() . "/{$uploadId}";
        if (!\is_dir($chunkDir)) {
            throw new \InvalidArgumentException("Upload session not found: {$uploadId}");
        }

        $chunkPath = "{$chunkDir}/{$chunkIndex}.part";
        if ($file) {
            $content = $file->buffer();
            \file_put_contents($chunkPath, $content);
        }

        return [
            'received' => true,
            'chunkIndex' => $chunkIndex,
        ];
    }

    /**
     * Check status of a chunked upload (for resume).
     * POST /api/uploadChunkStatus
     */
    public function uploadChunkStatus(
        API $madelineProto,
        string $uploadId,
        ?int $totalChunks = null,
    ): array {
        $chunkDir = self::getUploadChunkDir() . "/{$uploadId}";
        if (!\is_dir($chunkDir)) {
            return ['contiguousUploadedChunks' => 0, 'uploadedChunks' => []];
        }

        $files = \glob("{$chunkDir}/*.part") ?: [];
        $indices = [];
        foreach ($files as $f) {
            $basename = \pathinfo($f, \PATHINFO_FILENAME);
            if (\is_numeric($basename)) {
                $indices[] = (int)$basename;
            }
        }
        \sort($indices);

        $contiguous = 0;
        foreach ($indices as $i) {
            if ($i === $contiguous) {
                $contiguous++;
            } else {
                break;
            }
        }

        return [
            'contiguousUploadedChunks' => $contiguous,
            'uploadedChunks' => $indices,
        ];
    }

    /**
     * Finalise chunked upload: stream to Telegram via uploadFromCallable,
     * then send media message. Returns SSE stream with progress.
     * POST /api/uploadChunkComplete
     */
    public function uploadChunkComplete(
        API $madelineProto,
        Request $request,
        string $uploadId,
    ): Response {
        $chunkDir = self::getUploadChunkDir() . "/{$uploadId}";
        $metaPath = "{$chunkDir}/.meta.json";

        if (!\is_file($metaPath)) {
            throw new \InvalidArgumentException("Upload session not found: {$uploadId}");
        }

        $meta = \json_decode(\file_get_contents($metaPath), true);
        $chunkSize = 4 * 1024 * 1024;
        $fileSize = $meta['fileSize'];

        $pipe = new Pipe(65536);
        $response = new Response(
            status: 200,
            headers: [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ],
            body: $pipe->getSource(),
        );

        $sink = $pipe->getSink();
        $sseWrite = function (array $data) use ($sink): void {
            if ($sink->isWritable()) {
                try {
                    $encoded = \json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
                    if ($encoded === false) return;
                    $sink->write('data: ' . $encoded . "\n\n");
                } catch (\Throwable) {
                    // client disconnected
                }
            }
        };

        EventLoop::queue(function () use (
            $sink, $chunkDir, $meta, $chunkSize, $fileSize, $madelineProto, $sseWrite
        ) {
            try {
                $sseWrite(['event' => 'phase', 'phase' => 'upload']);

                $inputFile = $madelineProto->uploadFromCallable(
                    callable: function (int $offset, int $size) use ($chunkDir, $chunkSize, $fileSize, $sseWrite): string {
                        $chunkIndex = \intdiv($offset, $chunkSize);
                        $chunkPath = "{$chunkDir}/{$chunkIndex}.part";

                        if (!\is_file($chunkPath)) {
                            throw new \RuntimeException("Chunk {$chunkIndex} not found at offset {$offset}");
                        }

                        $data = \file_get_contents($chunkPath, offset: $offset % $chunkSize, length: $size);
                        if ($data === false || $data === '') {
                            throw new \RuntimeException("Failed to read chunk {$chunkIndex}");
                        }

                        $pct = (int)((($offset + \strlen($data)) / $fileSize) * 100);
                        $sseWrite([
                            'event' => 'progress',
                            'progress' => \min(99, $pct),
                            'uploaded' => $offset + \strlen($data),
                            'total' => $fileSize,
                        ]);

                        return $data;
                    },
                    size: $fileSize,
                    mime: $meta['mimeType'],
                );

                $sseWrite(['event' => 'phase', 'phase' => 'sending']);

                $media = [
                    '_' => 'inputMediaUploadedDocument',
                    'file' => $inputFile,
                    'attributes' => [
                        ['_' => 'documentAttributeFilename', 'file_name' => $meta['fileName']],
                    ],
                ];

                $kind = $meta['mediaKind'] ?? 'document';
                if ($kind === 'video') {
                    $videoAttr = [
                        '_' => 'documentAttributeVideo',
                        'supports_streaming' => true,
                    ];
                    if (!empty($meta['durationSeconds'])) {
                        $videoAttr['duration'] = (int)$meta['durationSeconds'];
                    }
                    if (!empty($meta['width'])) {
                        $videoAttr['w'] = (int)$meta['width'];
                    }
                    if (!empty($meta['height'])) {
                        $videoAttr['h'] = (int)$meta['height'];
                    }
                    $media['attributes'][] = $videoAttr;
                } elseif ($kind === 'audio') {
                    $media['attributes'][] = [
                        '_' => 'documentAttributeAudio',
                    ];
                } elseif ($kind === 'image') {
                    $media['force_file'] = true;
                }

                $sendParams = [
                    'peer' => $meta['peer'],
                    'media' => $media,
                    'message' => $meta['caption'] ?? '',
                ];

                if (!empty($meta['topicId']) && $meta['topicId'] > 0) {
                    $sendParams['reply_to'] = [
                        '_' => 'inputReplyToMessage',
                        'reply_to_msg_id' => $meta['topicId'],
                    ];
                }

                $result = $madelineProto->messages->sendMedia(...$sendParams);

                $di = new \RecursiveDirectoryIterator($chunkDir, \FilesystemIterator::SKIP_DOTS);
                $ri = new \RecursiveIteratorIterator($di, \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($ri as $f) {
                    $f->isDir() ? \rmdir((string)$f) : \unlink((string)$f);
                }
                \rmdir($chunkDir);

                $sseWrite([
                    'event' => 'complete',
                ]);
                $sink->end();

            } catch (\Throwable $e) {
                $sseWrite([
                    'event' => 'error',
                    'error' => $e->getMessage(),
                ]);
                $sink->end();
            }
        });

        return $response;
    }

}
