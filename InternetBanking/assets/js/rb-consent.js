(function () {
  'use strict';

  var banner = document.querySelector('[data-cookie-banner]');
  if (!banner) return;

  var storageKey = 'rb_analytics_consent';
  var measurementId = banner.getAttribute('data-ga-id') || '';
  var currentChoice = '';

  function readChoice() {
    try {
      return window.localStorage.getItem(storageKey) || '';
    } catch (error) {
      return currentChoice;
    }
  }

  function saveChoice(choice) {
    currentChoice = choice;
    try {
      window.localStorage.setItem(storageKey, choice);
    } catch (error) {
      currentChoice = choice;
    }
  }

  function loadAnalytics() {
    if (!/^G-[A-Z0-9]{6,20}$/i.test(measurementId) || window.__rbAnalyticsLoaded) return;
    window.__rbAnalyticsLoaded = true;
    window.dataLayer = window.dataLayer || [];
    window.gtag = function () { window.dataLayer.push(arguments); };
    window.gtag('js', new Date());
    window.gtag('config', measurementId, {
      anonymize_ip: true,
      allow_google_signals: false,
      allow_ad_personalization_signals: false
    });

    var script = document.createElement('script');
    script.async = true;
    script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(measurementId);
    document.head.appendChild(script);
  }

  function setChoice(choice) {
    var wasGranted = readChoice() === 'granted';
    saveChoice(choice);
    banner.hidden = true;
    if (choice === 'granted') {
      loadAnalytics();
    } else if (wasGranted && window.__rbAnalyticsLoaded) {
      window.location.reload();
    }
  }

  var choice = readChoice();
  if (choice === 'granted') {
    banner.hidden = true;
    loadAnalytics();
  } else if (choice === 'denied') {
    banner.hidden = true;
  } else {
    banner.hidden = false;
  }

  banner.querySelectorAll('[data-cookie-accept]').forEach(function (button) {
    button.addEventListener('click', function () { setChoice('granted'); });
  });
  banner.querySelectorAll('[data-cookie-reject]').forEach(function (button) {
    button.addEventListener('click', function () { setChoice('denied'); });
  });
  document.querySelectorAll('[data-cookie-settings]').forEach(function (button) {
    button.addEventListener('click', function () {
      banner.hidden = false;
      var firstButton = banner.querySelector('[data-cookie-accept]');
      if (firstButton) firstButton.focus();
    });
  });
})();
