<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

class UploadedFileSecurityValidator
{
    public const MAX_FILE_SIZE_BYTES = 5 * 1024 * 1024;

    private const PHOTO_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    private const DOCUMENT_MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    private const DANGEROUS_PDF_MARKERS = [
        '/JavaScript',
        '/JS',
        '/EmbeddedFile',
        '/Launch',
        '/OpenAction',
        '/RichMedia',
        '/XFA',
    ];

    /**
     * @return array{mimeType: string, extension: string}
     */
    public function validatePhoto(UploadedFile $file): array
    {
        $this->assertBaseConstraints($file);

        $mimeType = $this->detectMimeType($file);
        if (!isset(self::PHOTO_MIME_TYPES[$mimeType])) {
            throw new \RuntimeException('Le format de la photo doit être PNG ou JPEG');
        }

        $imageInfo = @getimagesize($file->getPathname());
        if ($imageInfo === false) {
            throw new \RuntimeException('Le contenu de la photo est invalide');
        }

        $imageType = $imageInfo[2] ?? null;
        if (!in_array($imageType, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            throw new \RuntimeException('Le format de la photo doit être PNG ou JPEG');
        }

        return [
            'mimeType' => $mimeType,
            'extension' => self::PHOTO_MIME_TYPES[$mimeType],
        ];
    }

    /**
     * @return array{mimeType: string, extension: string}
     */
    public function validateDocument(UploadedFile $file): array
    {
        $this->assertBaseConstraints($file);

        $mimeType = $this->detectMimeType($file);
        if (!isset(self::DOCUMENT_MIME_TYPES[$mimeType])) {
            throw new \RuntimeException('Le document doit être un PDF, PNG ou JPEG');
        }

        if (isset(self::PHOTO_MIME_TYPES[$mimeType])) {
            $imageInfo = @getimagesize($file->getPathname());
            if ($imageInfo === false) {
                throw new \RuntimeException('Le contenu du document image est invalide');
            }

            $imageType = $imageInfo[2] ?? null;
            if (!in_array($imageType, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
                throw new \RuntimeException('Le document doit être un PDF, PNG ou JPEG');
            }

            return [
                'mimeType' => $mimeType,
                'extension' => self::DOCUMENT_MIME_TYPES[$mimeType],
            ];
        }

        $contents = file_get_contents($file->getPathname());
        if ($contents === false || $contents === '') {
            throw new \RuntimeException('Le contenu du document est invalide');
        }

        if (!str_starts_with($contents, '%PDF-')) {
            throw new \RuntimeException('Le document doit être un PDF valide');
        }

        foreach (self::DANGEROUS_PDF_MARKERS as $marker) {
            if (str_contains($contents, $marker)) {
                throw new \RuntimeException('Le document PDF contient du contenu interdit');
            }
        }

        return [
            'mimeType' => $mimeType,
            'extension' => self::DOCUMENT_MIME_TYPES[$mimeType],
        ];
    }

    private function assertBaseConstraints(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new \RuntimeException('Le fichier transmis est invalide');
        }

        $size = $file->getSize();
        if (!is_int($size) && !is_float($size) && $size !== null) {
            throw new \RuntimeException('Impossible de déterminer la taille du fichier');
        }

        if ($size !== null && $size > self::MAX_FILE_SIZE_BYTES) {
            throw new \RuntimeException('Le fichier ne doit pas dépasser 5 Mo');
        }
    }

    private function detectMimeType(UploadedFile $file): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file->getPathname());

        if (!is_string($mimeType) || $mimeType === '') {
            throw new \RuntimeException('Impossible de détecter le type du fichier');
        }

        return $mimeType;
    }
}
