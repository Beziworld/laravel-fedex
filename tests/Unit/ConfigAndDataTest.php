<?php

declare(strict_types=1);

use Beziworld\Fedex\Data\Customs;
use Beziworld\Fedex\Data\CustomsItem;
use Beziworld\Fedex\Data\Label;
use Beziworld\Fedex\Data\Money;
use Beziworld\Fedex\Data\Party;
use Beziworld\Fedex\Data\Rate;
use Beziworld\Fedex\Data\TrackingEvent;
use Beziworld\Fedex\Data\WebhookEvent;
use Beziworld\Fedex\Enums\LabelFormat;
use Beziworld\Fedex\Enums\TrackingStatus;
use Beziworld\Fedex\Exceptions\ApiException;
use Beziworld\Fedex\Exceptions\InvalidConfiguration;
use Beziworld\Fedex\FedexConfig;
use Beziworld\Fedex\Tests\TestCase;

it('reads a minimal configuration with defaults', function (): void {
    $config = FedexConfig::fromArray(['client_id' => 'key', 'client_secret' => 'secret']);

    expect($config->baseUrl())->toBe('https://apis.fedex.com')
        ->and($config->grantType)->toBe('client_credentials')
        ->and($config->labelFormat)->toBe(LabelFormat::Pdf)
        ->and($config->weightUnit())->toBe('KG')
        ->and($config->dimensionUnit())->toBe('CM')
        ->and(FedexConfig::fromArray(['client_id' => 'k', 'client_secret' => 's', 'sandbox' => '1', 'units' => 'imperial', 'label_format' => 'zpl'])->baseUrl())->toBe('https://apis-sandbox.fedex.com');
});

it('validates credentials, grant types and options', function (array $config, string $message): void {
    expect(fn() => FedexConfig::fromArray($config))->toThrow(InvalidConfiguration::class, $message);
})->with([
    'client id' => [['client_secret' => 's'], 'FEDEX_CLIENT_ID'],
    'client secret' => [['client_id' => 'k'], 'FEDEX_CLIENT_SECRET'],
    'grant' => [['client_id' => 'k', 'client_secret' => 's', 'grant_type' => 'password'], 'grant type'],
    'child credentials' => [['client_id' => 'k', 'client_secret' => 's', 'grant_type' => 'csp_credentials'], 'FEDEX_CHILD_KEY'],
    'units' => [['client_id' => 'k', 'client_secret' => 's', 'units' => 'si'], 'units'],
    'label format' => [['client_id' => 'k', 'client_secret' => 's', 'label_format' => 'GIF'], 'label format'],
]);

it('maps label formats to stocks and file types', function (): void {
    expect(LabelFormat::Zpl->defaultStock())->toBe('STOCK_4X6')
        ->and(LabelFormat::Pdf->defaultStock())->toBe('PAPER_4X6')
        ->and(LabelFormat::Png->mimeType())->toBe('image/png')
        ->and(LabelFormat::Epl->extension())->toBe('epl');
});

it('maps a party to FedEx address, contact and tins', function (): void {
    $party = new Party('Max Mustermann', ['Potsdamer Str. 1', ''], 'Berlin', '10785', 'de', '+49301234', company: 'Muster GmbH', residential: true, taxId: 'DE123', taxIdType: 'BUSINESS_UNION');

    expect($party->toFedex())->toBe([
        'address' => ['streetLines' => ['Potsdamer Str. 1'], 'city' => 'Berlin', 'postalCode' => '10785', 'countryCode' => 'DE', 'residential' => true],
        'contact' => ['personName' => 'Max Mustermann', 'companyName' => 'Muster GmbH', 'phoneNumber' => '+49301234'],
        'tins' => [['number' => 'DE123', 'tinType' => 'BUSINESS_UNION']],
    ])->and(fn() => Party::fromArray(['name' => 'x']))->toThrow(InvalidConfiguration::class);
});

it('computes customs values and money', function (): void {
    $customs = new Customs([new CustomsItem('Shirt', 3, '9.99', 0.6, 'PL'), new CustomsItem('Cap', 1, '5', 0.1, 'CN')], 'usd');

    expect($customs->total())->amount->toBe('34.97')->currency->toBe('USD')
        ->and(Money::fromFedex(['amount' => 12, 'currency' => 'EUR'])?->amount)->toBe('12.00')
        ->and(Money::fromFedex(['amount' => 'x', 'currency' => 'EUR']))->toBeNull()
        ->and(Money::of(1, 'pln')->toFedex())->toBe(['amount' => 1.0, 'currency' => 'PLN']);
});

it('maps FedEx status codes', function (?string $code, TrackingStatus $status): void {
    expect(TrackingStatus::fromCode($code))->toBe($status);
})->with([
    ['DL', TrackingStatus::Delivered],
    ['od', TrackingStatus::OutForDelivery],
    ['PU', TrackingStatus::PickedUp],
    ['OC', TrackingStatus::LabelCreated],
    ['DE', TrackingStatus::Exception],
    ['CA', TrackingStatus::Cancelled],
    ['RS', TrackingStatus::ReturnedToShipper],
    ['AR', TrackingStatus::InTransit],
    [null, TrackingStatus::Unknown],
]);

it('decodes labels and URL-only documents', function (): void {
    $label = Label::fromArray(['contentType' => 'LABEL', 'docType' => 'ZPLII', 'encodedLabel' => base64_encode('^XA^XZ')], '794953535000');
    $link = Label::fromArray(['contentType' => 'LABEL', 'docType' => 'PDF', 'url' => 'https://wwwtest.fedex.com/document/label.pdf']);

    expect($label->content)->toBe('^XA^XZ')
        ->and($label->extension())->toBe('zpl')
        ->and($label->trackingNumber)->toBe('794953535000')
        ->and($label->base64())->toBe(base64_encode('^XA^XZ'))
        ->and($link->content)->toBeNull()
        ->and($link->url)->toBe('https://wwwtest.fedex.com/document/label.pdf')
        ->and(fn() => Label::fromArray(['encodedLabel' => '%%%']))->toThrow(ApiException::class);
});

it('prefers the account rate and keeps the list rate', function (): void {
    $rate = Rate::fromArray(TestCase::fixture('rates')['output']['rateReplyDetails'][0]);

    expect($rate?->total->amount)->toBe('120.50')
        ->and($rate?->listTotal?->amount)->toBe('160.00')
        ->and(array_keys($rate->totals ?? []))->toBe(['ACCOUNT', 'LIST'])
        ->and($rate?->deliveryAt?->format('Y-m-d'))->toBe('2026-11-06')
        ->and(Rate::fromArray(['serviceType' => 'X']))->toBeNull();
});

it('parses scan events and webhook payloads', function (): void {
    $event = TrackingEvent::fromArray(['date' => '2026-11-05T18:00:00+01:00', 'eventType' => 'PU', 'scanLocation' => ['city' => 'WARSAW']]);
    $webhook = WebhookEvent::fromArray(TestCase::fixture('webhook'));

    expect($event->occurredAt?->format('P'))->toBe('+01:00')
        ->and($event->status())->toBe(TrackingStatus::PickedUp)
        ->and(TrackingEvent::parseDate('not a date'))->toBeNull()
        ->and($webhook->trackingNumber)->toBe('794953535000')
        ->and($webhook->status())->toBe(TrackingStatus::Delivered)
        ->and($webhook->city)->toBe('BERLIN');
});
