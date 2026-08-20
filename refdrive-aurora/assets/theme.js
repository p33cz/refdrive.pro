/* RefDrive Aurora Theme – theme.js v1.0.0 */
(function () {
  'use strict';

  /* ---- THEME TOGGLE ---- */
  var html   = document.documentElement;
  var toggle = document.getElementById('rdpro-theme-toggle');
  var icon   = document.getElementById('rdpro-theme-icon');

  var saved = localStorage.getItem('rdpro-theme') || 'dark';
  html.setAttribute('data-theme', saved);
  if (icon) icon.textContent = saved === 'light' ? '🌙' : '☀️';

  if (toggle) {
    toggle.addEventListener('click', function () {
      var next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      html.setAttribute('data-theme', next);
      localStorage.setItem('rdpro-theme', next);
      if (icon) icon.textContent = next === 'light' ? '🌙' : '☀️';
    });
  }

  /* ---- HEADER SCROLL ---- */
  var header = document.getElementById('rdpro-header');
  if (header) {
    window.addEventListener('scroll', function () {
      header.classList.toggle('scrolled', window.scrollY > 20);
    }, { passive: true });
  }

  /* ---- BURGER MENU ---- */
  var burger     = document.getElementById('rdpro-burger');
  var mobileMenu = document.getElementById('rdpro-mobile-menu');

  if (burger && mobileMenu) {
    burger.addEventListener('click', function () {
      var open = burger.classList.toggle('open');
      mobileMenu.classList.toggle('open', open);
      burger.setAttribute('aria-expanded', open);
      mobileMenu.setAttribute('aria-hidden', !open);
      document.body.style.overflow = open ? 'hidden' : '';
    });

    mobileMenu.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function () {
        burger.classList.remove('open');
        mobileMenu.classList.remove('open');
        burger.setAttribute('aria-expanded', 'false');
        mobileMenu.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
      });
    });
  }

  /* ---- SCROLL REVEAL ---- */
  var groups = [
    { sel: '.rd-hero-wrap',      cls: 'rdpro-reveal',       stagger: false },
    { sel: '.rd-feature',         cls: 'rdpro-reveal',       stagger: true  },
    { sel: '.rd-how-step',        cls: 'rdpro-reveal',       stagger: true  },
    { sel: '.rd-tile',            cls: 'rdpro-reveal',       stagger: true  },
    { sel: '.rd-step',          cls: 'rdpro-reveal',       stagger: true  },
    { sel: '.rd-hero',          cls: 'rdpro-reveal-scale', stagger: false },
    { sel: '.rd-cta-bar',       cls: 'rdpro-reveal',       stagger: false },
    { sel: '.rd-lekce',         cls: 'rdpro-reveal',       stagger: true  },
    { sel: '.rd-otazka',        cls: 'rdpro-reveal',       stagger: true  },
    { sel: '.rd-kviz-header',   cls: 'rdpro-reveal-scale', stagger: false },
    { sel: '.rd-vysledek',      cls: 'rdpro-reveal-scale', stagger: false },
    { sel: '.rd-login-card',    cls: 'rdpro-reveal-scale', stagger: false },
    { sel: '.rd-cert',          cls: 'rdpro-reveal-scale', stagger: false },
    { sel: '.rd-pf-hero',       cls: 'rdpro-reveal',       stagger: false },
    { sel: '.rd-rozcestnik-opt',cls: 'rdpro-reveal',       stagger: true  },
    { sel: '.rdpro-reveal',     cls: 'rdpro-reveal',       stagger: false },
  ];

  var revealClasses = ['rdpro-reveal','rdpro-reveal-left','rdpro-reveal-right','rdpro-reveal-scale'];

  function hasReveal(el) {
    return revealClasses.some(function (c) { return el.classList.contains(c); });
  }

  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) {
          e.target.classList.add('visible');
          io.unobserve(e.target);
        }
      });
    }, { threshold: 0.06, rootMargin: '0px 0px -24px 0px' });

    groups.forEach(function (g) {
      document.querySelectorAll(g.sel).forEach(function (el, i) {
        if (!hasReveal(el)) el.classList.add(g.cls);
        if (g.stagger) el.style.transitionDelay = (Math.min(i % 4, 3) * 90) + 'ms';
        io.observe(el);
      });
    });

    /* Above-the-fold: trigger immediately */
    document.querySelectorAll(revealClasses.join(',')).forEach(function (el) {
      if (el.getBoundingClientRect().top < window.innerHeight) {
        setTimeout(function () { el.classList.add('visible'); }, 80);
      }
    });

  } else {
    /* Fallback: show everything */
    document.querySelectorAll(revealClasses.join(',')).forEach(function (el) {
      el.classList.add('visible');
    });
  }

  /* ---- ACTIVE NAV ---- */
  var path = window.location.pathname;
  document.querySelectorAll('.rdpro-nav-list a, .rdpro-mobile-nav-list a').forEach(function (a) {
    var href = a.getAttribute('href');
    if (href && (href === path || (path !== '/' && path.startsWith(href)))) {
      a.parentElement.classList.add('current-menu-item');
    }
  });

})();
