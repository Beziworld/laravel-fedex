<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Data;

use Beziworld\Fedex\Exceptions\InvalidConfiguration;
use Beziworld\Fedex\Support\Payload;

/**
 * Shipper, recipient or pickup location: contact details with a postal address.
 */
final readonly class Party
{
    /** @var list<string> */
    public array $addressLines;

    /**
     * @param string|list<string> $address Street address; an array for up to three lines.
     * @param string|null $taxId VAT, EORI or other tax number, sent as a TIN of type $taxIdType.
     * @param string $taxIdType PERSONAL_NATIONAL, PERSONAL_STATE, FEDERAL, BUSINESS_NATIONAL, BUSINESS_STATE or BUSINESS_UNION.
     */
    public function __construct(
        public string $name,
        string|array $address,
        public string $city,
        public string $postalCode,
        public string $countryCode,
        public string $phone,
        public ?string $company = null,
        public ?string $email = null,
        public ?string $stateCode = null,
        public bool $residential = false,
        public ?string $taxId = null,
        public string $taxIdType = 'BUSINESS_NATIONAL',
    ) {
        $this->addressLines = array_values(array_filter(array_map(trim(...), is_array($address) ? $address : [$address]), static fn(string $line): bool => $line !== ''));
    }

    /**
     * Builds a party from a configuration array (snake_case keys).
     *
     * @param array<array-key, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $required = static fn(string $key): string => Payload::string($config, $key) ?? throw InvalidConfiguration::invalid(sprintf('FedEx party is missing "%s".', $key));
        $address = $config['address'] ?? null;

        return new self(
            name: $required('name'),
            address: is_array($address) ? array_values(array_map(strval(...), array_filter($address, is_scalar(...)))) : (is_scalar($address) ? (string) $address : throw InvalidConfiguration::invalid('FedEx party is missing "address".')),
            city: $required('city'),
            postalCode: $required('postal_code'),
            countryCode: strtoupper($required('country_code')),
            phone: $required('phone'),
            company: Payload::string($config, 'company'),
            email: Payload::string($config, 'email'),
            stateCode: Payload::string($config, 'state_code'),
            residential: filter_var($config['residential'] ?? false, FILTER_VALIDATE_BOOL),
            taxId: Payload::string($config, 'tax_id'),
            taxIdType: Payload::string($config, 'tax_id_type') ?? 'BUSINESS_NATIONAL',
        );
    }

    /**
     * FedEx address object.
     *
     * @return array<string, mixed>
     */
    public function toFedexAddress(): array
    {
        return Payload::clean([
            'streetLines' => array_slice($this->addressLines, 0, 3),
            'city' => $this->city,
            'stateOrProvinceCode' => $this->stateCode,
            'postalCode' => $this->postalCode,
            'countryCode' => strtoupper($this->countryCode),
            'residential' => $this->residential ? true : null,
        ]);
    }

    /**
     * FedEx contact object.
     *
     * @return array<string, mixed>
     */
    public function toFedexContact(): array
    {
        return Payload::clean([
            'personName' => $this->name,
            'companyName' => $this->company,
            'phoneNumber' => $this->phone,
            'emailAddress' => $this->email,
        ]);
    }

    /**
     * FedEx party: {address, contact, tins}.
     *
     * @return array<string, mixed>
     */
    public function toFedex(): array
    {
        return Payload::clean([
            'address' => $this->toFedexAddress(),
            'contact' => $this->toFedexContact(),
            'tins' => $this->taxId !== null ? [['number' => $this->taxId, 'tinType' => $this->taxIdType]] : null,
        ]);
    }
}
