<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use YorvoID\Authn\Session\Session;
use YorvoID\Authn\StepUp\AuthenticationRequirement;
use YorvoID\Authn\StepUp\StepUpRequired;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Realm;
use YorvoID\Passkeys\BegunCeremony;
use YorvoID\Passkeys\PasskeyRefused;
use YorvoID\Passkeys\Passkeys;
use YorvoID\Symfony\Security\CurrentSession;
use YorvoID\Symfony\Security\Json;

/**
 * The JSON endpoints of passkey ceremonies, for the browser client: options to sign in, to add a
 * passkey and to step up, and finishing the last two. Signing in itself finishes in
 * PasskeyAuthenticator, in the application's firewall.
 *
 * Every endpoint takes and gives JSON only; adding a passkey and stepping up need a signed-in session.
 *
 * @internal
 */
final readonly class CeremonyController
{
    public function __construct(
        private Passkeys $passkeys,
        private CurrentSession $current,
        private Realm $realm,
        private Duration $window,
    ) {
    }

    public function signInOptions(Request $request): JsonResponse
    {
        return null === Json::body($request) ? self::notJson() : self::ceremony($this->passkeys->beginSignIn($this->realm));
    }

    /**
     * Where an application without PasskeyAuthenticator in its firewall ends up.
     */
    public function notConfigured(): JsonResponse
    {
        return new JsonResponse(['error' => 'Signing in this way isn\'t set up here.'], 404);
    }

    public function registrationOptions(Request $request): JsonResponse
    {
        return $this->signedIn($request, fn (Session $session, array $body): JsonResponse => self::ceremony($this->passkeys->beginRegistration($session)));
    }

    public function register(Request $request): JsonResponse
    {
        return $this->signedIn($request, function (Session $session, array $body): JsonResponse {
            $credential = $this->passkeys->finishRegistration($session, (string) Json::string($body, 'ceremony'), json_encode($body['response'] ?? null, \JSON_THROW_ON_ERROR), (string) Json::string($body, 'label'), new Origin(OriginKind::Web, 'yorvoid_passkey_register'));

            return new JsonResponse(['credential' => (string) $credential->id, 'label' => $credential->label], 201);
        });
    }

    /**
     * What the browser tells the person's passkey provider about their account (ATN-008); nothing
     * while they have no passkey.
     */
    public function signals(Request $request): JsonResponse
    {
        return $this->signedIn($request, function (Session $session, array $body): JsonResponse {
            $signals = $this->passkeys->signals($session);

            return null === $signals ? new JsonResponse(null, 204) : new JsonResponse($signals->toArray());
        });
    }

    public function stepUpOptions(Request $request): JsonResponse
    {
        return $this->signedIn($request, function (Session $session, array $body): JsonResponse {
            try {
                $requirement = new AuthenticationRequirement($this->window, multiFactor: true === ($body['multi_factor'] ?? false), operation: Json::string($body, 'operation'));
            } catch (IntegratorError) {
                return new JsonResponse(['error' => 'That isn\'t an operation\'s name.'], 400);
            }

            return self::ceremony($this->passkeys->beginStepUp($session, $requirement));
        });
    }

    public function stepUp(Request $request): JsonResponse
    {
        return $this->signedIn($request, function (Session $session, array $body): JsonResponse {
            $token = $this->passkeys->finishStepUp($session, (string) Json::string($body, 'ceremony'), json_encode($body['response'] ?? null, \JSON_THROW_ON_ERROR), new Origin(OriginKind::Web, 'yorvoid_step_up'));
            $this->current->hold($token);

            return new JsonResponse(['stepped_up' => true]);
        });
    }

    /**
     * @param \Closure(Session, array<mixed>): JsonResponse $work
     */
    private function signedIn(Request $request, \Closure $work): JsonResponse
    {
        $body = Json::body($request);
        $session = $this->current->get();

        if (null === $body) {
            return self::notJson();
        }

        if (null === $session) {
            return new JsonResponse(['error' => 'Sign in first.'], 401);
        }

        try {
            return $work($session, $body);
        } catch (PasskeyRefused $exception) {
            return new JsonResponse(['error' => $exception->getMessage(), 'reason' => $exception->refusal->value], 400);
        } catch (StepUpRequired $exception) {
            return new JsonResponse(['error' => $exception->getMessage(), 'step_up' => ['operation' => $exception->requirement->operation, 'within' => (string) $exception->requirement->within]], 403);
        }
    }

    private static function ceremony(BegunCeremony $ceremony): JsonResponse
    {
        return new JsonResponse(['ceremony' => $ceremony->id, 'options' => json_decode($ceremony->options)]);
    }

    private static function notJson(): JsonResponse
    {
        return new JsonResponse(['error' => 'Send the request as JSON.'], 415);
    }
}
