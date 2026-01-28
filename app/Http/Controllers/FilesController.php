<?php

namespace App\Http\Controllers;

use App\Services\File\FileService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Class FilesController
 *
 * @package App\Http\Controllers
 */
class FilesController
{
    protected FileService $fileService;

    public function __construct(FileService $fileService)
    {
        $this->fileService = $fileService;
    }

    /**
     * Передать файл на просмотр
     *
     * @param string $fileId
     *
     * @return StreamedResponse
     */
    public function getFileStream(string $fileId): StreamedResponse
    {
        try {
            return $this->fileService->streamFile($fileId);
        } catch (\Throwable $e) {
            Log::error('File stream error', ['fileId' => $fileId, 'error' => $e->getMessage()]);
            die();
        }
    }

    /**
     * Передать файл на скачивание
     *
     * @param string $fileId
     *
     * @return Response
     */
    public function getFileDownload(string $fileId): Response
    {
        try {
            return $this->fileService->downloadFile($fileId);
        } catch (\Throwable $e) {
            Log::error('File download error', ['fileId' => $fileId, 'error' => $e->getMessage()]);
            die();
        }
    }
}
