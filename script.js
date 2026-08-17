const observer = new IntersectionObserver((entries) => {
  entries.forEach((entry) => {
    if (entry.isIntersecting) {
      entry.target.classList.add('is-visible');
      observer.unobserve(entry.target);
    }
  });
}, { threshold: 0.12 });

document.querySelectorAll('.reveal').forEach((element) => observer.observe(element));

const navigation = document.querySelector('.desktop-nav');

if (navigation) {
  const currentPage = document.body.dataset.page || 'home';
  const navigationLinks = [...navigation.querySelectorAll('[data-nav-key]')];
  const navigationSections = navigationLinks
    .filter((link) => link.dataset.navSection)
    .map((link) => document.getElementById(link.dataset.navSection))
    .filter(Boolean);
  const navigationKeyForSection = (sectionId) =>
    navigationLinks.find((link) => link.dataset.navSection === sectionId)?.dataset.navKey || sectionId;

  const setActiveNavigation = (pageKey) => {
    const activeIndex = navigationLinks.findIndex((link) => link.dataset.navKey === pageKey);
    const fallbackIndex = activeIndex >= 0 ? activeIndex : 0;

    navigation.style.setProperty('--active-index', fallbackIndex);
    navigationLinks.forEach((link, index) => {
      const isActive = index === fallbackIndex;
      link.classList.toggle('is-active', isActive);
      if (isActive) link.setAttribute('aria-current', 'location');
      else link.removeAttribute('aria-current');
    });
  };

  const hashSection = window.location.hash.slice(1);
  const initialSection = currentPage === 'home' && navigationSections.some((section) => section.id === hashSection)
    ? hashSection
    : currentPage;
  setActiveNavigation(currentPage === 'home' ? navigationKeyForSection(initialSection) : currentPage);

  navigationLinks.forEach((link) => {
    link.addEventListener('click', () => setActiveNavigation(link.dataset.navKey));
  });

  if (currentPage === 'home' && 'IntersectionObserver' in window) {
    const sectionObserver = new IntersectionObserver((entries) => {
      const visibleSection = entries
        .filter((entry) => entry.isIntersecting)
        .sort((first, second) => second.intersectionRatio - first.intersectionRatio)[0];

      if (visibleSection) setActiveNavigation(navigationKeyForSection(visibleSection.target.id));
    }, { rootMargin: '-28% 0px -58% 0px', threshold: [0.12, 0.35, 0.7] });

    navigationSections.forEach((section) => sectionObserver.observe(section));
  }

  window.addEventListener('hashchange', () => {
    const nextSection = window.location.hash.slice(1);
    if (currentPage === 'home' && navigationSections.some((section) => section.id === nextSection)) {
      setActiveNavigation(navigationKeyForSection(nextSection));
    }
  });
}

const homeMega = document.querySelector('[data-home-mega]');

if (homeMega) {
  const toggle = homeMega.querySelector('[data-home-mega-toggle]');
  const panel = homeMega.querySelector('[data-home-mega-panel]');
  const menuLinks = [...homeMega.querySelectorAll('.home-mega-menu a')];
  let hoverOpenTimer;
  let hoverCloseTimer;
  let openedByHover = false;

  const clearHoverTimers = () => {
    window.clearTimeout(hoverOpenTimer);
    window.clearTimeout(hoverCloseTimer);
  };

  const setHomeMegaOpen = (isOpen) => {
    homeMega.classList.toggle('is-open', isOpen);
    toggle?.setAttribute('aria-expanded', String(isOpen));
    toggle?.setAttribute('aria-label', isOpen ? 'Chiudi menu' : 'Apri menu');
  };

  const setActiveHomeMenu = () => {
    const currentHash = window.location.hash || '#contenuto';
    menuLinks.forEach((link) => {
      const target = new URL(link.href, window.location.href);
      const isActive = target.pathname === window.location.pathname && target.hash === currentHash;
      link.classList.toggle('is-active', isActive);
      if (isActive) link.setAttribute('aria-current', 'location');
      else link.removeAttribute('aria-current');
    });
  };

  toggle?.addEventListener('click', (event) => {
    event.stopPropagation();
    clearHoverTimers();
    openedByHover = false;
    setHomeMegaOpen(!homeMega.classList.contains('is-open'));
  });

  menuLinks.forEach((link) => {
    link.addEventListener('click', () => {
      clearHoverTimers();
      openedByHover = false;
      setHomeMegaOpen(false);
    });
  });

  document.addEventListener('click', (event) => {
    if (!homeMega.contains(event.target)) {
      clearHoverTimers();
      openedByHover = false;
      setHomeMegaOpen(false);
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      clearHoverTimers();
      openedByHover = false;
      setHomeMegaOpen(false);
    }
  });

  homeMega.addEventListener('pointerenter', () => {
    clearTimeout(hoverCloseTimer);
    if (!window.matchMedia('(hover: hover)').matches || homeMega.classList.contains('is-open')) return;
    clearTimeout(hoverOpenTimer);
    hoverOpenTimer = window.setTimeout(() => {
      openedByHover = true;
      setHomeMegaOpen(true);
    }, 220);
  });

  homeMega.addEventListener('pointerleave', () => {
    clearTimeout(hoverOpenTimer);
    if (!openedByHover) return;
    clearTimeout(hoverCloseTimer);
    hoverCloseTimer = window.setTimeout(() => {
      openedByHover = false;
      setHomeMegaOpen(false);
    }, 420);
  });

  window.addEventListener('hashchange', setActiveHomeMenu);
  setActiveHomeMenu();
}

const caseCarousel = document.querySelector('[data-case-carousel]');

if (caseCarousel) {
  const viewport = caseCarousel.querySelector('.case-carousel__viewport');
  const track = caseCarousel.querySelector('.case-carousel__track');
  const originals = [...track.querySelectorAll('[data-case-card]')];
  const cardCount = originals.length;
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  let cards = [...originals];
  let cardStep = 0;
  let offset = 0;
  let position = 0;
  let pointerStart = 0;
  let positionStart = 0;
  let dragging = false;
  let hovering = false;
  let moved = false;
  let lastFrame = performance.now();
  const firstFrameHold = performance.now() + 850;
  const speed = 0.021;

  originals.forEach((card) => {
    const clone = card.cloneNode(true);
    clone.setAttribute('aria-hidden', 'true');
    clone.dataset.carouselClone = 'true';
    clone.querySelectorAll('a').forEach((link) => link.setAttribute('tabindex', '-1'));
    track.appendChild(clone);
  });

  cards = [...track.querySelectorAll('[data-case-card]')];

  const normalisePosition = () => {
    const cycle = cardStep * cardCount;
    const minimum = -offset - cycle;
    const maximum = -offset;

    while (position <= minimum) position += cycle;
    while (position > maximum) position -= cycle;
  };

  const render = () => {
    track.style.transform = `translate3d(${position}px, 0, 0)`;
  };

  const measure = () => {
    const card = cards[0];
    if (!card) return;
    const styles = getComputedStyle(track);
    const gap = parseFloat(styles.columnGap || styles.gap || '0');
    cardStep = card.getBoundingClientRect().width + gap;
    offset = card.getBoundingClientRect().width / 2;
    position = -offset;
    render();
  };

  const frame = (now) => {
    const delta = Math.min(now - lastFrame, 48);
    lastFrame = now;

    if (now >= firstFrameHold && !hovering && !dragging && !reducedMotion.matches) {
      position -= speed * delta;
      normalisePosition();
      render();
    }

    requestAnimationFrame(frame);
  };

  const endDrag = (event) => {
    if (!dragging) return;
    dragging = false;
    viewport.dataset.dragging = 'false';
    hovering = viewport.matches(':hover');
    if (event?.pointerId !== undefined && viewport.hasPointerCapture(event.pointerId)) {
      viewport.releasePointerCapture(event.pointerId);
    }
  };

  viewport.addEventListener('mouseenter', () => { hovering = true; });
  viewport.addEventListener('mouseleave', () => {
    if (!dragging) hovering = false;
  });
  viewport.addEventListener('pointerdown', (event) => {
    if (event.pointerType === 'mouse' && event.button !== 0) return;
    dragging = true;
    hovering = true;
    moved = false;
    pointerStart = event.clientX;
    positionStart = position;
    viewport.dataset.dragging = 'true';
    viewport.setPointerCapture(event.pointerId);
  });
  viewport.addEventListener('pointermove', (event) => {
    if (!dragging) return;
    const distance = event.clientX - pointerStart;
    if (Math.abs(distance) > 6) moved = true;
    position = positionStart + distance;
    normalisePosition();
    render();
  });
  viewport.addEventListener('pointerup', endDrag);
  viewport.addEventListener('pointercancel', endDrag);
  viewport.addEventListener('click', (event) => {
    if (!moved) return;
    event.preventDefault();
    event.stopPropagation();
    moved = false;
  }, true);
  window.addEventListener('resize', measure, { passive: true });

  measure();
  requestAnimationFrame(frame);
}
