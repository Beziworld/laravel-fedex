<?php

declare(strict_types=1);

use Beziworld\Fedex\Enums\TrackingStatus;
use Beziworld\Fedex\Events\TrackingWebhookReceived;
use Beziworld\Fedex\Exceptions\ApiException;
use Beziworld\Fedex\Exceptions\WebhookSignatureException;
use Beziworld\Fedex\Facades\Fedex;
use Beziworld\Fedex\Tests\TestCase;
use Beziworld\Fedex\Webhooks\SignatureVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

it('tracks a package with typed events, newest first', function (): void {
    $this->fakeFedex([TestCase::API . '/track/v1/trackingnumbers' => Http::response(TestCase::fixture('track'))]);

    $package = Fedex::track('794953535000');

    expect($package->status)->toBe(TrackingStatus::Delivered)
        ->and($package->isDelivered())->toBeTrue()
        ->and($package->statusDescription)->toBe('Delivered')
        ->and($package->serviceName)->toBe('FedEx International Priority')
        ->and($package->receivedBy)->toBe('M.MUSTERMANN')
        ->and($package->deliveredAt?->toIso8601String())->toBe('2026-11-06T10:12:00+01:00')
        ->and(array_map(fn($event): ?string => $event->eventType, $package->events))->toBe(['DL', 'OD', 'PU'])
        ->and($package->latestEvent()?->city)->toBe('BERLIN')
        ->and($package->events[1]->status())->toBe(TrackingStatus::OutForDelivery);

    Http::assertSent(fn(Request $request): bool => $request->data() === [
        'includeDetailedScans' => true,
        'trackingInfo' => [['trackingNumberInfo' => ['trackingNumber' => '794953535000']]],
    ]);
});

it('reports per-number errors when tracking several packages', function (): void {
    $this->fakeFedex([TestCase::API . '/track/v1/trackingnumbers' => Http::response(TestCase::fixture('track'))]);

    $packages = Fedex::trackMany(['794953535000', '123456789012']);

    expect($packages)->toHaveCount(2)
        ->and($packages[0]->hasError())->toBeFalse()
        ->and($packages[1]->hasError())->toBeTrue()
        ->and($packages[1]->trackingNumber)->toBe('123456789012')
        ->and($packages[1]->error['code'] ?? null)->toBe('TRACKING.TRACKINGNUMBER.NOTFOUND');
});

it('throws a not found exception when a single number is unknown', function (): void {
    $fixture = TestCase::fixture('track');
    $fixture['output']['completeTrackResults'] = [$fixture['output']['completeTrackResults'][1]];
    $this->fakeFedex([TestCase::API . '/track/v1/trackingnumbers' => Http::response($fixture)]);

    try {
        Fedex::track('123456789012');
        $this->fail('Expected an exception.');
    } catch (ApiException $exception) {
        expect($exception->isNotFound())->toBeTrue()->and($exception->errorCode())->toBe('TRACKING.TRACKINGNUMBER.NOTFOUND');
    }
});

it('tracks by reference and downloads the proof of delivery', function (): void {
    $this->fakeFedex([
        TestCase::API . '/track/v1/referencenumbers' => Http::response(TestCase::fixture('track')),
        TestCase::API . '/track/v1/trackingdocuments' => Http::response(['output' => ['documentType' => 'SIGNATURE_PROOF_OF_DELIVERY', 'documentFormat' => 'PDF', 'document' => [base64_encode('%PDF-1.7 spod')]]]),
    ]);

    expect(Fedex::trackByReference('ORDER-1', CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-04')))->toHaveCount(2)
        ->and(Fedex::proofOfDelivery('794953535000'))->toBe('%PDF-1.7 spod');

    Http::assertSent(fn(Request $request): bool => ($request->data()['referencesInformation'] ?? null) === [
        'value' => 'ORDER-1',
        'type' => 'CUSTOMER_REFERENCE',
        'accountNumber' => '740561073',
        'shipDateBegin' => '2026-11-01',
        'shipDateEnd' => '2026-11-04',
    ]);
});

it('subscribes e-mail notifications', function (): void {
    $this->fakeFedex([TestCase::API . '/track/v1/notifications' => Http::response(['output' => []])]);

    Fedex::notifyByEmail('794953535000', ['customer@example.com'], 'Shop', 'shop@example.com');

    Http::assertSent(fn(Request $request): bool => ($request->data()['senderEMailAddress'] ?? null) === 'shop@example.com'
        && ($request->data()['trackingEventNotificationDetail']['trackingNotifications'][0]['notificationDetail']['emailDetail'] ?? null) === ['emailAddress' => 'customer@example.com']);
});

it('accepts a signed webhook and dispatches the tracking event', function (): void {
    Event::fake([TrackingWebhookReceived::class]);
    $payload = (string) json_encode(TestCase::fixture('webhook'));
    $signature = base64_encode(hash_hmac('sha256', $payload, TestCase::WEBHOOK_TOKEN, true));

    $this->call('POST', '/fedex/webhook', [], [], [], ['HTTP_FDX_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload)
        ->assertOk()
        ->assertJson(['received' => true]);

    Event::assertDispatched(TrackingWebhookReceived::class, fn(TrackingWebhookReceived $event): bool => $event->event->trackingNumber === '794953535000' && $event->event->status() === TrackingStatus::Delivered);
});

it('rejects webhooks with a missing or wrong signature', function (?string $signature): void {
    Event::fake([TrackingWebhookReceived::class]);
    $headers = $signature !== null ? ['HTTP_FDX_SIGNATURE' => $signature] : [];

    $this->call('POST', '/fedex/webhook', [], [], [], $headers + ['CONTENT_TYPE' => 'application/json'], '{"scanEvent":{}}')->assertStatus(401);

    Event::assertNotDispatched(TrackingWebhookReceived::class);
})->with(['missing' => [null], 'wrong' => ['c2lnbmF0dXJl']]);

it('verifies signatures directly', function (): void {
    $verifier = new SignatureVerifier('Secret-token-1234567890abcdef');
    $signature = $verifier->sign('{"a":1}');

    $verifier->verify('{"a":1}', $signature);

    expect($verifier->isValid('{"a":2}', $signature))->toBeFalse()
        ->and(fn() => $verifier->verify('{"a":1}', ''))->toThrow(WebhookSignatureException::class, 'no fdx-signature');
});
