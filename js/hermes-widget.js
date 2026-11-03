(function (w, d) {
  var config = w.hermesWidget;
  if (!config) {
    return;
  }

  var IDENTITY_KEY = 'hermesIdentifier';
  var identity = config.identity;

  function storedIdentifier() {
    try {
      return w.localStorage.getItem(IDENTITY_KEY);
    } catch (e) {
      return null;
    }
  }

  function storeIdentifier(identifier) {
    try {
      if (identifier) {
        w.localStorage.setItem(IDENTITY_KEY, identifier);
      } else {
        w.localStorage.removeItem(IDENTITY_KEY);
      }
    } catch (e) {
      // Private browsing can block storage. The widget still works.
    }
  }

  function expireCookie(name) {
    d.cookie = name + '=; Max-Age=0; path=/';
  }

  // Remove the previous session before the SDK reads it. After logout, or on a
  // shared browser, the next person must not see the previous conversations.
  var previous = storedIdentifier();
  var current = identity ? String(identity.identifier) : null;
  if (previous && previous !== current) {
    expireCookie('cw_conversation');
    expireCookie('cw_user_' + config.websiteToken);
  }
  storeIdentifier(current);

  w.chatwootSettings = config.settings;

  if (identity) {
    w.addEventListener('chatwoot:ready', function () {
      w.$chatwoot.setUser(identity.identifier, identity.user);
    });
  }

  var script = d.createElement('script');
  var first = d.getElementsByTagName('script')[0];
  script.async = true;
  script.defer = true;
  script.src = config.baseUrl + '/packs/js/sdk.js';
  script.onload = function () {
    w.chatwootSDK.run({
      websiteToken: config.websiteToken,
      baseUrl: config.baseUrl,
    });
  };
  first.parentNode.insertBefore(script, first);
})(window, document);
