<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Data;

use Beziworld\Fedex\Support\Payload;

/**
 * Shipment created by the Ship API with its decoded labels and documents.
 *
 * For asynchronous shipments (more than 40 packages) only jobId is set;
 * fetch the result later with FedexClient::shipmentResults().
 */
final readonly class ShipmentResult
{
    /**
     * @param list<string> $trackingNumbers
     * @param list<Label> $labels Package labels.
     * @param list<Label> $documents Shipment documents such as the commercial invoice.
     * @param list<string> $alerts
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public ?string $masterTrackingNumber,
        public array $trackingNumbers,
        public array $labels,
        public array $documents,
        public ?string $serviceType,
        public ?string $serviceName,
        public ?Money $netCharge,
        public ?string $jobId,
        public array $alerts,
        public array $raw,
    ) {}

    /**
     * @param array<array-key, mixed> $data Decoded response body.
     */
    public static function fromArray(array $data): self
    {
        $output = Payload::array($data, 'output');
        $shipment = Payload::items($output, 'transactionShipments')[0] ?? [];
        $trackingNumbers = [];
        $labels = [];
        $documents = [];

        foreach (Payload::items($shipment, 'pieceResponses') as $piece) {
            $number = Payload::string($piece, 'trackingNumber');

            if ($number !== null) {
                $trackingNumbers[] = $number;
            }

            foreach (Payload::items($piece, 'packageDocuments') as $document) {
                $labels[] = Label::fromArray($document, $number);
            }
        }

        foreach (Payload::items($shipment, 'shipmentDocuments') as $document) {
            $documents[] = Label::fromArray($document);
        }

        $rate = Payload::items($shipment, 'completedShipmentDetail.shipmentRating.shipmentRateDetails')[0] ?? [];
        $net = Payload::string($rate, 'totalNetCharge');
        $currency = Payload::string($rate, 'currency');

        return new self(
            masterTrackingNumber: Payload::string($shipment, 'masterTrackingNumber') ?? ($trackingNumbers[0] ?? null),
            trackingNumbers: $trackingNumbers,
            labels: $labels,
            documents: $documents,
            serviceType: Payload::string($shipment, 'serviceType'),
            serviceName: Payload::string($shipment, 'serviceName'),
            netCharge: $net !== null && $currency !== null && is_numeric($net) ? Money::of($net, $currency) : null,
            jobId: Payload::string($output, 'jobId'),
            alerts: self::alerts([...Payload::items($output, 'alerts'), ...Payload::items($shipment, 'alerts')]),
            raw: $data,
        );
    }

    public function isAsynchronous(): bool
    {
        return $this->jobId !== null && $this->trackingNumbers === [];
    }

    /**
     * Label of the given package; the first label when no tracking number is given.
     */
    public function label(?string $trackingNumber = null): ?Label
    {
        foreach ($this->labels as $label) {
            if ($trackingNumber === null || $label->trackingNumber === $trackingNumber) {
                return $label;
            }
        }

        return null;
    }

    /**
     * First shipment document of the given type, e.g. COMMERCIAL_INVOICE.
     */
    public function document(string $type): ?Label
    {
        foreach ($this->documents as $document) {
            if ($document->type === $type) {
                return $document;
            }
        }

        return null;
    }

    /**
     * Formats FedEx alerts as "code: message".
     *
     * @param list<array<array-key, mixed>> $alerts
     * @return list<string>
     */
    public static function alerts(array $alerts): array
    {
        $messages = [];

        foreach ($alerts as $alert) {
            $message = Payload::string($alert, 'message');

            if ($message !== null) {
                $code = Payload::string($alert, 'code');
                $messages[] = $code !== null ? $code . ': ' . $message : $message;
            }
        }

        return $messages;
    }
}
