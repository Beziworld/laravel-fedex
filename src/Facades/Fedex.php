<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Facades;

use Beziworld\Fedex\FedexManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Beziworld\Fedex\FedexClient connection(?string $name = null)
 * @method static \Beziworld\Fedex\FedexClient using(\Beziworld\Fedex\FedexConfig|array<string, mixed> $config, string $name = 'runtime')
 * @method static string getDefaultConnection()
 * @method static list<string> connectionNames()
 * @method static void purge(?string $name = null)
 * @method static \Beziworld\Fedex\FedexConfig config()
 * @method static string connectionName()
 * @method static \Beziworld\Fedex\Shipments\ShipmentBuilder shipment()
 * @method static \Beziworld\Fedex\Data\ShipmentResult createShipment(\Beziworld\Fedex\Shipments\ShipmentBuilder|array<string, mixed> $shipment)
 * @method static list<string> validateShipment(\Beziworld\Fedex\Shipments\ShipmentBuilder|array<string, mixed> $shipment)
 * @method static \Beziworld\Fedex\Data\ShipmentResult shipmentResults(string $jobId)
 * @method static \Beziworld\Fedex\Data\CancelResult cancelShipment(string $trackingNumber, ?string $senderCountryCode = null)
 * @method static list<\Beziworld\Fedex\Data\Rate> rates(\Beziworld\Fedex\Shipments\ShipmentBuilder|array<string, mixed> $shipment)
 * @method static \Beziworld\Fedex\Data\TrackedPackage track(string $trackingNumber)
 * @method static list<\Beziworld\Fedex\Data\TrackedPackage> trackMany(list<string> $trackingNumbers)
 * @method static list<\Beziworld\Fedex\Data\TrackedPackage> trackByReference(string $reference, \DateTimeInterface $from, \DateTimeInterface $to, string $type = 'CUSTOMER_REFERENCE', ?string $destinationCountry = null, ?string $destinationPostalCode = null)
 * @method static string proofOfDelivery(string $trackingNumber, string $format = 'PDF')
 * @method static void notifyByEmail(string $trackingNumber, list<string> $emails, string $senderName, string $senderEmail, list<string> $events = ['ON_DELIVERY', 'ON_EXCEPTION', 'ON_ESTIMATED_DELIVERY'], string $language = 'en')
 * @method static list<\Beziworld\Fedex\Data\ResolvedAddress> validateAddresses(list<\Beziworld\Fedex\Data\Party> $addresses)
 * @method static \Beziworld\Fedex\Data\ResolvedAddress|null validateAddress(\Beziworld\Fedex\Data\Party $address)
 * @method static list<\Beziworld\Fedex\Data\Location> locations(\Beziworld\Fedex\Data\Party $near, int $radius = 10, int $limit = 10, list<string> $types = [])
 * @method static list<\Beziworld\Fedex\Data\Location> locationsNear(float $latitude, float $longitude, string $countryCode, int $radius = 10, int $limit = 10, list<string> $types = [])
 * @method static list<\Beziworld\Fedex\Data\PickupOption> pickupAvailability(?\Beziworld\Fedex\Data\Party $address = null, string $carrier = 'FDXE', ?\DateTimeInterface $date = null, bool $international = false)
 * @method static \Beziworld\Fedex\Data\PickupConfirmation schedulePickup(\Beziworld\Fedex\Data\PickupRequest $pickup)
 * @method static void cancelPickup(\Beziworld\Fedex\Data\PickupConfirmation $pickup, ?string $remarks = null)
 * @method static array<array-key, mixed> request(string $method, string $path, array<string, mixed> $body = [], bool $write = true)
 * @method static string accessToken()
 * @method static void forgetToken()
 *
 * @see \Beziworld\Fedex\FedexManager
 * @see \Beziworld\Fedex\FedexClient
 */
final class Fedex extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FedexManager::class;
    }
}
