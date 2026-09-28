(() => {
  'use strict';
  const academy = document.querySelector('[data-academy]');
  if (!academy) return;
  // Keep the lesson within easy reach on mobile; learners can expand any pillar.
  if (window.matchMedia('(max-width:1050px)').matches) {
    academy.querySelectorAll('.ac-lesson-outline, .ac-course-library').forEach(group => { group.open = false; });
  }
  const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('el');
  const search = academy.querySelector('[data-ac-search]');
  search?.addEventListener('input', () => {
    const query = normalize(search.value.trim());
    let visible = 0;
    academy.querySelectorAll('[data-ac-searchable]').forEach(row => {
      row.hidden = !normalize(row.dataset.acSearchable || '').includes(query);
      if (!row.hidden) visible++;
    });
    const empty = academy.querySelector('[data-ac-empty]');
    if (empty) empty.hidden = visible > 0;
  });
  academy.querySelector('[data-ac-print]')?.addEventListener('click', () => window.print());
  // Reading position is local UI only: it never awards completion or writes progress.
  const reading = academy.querySelector('[data-ac-reading-article]');
  if (reading) {
    const sections = [...academy.querySelectorAll('[data-ac-reading-section]')];
    const jumps = [...academy.querySelectorAll('[data-ac-jump]')];
    const meter = academy.querySelector('[data-ac-reading-position]');
    const bar = academy.querySelector('[data-ac-reading-bar]');
    const percent = academy.querySelector('[data-ac-reading-percent]');
    let scheduled = false;
    const updateReading = () => {
      scheduled = false;
      const visibleSections = sections.filter(s => !s.hidden);
      const first = visibleSections[0], last = visibleSections[visibleSections.length - 1];
      if (!first || !last) return;
      const start = first.getBoundingClientRect().top + window.scrollY;
      const end = last.getBoundingClientRect().bottom + window.scrollY;
      const value = Math.max(0, Math.min(100, Math.round((window.scrollY + 140 - start) / Math.max(1, end - start - window.innerHeight + 140) * 100)));
      bar.value = value;
      percent.textContent = `${value}%`;
      let current = first.id;
      for (const section of visibleSections) if (section.getBoundingClientRect().top <= 190) current = section.id;
      jumps.forEach(link => {
        if (link.hash === `#${current}`) link.setAttribute('aria-current', 'location');
        else link.removeAttribute('aria-current');
      });
    };
    const scheduleReading = () => { if (!scheduled) { scheduled = true; requestAnimationFrame(updateReading); } };
    meter.hidden = false;
    window.addEventListener('scroll', scheduleReading, { passive: true });
    window.addEventListener('resize', scheduleReading);
    academy.addEventListener('toggle', scheduleReading, true);
    if ('ResizeObserver' in window) new ResizeObserver(scheduleReading).observe(reading);
    updateReading();
  }
  academy.querySelectorAll('[data-ac-lab]').forEach(lab => {
    const quantity = lab.querySelector('[data-ac-quantity]');
    const price = lab.querySelector('[data-ac-price]');
    const approved = lab.querySelector('[data-ac-approved]');
    const output = lab.querySelector('[data-ac-output]');
    const euro = new Intl.NumberFormat('el-GR', { style: 'currency', currency: 'EUR' });
    const update = () => {
      const q = quantity.valueAsNumber;
      const p = price.valueAsNumber;
      if (!quantity.validity.valid || !price.validity.valid || !Number.isSafeInteger(q) || q < 0 || !Number.isFinite(p) || p < 0) {
        output.textContent = 'Συμπλήρωσε έγκυρη ακέραιη ποσότητα και μη αρνητική τιμή με έως δύο δεκαδικά.';
        return;
      }
      const priceCents = Math.round(p * 100);
      const commissionCents = q * 60;
      const qualifiedCents = approved.checked ? 8000 : 0;
      output.textContent = `Υποθετική αξία αγοράς: ${euro.format(q * priceCents / 100)} · Προμήθεια πάνελ: ${euro.format(commissionCents / 100)} · Αμοιβή lead: ${euro.format(qualifiedCents / 100)} · Σύνολο συνεργάτη: ${euro.format((commissionCents + qualifiedCents) / 100)}.`;
    };
    lab.addEventListener('input', update);
    update();
  });
  academy.querySelectorAll('.ac-quiz-form').forEach(form => {
    form.addEventListener('submit', () => {
      const button = form.querySelector('button[type="submit"]');
      if (button) { button.disabled = true; button.textContent = 'Αποθήκευση προσπάθειας…'; }
    });
  });
  // A browser back/forward cache must not leave a valid form permanently disabled.
  window.addEventListener('pageshow', event => {
    if (event.persisted) academy.querySelectorAll('.ac-quiz-form button[type="submit"]').forEach(button => {
      button.disabled = false;
      button.textContent = 'Υποβολή απαντήσεων';
    });
  });
  if (location.hash === '#quiz-result') academy.querySelector('#quiz-result')?.focus({ preventScroll: true });
})();
