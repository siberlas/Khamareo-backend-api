<?php

namespace App\Order\Service\Pdf\Generator;

readonly class PdfGenerationResult
{
    public function __construct(
        public bool $success,
        public ?string $pdfContent = null,
        public ?string $filename = null,
        public ?string $cloudinaryUrl = null,
        public ?string $cloudinaryAssetId = null,
        public ?string $cloudinaryPublicId = null,
        public ?string $error = null,
    ) {}

    public static function success(string $pdfContent, string $filename): self
    {
        return new self(
            success: true,
            pdfContent: $pdfContent,
            filename: $filename,
        );
    }

    public static function failure(string $error): self
    {
        return new self(success: false, error: $error);
    }

    public function withCloudinaryData(string $url, string $assetId, string $publicId): self
    {
        return new self(
            success: $this->success,
            pdfContent: $this->pdfContent,
            filename: $this->filename,
            cloudinaryUrl: $url,
            cloudinaryAssetId: $assetId,
            cloudinaryPublicId: $publicId,
            error: $this->error,
        );
    }

    public function isStoredOnCloudinary(): bool
    {
        return $this->cloudinaryUrl !== null;
    }
}