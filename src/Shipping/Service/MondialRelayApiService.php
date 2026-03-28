<?php

namespace App\Shipping\Service;

use App\Shipping\DTO\MondialRelay\MondialRelayAddressDTO;
use App\Shipping\DTO\MondialRelay\MondialRelayLabelDTO;
use App\Shipping\DTO\MondialRelay\MondialRelayShipmentDTO;
use App\Shipping\Exception\MondialRelayApiException;
use App\Shipping\Exception\MondialRelayException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MondialRelayApiService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string              $apiUrl,
        private string              $login,
        private string              $password,
        private string              $customerId,
        private string              $culture,
        private LoggerInterface     $logger,
    ) {}

    /**
     * Create a shipping label via Mondial Relay API
     */
    public function createLabel(MondialRelayShipmentDTO $dto): MondialRelayLabelDTO
    {
        if ($dto->weightGrams < 10) {
            throw new MondialRelayException(
                sprintf('Weight must be at least 10 grams, got %d', $dto->weightGrams)
            );
        }

        $xmlRequest = $this->buildXmlRequest($dto);

        $this->logger->debug('Mondial Relay XML request', [
            'xml' => preg_replace(
                '/<Password>[^<]*<\/Password>/',
                '<Password>***</Password>',
                $xmlRequest
            ),
        ]);

        $rawResponse = $this->sendRequest($xmlRequest);

        $this->logger->debug('Mondial Relay XML response', [
            'xml' => $rawResponse,
        ]);

        return $this->parseXmlResponse($rawResponse);
    }

    /**
     * Build the XML request body using DOMDocument
     */
    public function buildXmlRequest(MondialRelayShipmentDTO $dto): string
    {
        $doc = new \DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = true;

        $root = $doc->createElementNS(
            'http://www.example.org/Request',
            'ShipmentCreationRequest'
        );
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:xsi',
            'http://www.w3.org/2001/XMLSchema-instance'
        );
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:xsd',
            'http://www.w3.org/2001/XMLSchema'
        );
        $doc->appendChild($root);

        // Context
        $context = $doc->createElement('Context');
        $context->appendChild($doc->createElement('Login', $this->escapeXml($this->login)));
        $context->appendChild($doc->createElement('Password', $this->escapeXml($this->password)));
        $context->appendChild($doc->createElement('CustomerId', $this->escapeXml($this->customerId)));
        $context->appendChild($doc->createElement('Culture', $this->escapeXml($this->culture)));
        $context->appendChild($doc->createElement('VersionAPI', '1.0'));
        $root->appendChild($context);

        // OutputOptions
        $outputOptions = $doc->createElement('OutputOptions');
        $outputOptions->appendChild($doc->createElement('OutputFormat', $this->escapeXml($dto->outputFormat)));
        $outputOptions->appendChild($doc->createElement('OutputType', $this->escapeXml($dto->outputType)));
        $root->appendChild($outputOptions);

        // ShipmentsList > Shipment
        $shipmentsList = $doc->createElement('ShipmentsList');
        $shipment = $doc->createElement('Shipment');

        $shipment->appendChild($doc->createElement('OrderNo', $this->escapeXml(substr($dto->orderNo, 0, 15))));
        $shipment->appendChild($doc->createElement('CustomerNo', $this->escapeXml(substr($dto->customerNo, 0, 9))));
        $shipment->appendChild($doc->createElement('ParcelCount', '1'));

        // DeliveryMode
        $deliveryMode = $doc->createElement('DeliveryMode');
        $deliveryMode->setAttribute('Mode', $this->escapeXml($dto->deliveryMode));
        $deliveryMode->setAttribute('Location', $this->escapeXml($dto->deliveryLocation));
        $shipment->appendChild($deliveryMode);

        // CollectionMode
        $collectionMode = $doc->createElement('CollectionMode');
        $collectionMode->setAttribute('Mode', $this->escapeXml($dto->collectionMode));
        $collectionMode->setAttribute('Location', $this->escapeXml($dto->collectionLocation));
        $shipment->appendChild($collectionMode);

        // Parcels
        $parcels = $doc->createElement('Parcels');
        $parcel = $doc->createElement('Parcel');
        $parcel->appendChild($doc->createElement('Content', $this->escapeXml(substr($dto->parcelContent, 0, 40))));
        $weight = $doc->createElement('Weight');
        $weight->setAttribute('Value', (string) $dto->weightGrams);
        $weight->setAttribute('Unit', 'gr');
        $parcel->appendChild($weight);
        $parcels->appendChild($parcel);
        $shipment->appendChild($parcels);

        // Sender
        $sender = $doc->createElement('Sender');
        $sender->appendChild($this->buildAddressElement($doc, $dto->sender));
        $shipment->appendChild($sender);

        // Recipient
        $recipient = $doc->createElement('Recipient');
        $recipient->appendChild($this->buildAddressElement($doc, $dto->recipient));
        $shipment->appendChild($recipient);

        $shipmentsList->appendChild($shipment);
        $root->appendChild($shipmentsList);

        return $doc->saveXML();
    }

    /**
     * Build <Address> element from DTO
     */
    private function buildAddressElement(\DOMDocument $doc, MondialRelayAddressDTO $address): \DOMElement
    {
        $el = $doc->createElement('Address');

        $fields = [
            'Title'       => $address->title,
            'Firstname'   => $address->firstname,
            'Lastname'    => $address->lastname,
            'Streetname'  => $this->normalizeForXml($address->streetname),
            'HouseNo'     => $address->houseNo,
            'CountryCode' => strtoupper($address->countryCode),
            'PostCode'    => $address->postcode,
            'City'        => $this->normalizeForXml($address->city),
            'AddressAdd1' => $this->normalizeForXml($address->addressAdd1),
            'AddressAdd2' => $this->normalizeForXml($address->addressAdd2),
            'AddressAdd3' => $this->normalizeForXml($address->addressAdd3),
            'PhoneNo'     => $address->phoneNo,
            'MobileNo'    => $address->mobileNo,
            'Email'       => $address->email,
        ];

        foreach ($fields as $tag => $value) {
            // Empty fields = empty XML tags (not omitted)
            $el->appendChild($doc->createElement($tag, $this->escapeXml($value)));
        }

        return $el;
    }

    /**
     * Send XML request to Mondial Relay API
     */
    private function sendRequest(string $xmlBody): string
    {
        try {
            $response = $this->httpClient->request('POST', $this->apiUrl, [
                'headers' => [
                    'Content-Type' => 'text/xml; charset=utf-8',
                    'Accept'       => 'application/xml',
                ],
                'body'    => $xmlBody,
                'timeout' => 30,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new MondialRelayApiException(
                    sprintf('Mondial Relay API returned HTTP %d: %s', $statusCode, substr($content, 0, 500)),
                    [],
                    $statusCode,
                );
            }

            return $content;

        } catch (MondialRelayApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new MondialRelayException(
                'Mondial Relay API request failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Parse XML response and extract shipment number + label URL
     *
     * Mondial Relay responses may use namespaces and two status formats:
     *   - Child elements: <Status><Code>20</Code><Message>...</Message></Status>
     *   - Attributes:     <Status Code="10001" Level="..." Message="..." />
     */
    public function parseXmlResponse(string $xmlResponse): MondialRelayLabelDTO
    {
        libxml_use_internal_errors(true);

        // Strip default namespace to simplify XPath / child access
        $cleanXml = preg_replace('/xmlns\s*=\s*"[^"]*"/', '', $xmlResponse);
        $xml = simplexml_load_string($cleanXml);

        if ($xml === false) {
            $errors = array_map(
                fn(\LibXMLError $e) => trim($e->message),
                libxml_get_errors()
            );
            libxml_clear_errors();

            throw new MondialRelayException(
                'Failed to parse Mondial Relay XML response: ' . implode(', ', $errors)
            );
        }

        // Check StatusList for errors
        $errorCodes = [];
        $statusList = $xml->StatusList ?? null;

        if ($statusList) {
            foreach ($statusList->children() as $status) {
                // Format 1: attributes (<Status Code="10001" Message="..." />)
                $attrs = $status->attributes();
                $codeFromAttr = (string) ($attrs['Code'] ?? '');
                $msgFromAttr = (string) ($attrs['Message'] ?? '');

                // Format 2: child elements (<Code>20</Code><Message>...</Message>)
                $codeFromChild = (string) ($status->Code ?? '');
                $msgFromChild = (string) ($status->Message ?? '');

                $code = $codeFromAttr !== '' ? $codeFromAttr : $codeFromChild;
                $message = $msgFromAttr !== '' ? $msgFromAttr : $msgFromChild;

                // Code "0" or empty = success
                if ($code !== '' && $code !== '0') {
                    $errorCodes[] = $code . ($message ? ': ' . $message : '');
                }
            }
        }

        if (!empty($errorCodes)) {
            throw new MondialRelayApiException(
                'Mondial Relay API error(s): ' . implode(', ', $errorCodes),
                $errorCodes,
            );
        }

        // Extract ShipmentNumber and label URL from response
        $shipmentNumber = '';
        $labelUrl = '';

        $shipmentsList = $xml->ShipmentsList ?? null;
        if ($shipmentsList) {
            $shipment = $shipmentsList->Shipment ?? null;
            if ($shipment) {
                // ShipmentNumber: try child element then attribute
                $shipmentNumber = (string) ($shipment->ShipmentNumber ?? '');
                if (empty($shipmentNumber)) {
                    $shipmentNumber = (string) ($shipment->attributes()['ShipmentNumber'] ?? '');
                }

                // Label URL: try multiple paths
                // Path 1: <LabelList><Label><Output>URL</Output></Label></LabelList>
                $labelList = $shipment->LabelList ?? null;
                if ($labelList) {
                    $label = $labelList->Label ?? null;
                    if ($label) {
                        $labelUrl = (string) ($label->Output ?? '');
                    }
                }

                // Path 2: direct <LabelURL> or <LabelUrl>
                if (empty($labelUrl)) {
                    $labelUrl = (string) ($shipment->LabelURL ?? $shipment->LabelUrl ?? '');
                }

                // Path 3: attributes
                if (empty($labelUrl)) {
                    $labelUrl = (string) ($shipment->attributes()['LabelURL'] ?? $shipment->attributes()['LabelUrl'] ?? '');
                }

                $this->logger->debug('Mondial Relay response parsed', [
                    'shipmentNumber' => $shipmentNumber,
                    'labelUrl' => $labelUrl,
                ]);
            }
        }

        if (empty($shipmentNumber)) {
            throw new MondialRelayException(
                'No ShipmentNumber found in Mondial Relay response'
            );
        }

        if (empty($labelUrl)) {
            $this->logger->error('No label URL found in Mondial Relay response', [
                'xml_response' => mb_substr($xmlResponse, 0, 2000),
            ]);
            throw new MondialRelayException(
                'No label URL found in Mondial Relay response'
            );
        }

        return new MondialRelayLabelDTO(
            shipmentNumber: $shipmentNumber,
            labelUrl: $labelUrl,
            rawXmlResponse: $xmlResponse,
        );
    }

    /**
     * Transliterate accents and convert to uppercase for XML address fields
     */
    private function normalizeForXml(string $value): string
    {
        if (empty($value)) {
            return '';
        }

        // Transliterate accents (e.g. "e" -> "e", "a" -> "a")
        if (function_exists('transliterator_transliterate')) {
            $value = transliterator_transliterate('Any-Latin; Latin-ASCII', $value);
        }

        return strtoupper($value);
    }

    /**
     * Escape special XML characters
     */
    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
