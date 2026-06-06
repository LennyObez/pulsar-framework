<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\EInvoicing;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Http\Client\HttpClientInterface;
use XMLWriter;

use function bin2hex;
use function random_bytes;

/**
 * Peppol Access Point client for sending UBL invoices via the Peppol network.
 *
 * Wraps UBL documents in an AS4 protocol envelope using the Standard
 * Business Document Header (SBDH) before transmitting to the configured
 * Access Point. The Access Point is responsible for SMP lookup and final
 * delivery to the receiving participant.
 *
 * This client does not perform SMP/SML lookups directly; it delegates
 * to the Access Point which handles routing. For environments requiring
 * direct SMP lookups, configure the receiverLookupEndpoint in PeppolConfig.
 */
#[Internal(reason: 'Peppol access point client')]
final readonly class PeppolClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $accessPointUrl,
        private string $senderId,
        private string $senderScheme,
    ) {}

    /**
     * Create a PeppolClient from a PeppolConfig DTO.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    #[NoDiscard]
    public static function fromConfig(PeppolConfig $config, HttpClientInterface $httpClient): self
    {
        return new self(
            httpClient: $httpClient,
            accessPointUrl: $config->accessPointUrl,
            senderId: $config->senderId,
            senderScheme: $config->senderScheme,
        );
    }

    /**
     * Send a UBL invoice XML via the Peppol network.
     *
     * The XML is wrapped in an SBDH (Standard Business Document Header)
     * envelope and transmitted to the Access Point via HTTP POST.
     *
     * @param string $ublXml Valid UBL 2.1 Invoice or CreditNote XML
     * @param string $receiverId Recipient Peppol participant ID
     * @param string $receiverScheme Recipient scheme (e.g., "0088" for GLN, "9925" for VAT BE)
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    #[NoDiscard]
    public function send(string $ublXml, string $receiverId, string $receiverScheme): PeppolTransmissionResult
    {
        $transmissionId = bin2hex(random_bytes(16));
        $envelope = $this->wrapInSbdh($ublXml, $receiverId, $receiverScheme, $transmissionId);

        $response = $this->httpClient->post($this->accessPointUrl, [
            'headers' => [
                'Content-Type' => 'application/xml',
                'X-Transmission-ID' => $transmissionId,
            ],
            'body' => $envelope,
        ]);

        $status = $response->ok()
            ? PeppolTransmissionStatus::Accepted
            : PeppolTransmissionStatus::Rejected;

        return new PeppolTransmissionResult(
            transmissionId: $transmissionId,
            status: $status,
            timestamp: new DateTimeImmutable(),
            rawResponse: $response->body(),
        );
    }

    /**
     * Wrap a UBL document in a Standard Business Document Header (SBDH) envelope.
     *
     * The SBDH provides routing metadata for the Peppol 4-corner model:
     * sender identification, receiver identification, document type,
     * and business scope (process identifier).
     */
    private function wrapInSbdh(
        string $ublXml,
        string $receiverId,
        string $receiverScheme,
        string $instanceIdentifier,
    ): string {
        $w = new XMLWriter();
        $w->openMemory();
        $w->setIndent(true);
        $w->setIndentString('  ');
        $w->startDocument('1.0', 'UTF-8');

        $sbdhNs = 'http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader';

        $w->startElementNs(null, 'StandardBusinessDocument', $sbdhNs);

        $w->startElement('StandardBusinessDocumentHeader');

        // Header version
        $w->startElement('HeaderVersion');
        $w->text('1.0');
        $w->endElement();

        // Sender
        $w->startElement('Sender');
        $w->startElement('Identifier');
        $w->writeAttribute('Authority', $this->senderScheme);
        $w->text($this->senderId);
        $w->endElement();
        $w->endElement();

        // Receiver
        $w->startElement('Receiver');
        $w->startElement('Identifier');
        $w->writeAttribute('Authority', $receiverScheme);
        $w->text($receiverId);
        $w->endElement();
        $w->endElement();

        // Document identification
        $w->startElement('DocumentIdentification');

        $w->startElement('Standard');
        $w->text('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
        $w->endElement();

        $w->startElement('TypeVersion');
        $w->text('2.1');
        $w->endElement();

        $w->startElement('InstanceIdentifier');
        $w->text($instanceIdentifier);
        $w->endElement();

        $w->startElement('Type');
        $w->text('Invoice');
        $w->endElement();

        $w->startElement('CreationDateAndTime');
        $w->text(new DateTimeImmutable()->format('Y-m-d\TH:i:s\Z'));
        $w->endElement();

        $w->endElement(); // DocumentIdentification

        // Business scope
        $w->startElement('BusinessScope');
        $w->startElement('Scope');

        $w->startElement('Type');
        $w->text('DOCUMENTID');
        $w->endElement();

        $w->startElement('InstanceIdentifier');
        $w->text('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2::Invoice##urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0::2.1');
        $w->endElement();

        $w->endElement(); // Scope

        $w->startElement('Scope');

        $w->startElement('Type');
        $w->text('PROCESSID');
        $w->endElement();

        $w->startElement('InstanceIdentifier');
        $w->text('urn:fdc:peppol.eu:2017:poacc:billing:01:1.0');
        $w->endElement();

        $w->endElement(); // Scope
        $w->endElement(); // BusinessScope

        $w->endElement(); // StandardBusinessDocumentHeader

        // Raw UBL payload
        $w->writeRaw("\n" . $ublXml);

        $w->endElement(); // StandardBusinessDocument
        $w->endDocument();

        return $w->outputMemory();
    }
}
