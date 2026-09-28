(() => {
  'use strict';
  const root = document.querySelector('[data-lab-root]');
  if (!root) return;
  const filters = root.querySelector('[data-lab-filter]');
  if (filters) {
    filters.hidden = false;
    filters.addEventListener('click', event => {
      const button = event.target.closest('[data-company]');
      if (!button) return;
      filters.querySelectorAll('button').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
      root.querySelectorAll('[data-lab-company]').forEach(card => {
        card.hidden = button.dataset.company !== 'all' && card.dataset.labCompany !== 'both' && card.dataset.labCompany !== button.dataset.company;
      });
    });
  }
  const form = root.querySelector('[data-lab-form]');
  if (!form) return;
  const source = root.querySelector('.lab-inbox');
  const slot = root.querySelector('[data-lab-source-slot]');
  const dialog = root.querySelector('[data-lab-dialog]');
  if(dialog?.showModal)root.classList.add('has-reference-dialog');
  const search = root.querySelector('[data-lab-search]');
  const normalize = text => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('el').replace(/ς/g, 'σ');
  root.querySelector('[data-lab-search-box]').hidden = false;
  search.addEventListener('input', () => {
    const term = normalize(search.value.trim()); let count = 0;
    source.querySelectorAll('.lab-message').forEach(message => { const found = normalize(message.textContent).includes(term); message.hidden = !found; if(found) count++; });
    source.querySelector('[data-lab-search-status]').textContent = term ? `${count} αποσπάσματα · σβήσε την αναζήτηση για όλη τη συνομιλία` : '';
  });
  let sourceScroll = 0;
  const openReference = () => {
    if (!dialog?.showModal) { source.scrollIntoView({block:'start'}); return; }
    sourceScroll = source.scrollTop;
    dialog.querySelector('[data-lab-dialog-body]').append(source);
    dialog.showModal(); source.scrollTop = sourceScroll;
  };
  root.querySelector('[data-lab-reference]').addEventListener('click', openReference);
  root.querySelector('.lab-mobile-nav a').addEventListener('click', event => { if(dialog?.showModal){event.preventDefault();openReference();} });
  root.querySelector('[data-lab-close]').addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', () => { sourceScroll=source.scrollTop;slot.append(source);source.scrollTop=sourceScroll; });
  root.querySelector('[data-lab-toolbox]').hidden = false;
  const panels = [...form.querySelectorAll('[data-lab-panel]')];
  const steps = form.querySelector('[data-lab-steps]');
  const controls = form.querySelector('[data-lab-controls]');
  const previous = form.querySelector('[data-lab-prev]');
  const next = form.querySelector('[data-lab-next]');
  const viewButton = form.querySelector('[data-lab-view]');
  const uiKey = 'academy-lab-view:' + form.elements.namedItem('submission_key').value;
  let position = 0, allFields = true;
  try { const state=JSON.parse(sessionStorage.getItem(uiKey));if(state){position=Number.isInteger(state.position)?state.position:0;allFields=state.allFields!==false;} } catch {}
  const remember = () => { try {sessionStorage.setItem(uiKey,JSON.stringify({position,allFields}));} catch {} };
  const updateFilled = () => {
    const fields=[...form.querySelectorAll('[name^="answers["]')];
    form.querySelector('[data-lab-filled]').textContent=`Συμπληρωμένα: ${fields.filter(field=>field.value.trim()).length}/${fields.length} · τα άγνωστα μπορούν να μείνουν κενά`;
    panels.forEach((panel,i)=>{const inputs=[...panel.querySelectorAll('[name^="answers["]')];const button=steps.querySelectorAll('button')[i];button.dataset.filled=`${inputs.filter(field=>field.value.trim()).length}/${inputs.length}`;});
  };
  function show(index, focus = false) {
    position = Math.max(0, Math.min(index, panels.length - 1));
    panels.forEach((panel, i) => { panel.hidden = !allFields && i !== position; });
    steps.querySelectorAll('button').forEach((button, i) => {
      if (i === position) button.setAttribute('aria-current', 'step'); else button.removeAttribute('aria-current');
    });
    previous.disabled = position === 0;
    next.disabled = position === panels.length - 1;
    form.querySelector('[data-lab-position]').textContent = `${position + 1} / ${panels.length}`;
    viewButton.textContent=allFields?'Προβολή: όλα τα πεδία':'Προβολή: ανά βήμα';viewButton.setAttribute('aria-pressed',String(allFields));
    remember();
    if (focus) {panels[position].scrollIntoView({block:'start'});panels[position].querySelector('input,select,textarea')?.focus({preventScroll:true});}
  }
  steps.hidden = false; controls.hidden = panels.length < 2;
  steps.addEventListener('click', event => { const button = event.target.closest('[data-lab-step]'); if (button) show(Number(button.dataset.labStep), true); });
  previous.addEventListener('click', () => show(position - 1, true));
  next.addEventListener('click', () => show(position + 1, true));
  viewButton.addEventListener('click',()=>{allFields=!allFields;show(position);});
  show(position);updateFilled();
  // Native email/date validation still works when a step is hidden.
  form.addEventListener('invalid', event => {
    const panel = event.target.closest('[data-lab-panel]');
    if (panel) show(Number(panel.dataset.labPanel));
  }, true);
  const input = name => form.elements.namedItem(`answers[${name}]`);
  const qty = input('panel_quantity'), unit = input('asking_price'), total = input('asking_total_price');
  let priceSource = 'unit';
  const number = value => /^\d{1,9}([.,]\d{1,2})?$/.test(value.trim()) ? Number(value.trim().replace(',', '.')) : NaN;
  const calculate = changed => {
    if (!qty || !unit || !total) return;
    if (changed === unit) priceSource = 'unit';
    if (changed === total) priceSource = 'total';
    const count = number(qty.value);
    if (!Number.isInteger(count) || count <= 0) return;
    const source = priceSource === 'total' ? total : unit;
    const value = number(source.value);
    if (!Number.isFinite(value)) return;
    (priceSource === 'total' ? unit : total).value = (priceSource === 'total' ? value / count : value * count).toFixed(2);
  };
  const status = form.querySelector('[data-lab-save-status]');
  let timer, dirty = false, revision = 0, pending = Promise.resolve(), submitting = false;
  const save = () => {
    if (submitting || !dirty) return;
    const version = revision;
    const data = new FormData(form); data.set('action', 'save');
    status.textContent = 'Αποθήκευση…';
    pending = pending.then(async () => {
      // The named submit buttons "action" shadow form.action in the DOM.
      const response = await fetch(form.getAttribute('action'), {method: 'POST', body: data, credentials: 'same-origin', headers: {'X-Lab-Save': '1'}});
      const contentType = response.headers.get('content-type') || '';
      if (!contentType.includes('application/json')) throw new Error('Η συνεδρία έληξε. Κάνε ξανά σύνδεση· κράτησε πρώτα τις απαντήσεις σου.');
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || 'Η αποθήκευση δεν ολοκληρώθηκε.');
      if (version === revision) { dirty = false; status.textContent = '✓ Το πρόχειρό σου αποθηκεύτηκε'; }
    }).catch(error => { status.textContent = `Δεν αποθηκεύτηκε: ${error.message} Χρησιμοποίησε «Αποθήκευση» για νέα προσπάθεια.`; });
  };
  form.addEventListener('input', event => {
    calculate(event.target);updateFilled(); revision++; dirty = true;
    status.textContent = 'Μη αποθηκευμένες αλλαγές…';
    clearTimeout(timer); timer = setTimeout(save, 900);
  });
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (submitting) return;
    clearTimeout(timer);
    const submitter = event.submitter;
    submitting = true;status.textContent = 'Ολοκλήρωση αποθήκευσης…';
    await pending;
    dirty = false;
    // Native validation has already run. A nested requestSubmit() can be ignored
    // by the browser while its original submit algorithm is still unwinding.
    const action = document.createElement('input');
    action.type = 'hidden'; action.name = 'action';
    action.value = submitter?.value === 'submit' ? 'submit' : 'save';
    form.append(action);
    HTMLFormElement.prototype.submit.call(form);
  });
  window.addEventListener('beforeunload', event => { if (dirty && !submitting) { event.preventDefault(); event.returnValue = ''; } });
})();
