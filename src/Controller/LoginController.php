<?php

declare(strict_types=1);

namespace Drupal\didroom_login\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Url;
use Drupal\didroom_login\Login\AccountOutcome;
use Drupal\didroom_login\Login\AccountResolution;
use Drupal\didroom_login\Login\AccountResolver;
use Drupal\didroom_login\Login\ClaimValidator;
use Drupal\didroom_login\Login\LoginException;
use Drupal\didroom_login\Verifier\VerificationStatus;
use Drupal\didroom_login\Verifier\VerifierClient;
use Drupal\didroom_login\Verifier\VerifierException;
use Drupal\externalauth\Exception\ExternalAuthRegisterException;
use Drupal\externalauth\ExternalAuthInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shows the wallet QR code and logs the user in once the wallet answers.
 *
 * The verifier's transaction id is kept in the user's session only: whoever
 * knows it can read the verified claims, and the browser that started the
 * login is the only one that may finish it.
 */
final class LoginController extends ControllerBase {

  /**
   * Session key holding the login in progress.
   */
  private const SESSION_KEY = 'didroom_login';

  /**
   * Flood event and limits for starting logins, per IP address.
   */
  private const FLOOD_EVENT = 'didroom_login.start';

  private const FLOOD_LIMIT = 30;

  private const FLOOD_WINDOW = 3600;

  /**
   * Milliseconds between two status checks in the browser.
   */
  private const POLL_INTERVAL = 3000;

  public function __construct(
    private readonly VerifierClient $verifier,
    private readonly ClaimValidator $claimValidator,
    private readonly AccountResolver $accountResolver,
    private readonly ExternalAuthInterface $externalAuth,
    private readonly FloodInterface $flood,
    private readonly MailManagerInterface $mailManager,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('didroom_login.verifier_client'),
      $container->get('didroom_login.claim_validator'),
      $container->get('didroom_login.account_resolver'),
      $container->get('externalauth.externalauth'),
      $container->get('flood'),
      $container->get('plugin.manager.mail'),
      $container->get('datetime.time'),
      $container->get('logger.channel.didroom_login'),
    );
  }

  /**
   * Opens a verifier session and shows its QR code.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function start(Request $request): array {
    if (!$this->verifier->isConfigured()) {
      throw new NotFoundHttpException();
    }
    $build = ['#cache' => ['max-age' => 0]];

    if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_LIMIT, self::FLOOD_WINDOW)) {
      return $build + $this->errorPage($this->t('Too many wallet login attempts from your network. Try again later.'));
    }
    $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW);

    try {
      $session = $this->verifier->createSession();
    }
    catch (VerifierException) {
      // Already logged by the client.
      return $build + $this->errorPage($this->t('Wallet login is unavailable right now. Try again later, or log in with your password.'));
    }

    $ttl = (int) $this->config('didroom_login.settings')->get('session_ttl');
    $request->getSession()->set(self::SESSION_KEY, [
      'transaction_id' => $session->transactionId,
      'created' => $this->time->getRequestTime(),
      'destination' => $this->safeDestination($request),
    ]);

    return $build + [
      '#theme' => 'didroom_login',
      '#qr_code' => $session->qrCode,
      '#intent_url' => $session->intentUrl,
      '#expires_in' => $ttl,
      '#password_url' => Url::fromRoute('user.login')->toString(),
      '#attached' => [
        'library' => ['didroom_login/login'],
        'drupalSettings' => [
          'didroomLogin' => [
            'statusUrl' => Url::fromRoute('didroom_login.status')->toString(),
            'retryUrl' => Url::fromRoute('didroom_login.login')->toString(),
            'interval' => self::POLL_INTERVAL,
            'expiresIn' => $ttl,
          ],
        ],
      ],
    ];
  }

  /**
   * Reports the state of the login in progress, and logs in when verified.
   *
   * Answers JSON: {status: pending|done|expired|error, message?, redirect?}.
   */
  public function status(Request $request): JsonResponse {
    $session = $request->getSession();
    $login = $session->get(self::SESSION_KEY);
    if (!is_array($login) || !is_string($login['transaction_id'] ?? NULL)) {
      return $this->json('expired', $this->t('No wallet login is in progress.'));
    }
    $ttl = (int) $this->config('didroom_login.settings')->get('session_ttl');
    if ($this->time->getRequestTime() - (int) ($login['created'] ?? 0) > $ttl) {
      $session->remove(self::SESSION_KEY);
      return $this->json('expired', $this->t('The QR code has expired.'));
    }

    try {
      $result = $this->verifier->fetchResult($login['transaction_id']);
    }
    catch (VerifierException) {
      // Possibly transient, and already logged: keep waiting until expiry.
      return $this->json('pending');
    }
    if ($result->status === VerificationStatus::Pending) {
      return $this->json('pending');
    }

    // A result is used once, whatever happens next.
    $session->remove(self::SESSION_KEY);
    if ($result->status === VerificationStatus::Failed) {
      return $this->json('error', $this->t('Your wallet could not prove the requested credential.'));
    }

    try {
      $identity = $this->claimValidator->validate($result->claims);
      $resolution = $this->accountResolver->resolve($identity);
    }
    catch (LoginException $e) {
      $this->logger->notice('Wallet login refused: @message', ['@message' => $e->getMessage()]);
      return $this->json('error', $e->reason->userMessage());
    }
    catch (ExternalAuthRegisterException $e) {
      $this->logger->error('Wallet login could not create an account: @message', ['@message' => $e->getMessage()]);
      return $this->json('error', $this->t('Your account could not be created. Contact the site administrators.'));
    }

    $account = $this->externalAuth->userLoginFinalize($resolution->account, $identity->email, AccountResolver::PROVIDER);
    $this->notify($resolution, $request);

    $redirect = is_string($login['destination'] ?? NULL)
      ? $login['destination']
      : Url::fromRoute('entity.user.canonical', ['user' => $account->id()])->toString();
    return $this->json('done', redirect: $redirect);
  }

  /**
   * Emails the user about the wallet login, if enabled.
   */
  private function notify(AccountResolution $resolution, Request $request): void {
    if (!$this->config('didroom_login.settings')->get('notify_login')) {
      return;
    }
    $account = $resolution->account;
    $this->mailManager->mail('didroom_login', 'login_notification', $account->getEmail(), $account->getPreferredLangcode(), [
      'account' => $account,
      'created' => $resolution->outcome === AccountOutcome::Created,
      'linked' => in_array($resolution->outcome, [AccountOutcome::Linked, AccountOutcome::LinkedPasswordReset], TRUE),
      'password_reset' => $resolution->outcome === AccountOutcome::LinkedPasswordReset,
      'ip' => (string) $request->getClientIp(),
      'user_agent' => mb_substr((string) $request->headers->get('User-Agent'), 0, 255),
    ]);
  }

  /**
   * Returns the destination query parameter if it stays on this site.
   *
   * Like core's, the destination is a path from the host, base path included.
   */
  private function safeDestination(Request $request): ?string {
    $destination = $request->query->get('destination');
    if (!is_string($destination) || !str_starts_with($destination, '/') || UrlHelper::isExternal($destination)
      // Browsers read "/\host" as "//host".
      || preg_match('/[\\\\\x00-\x1f]/', $destination)) {
      return NULL;
    }
    // Coming back to the login pages after logging in makes no sense.
    $path = parse_url($destination, PHP_URL_PATH);
    $login_paths = [
      Url::fromRoute('user.login')->toString(),
      Url::fromRoute('didroom_login.login')->toString(),
    ];
    return in_array($path, $login_paths, TRUE) ? NULL : $destination;
  }

  /**
   * Builds a page showing an error and the way back to the login form.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function errorPage(\Stringable $message): array {
    return [
      'message' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $message,
      ],
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('Log in with your password'),
        '#url' => Url::fromRoute('user.login'),
      ],
    ];
  }

  /**
   * Builds an uncacheable status response.
   */
  private function json(string $status, ?\Stringable $message = NULL, ?string $redirect = NULL): JsonResponse {
    $data = ['status' => $status];
    if ($message !== NULL) {
      $data['message'] = (string) $message;
    }
    if ($redirect !== NULL) {
      $data['redirect'] = $redirect;
    }
    $response = new JsonResponse($data);
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

}
