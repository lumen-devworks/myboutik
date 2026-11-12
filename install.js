// Bouton "Installer l'appli", commun a toutes les pages (charge avant leur script).
// Chrome, Edge et Samsung Internet sur Android proposent l'installation en un clic
// (beforeinstallprompt). iPhone et navigateurs integres (WhatsApp, Facebook,
// Instagram...) ne le permettent jamais : le bouton affiche alors la marche a suivre.
(function () {
  var ua = navigator.userAgent || '';
  var isIos = /iPhone|iPad|iPod/i.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
  var isAndroid = /Android/i.test(ua);
  var inApp = /FBAN|FBAV|FBIOS|FB_IAB|Instagram|WhatsApp|Snapchat|musical_ly|TikTok|Line\//i.test(ua);
  // Une cle par espace (site, boutique, tableau de bord...) : chacun s'installe a part.
  var KEY = 'myboutik_installed:' + location.pathname.replace(/[^/]*$/, '');
  var deferred = null;

  var TEXT = {
    fr: {
      title_app: "Installer l'application",
      title_inapp: "Ouvrez d'abord ce lien dans votre navigateur",
      inapp: ["Touchez ••• ou ⋮ en haut de l'écran.", "Choisissez « Ouvrir dans un navigateur externe » (ou « Ouvrir dans Safari » / « Ouvrir dans Chrome »).", "Dans le navigateur, touchez de nouveau 📲 pour installer."],
      ios: ["Touchez le bouton Partager ⬆ : en bas de l'écran sur Safari, dans la barre d'adresse sur Chrome. Si vous ne le voyez pas, touchez d'abord •••.", "Faites défiler et choisissez « Sur l'écran d'accueil ».", "Touchez « Ajouter » : l'icône MYBOUTIK apparaît sur votre écran."],
      android: ["Touchez le menu ⋮ du navigateur, en haut ou en bas à droite.", "Choisissez « Installer l'application » ou « Ajouter à l'écran d'accueil ».", "Confirmez : l'icône MYBOUTIK apparaît sur votre écran."],
      copy: 'Copier le lien', copied: 'Lien copié : collez-le dans Safari ou Chrome.',
      already: "Déjà installée ? Ouvrez MYBOUTIK depuis l'icône de votre écran d'accueil.",
      close: 'Fermer', installed: 'MYBOUTIK est maintenant installé sur votre appareil !'
    },
    en: {
      title_app: 'Install the app',
      title_inapp: 'First open this link in your browser',
      inapp: ['Tap ••• or ⋮ at the top of the screen.', 'Choose "Open in external browser" (or "Open in Safari" / "Open in Chrome").', 'In the browser, tap 📲 again to install.'],
      ios: ["Tap the Share button ⬆: at the bottom in Safari, in the address bar in Chrome. If you can't see it, tap ••• first.", 'Scroll and choose "Add to Home Screen".', 'Tap "Add": the MYBOUTIK icon appears on your screen.'],
      android: ['Tap the browser menu ⋮, top or bottom right.', 'Choose "Install app" or "Add to Home screen".', 'Confirm: the MYBOUTIK icon appears on your screen.'],
      copy: 'Copy link', copied: 'Link copied: paste it into Safari or Chrome.',
      already: 'Already installed? Open MYBOUTIK from the icon on your home screen.',
      close: 'Close', installed: 'MYBOUTIK is now installed on your device!'
    }
  };
  function text() { return TEXT[(typeof LANG !== 'undefined' && LANG === 'en') ? 'en' : 'fr']; }
  function standalone() { return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true; }
  function installed() { try { return localStorage.getItem(KEY) === '1'; } catch (e) { return false; } }
  function markInstalled() { try { localStorage.setItem(KEY, '1'); } catch (e) { /* stockage indisponible */ } }
  function toast(message) { if (typeof showToast === 'function') showToast(message, 'info'); }

  // A rappeler quand une page reconstruit son en-tete (voir renderShop()).
  window.mbInstallSync = function () {
    var btn = document.getElementById('installBtn');
    if (!btn) return;
    var show = !standalone() && !installed() && (deferred || isIos || isAndroid || inApp);
    btn.style.display = show ? 'inline-flex' : 'none';
  };

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferred = e;
    window.mbInstallSync();
  });
  window.addEventListener('appinstalled', function () {
    markInstalled();
    deferred = null;
    window.mbInstallSync();
  });

  window.installApp = function () {
    if (deferred) {
      var promptEvent = deferred;
      deferred = null;
      promptEvent.prompt();
      promptEvent.userChoice.then(function (choice) {
        if (choice.outcome === 'accepted') { markInstalled(); toast(text().installed); }
        window.mbInstallSync();
      });
      return;
    }
    showHelp();
  };

  function el(tag, style, content) {
    var node = document.createElement(tag);
    if (style) node.style.cssText = style;
    if (content) node.textContent = content;
    return node;
  }
  function showHelp() {
    var tx = text();
    var steps = inApp ? tx.inapp : (isIos ? tx.ios : tx.android);
    var overlay = el('div', 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:1000;display:flex;align-items:flex-end;justify-content:center;padding:12px');
    var box = el('div', 'background:#fff;color:#0f172a;border-radius:16px;max-width:440px;width:100%;padding:20px;font-family:inherit;box-shadow:0 10px 40px rgba(0,0,0,.25)');
    box.setAttribute('role', 'dialog');
    box.appendChild(el('div', 'font-size:17px;font-weight:800;margin-bottom:12px', '📲 ' + (inApp ? tx.title_inapp : tx.title_app)));
    var list = el('ol', 'margin:0 0 14px 20px;padding:0;font-size:14.5px;line-height:1.5');
    steps.forEach(function (s) { list.appendChild(el('li', 'margin-bottom:8px', s)); });
    box.appendChild(list);
    if (inApp) {
      var copy = el('button', 'width:100%;padding:11px;border-radius:10px;border:1px solid #e2e8f0;background:#fff;font-weight:700;font-size:14px;margin-bottom:8px;cursor:pointer', '🔗 ' + tx.copy);
      copy.type = 'button';
      copy.onclick = function () {
        var done = function () { toast(tx.copied); };
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(location.href).then(done, function () { window.prompt(tx.copy, location.href); });
        else window.prompt(tx.copy, location.href);
      };
      box.appendChild(copy);
    }
    box.appendChild(el('div', 'font-size:12.5px;color:#64748b;margin-bottom:12px', tx.already));
    var close = el('button', 'width:100%;padding:11px;border-radius:10px;border:0;background:#ea580c;color:#fff;font-weight:700;font-size:14px;cursor:pointer', tx.close);
    close.type = 'button';
    close.onclick = function () { overlay.remove(); };
    overlay.onclick = function (e) { if (e.target === overlay) overlay.remove(); };
    box.appendChild(close);
    overlay.appendChild(box);
    document.body.appendChild(overlay);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', window.mbInstallSync);
  else window.mbInstallSync();
})();
