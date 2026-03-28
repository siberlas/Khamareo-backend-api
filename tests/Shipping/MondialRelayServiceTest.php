<?php

namespace App\Tests\Shipping;

use App\Shipping\DTO\MondialRelay\MondialRelayAddressDTO;
use App\Shipping\DTO\MondialRelay\MondialRelayLabelDTO;
use App\Shipping\DTO\MondialRelay\MondialRelayShipmentDTO;
use App\Shipping\Exception\MondialRelayApiException;
use App\Shipping\Exception\MondialRelayException;
use App\Shipping\Service\MondialRelayApiService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class MondialRelayServiceTest extends TestCase
{
    private function createService(?HttpClientInterface $httpClient = null): MondialRelayApiService
    {
        return new MondialRelayApiService(
            httpClient: $httpClient ?? $this->createMock(HttpClientInterface::class),
            apiUrl: 'https://connect-api-sandbox.mondialrelay.com/api/shipment',
            login: 'test@test.com',
            password: 'testpass',
            customerId: 'TESTID',
            culture: 'fr-FR',
            logger: new NullLogger(),
        );
    }

    private function createTestShipmentDTO(): MondialRelayShipmentDTO
    {
        $sender = new MondialRelayAddressDTO(
            title: 'MR',
            firstname: 'John',
            lastname: 'Doe',
            addressAdd1: 'JOHN DOE',
            streetname: '1 RUE EXEMPLE',
            postcode: '75001',
            city: 'PARIS',
            countryCode: 'FR',
            phoneNo: '0600000000',
            email: 'john@example.com',
        );

        $recipient = new MondialRelayAddressDTO(
            title: 'MME',
            firstname: 'Marie',
            lastname: 'Dupont',
            addressAdd1: 'MARIE DUPONT',
            streetname: '10 RUE DE LA PAIX',
            postcode: '75002',
            city: 'PARIS',
            countryCode: 'FR',
            phoneNo: '0612345678',
            mobileNo: '0612345678',
            email: 'marie@example.com',
        );

        return new MondialRelayShipmentDTO(
            sender: $sender,
            recipient: $recipient,
            weightGrams: 500,
            deliveryMode: '24R',
            deliveryLocation: 'FR-66974',
            collectionMode: 'CCC',
            orderNo: 'TEST-001',
            parcelContent: 'Produits naturels',
        );
    }

    public function testBuildXmlRequestContainsCorrectFields(): void
    {
        $service = $this->createService();
        $dto = $this->createTestShipmentDTO();

        $xml = $service->buildXmlRequest($dto);

        // Verify XML structure
        $this->assertStringContainsString('<Login>test@test.com</Login>', $xml);
        $this->assertStringContainsString('<Password>testpass</Password>', $xml);
        $this->assertStringContainsString('<CustomerId>TESTID</CustomerId>', $xml);
        $this->assertStringContainsString('<Culture>fr-FR</Culture>', $xml);
        $this->assertStringContainsString('<VersionAPI>1.0</VersionAPI>', $xml);

        // Output options
        $this->assertStringContainsString('<OutputFormat>A4</OutputFormat>', $xml);
        $this->assertStringContainsString('<OutputType>PdfUrl</OutputType>', $xml);

        // Shipment fields
        $this->assertStringContainsString('<OrderNo>TEST-001</OrderNo>', $xml);
        $this->assertStringContainsString('<ParcelCount>1</ParcelCount>', $xml);
        $this->assertStringContainsString('Mode="24R"', $xml);
        $this->assertStringContainsString('Location="FR-66974"', $xml);

        // Weight
        $this->assertStringContainsString('Value="500"', $xml);
        $this->assertStringContainsString('Unit="gr"', $xml);

        // Content
        $this->assertStringContainsString('<Content>Produits naturels</Content>', $xml);

        // Sender address fields (uppercased)
        $this->assertStringContainsString('<Streetname>1 RUE EXEMPLE</Streetname>', $xml);
        $this->assertStringContainsString('<City>PARIS</City>', $xml);
        $this->assertStringContainsString('<CountryCode>FR</CountryCode>', $xml);

        // Recipient address fields
        $this->assertStringContainsString('<PostCode>75002</PostCode>', $xml);
    }

    public function testBuildXmlRequestEmptyFieldsAreEmptyTags(): void
    {
        $service = $this->createService();

        $sender = new MondialRelayAddressDTO(
            addressAdd1: 'TEST SENDER',
            streetname: '1 RUE TEST',
            postcode: '75001',
            city: 'PARIS',
            countryCode: 'FR',
        );

        $recipient = new MondialRelayAddressDTO(
            addressAdd1: 'TEST RECIPIENT',
            streetname: '2 RUE TEST',
            postcode: '75002',
            city: 'LYON',
            countryCode: 'FR',
        );

        $dto = new MondialRelayShipmentDTO(
            sender: $sender,
            recipient: $recipient,
            weightGrams: 100,
            deliveryMode: 'HOM',
        );

        $xml = $service->buildXmlRequest($dto);

        // Empty fields should be present as empty tags
        $this->assertStringContainsString('<HouseNo/>', $xml);
        $this->assertStringContainsString('<Email/>', $xml);
        $this->assertStringContainsString('<MobileNo/>', $xml);
    }

    public function testParseXmlResponseSuccess(): void
    {
        $service = $this->createService();

        $xmlResponse = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ShipmentCreationResponse>
  <StatusList>
    <Status>
      <Code>0</Code>
      <Message>OK</Message>
    </Status>
  </StatusList>
  <ShipmentsList>
    <Shipment>
      <ShipmentNumber>31236578</ShipmentNumber>
      <LabelURL>https://connect-api-sandbox.mondialrelay.com/api/labels/31236578.pdf</LabelURL>
    </Shipment>
  </ShipmentsList>
</ShipmentCreationResponse>
XML;

        $result = $service->parseXmlResponse($xmlResponse);

        $this->assertInstanceOf(MondialRelayLabelDTO::class, $result);
        $this->assertSame('31236578', $result->shipmentNumber);
        $this->assertStringContainsString('31236578.pdf', $result->labelUrl);
        $this->assertSame($xmlResponse, $result->rawXmlResponse);
    }

    public function testParseXmlResponseWithErrors(): void
    {
        $service = $this->createService();

        $xmlResponse = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ShipmentCreationResponse>
  <StatusList>
    <Status>
      <Code>20</Code>
      <Message>Invalid postcode</Message>
    </Status>
    <Status>
      <Code>30</Code>
      <Message>Invalid weight</Message>
    </Status>
  </StatusList>
  <ShipmentsList/>
</ShipmentCreationResponse>
XML;

        $this->expectException(MondialRelayApiException::class);
        $this->expectExceptionMessage('Mondial Relay API error(s)');

        try {
            $service->parseXmlResponse($xmlResponse);
        } catch (MondialRelayApiException $e) {
            $this->assertCount(2, $e->getErrorCodes());
            throw $e;
        }
    }

    public function testParseXmlResponseInvalidXml(): void
    {
        $service = $this->createService();

        $this->expectException(MondialRelayException::class);
        $this->expectExceptionMessage('Failed to parse');

        $service->parseXmlResponse('not valid xml <><>');
    }

    public function testParseXmlResponseMissingShipmentNumber(): void
    {
        $service = $this->createService();

        $xmlResponse = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ShipmentCreationResponse>
  <StatusList>
    <Status><Code>0</Code></Status>
  </StatusList>
  <ShipmentsList>
    <Shipment>
      <LabelURL>https://example.com/label.pdf</LabelURL>
    </Shipment>
  </ShipmentsList>
</ShipmentCreationResponse>
XML;

        $this->expectException(MondialRelayException::class);
        $this->expectExceptionMessage('No ShipmentNumber');

        $service->parseXmlResponse($xmlResponse);
    }

    public function testMinWeightValidation(): void
    {
        $service = $this->createService();

        $dto = new MondialRelayShipmentDTO(
            sender: new MondialRelayAddressDTO(
                addressAdd1: 'TEST',
                streetname: 'RUE TEST',
                postcode: '75001',
                city: 'PARIS',
                countryCode: 'FR',
            ),
            recipient: new MondialRelayAddressDTO(
                addressAdd1: 'TEST',
                streetname: 'RUE TEST',
                postcode: '75002',
                city: 'PARIS',
                countryCode: 'FR',
            ),
            weightGrams: 5, // Below minimum
            deliveryMode: 'HOM',
        );

        $this->expectException(MondialRelayException::class);
        $this->expectExceptionMessage('Weight must be at least 10 grams');

        $service->createLabel($dto);
    }

    public function testCreateLabelSuccess(): void
    {
        $successXml = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ShipmentCreationResponse>
  <StatusList>
    <Status><Code>0</Code></Status>
  </StatusList>
  <ShipmentsList>
    <Shipment>
      <ShipmentNumber>99887766</ShipmentNumber>
      <LabelURL>https://sandbox.mondialrelay.com/labels/99887766.pdf</LabelURL>
    </Shipment>
  </ShipmentsList>
</ShipmentCreationResponse>
XML;

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn($successXml);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $service = $this->createService($httpClient);
        $dto = $this->createTestShipmentDTO();

        $result = $service->createLabel($dto);

        $this->assertSame('99887766', $result->shipmentNumber);
        $this->assertStringContainsString('99887766.pdf', $result->labelUrl);
    }

    public function testCreateLabelHttpError(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(500);
        $response->method('getContent')->willReturn('Internal Server Error');

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $service = $this->createService($httpClient);
        $dto = $this->createTestShipmentDTO();

        $this->expectException(MondialRelayApiException::class);
        $this->expectExceptionMessage('HTTP 500');

        $service->createLabel($dto);
    }

    public function testOrderNoTruncatedTo15Chars(): void
    {
        $service = $this->createService();

        $dto = new MondialRelayShipmentDTO(
            sender: new MondialRelayAddressDTO(
                addressAdd1: 'TEST',
                streetname: 'RUE TEST',
                postcode: '75001',
                city: 'PARIS',
                countryCode: 'FR',
            ),
            recipient: new MondialRelayAddressDTO(
                addressAdd1: 'TEST',
                streetname: 'RUE TEST',
                postcode: '75002',
                city: 'PARIS',
                countryCode: 'FR',
            ),
            weightGrams: 500,
            deliveryMode: 'HOM',
            orderNo: 'VERY-LONG-ORDER-NUMBER-12345',
        );

        $xml = $service->buildXmlRequest($dto);

        $this->assertStringContainsString('<OrderNo>VERY-LONG-ORDER</OrderNo>', $xml);
        $this->assertStringNotContainsString('VERY-LONG-ORDER-NUMBER-12345', $xml);
    }

    public function testParseXmlResponseWithAttributeStatusFormat(): void
    {
        $service = $this->createService();

        // Actual Mondial Relay API response format uses attributes on Status elements
        $xmlResponse = <<<'XML'
<ShipmentCreationResponse xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns="http://www.example.org/Response"><StatusList><Status Code="10001" Level="Critical error" Message="Login et/ou mot de passe non valide." /></StatusList></ShipmentCreationResponse>
XML;

        $this->expectException(MondialRelayApiException::class);
        $this->expectExceptionMessage('Mondial Relay API error(s)');

        try {
            $service->parseXmlResponse($xmlResponse);
        } catch (MondialRelayApiException $e) {
            $this->assertCount(1, $e->getErrorCodes());
            $this->assertStringContainsString('10001', $e->getErrorCodes()[0]);
            throw $e;
        }
    }

    public function testParseXmlResponseWithNamespace(): void
    {
        $service = $this->createService();

        // Response with namespace (actual format from Mondial Relay)
        $xmlResponse = <<<'XML'
<ShipmentCreationResponse xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns="http://www.example.org/Response"><StatusList><Status Code="0" Level="Information" Message="OK" /></StatusList><ShipmentsList><Shipment><ShipmentNumber>12345678</ShipmentNumber><LabelURL>https://sandbox.mondialrelay.com/labels/12345678.pdf</LabelURL></Shipment></ShipmentsList></ShipmentCreationResponse>
XML;

        $result = $service->parseXmlResponse($xmlResponse);

        $this->assertSame('12345678', $result->shipmentNumber);
        $this->assertStringContainsString('12345678.pdf', $result->labelUrl);
    }

    public function testAccentTransliteration(): void
    {
        $service = $this->createService();

        $dto = new MondialRelayShipmentDTO(
            sender: new MondialRelayAddressDTO(
                addressAdd1: 'EXPEDITEUR',
                streetname: 'Rue des Acacias',
                postcode: '75001',
                city: 'Paris',
                countryCode: 'FR',
            ),
            recipient: new MondialRelayAddressDTO(
                addressAdd1: 'Helene Lefevre',
                streetname: 'Avenue des Champs-Elysees',
                postcode: '75008',
                city: 'Paris',
                countryCode: 'FR',
            ),
            weightGrams: 300,
            deliveryMode: 'HOM',
        );

        $xml = $service->buildXmlRequest($dto);

        // Cities and streets should be uppercased
        $this->assertStringContainsString('PARIS', $xml);
        $this->assertStringContainsString('RUE DES ACACIAS', $xml);
    }
}
