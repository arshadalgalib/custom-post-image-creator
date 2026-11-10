/**
 * Social Accounts — Facebook OAuth popup.
 */
(function () {
  'use strict';

  var cfg = window.CPICAccounts || {};
  var restRoot = cfg.restRoot || '';
  var nonce = cfg.nonce || '';
  var i18n = cfg.i18n || {};

  function status(msg, isError) {
    var el = document.getElementById('cpic-fb-oauth-status');
    if (!el) return;
    el.textContent = msg || '';
    el.style.color = isError ? '#b32d2e' : '#646970';
  }

  function rest(path, method, body) {
    var url = restRoot.replace(/\/$/, '') + path;
    return fetch(url, {
      method: method || 'GET',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce
      },
      body: body ? JSON.stringify(body) : undefined
    }).then(function (r) {
      return r.json().then(function (data) {
        if (!r.ok) {
          var msg = (data && data.message) || (data && data.code) || i18n.failed || 'Request failed';
          var err = new Error(msg);
          err.data = data;
          throw err;
        }
        return data;
      });
    });
  }

  function openPopup(url) {
    var w = 700, h = 700;
    var left = window.screenX + (window.outerWidth - w) / 2;
    var top = window.screenY + (window.outerHeight - h) / 2;
    var features = 'width=' + w + ',height=' + h + ',left=' + left + ',top=' + top + ',resizable=yes,scrollbars=yes';
    var win = window.open(url, 'cpicFacebookAuth', features);
    if (!win) {
      status(i18n.popupBlocked || 'Popup blocked', true);
      return null;
    }
    try { win.focus(); } catch (e) {}
    return win;
  }

  function doOAuth(btn) {
    if (!cfg.canManage) return;
    btn.disabled = true;
    status(i18n.connecting || 'Connecting…', false);
    var appIdEl = document.getElementById('cpic-fb-app-id');
    var appSecEl = document.getElementById('cpic-fb-app-secret');
    var payload = {};
    if (appIdEl && appIdEl.value.trim()) payload.app_id = appIdEl.value.trim();
    if (appSecEl && appSecEl.value.trim()) payload.app_secret = appSecEl.value.trim();
    rest('/cpic/v1/accounts/facebook/oauth-url', 'POST', payload)
      .then(function (data) {
        var url = data && (data.authorization_url || (data.data && data.data.authorization_url));
        if (!url && data && data.url) url = data.url;
        if (!url) throw new Error(i18n.failed || 'No URL');
        status('', false);
        var popup = openPopup(url);
        if (!popup) btn.disabled = false;
        else {
          setTimeout(function () { btn.disabled = false; }, 1500);
          var timer = setInterval(function () {
            if (popup.closed) clearInterval(timer);
          }, 800);
        }
      })
      .catch(function (err) {
        status(err.message || i18n.failed, true);
        btn.disabled = false;
      });
  }

  function init() {
    var btn = document.getElementById('cpic-fb-oauth-btn');
    if (btn) btn.addEventListener('click', function () { doOAuth(btn); });
    var reconnect = document.getElementById('cpic-fb-reconnect');
    if (reconnect) reconnect.addEventListener('click', function (e) { e.preventDefault(); doOAuth(reconnect); });

    // Telegram test
    var tgBtn = document.getElementById('cpic-tg-test-btn');
    if (tgBtn) {
      tgBtn.addEventListener('click', function () {
        var st = document.getElementById('cpic-tg-test-status');
        var bot = document.getElementById('cpic-tg-bot-token');
        var chat = document.getElementById('cpic-tg-chat-id');
        // Use stored values if fields are blank (masked)
        var botVal = bot && bot.value ? bot.value.trim() : '';
        var chatVal = chat && chat.value ? chat.value.trim() : '';
        if (!botVal && st) { st.textContent = 'Enter Bot Token to test'; st.style.color='#b32d2e'; return; }
        tgBtn.disabled = true;
        if (st) { st.textContent = 'Testing…'; st.style.color='#646970'; }
        // Hit existing destinations verify via REST — fallback to admin-post.
        // Try fetching Telegram account and verifying.
        rest('/cpic/v1/accounts?provider=telegram', 'GET')
          .then(function (d) {
            var accounts = (d && d.accounts) || (d && d.data && d.data.accounts) || [];
            if (accounts.length) {
              return rest('/cpic/v1/accounts/' + accounts[0].id + '/verify', 'POST', {});
            }
            // No destination yet — verify via temporary save + verify would be needed.
            // For now just report success if token format looks valid.
            throw new Error('Save Telegram credentials first, then test.');
          })
          .then(function () {
            if (st) { st.textContent = 'Connection OK'; st.style.color='#007017'; }
          })
          .catch(function (err) {
            if (st) { st.textContent = err.message || 'Test failed'; st.style.color='#b32d2e'; }
          })
          .then(function(){ tgBtn.disabled=false; });
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
