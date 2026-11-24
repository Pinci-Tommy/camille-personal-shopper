/**
 * Camille chat button for WooCommerce shops (plugin "Camille – AI Personal Shopper").
 *
 * Copy of the MODE A part of Pinci-Backend/extensions/camille-widget/assets/camille.js
 * (git blob bec5da93585d60d43857e2117cf6e6f80f85ccc9, 2026-11-24), adapted to WordPress:
 * settings come from window.CamilleWooConfig printed by PHP, who sees Camille is decided in PHP,
 * product photos come from PHP, WooCommerce add-to-cart and theme header selectors.
 * camille.js itself is never changed for WooCommerce; port fixes from it by hand.
 *
 * License: GPLv2 or later.
 */
(function () {
  'use strict';

  var cfg = window.CamilleWooConfig;
  if (!cfg || !cfg.storeId) {
    return;
  }

  function camilleWooApiBaseUrl(c) {
    return String(c.apiUrl || 'https://api.pinci.ai').replace(/\/$/, '');
  }

  function camilleWooStoreInfoAvatar(d) {
    if (!d || typeof d !== 'object') {
      return '';
    }
    var u = d.agentAvatarUrl;
    if ((u == null || u === '') && d.agentConfig && d.agentConfig.avatarUrl != null) {
      u = d.agentConfig.avatarUrl;
    }
    return (u == null || u === false) ? '' : String(u).trim();
  }

  function camilleWooStoreInfoName(d) {
    if (!d || typeof d !== 'object') {
      return '';
    }
    var n = d.agentName;
    if ((n == null || n === '') && d.agentConfig && d.agentConfig.name) {
      n = d.agentConfig.name;
    }
    return n == null ? '' : String(n).trim();
  }

  /** Button and panel texts. The chat itself is translated by Pinci. */
  function camilleWooUiStrings(lang, personaName) {
    var p = (personaName && String(personaName).trim()) || 'Camille';
    var l = String(lang || 'en').toLowerCase();
    var common = {
      floatCta: 'Personal Shopper',
      overlayLabel: p + ' — personal shopper',
      widgetPoweredBy: 'Powered by Pinci.ai',
    };
    var byLang = {
      it: {
        floatOpen: 'Apri Personal Shopper',
        floatClose: 'Chiudi ' + p,
        floatPanelOpen: p + ' — pannello aperto',
        productCta: 'Prova con ' + p,
        productAria: 'Apri ' + p + ' per questo prodotto',
        closeChat: 'Chiudi chat',
        iframeTitle: p + ' — assistente del negozio',
        widgetHeaderTitle: 'Trova il tuo outfit perfetto',
        floatPending: 'Simulazione in corso…',
        floatReady: 'Simulazione pronta',
        floatReadyAria: 'Apri ' + p + ': la tua simulazione è pronta',
        closeImage: 'Chiudi immagine',
      },
      fr: {
        floatOpen: 'Ouvrir le Personal Shopper',
        floatClose: 'Fermer ' + p,
        floatPanelOpen: p + ' — panneau ouvert',
        productCta: 'Essayer avec ' + p,
        productAria: 'Ouvrir ' + p + ' pour ce produit',
        closeChat: 'Fermer la discussion',
        iframeTitle: p + ' — assistante de la boutique',
        widgetHeaderTitle: 'Trouvez votre tenue idéale',
        floatPending: 'Simulation en cours…',
        floatReady: 'Votre simulation est prête',
        floatReadyAria: 'Ouvrir ' + p + ' : votre simulation est prête',
        closeImage: 'Fermer l’image',
      },
      de: {
        floatOpen: 'Personal Shopper öffnen',
        floatClose: p + ' schließen',
        floatPanelOpen: p + ' — Fenster geöffnet',
        productCta: 'Mit ' + p + ' anprobieren',
        productAria: p + ' für dieses Produkt öffnen',
        closeChat: 'Chat schließen',
        iframeTitle: p + ' — Assistentin des Shops',
        widgetHeaderTitle: 'Finden Sie Ihr perfektes Outfit',
        floatPending: 'Simulation läuft…',
        floatReady: 'Ihre Simulation ist fertig',
        floatReadyAria: p + ' öffnen: Ihre Simulation ist fertig',
        closeImage: 'Bild schließen',
      },
      es: {
        floatOpen: 'Abrir Personal Shopper',
        floatClose: 'Cerrar ' + p,
        floatPanelOpen: p + ' — panel abierto',
        productCta: 'Probar con ' + p,
        productAria: 'Abrir ' + p + ' para este producto',
        closeChat: 'Cerrar chat',
        iframeTitle: p + ' — asistente de la tienda',
        widgetHeaderTitle: 'Encuentre su outfit perfecto',
        floatPending: 'Simulación en curso…',
        floatReady: 'Su simulación está lista',
        floatReadyAria: 'Abrir ' + p + ': su simulación está lista',
        closeImage: 'Cerrar imagen',
      },
      en: {
        floatOpen: 'Open Personal Shopper',
        floatClose: 'Close ' + p,
        floatPanelOpen: p + ' — panel open',
        productCta: 'Try with ' + p,
        productAria: 'Open ' + p + ' for this product',
        closeChat: 'Close chat',
        iframeTitle: p + ' — shop assistant',
        widgetHeaderTitle: 'Find your perfect outfit',
        floatPending: 'Simulation in progress…',
        floatReady: 'Your simulation is ready',
        floatReadyAria: 'Open ' + p + ': your simulation is ready',
        closeImage: 'Close image',
      },
    };
    return Object.assign({}, common, byLang[l] || byLang.en);
  }

  /** Name and photo of Camille set by the merchant on Pinci; the button falls back to defaults. */
  function camilleWooStart() {
    var url = camilleWooApiBaseUrl(cfg) + '/api/widget/store-info/' + encodeURIComponent(String(cfg.storeId));
    fetch(url, { method: 'GET', credentials: 'omit', cache: 'no-store' })
      .then(function (r) {
        if (!r.ok) {
          throw new Error('store-info ' + r.status);
        }
        return r.json();
      })
      .then(function (d) {
        var extra = {};
        var avatar = camilleWooStoreInfoAvatar(d);
        if (avatar) {
          extra.agentAvatarUrl = avatar;
        }
        var name = camilleWooStoreInfoName(d);
        if (name) {
          extra.agentName = name;
        }
        initCamilleWoo(Object.assign({}, cfg, extra));
      })
      .catch(function () {
        initCamilleWoo(cfg);
      });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', camilleWooStart);
  } else {
    camilleWooStart();
  }

  /* =========================================================
   * Copied from camille.js MODE A (initShopifyWidget), see the header.
   * ========================================================= */

  function initCamilleWoo(cfg) {
    if (window.__CAMILLE_WOO_INIT) return;
    window.__CAMILLE_WOO_INIT = 1;

    var storeId      = String(cfg.storeId || '').trim();
    var position     = String(cfg.position || 'bottom-right').toLowerCase();
    var primaryColor = String(cfg.primaryColor || '#7c3aed').trim();
    /* The chat speaks the page language when Camille knows it (chatLanguage, decided in PHP);
       the button and panel texts follow the page language in it/en/fr/de/es. */
    var language = String(cfg.chatLanguage || 'en').trim().toLowerCase();

    if (!storeId) return;

    /* Who sees Camille was decided in PHP before this script was printed. */

    var floatAvatarUrl = '';
    if (cfg.agentAvatarUrl) {
      floatAvatarUrl = String(cfg.agentAvatarUrl).trim();
    }
    if (!floatAvatarUrl && cfg.agentConfig && cfg.agentConfig.avatarUrl) {
      floatAvatarUrl = String(cfg.agentConfig.avatarUrl).trim();
    }

    var persona =
      (cfg.agentName && String(cfg.agentName).trim()) ||
      (cfg.agentConfig && cfg.agentConfig.personaName && String(cfg.agentConfig.personaName).trim()) ||
      'Camille';
    if (floatAvatarUrl && floatAvatarUrl.indexOf('//') === -1 && floatAvatarUrl.indexOf('data:') !== 0) {
      if (floatAvatarUrl.charAt(0) === '/') {
        var api0 = camilleWooApiBaseUrl(cfg);
        var origin0 = api0.indexOf('//') === -1 ? '' : api0.split('/').slice(0, 3).join('/');
        if (origin0) {
          floatAvatarUrl = origin0 + floatAvatarUrl;
        }
      }
    }

    var wT = camilleWooUiStrings(cfg.language, persona);

    var showProductSettings = cfg.showProductButton !== false;
    var hasProduct =
      cfg.currentProduct && typeof cfg.currentProduct === 'object' && (cfg.currentProduct.id != null || cfg.currentProduct.title);
    var showProductLauncher = showProductSettings && hasProduct;
    var isRight    = position.indexOf('right') !== -1;
    var PINCI_BASE = String(cfg.chatUrl || 'https://pinci.ai').replace(/\/$/, '');

    var bottomPx = 90;
    var vOff = cfg.verticalOffset != null ? cfg.verticalOffset : cfg.vertical_offset;
    if (typeof vOff === 'number' && !isNaN(vOff) && vOff >= 20 && vOff <= 400) {
      bottomPx = Math.round(vOff);
    } else if (vOff != null && /^\d+$/.test(String(vOff).trim())) {
      var vi = parseInt(String(vOff).trim(), 10);
      if (vi >= 20 && vi <= 400) {
        bottomPx = vi;
      }
    }

    var placeRaw = String(
      (cfg.launcherPlacement != null && String(cfg.launcherPlacement)) ||
        (cfg.launcher_placement != null && String(cfg.launcher_placement)) ||
        'bottom'
    )
      .toLowerCase()
      .replace(/-/g, '_');
    if (placeRaw === 'belowheader') {
      placeRaw = 'below_header';
    }
    var useBelowHeader = placeRaw === 'below_header';

    var belowHeaderMargin = 8;
    var bhm = cfg.belowHeaderOffset != null ? cfg.belowHeaderOffset : cfg.below_header_offset;
    if (typeof bhm === 'number' && !isNaN(bhm) && bhm >= 0 && bhm <= 48) {
      belowHeaderMargin = Math.round(bhm);
    } else if (bhm != null && /^\d+$/.test(String(bhm).trim())) {
      var bhi = parseInt(String(bhm).trim(), 10);
      if (bhi >= 0 && bhi <= 48) {
        belowHeaderMargin = bhi;
      }
    }

    var customHeaderSelector =
      (cfg.headerCssSelector && String(cfg.headerCssSelector).trim()) ||
      (cfg.header_css_selector && String(cfg.header_css_selector).trim()) ||
      '';

    function measureHeaderBottomPx() {
      if (customHeaderSelector) {
        try {
          var el0 = document.querySelector(customHeaderSelector);
          if (el0) {
            var g0 = el0.getBoundingClientRect();
            if (g0.height >= 8) {
              return Math.round(g0.bottom);
            }
          }
        } catch (e) {
          /* invalid selector */
        }
      }
      /* Classic themes (Storefront, Astra, OceanWP...) and block themes. */
      var cands = [
        '#masthead',
        'header.site-header',
        '.site-header',
        '.wp-site-blocks > header',
        'header.wp-block-template-part',
        'header[role="banner"]',
        'header',
        '.header',
      ];
      for (var i = 0; i < cands.length; i++) {
        var el = document.querySelector(cands[i]);
        if (el) {
          var g = el.getBoundingClientRect();
          if (g.height >= 20 && g.bottom > 0) {
            return Math.round(g.bottom);
          }
        }
      }
      var fo = cfg.fallbackTopOffset != null ? cfg.fallbackTopOffset : cfg.fallback_top_offset;
      if (fo != null && /^\d+$/.test(String(fo).trim())) {
        var fp = parseInt(String(fo).trim(), 10);
        if (fp >= 40 && fp <= 240) {
          return fp;
        }
      }
      return 88;
    }

    function getLauncherTopPx() {
      if (!useBelowHeader) {
        return null;
      }
      return measureHeaderBottomPx() + belowHeaderMargin;
    }

    function buildDemoIframeSrc(mode) {
      var qs = new URLSearchParams();
      qs.set('lang', language);
      qs.set('embed', 'widget');
      qs.set('mode', mode === 'tryon' ? 'tryon' : 'generic');
      /* Storefront market and locale: Camille quotes the shopper's currency and links her language. */
      var sf = cfg.storefront || {};
      if (sf.language) qs.set('sfLang', String(sf.language));
      if (sf.country) qs.set('sfCountry', String(sf.country));
      if (sf.currency) qs.set('sfCurrency', String(sf.currency));
      /* No sfRoot (WordPress permalinks already carry any sub-folder) and no sfPath: the store
         sign-in links the chat builds are Shopify ones. */
      if (sf.host) qs.set('sfHost', String(sf.host));
      return PINCI_BASE + '/demo/' + encodeURIComponent(storeId) + '?' + qs.toString();
    }

    if (!document.getElementById('pinci-w-css')) {
      var styleEl = document.createElement('style');
      styleEl.id = 'pinci-w-css';
      styleEl.textContent =
        '@keyframes pinci-w-pulse{' +
          '0%,100%{transform:scale(1);box-shadow:0 4px 14px rgba(0,0,0,.25);}' +
          '50%{transform:scale(1.07);box-shadow:0 8px 24px rgba(0,0,0,.35);}' +
        '}' +
        '@keyframes pinci-w-slide{' +
          'from{opacity:0;transform:translateY(18px);}' +
          'to{opacity:1;transform:translateY(0);}' +
        '}' +
        '@keyframes pinci-w-sheet-in{' +
          'from{transform:translateY(100%);}' +
          'to{transform:translateY(0);}' +
        '}' +
        '@keyframes pinci-w-dot-pulse{' +
          '0%,100%{opacity:1;transform:scale(1);}' +
          '50%{opacity:.45;transform:scale(.8);}' +
        '}';
      (document.head || document.documentElement).appendChild(styleEl);
    }

    /* ---- Persisted launcher state (storefront localStorage, survives page changes) ---- */
    var WIDGET_STATE_KEY = 'pinci_w_state_' + storeId;
    var WIDGET_STATE_TTL_MS = 23 * 60 * 60 * 1000;
    /* Longer than the demo turn (3 min) + generation polling (11 min) windows: older flags are stale. */
    var PENDING_STATE_MAX_AGE_MS = 15 * 60 * 1000;

    function emptyWidgetState() {
      return {
        lastMode: 'generic',
        isPending: false,
        pendingSince: 0,
        isGenerationPending: false,
        hasUnseenResult: false,
        completedCount: { generic: 0, tryon: 0 },
        seenCompletedCount: { generic: 0, tryon: 0 },
        updatedAt: 0,
      };
    }

    function readCountByMode(raw) {
      var o = raw && typeof raw === 'object' ? raw : {};
      return {
        generic: typeof o.generic === 'number' && o.generic >= 0 ? o.generic : 0,
        tryon: typeof o.tryon === 'number' && o.tryon >= 0 ? o.tryon : 0,
      };
    }

    function readWidgetState() {
      var state = emptyWidgetState();
      try {
        var raw = localStorage.getItem(WIDGET_STATE_KEY);
        if (!raw) {
          return state;
        }
        var parsed = JSON.parse(raw);
        var updatedAt = parsed && typeof parsed.updatedAt === 'number' ? parsed.updatedAt : 0;
        if (!updatedAt || Date.now() - updatedAt > WIDGET_STATE_TTL_MS) {
          return state;
        }
        var pendingSince = typeof parsed.pendingSince === 'number' ? parsed.pendingSince : 0;
        var isPendingFresh = parsed.isPending === true && Date.now() - pendingSince < PENDING_STATE_MAX_AGE_MS;
        state.lastMode = parsed.lastMode === 'tryon' ? 'tryon' : 'generic';
        state.isPending = isPendingFresh;
        state.pendingSince = isPendingFresh ? pendingSince : 0;
        state.isGenerationPending = isPendingFresh && parsed.isGenerationPending === true;
        state.hasUnseenResult = parsed.hasUnseenResult === true;
        state.completedCount = readCountByMode(parsed.completedCount);
        state.seenCompletedCount = readCountByMode(parsed.seenCompletedCount);
        state.updatedAt = updatedAt;
      } catch (e) {
        /* storage unavailable (private mode) or corrupted JSON — start clean */
      }
      return state;
    }

    var widgetState = readWidgetState();

    function writeWidgetState(patch) {
      widgetState = Object.assign({}, widgetState, patch, { updatedAt: Date.now() });
      try {
        localStorage.setItem(WIDGET_STATE_KEY, JSON.stringify(widgetState));
      } catch (e) {
        /* quota / private mode — state stays in memory for this page */
      }
    }

    var PINCI_ORIGIN = '';
    try {
      PINCI_ORIGIN = new URL(PINCI_BASE).origin;
    } catch (e) {
      PINCI_ORIGIN = '';
    }

    function isMobile() {
      return window.innerWidth < 768;
    }

    var ICON_CLOSE_SM =
      '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" ' +
      'viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>';

    var floatBtn = document.createElement('button');
    floatBtn.id = 'pinci-w-float';
    floatBtn.type = 'button';
    var floatInner = document.createElement('span');
    floatInner.setAttribute('aria-hidden', 'true');
    floatInner.style.cssText = 'display:flex;align-items:center;gap:10px;';
    var floatAvWrap = document.createElement('span');
    floatAvWrap.style.cssText =
      'position:relative;width:32px;height:32px;flex-shrink:0;display:flex;align-items:center;justify-content:center;';
    var floatFace;
    if (floatAvatarUrl) {
      floatFace = document.createElement('img');
      floatFace.src = floatAvatarUrl;
      floatFace.alt = persona;
      floatFace.setAttribute('loading', 'lazy');
      floatFace.style.cssText =
        'width:32px;height:32px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.5);display:block;';
      floatFace.addEventListener('error', function onAvErr() {
        floatFace.removeEventListener('error', onAvErr);
        var sp = document.createElement('span');
        sp.textContent = '👗';
        sp.style.cssText = 'font-size:24px;line-height:1;';
        floatAvWrap.replaceChild(sp, floatFace);
        floatFace = sp;
      });
    } else {
      floatFace = document.createElement('span');
      floatFace.textContent = '👗';
      floatFace.style.cssText = 'font-size:24px;line-height:1;';
    }
    floatAvWrap.appendChild(floatFace);
    var floatXOverlay = document.createElement('span');
    floatXOverlay.setAttribute('aria-hidden', 'true');
    floatXOverlay.style.cssText =
      'display:none;position:absolute;inset:0;border-radius:50%;background:rgba(0,0,0,0.5);' +
      'color:#fff;pointer-events:none;align-items:center;justify-content:center;';
    floatXOverlay.innerHTML = ICON_CLOSE_SM;
    floatAvWrap.appendChild(floatXOverlay);

    var floatBadgeDot = document.createElement('span');
    floatBadgeDot.setAttribute('aria-hidden', 'true');
    floatBadgeDot.style.cssText = 'display:none;';
    floatAvWrap.appendChild(floatBadgeDot);

    var floatLabel = document.createElement('span');
    floatLabel.className = 'pinci-w-float-label';
    floatLabel.textContent = wT.floatCta;
    floatLabel.style.cssText = 'font-size:14px;font-weight:600;color:#fff;white-space:nowrap;';
    floatInner.appendChild(floatAvWrap);
    floatInner.appendChild(floatLabel);
    floatBtn.appendChild(floatInner);

    var isOpen = false;
    var lastOpenWithProduct = false;

    function getLauncherBadge() {
      if (isOpen) {
        return '';
      }
      if (widgetState.hasUnseenResult) {
        return 'ready';
      }
      return widgetState.isGenerationPending ? 'pending' : '';
    }

    function updateLauncherBadge() {
      var badge = getLauncherBadge();
      if (!badge) {
        floatBadgeDot.style.cssText = 'display:none;';
        floatLabel.textContent = wT.floatCta;
        return;
      }
      var isReady = badge === 'ready';
      floatBadgeDot.style.cssText =
        'position:absolute;top:-2px;right:-2px;width:11px;height:11px;border-radius:50%;box-sizing:border-box;' +
        'border:2px solid ' + primaryColor + ';pointer-events:none;' +
        'background:' + (isReady ? '#22c55e' : '#f59e0b') + ';' +
        (isReady ? '' : 'animation:pinci-w-dot-pulse 1.4s ease-in-out infinite;');
      floatLabel.textContent = isReady ? wT.floatReady : wT.floatPending;
      if (isReady) {
        floatBtn.setAttribute('aria-label', wT.floatReadyAria);
      }
    }

    function applyFloatingPillStyle(open) {
      var side = isRight ? 'right:20px;left:auto;' : 'left:20px;right:auto;';
      var vPos =
        useBelowHeader && getLauncherTopPx() != null
          ? 'top:' + getLauncherTopPx() + 'px;bottom:auto;'
          : 'bottom:' + bottomPx + 'px;top:auto;';
      // While the chat is open the launcher is hidden everywhere: the X in the black header closes it,
      // and on desktop the panel takes the launcher's place to be taller.
      if (open) {
        floatBtn.style.cssText = 'display:none;';
        floatBtn.setAttribute('aria-label', wT.floatPanelOpen);
        floatBtn.setAttribute('aria-expanded', 'true');
        return;
      }
      floatBtn.style.cssText =
        'position:fixed;z-index:999999;cursor:pointer;border:none;outline:none;font-family:inherit;' +
        'display:flex;align-items:center;justify-content:center;width:auto;min-width:0;height:52px;padding:0 20px;border-radius:26px;' +
        'background:' + primaryColor + ';color:#fff;box-shadow:0 4px 16px rgba(0,0,0,0.2);' +
        'transition:transform 0.2s,box-shadow 0.2s;' +
        side +
        vPos;
      floatLabel.style.display = '';
      floatXOverlay.style.display = 'none';
      floatInner.style.gap = '10px';
      floatAvWrap.style.margin = '';
      floatBtn.setAttribute('aria-label', wT.floatOpen);
      floatBtn.setAttribute('aria-expanded', 'false');
      updateLauncherBadge();
    }

    floatBtn.addEventListener('mouseenter', function () {
      if (isOpen) {
        return;
      }
      floatBtn.style.transform = 'translateY(-2px)';
      floatBtn.style.boxShadow = '0 6px 20px rgba(0,0,0,0.25)';
    });
    floatBtn.addEventListener('mouseleave', function () {
      if (isOpen) {
        return;
      }
      floatBtn.style.transform = '';
      floatBtn.style.boxShadow = '0 4px 16px rgba(0,0,0,0.2)';
    });

    applyFloatingPillStyle(false);

    var overlay = document.createElement('div');
    overlay.id = 'pinci-w-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', wT.overlayLabel);

    /**
     * Mobile sheet follows the *visual* viewport: on iOS the keyboard and the collapsing URL bar
     * shrink it without changing 100vh, which would push the chat input off-screen.
     */
    function getMobileSheetGeometryCss() {
      var css = 'top:0;left:0;right:0;bottom:auto;width:100%;height:100vh;height:100dvh;';
      var vv = window.visualViewport;
      if (vv && vv.height > 0) {
        css +=
          'top:' + Math.round(vv.offsetTop) + 'px;' +
          'height:' + Math.round(vv.height) + 'px;';
      }
      return css;
    }

    var pageScrollLock = null;

    function setPageScrollLocked(shouldLock) {
      var de = document.documentElement;
      var b = document.body;
      if (shouldLock && !pageScrollLock) {
        pageScrollLock = { htmlOverflow: de.style.overflow, bodyOverflow: b.style.overflow };
        de.style.overflow = 'hidden';
        b.style.overflow = 'hidden';
        return;
      }
      if (!shouldLock && pageScrollLock) {
        de.style.overflow = pageScrollLock.htmlOverflow;
        b.style.overflow = pageScrollLock.bodyOverflow;
        pageScrollLock = null;
      }
    }

    var OVERLAY_ENTER_MS = 400;
    var overlayEnteredAt = 0;

    /**
     * shouldAnimate only on open. Relayouts right after opening keep the same animation value
     * (so it is not cut short); later ones (resize, scroll, keyboard) must not replay it.
     */
    function applyOverlayStyle(visible, shouldAnimate) {
      var mobile = isMobile();
      if (visible && shouldAnimate === true) {
        overlayEnteredAt = Date.now();
      }
      var isEntering = visible && Date.now() - overlayEnteredAt < OVERLAY_ENTER_MS;
      setPageScrollLocked(visible && mobile);
      var base =
        'position:fixed;z-index:' + (mobile ? '2147483000' : '999998') + ';' +
        'display:' + (visible ? 'flex' : 'none') + ';' +
        'flex-direction:column;min-height:0;overflow:hidden;box-sizing:border-box;' +
        'font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;';
      if (mobile) {
        base +=
          getMobileSheetGeometryCss() +
          'min-width:0;max-height:none;border-radius:0;box-shadow:none;background:#fff;' +
          'padding-bottom:env(safe-area-inset-bottom,0px);overscroll-behavior:contain;';
        overlay.style.cssText = base + (isEntering ? 'animation:pinci-w-sheet-in .28s cubic-bezier(.2,.8,.2,1);' : '');
        return;
      }
      var rest = isEntering ? 'animation:pinci-w-slide .25s ease;' : '';
      // The launcher is hidden while the chat is open, so the panel starts where the launcher was.
      if (useBelowHeader) {
        var launcherTop = getLauncherTopPx();
        var pt = launcherTop != null ? launcherTop : 0;
        var minTop = 8;
        if (pt < minTop) {
          pt = minTop;
        }
        base +=
          (isRight ? 'right:20px;left:auto;' : 'left:20px;right:auto;') +
          'top:' + pt + 'px;bottom:20px;height:auto;max-height:none;min-width:0;' +
          'width:min(420px, calc(100vw - 40px));border-radius:16px;' +
          'box-shadow:0 8px 40px rgba(0,0,0,.22),0 0 0 1px rgba(0,0,0,.06);';
        overlay.style.cssText = base + rest;
        return;
      }
      base +=
        (isRight ? 'right:20px;left:auto;' : 'left:20px;right:auto;') +
        'bottom:' + bottomPx + 'px;' +
        'width:420px;min-width:0;max-width:min(420px, calc(100vw - 40px));' +
        'height:min(760px, calc(100dvh - ' + (bottomPx + 20) + 'px));' +
        'max-height:calc(100dvh - 24px);' +
        'border-radius:16px;box-shadow:0 8px 40px rgba(0,0,0,.22),0 0 0 1px rgba(0,0,0,.06);';
      overlay.style.cssText = base + rest;
    }

    applyOverlayStyle(false);

    // ---- Overlay header (black bar; iframe ?embed=widget shows white agent row below) ----
    var header = document.createElement('div');

    /* Swipe-to-dismiss affordance; gestures are read on the header because the cross-origin iframe swallows touches. */
    var dragHandle = document.createElement('span');
    dragHandle.setAttribute('aria-hidden', 'true');

    function applyHeaderStyle() {
      var mobile = isMobile();
      header.style.cssText =
        'position:relative;display:flex;align-items:center;gap:8px;flex-shrink:0;' +
        'background:#000;border-bottom:1px solid #262626;' +
        (mobile
          ? 'min-height:56px;padding:calc(env(safe-area-inset-top,0px) + 14px) 8px 6px 14px;' +
            'touch-action:none;user-select:none;-webkit-user-select:none;cursor:grab;'
          : 'min-height:48px;padding:0 8px 0 14px;');
      dragHandle.style.cssText = mobile
        ? 'position:absolute;left:50%;top:calc(env(safe-area-inset-top,0px) + 6px);width:40px;height:4px;' +
          'margin-left:-20px;border-radius:2px;background:rgba(255,255,255,.45);pointer-events:none;'
        : 'display:none;';
    }

    var headerTitle = document.createElement('span');
    headerTitle.textContent = wT.widgetHeaderTitle;
    headerTitle.style.cssText =
      'min-width:0;flex:1;color:#fff;font-weight:700;font-size:14px;line-height:1.25;';

    var headerPowered = document.createElement('span');
    headerPowered.textContent = wT.widgetPoweredBy;
    headerPowered.setAttribute('aria-hidden', 'true');
    headerPowered.style.cssText = 'color:#fff;font-size:10px;white-space:nowrap;flex-shrink:0;line-height:1.2;';

    var headerClose = document.createElement('button');
    headerClose.type = 'button';
    headerClose.setAttribute('aria-label', wT.closeChat);
    headerClose.innerHTML =
      '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" ' +
      'viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">' +
      '<path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 ' +
      '6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>';
    headerClose.style.cssText =
      'background:none;border:none;cursor:pointer;color:#fff;' +
      'width:32px;height:32px;display:flex;align-items:center;justify-content:center;' +
      'border-radius:8px;padding:0;flex-shrink:0;transition:background .15s;';
    headerClose.addEventListener('mouseenter', function () {
      headerClose.style.background = 'rgba(255,255,255,.18)';
    });
    headerClose.addEventListener('mouseleave', function () {
      headerClose.style.background = 'none';
    });

    header.appendChild(dragHandle);
    header.appendChild(headerTitle);
    header.appendChild(headerPowered);
    header.appendChild(headerClose);
    applyHeaderStyle();

    // ---- Iframe ----
    var iframe = document.createElement('iframe');
    iframe.setAttribute('title', wT.iframeTitle);
    iframe.setAttribute('allow', 'microphone; clipboard-write');
    iframe.style.cssText =
      'flex:1;min-height:0;width:100%;height:100%;border:none;background:#fff;display:block;';

    function postToIframe(wantProduct) {
      if (!iframe.contentWindow) return;
      try {
        iframe.contentWindow.postMessage({ type: 'PINCI_CONFIG', language: language }, PINCI_BASE);
      } catch (e) {
        /* ignore */
      }
      try {
        if (wantProduct && cfg.currentProduct) {
          /* productImageUrls come from PHP (up to 3 product photos). */
          var productPayload = Object.assign({ productImageUrls: [] }, cfg.currentProduct);
          iframe.contentWindow.postMessage(
            { type: 'PINCI_PRODUCT_CONTEXT', product: productPayload },
            PINCI_BASE
          );
        }
      } catch (e) {
        /* ignore */
      }
    }

    iframe.addEventListener('load', function () {
      if (!iframe.src) return;
      setTimeout(function () {
        postToIframe(lastOpenWithProduct);
      }, 0);
    });

    /* WooCommerce add-to-cart form: classic templates, then the block "Add to cart with options". */
    function findProductFormAnchor() {
      var f = document.querySelector('.summary form.cart');
      if (!f) f = document.querySelector('form.cart');
      if (f) return f;
      f = document.querySelector('.wp-block-add-to-cart-form');
      if (f) return f;
      f = document.querySelector('.wp-block-woocommerce-add-to-cart-with-options');
      if (f) return f;
      return document.querySelector('.summary.entry-summary');
    }

    function getIframeMode() {
      if (!iframe.src) {
        return '';
      }
      try {
        return new URL(iframe.src, PINCI_BASE).searchParams.get('mode') || '';
      } catch (e) {
        return '';
      }
    }

    /** Loads the iframe for `mode` unless it already shows that conversation; true if (re)loaded. */
    function ensureIframeMode(mode) {
      var needLoad = !iframe.src || getIframeMode() !== mode;
      if (needLoad) {
        iframe.src = buildDemoIframeSrc(mode);
      }
      return needLoad;
    }

    /**
     * mode: which conversation to show ('generic' | 'tryon').
     * sendProductContext: only the PDP button sends the current product (the floating
     * launcher reopens the last conversation without triggering a product handshake).
     */
    function openWidget(mode, sendProductContext) {
      var wasOpen = isOpen;
      lastOpenWithProduct = !!sendProductContext;
      isOpen = true;
      var needLoad = ensureIframeMode(mode);
      writeWidgetState({
        lastMode: mode,
        hasUnseenResult: false,
        seenCompletedCount: widgetState.completedCount,
      });
      applyOverlayStyle(true, !wasOpen);
      applyFloatingPillStyle(true);
      if (useBelowHeader) {
        setTimeout(relayoutForViewport, 0);
        setTimeout(relayoutForViewport, 200);
        setTimeout(relayoutForViewport, 500);
      }
      if (!needLoad) {
        setTimeout(function () {
          postToIframe(lastOpenWithProduct);
        }, 200);
      }
    }

    function closeWidget() {
      isOpen = false;
      lastOpenWithProduct = false;
      applyOverlayStyle(false);
      applyFloatingPillStyle(false);
    }

    /* ---- Swipe down / right on the header to dismiss (mobile) ---- */
    var SWIPE_AXIS_LOCK_PX = 8;
    var SWIPE_CLOSE_RATIO = 0.3;
    var SWIPE_CLOSE_VELOCITY = 0.5; // px per ms
    var SWIPE_FLICK_MIN_PX = 40;
    var SWIPE_ANIMATION_MS = 220;
    var swipe = null;
    var isSwipeClosing = false;

    function swipeTransform(axis, distance) {
      return axis === 'y' ? 'translateY(' + distance + 'px)' : 'translateX(' + distance + 'px)';
    }

    function onHeaderTouchStart(ev) {
      if (!isOpen || !isMobile() || isSwipeClosing || ev.touches.length !== 1) {
        return;
      }
      if (headerClose.contains(ev.target)) {
        return;
      }
      var t = ev.touches[0];
      var now = Date.now();
      swipe = { startX: t.clientX, startY: t.clientY, axis: null, distance: 0, lastDistance: 0, lastAt: now, velocity: 0 };
    }

    function onHeaderTouchMove(ev) {
      if (!swipe || ev.touches.length !== 1) {
        return;
      }
      var t = ev.touches[0];
      var dx = t.clientX - swipe.startX;
      var dy = t.clientY - swipe.startY;
      if (!swipe.axis) {
        if (Math.abs(dx) < SWIPE_AXIS_LOCK_PX && Math.abs(dy) < SWIPE_AXIS_LOCK_PX) {
          return;
        }
        swipe.axis = Math.abs(dy) >= Math.abs(dx) ? 'y' : 'x';
        overlay.style.animation = 'none';
        overlay.style.transition = 'none';
      }
      if (ev.cancelable) {
        ev.preventDefault();
      }
      var distance = Math.max(0, swipe.axis === 'y' ? dy : dx);
      var now = Date.now();
      var elapsed = Math.max(1, now - swipe.lastAt);
      swipe.velocity = (distance - swipe.lastDistance) / elapsed;
      swipe.lastDistance = distance;
      swipe.lastAt = now;
      swipe.distance = distance;
      overlay.style.transform = swipeTransform(swipe.axis, distance);
    }

    function onHeaderTouchEnd(ev) {
      if (!swipe) {
        return;
      }
      var s = swipe;
      swipe = null;
      if (!s.axis) {
        return;
      }
      var size = s.axis === 'y' ? overlay.offsetHeight : overlay.offsetWidth;
      var isCancelled = ev.type === 'touchcancel';
      var isFlick = s.distance > SWIPE_FLICK_MIN_PX && s.velocity > SWIPE_CLOSE_VELOCITY;
      var shouldClose = !isCancelled && (s.distance > size * SWIPE_CLOSE_RATIO || isFlick);
      overlay.style.transition = 'transform ' + SWIPE_ANIMATION_MS + 'ms ease-out';
      if (shouldClose) {
        isSwipeClosing = true;
        overlay.style.transform = swipeTransform(s.axis, size);
        setTimeout(function () {
          isSwipeClosing = false;
          closeWidget();
        }, SWIPE_ANIMATION_MS);
        return;
      }
      overlay.style.transform = swipeTransform(s.axis, 0);
      setTimeout(function () {
        if (!swipe && !isSwipeClosing) {
          overlay.style.transition = '';
          overlay.style.transform = '';
        }
      }, SWIPE_ANIMATION_MS);
    }

    header.addEventListener('touchstart', onHeaderTouchStart, { passive: true });
    header.addEventListener('touchmove', onHeaderTouchMove, { passive: false });
    header.addEventListener('touchend', onHeaderTouchEnd);
    header.addEventListener('touchcancel', onHeaderTouchEnd);

    /* ---- Activity state from the iframe (PINCI_ACTIVITY_STATE) → persisted badge ---- */
    function onIframeActivityState(data) {
      var mode = data.mode === 'tryon' ? 'tryon' : 'generic';
      var isReplyPending = data.isReplyPending === true;
      var isGenerationPending = data.isGenerationPending === true;
      var isPending = isReplyPending || isGenerationPending;
      var count = typeof data.completedGenerationCount === 'number' && data.completedGenerationCount >= 0
        ? Math.floor(data.completedGenerationCount)
        : 0;
      var completedCount = Object.assign({}, widgetState.completedCount);
      var seenCompletedCount = Object.assign({}, widgetState.seenCompletedCount);
      completedCount[mode] = count;
      /* History is capped server-side, so the count can shrink: never leave "seen" above it. */
      if (isOpen || seenCompletedCount[mode] > count) {
        seenCompletedCount[mode] = count;
      }
      writeWidgetState({
        isPending: isPending,
        pendingSince: isPending ? widgetState.pendingSince || Date.now() : 0,
        isGenerationPending: isGenerationPending,
        hasUnseenResult: !isOpen && (widgetState.hasUnseenResult || count > seenCompletedCount[mode]),
        completedCount: completedCount,
        seenCompletedCount: seenCompletedCount,
      });
      updateLauncherBadge();
    }

    /* ---- Full-page image lightbox (PINCI_OPEN_LIGHTBOX from the iframe) ----
     * The chat iframe is narrow on desktop, so a zoom inside it stays small: the storefront page
     * shows the image over everything instead. The iframe opens its own lightbox when no ack arrives. */
    var lightbox = null;

    var LIGHTBOX_MAX_HTTPS_URL_LENGTH = 8192;
    /* A watermarked 1024x1536 PNG is a few MB as base64: well under this. */
    var LIGHTBOX_MAX_DATA_URL_LENGTH = 30000000;
    var LIGHTBOX_DATA_URL_PATTERN = /^data:image\/(png|jpeg|webp);base64,/;

    /* The iframe sends the watermarked image as a data URL once the watermark is drawn,
       so data URLs of plain raster images are accepted next to https URLs. */
    function isAllowedLightboxUrl(url) {
      if (typeof url !== 'string') return false;
      if (LIGHTBOX_DATA_URL_PATTERN.test(url)) return url.length <= LIGHTBOX_MAX_DATA_URL_LENGTH;
      if (url.length > LIGHTBOX_MAX_HTTPS_URL_LENGTH) return false;
      try {
        return new URL(url).protocol === 'https:';
      } catch (e) {
        return false;
      }
    }

    function postLightboxResult(type) {
      if (iframe.contentWindow && PINCI_ORIGIN) {
        iframe.contentWindow.postMessage({ type: type }, PINCI_ORIGIN);
      }
    }

    /* The image fits the window; a click or tap anywhere (image included) or Esc closes it. */
    function buildLightbox() {
      var root = document.createElement('div');
      root.id = 'pinci-w-lightbox';
      root.setAttribute('role', 'dialog');
      root.setAttribute('aria-modal', 'true');
      root.style.cssText =
        'position:fixed;inset:0;z-index:2147483647;display:none;align-items:center;justify-content:center;' +
        'background:rgba(0,0,0,.92);cursor:zoom-out;';
      var img = document.createElement('img');
      img.alt = '';
      img.draggable = false;
      img.style.cssText =
        'max-width:100vw;max-height:100vh;max-height:100dvh;object-fit:contain;user-select:none;-webkit-user-select:none;';
      var close = document.createElement('button');
      close.type = 'button';
      close.setAttribute('aria-label', wT.closeImage);
      close.textContent = '×';
      close.style.cssText =
        'position:absolute;top:calc(12px + env(safe-area-inset-top,0px));right:12px;width:44px;height:44px;' +
        'border:none;border-radius:50%;background:rgba(255,255,255,.18);color:#fff;font-size:30px;line-height:44px;' +
        'cursor:pointer;padding:0;font-family:system-ui,sans-serif;';
      root.appendChild(img);
      root.appendChild(close);
      document.body.appendChild(root);
      return { root: root, img: img, close: close };
    }

    function isLightboxOpen() {
      return !!lightbox && lightbox.root.style.display !== 'none';
    }

    function closeLightbox() {
      if (!isLightboxOpen()) return;
      lightbox.root.style.display = 'none';
      lightbox.img.removeAttribute('src');
      /* Back to the chat's own lock: the mobile sheet keeps the page locked while it is open. */
      setPageScrollLocked(isOpen && isMobile());
    }

    function initLightbox() {
      lightbox = buildLightbox();
      lightbox.root.addEventListener('click', closeLightbox);
      /* Capture phase: Esc closes the lightbox only, not the chat behind it. */
      document.addEventListener(
        'keydown',
        function (ev) {
          if (ev.key !== 'Escape' || !isLightboxOpen()) return;
          ev.stopImmediatePropagation();
          closeLightbox();
        },
        true
      );
      lightbox.img.addEventListener('error', function () {
        if (!isLightboxOpen()) return;
        closeLightbox();
        postLightboxResult('PINCI_LIGHTBOX_FAILED');
      });
    }

    function openLightbox(url) {
      if (!isAllowedLightboxUrl(url)) {
        postLightboxResult('PINCI_LIGHTBOX_FAILED');
        return;
      }
      if (!lightbox) initLightbox();
      lightbox.img.src = url;
      lightbox.root.style.display = 'flex';
      setPageScrollLocked(true);
      postLightboxResult('PINCI_LIGHTBOX_OPENED');
      /* Focus leaves the iframe, so Esc reaches this page. */
      lightbox.close.focus({ preventScroll: true });
    }

    window.addEventListener('message', function (ev) {
      if (!PINCI_ORIGIN || ev.origin !== PINCI_ORIGIN || ev.source !== iframe.contentWindow) {
        return;
      }
      if (ev.data && ev.data.type === 'PINCI_ACTIVITY_STATE') {
        onIframeActivityState(ev.data);
      } else if (ev.data && ev.data.type === 'PINCI_OPEN_LIGHTBOX') {
        openLightbox(ev.data.url);
      }
    });

    function mountProductButton() {
      if (document.getElementById('pinci-w-product-launch')) return;
      if (!showProductLauncher) return;
      var anchor = findProductFormAnchor();
      if (!anchor) return;
      var pb = document.createElement('button');
      pb.id = 'pinci-w-product-launch';
      pb.type = 'button';
      if (floatAvatarUrl) {
        var row = document.createElement('span');
        row.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;gap:10px;';
        var pimg = document.createElement('img');
        pimg.src = floatAvatarUrl;
        pimg.alt = '';
        pimg.setAttribute('aria-hidden', 'true');
        pimg.width = 24;
        pimg.height = 24;
        pimg.style.cssText = 'width:24px;height:24px;border-radius:50%;object-fit:cover;flex-shrink:0;border:1px solid rgba(0,0,0,0.08);';
        pimg.addEventListener('error', function onPbImg() {
          pimg.removeEventListener('error', onPbImg);
          pimg.style.display = 'none';
        });
        var plab = document.createElement('span');
        plab.textContent = wT.productCta;
        row.appendChild(pimg);
        row.appendChild(plab);
        pb.appendChild(row);
      } else {
        pb.textContent = wT.productCta;
      }
      pb.setAttribute('aria-label', wT.productAria);
      pb.style.cssText =
        'display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;width:auto;min-width:0;max-width:100%;' +
        'align-self:flex-start;margin-top:12px;padding:10px 14px;border-radius:8px;cursor:pointer;font:inherit;' +
        'background:#fff;border:2px solid ' + primaryColor + ';' +
        'color:' + primaryColor + ';font-weight:600;transition:background .2s ease,color .2s ease;';
      pb.addEventListener('mouseenter', function () {
        pb.style.background = primaryColor;
        pb.style.color = '#fff';
      });
      pb.addEventListener('mouseleave', function () {
        pb.style.background = '#fff';
        pb.style.color = primaryColor;
      });
      pb.addEventListener('click', function (e) {
        e.preventDefault();
        openWidget('tryon', true);
      });
      try {
        if (anchor.tagName && String(anchor.tagName).toLowerCase() === 'form') {
          anchor.insertAdjacentElement('afterend', pb);
        } else {
          anchor.appendChild(pb);
        }
      } catch (e) {
        anchor.appendChild(pb);
      }
    }

    overlay.appendChild(header);
    overlay.appendChild(iframe);
    document.body.appendChild(floatBtn);
    document.body.appendChild(overlay);

    floatBtn.addEventListener('click', function () {
      if (isOpen) {
        closeWidget();
        return;
      }
      openWidget(widgetState.lastMode, false);
    });

    headerClose.addEventListener('click', closeWidget);

    /* Buttons from the [camille_button] shortcode and the Camille button block, and a small API
       for themes: CamilleWoo.open('tryon' | 'generic'). */
    function openFromPage(mode) {
      var isTryOn = mode !== 'generic';
      openWidget(isTryOn ? 'tryon' : 'generic', isTryOn && !!hasProduct);
    }
    document.addEventListener('click', function (ev) {
      var target = ev.target && ev.target.closest ? ev.target.closest('.camille-ps-open') : null;
      if (!target) return;
      ev.preventDefault();
      openFromPage(target.getAttribute('data-camille-mode') || 'tryon');
    });
    window.CamilleWoo = {
      open: openFromPage,
      close: closeWidget,
    };

    document.addEventListener('keydown', function (ev) {
      if (isOpen && ev.key === 'Escape') closeWidget();
    });

    var lastLayoutAt = 0;
    function relayoutForViewport() {
      /* Re-applying cssText mid-gesture would drop the drag transform. */
      if ((swipe && swipe.axis) || isSwipeClosing) {
        return;
      }
      applyHeaderStyle();
      applyOverlayStyle(isOpen);
      applyFloatingPillStyle(isOpen);
    }
    window.addEventListener('resize', function () {
      relayoutForViewport();
    });
    if (window.visualViewport) {
      var onVisualViewportChange = function () {
        if (isOpen && isMobile()) {
          relayoutForViewport();
        }
      };
      window.visualViewport.addEventListener('resize', onVisualViewportChange);
      window.visualViewport.addEventListener('scroll', onVisualViewportChange);
    }

    /* A simulation was running on the previous page: load the conversation hidden so it can
       resume polling and light the badge when the result is ready. */
    if (widgetState.isPending) {
      ensureIframeMode(widgetState.lastMode);
    }
    updateLauncherBadge();
    if (useBelowHeader) {
      window.addEventListener(
        'scroll',
        function () {
          var n = Date.now();
          if (n - lastLayoutAt < 80) {
            return;
          }
          lastLayoutAt = n;
          relayoutForViewport();
        },
        { passive: true, capture: true }
      );
    }

    if (showProductLauncher) {
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
          setTimeout(mountProductButton, 0);
        });
      } else {
        setTimeout(mountProductButton, 0);
      }
    }
  }
})();
