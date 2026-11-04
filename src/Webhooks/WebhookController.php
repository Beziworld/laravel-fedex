<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Webhooks;

use Beziworld\Fedex\Data\WebhookEvent;
use Beziworld\Fedex\Events\TrackingWebhookReceived;
use Beziworld\Fedex\Exceptions\WebhookSignatureException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives FedEx tracking webhooks, verifies their signature and dispatches TrackingWebhookReceived.
 *
 * FedEx treats any status other than 200 or 202 as a failed delivery, so
 * a valid request is acknowledged with 200 before listeners do heavy work
 * (queue the listener when processing takes time).
 */
final class WebhookController
{
    public function __construct(
        private readonly SignatureVerifier $verifier,
        private readonly Dispatcher $events,
        private readonly string $connection = 'default',
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getContent();

        try {
            $this->verifier->verify($payload, $request->header('fdx-signature'));
        } catch (WebhookSignatureException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 401);
        }

        $data = json_decode($payload, true);

        if (! is_array($data)) {
            return new JsonResponse(['message' => 'The payload is not valid JSON.'], 400);
        }

        $this->events->dispatch(new TrackingWebhookReceived($this->connection, WebhookEvent::fromArray($data)));

        return new JsonResponse(['received' => true]);
    }
}
