/**
 * @file
 * Polls the wallet login status and follows the redirect once logged in.
 */

((Drupal, drupalSettings, once) => {
  Drupal.behaviors.didroomLogin = {
    attach(context) {
      once('didroom-login', '[data-didroom-login]', context).forEach((root) => {
        const settings = drupalSettings.didroomLogin;
        const status = root.querySelector('[data-didroom-login-status]');
        const qr = root.querySelector('.didroom-login__qr');
        const expiresAt = Date.now() + settings.expiresIn * 1000;
        let timer = null;
        let busy = false;
        let finished = false;

        const finish = (message, retry) => {
          finished = true;
          clearTimeout(timer);
          if (qr) {
            qr.hidden = true;
          }
          root.classList.add('didroom-login--finished');
          status.textContent = message;
          if (retry) {
            const link = document.createElement('a');
            link.href = settings.retryUrl;
            link.className = 'button';
            link.textContent = Drupal.t('Try again');
            status.append(' ', link);
          }
        };

        async function poll() {
          if (finished || busy) {
            return;
          }
          // Give the server a moment past expiry to say so itself.
          if (Date.now() > expiresAt + settings.interval) {
            finish(Drupal.t('The QR code has expired.'), true);
            return;
          }
          busy = true;
          try {
            const response = await fetch(settings.statusUrl, {
              credentials: 'same-origin',
              headers: { Accept: 'application/json' },
              cache: 'no-store',
            });
            const data = response.ok
              ? await response.json()
              : { status: 'pending' };
            if (data.status === 'done') {
              finish(Drupal.t('Logged in. Redirecting…'), false);
              window.location.assign(data.redirect);
              return;
            }
            if (data.status === 'expired' || data.status === 'error') {
              finish(data.message || Drupal.t('Wallet login failed.'), true);
              return;
            }
          } catch (e) {
            // Network hiccup: keep polling until expiry.
          } finally {
            busy = false;
          }
          clearTimeout(timer);
          if (!finished) {
            timer = setTimeout(poll, settings.interval);
          }
        }

        // Mobile browsers pause background tabs while the wallet app is open:
        // check right away when the user comes back.
        document.addEventListener('visibilitychange', () => {
          if (document.visibilityState === 'visible') {
            poll();
          }
        });

        timer = setTimeout(poll, settings.interval);
      });
    },
  };
})(Drupal, drupalSettings, once);
