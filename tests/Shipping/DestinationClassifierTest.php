<?php

namespace App\Tests\Shipping;

use App\Shipping\Enum\DestinationZone;
use App\Shipping\Service\DestinationClassifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests unitaires du classifieur de zones Colissimo.
 * Aucune base de données ni service externe requis.
 */
class DestinationClassifierTest extends TestCase
{
    private DestinationClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new DestinationClassifier(new NullLogger());
    }

    // -----------------------------------------------------------------------
    // France métropolitaine
    // -----------------------------------------------------------------------

    public function testFranceMetroByCountryCode(): void
    {
        $zone = $this->classifier->classify('75001', 'FR');
        $this->assertSame(DestinationZone::FRANCE_METRO, $zone);
    }

    public function testMonacoByCountryCode(): void
    {
        $zone = $this->classifier->classify('98000', 'MC');
        $this->assertSame(DestinationZone::FRANCE_METRO, $zone);
    }

    public function testAndorraByCountryCode(): void
    {
        $zone = $this->classifier->classify('AD500', 'AD');
        $this->assertSame(DestinationZone::FRANCE_METRO, $zone);
    }

    public function testFranceByPostalCodeWithoutCountry(): void
    {
        $zone = $this->classifier->classify('69001', null);
        $this->assertSame(DestinationZone::FRANCE_METRO, $zone);
    }

    // -----------------------------------------------------------------------
    // Outre-Mer (DROM-COM)
    // -----------------------------------------------------------------------

    public function testMartiniqueByCountryCode(): void
    {
        $zone = $this->classifier->classify('97200', 'MQ');
        $this->assertSame(DestinationZone::OUTRE_MER, $zone);
    }

    public function testGuadeloupeByFrenchPostalCode(): void
    {
        // Guadeloupe utilise CP français 971xx avec country FR
        $zone = $this->classifier->classify('97100', 'FR');
        $this->assertSame(DestinationZone::OUTRE_MER, $zone);
    }

    public function testReunionByCountryCode(): void
    {
        $zone = $this->classifier->classify('97400', 'RE');
        $this->assertSame(DestinationZone::OUTRE_MER, $zone);
    }

    public function testMayotteByCountryCode(): void
    {
        $zone = $this->classifier->classify('97600', 'YT');
        $this->assertSame(DestinationZone::OUTRE_MER, $zone);
    }

    // -----------------------------------------------------------------------
    // Union Européenne
    // -----------------------------------------------------------------------

    public function testGermanyByCountryCode(): void
    {
        $zone = $this->classifier->classify('10115', 'DE');
        $this->assertSame(DestinationZone::UNION_EUROPEENNE, $zone);
    }

    public function testItalyByCountryCode(): void
    {
        $zone = $this->classifier->classify('00100', 'IT');
        $this->assertSame(DestinationZone::UNION_EUROPEENNE, $zone);
    }

    public function testSpainByCountryCode(): void
    {
        $zone = $this->classifier->classify('28001', 'ES');
        $this->assertSame(DestinationZone::UNION_EUROPEENNE, $zone);
    }

    public function testBelgiumByCountryCode(): void
    {
        $zone = $this->classifier->classify('1000', 'BE');
        $this->assertSame(DestinationZone::UNION_EUROPEENNE, $zone);
    }

    public function testNetherlandsByCountryCode(): void
    {
        $zone = $this->classifier->classify('1011 AB', 'NL');
        $this->assertSame(DestinationZone::UNION_EUROPEENNE, $zone);
    }

    // -----------------------------------------------------------------------
    // Europe hors UE (Suisse, UK…)
    // -----------------------------------------------------------------------

    public function testSwitzerlandByCountryCode(): void
    {
        $zone = $this->classifier->classify('8001', 'CH');
        $this->assertSame(DestinationZone::EUROPE_HORS_UE, $zone);
    }

    public function testUKByCountryCode(): void
    {
        $zone = $this->classifier->classify('EC1A 1BB', 'GB');
        $this->assertSame(DestinationZone::EUROPE_HORS_UE, $zone);
    }

    // -----------------------------------------------------------------------
    // International (reste du monde)
    // -----------------------------------------------------------------------

    public function testUSAByCountryCode(): void
    {
        $zone = $this->classifier->classify('10001', 'US');
        $this->assertSame(DestinationZone::INTERNATIONAL, $zone);
    }

    /** Régression : zip 43952 ressemble à un CP français mais le pays est US */
    public function testUSAWithFrenchLookingZip(): void
    {
        $zone = $this->classifier->classify('43952', 'US');
        $this->assertSame(DestinationZone::INTERNATIONAL, $zone);
    }

    public function testCanadaByCountryCode(): void
    {
        $zone = $this->classifier->classify('M5H 2N2', 'CA');
        $this->assertSame(DestinationZone::INTERNATIONAL, $zone);
    }

    public function testJapanByCountryCode(): void
    {
        $zone = $this->classifier->classify('100-0001', 'JP');
        $this->assertSame(DestinationZone::INTERNATIONAL, $zone);
    }

    /** Pays inconnu → fallback INTERNATIONAL (le plus sûr) */
    public function testUnknownCountryFallsbackToInternational(): void
    {
        $zone = $this->classifier->classify(null, 'XX');
        $this->assertSame(DestinationZone::INTERNATIONAL, $zone);
    }

    /** Aucune info → fallback INTERNATIONAL */
    public function testNoInfoFallsbackToInternational(): void
    {
        $zone = $this->classifier->classify(null, null);
        $this->assertSame(DestinationZone::INTERNATIONAL, $zone);
    }

    // -----------------------------------------------------------------------
    // Codes produits Colissimo (enum DestinationZone)
    // -----------------------------------------------------------------------

    public function testProductCodeFranceMetro(): void
    {
        $this->assertSame('DOM', DestinationZone::FRANCE_METRO->getProductCode());
    }

    public function testProductCodeOutreMer(): void
    {
        $this->assertSame('COM', DestinationZone::OUTRE_MER->getProductCode());
    }

    public function testProductCodeUnionEuropeenne(): void
    {
        $this->assertSame('DOS', DestinationZone::UNION_EUROPEENNE->getProductCode());
    }

    public function testProductCodeEuropeHorsUE(): void
    {
        $this->assertSame('DOM', DestinationZone::EUROPE_HORS_UE->getProductCode());
    }

    public function testProductCodeInternational(): void
    {
        $this->assertSame('COLI', DestinationZone::INTERNATIONAL->getProductCode());
    }

    // -----------------------------------------------------------------------
    // CN23 (déclaration douanière)
    // -----------------------------------------------------------------------

    public function testCN23NotRequiredForFranceMetro(): void
    {
        $this->assertFalse(DestinationZone::FRANCE_METRO->requiresCN23());
    }

    public function testCN23RequiredForOutreMer(): void
    {
        $this->assertTrue(DestinationZone::OUTRE_MER->requiresCN23());
    }

    public function testCN23NotRequiredForUE(): void
    {
        $this->assertFalse(DestinationZone::UNION_EUROPEENNE->requiresCN23());
    }

    public function testCN23RequiredForEuropeHorsUE(): void
    {
        $this->assertTrue(DestinationZone::EUROPE_HORS_UE->requiresCN23());
    }

    public function testCN23RequiredForInternational(): void
    {
        $this->assertTrue(DestinationZone::INTERNATIONAL->requiresCN23());
    }

    // -----------------------------------------------------------------------
    // isFrenchTerritory()
    // -----------------------------------------------------------------------

    public function testFranceMetroIsFrenchTerritory(): void
    {
        $this->assertTrue(DestinationZone::FRANCE_METRO->isFrenchTerritory());
    }

    public function testOutreMerIsFrenchTerritory(): void
    {
        $this->assertTrue(DestinationZone::OUTRE_MER->isFrenchTerritory());
    }

    public function testUEIsNotFrenchTerritory(): void
    {
        $this->assertFalse(DestinationZone::UNION_EUROPEENNE->isFrenchTerritory());
    }

    public function testInternationalIsNotFrenchTerritory(): void
    {
        $this->assertFalse(DestinationZone::INTERNATIONAL->isFrenchTerritory());
    }
}
