<?php

namespace App\Tests\Order;

use App\Order\Entity\DigitalDownload;
use PHPUnit\Framework\TestCase;

/**
 * Machine à états d'un lien de téléchargement : « 1 téléchargement puis mort »,
 * expiration, révocation, réémission par l'admin.
 */
class DigitalDownloadTest extends TestCase
{
    private function make(): DigitalDownload
    {
        return (new DigitalDownload())
            ->setEmail('client@example.com')
            ->setDeliveredFilePublicId('khamareo/ebooks/delivered/abc');
    }

    public function testTokenIsGeneratedAndLong(): void
    {
        $d = $this->make();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $d->getToken());
    }

    public function testDownloadableUntilFirstDownload(): void
    {
        $d = $this->make();
        $this->assertTrue($d->isDownloadable());

        $d->registerDownload();
        $this->assertSame(1, $d->getDownloadCount());
        $this->assertNotNull($d->getFirstDownloadedAt());
        $this->assertFalse($d->isDownloadable(), 'Le lien est mort après 1 téléchargement');
    }

    public function testNotDownloadableWithoutDeliveredFile(): void
    {
        $d = (new DigitalDownload())->setEmail('x@example.com');
        $this->assertFalse($d->isDownloadable());
    }

    public function testExpiryBlocksDownload(): void
    {
        $d = $this->make()->setExpiresAt(new \DateTimeImmutable('-1 hour'));
        $this->assertTrue($d->isExpired());
        $this->assertFalse($d->isDownloadable());
    }

    public function testRevokeBlocksDownload(): void
    {
        $d = $this->make();
        $d->revoke();
        $this->assertNotNull($d->getRevokedAt());
        $this->assertFalse($d->isDownloadable());
    }

    public function testReissueResetsTokenCountAndExpiry(): void
    {
        $d = $this->make();
        $d->registerDownload();
        $firstToken = $d->getToken();

        $newExpiry = new \DateTimeImmutable('+30 days');
        $d->reissue($newExpiry);

        $this->assertNotSame($firstToken, $d->getToken());
        $this->assertSame(0, $d->getDownloadCount());
        $this->assertNull($d->getRevokedAt());
        $this->assertEquals($newExpiry, $d->getExpiresAt());
        $this->assertTrue($d->isDownloadable());
    }

    public function testMaxDownloadsFloorIsOne(): void
    {
        $d = $this->make()->setMaxDownloads(0);
        $this->assertSame(1, $d->getMaxDownloads());
    }
}
