/* Progressive row editors. Keep the original POST fields and document format. */
(() => {
  'use strict';
  const schemas = {
    deliverables_table: ['Παραδοτέα', ['Παραδοτέο','Περιγραφή','Κριτήριο αποδοχής'], ['π.χ. Customer portal','π.χ. Διαχείριση λογαριασμού πελάτη','π.χ. Επιτυχής ολοκλήρωση UAT']],
    deliverables: ['Παραδοτέα', ['Κωδικός','Παραδοτέο','Περιγραφή','Ημερομηνία στόχος','Κριτήριο αποδοχής'], ['π.χ. D1','π.χ. REST API','π.χ. Διασύνδεση με ERP','π.χ. 30/11/2026','π.χ. Επιτυχία στα συμφωνημένα tests']],
    team: ['Ομάδα έργου', ['Ρόλος','Επίπεδο εμπειρίας','Απασχόληση','Αρμοδιότητα'], ['π.χ. Backend Engineer','π.χ. Senior','π.χ. 1 FTE ή 50%','π.χ. Ανάπτυξη και έλεγχος API']],
    timeline: ['Χρονοδιάγραμμα', ['Φάση','Διάρκεια','Ολοκλήρωση στόχος'], ['π.χ. Discovery','π.χ. 2 εβδομάδες','π.χ. 30/10/2026']],
    phases: ['Φάσεις έργου', ['Φάση','Περιγραφή','Διάρκεια'], ['π.χ. Phase 1','π.χ. Ανάλυση απαιτήσεων','π.χ. 2 εβδομάδες']],
    milestones: ['Ορόσημα', ['Ορόσημο','Περιγραφή','Ημερομηνία','Συνδεδεμένη πληρωμή'], ['π.χ. M1','π.χ. Ολοκλήρωση σχεδιασμού','π.χ. 30/10/2026','π.χ. 20% με την έγκριση']],
    rates: ['Χρεώσεις Time & Materials', ['Ρόλος','Χρέωση','Εκτιμώμενη δυναμικότητα'], ['π.χ. QA Engineer','π.χ. €60 / ώρα','π.χ. 80 ώρες / μήνα']],
    payment_schedule: ['Πρόγραμμα πληρωμών', ['Στάδιο πληρωμής','Ποσοστό','Ποσό'], ['π.χ. Mobilisation','π.χ. 20%','π.χ. €10.000']]
  };
  let sequence = 0;
  document.querySelectorAll('textarea[data-document-rows]').forEach(source => {
    const schema = schemas[source.name];
    const originalLabel = source.closest('label');
    if (!schema || !originalLabel || source.disabled || source.readOnly) return;
    const [title, columns, examples] = schema;
    const parse = value => value.split(/\r\n|[\n\r\v\f\u0085\u2028\u2029]/u).filter(line => line.trim() !== '').map(line => line.split('|').map(cell => cell.trim()));
    // Do not silently discard legacy columns that cannot fit the declared schema.
    if (parse(source.value).some(row => row.length > columns.length)) {
      const warning = document.createElement('p');
      warning.className = 'document-rows-warning';
      warning.textContent = 'Αυτό το παλιό πεδίο έχει περισσότερες στήλες από τις αναμενόμενες. Διατηρείται αυτούσιο για έλεγχο, χωρίς απώλεια στοιχείων.';
      originalLabel.after(warning);
      return;
    }
    const editor = document.createElement('fieldset');
    editor.className = 'document-rows field-full';
    const legend = document.createElement('legend'); legend.textContent = title;
    const help = document.createElement('p'); help.className = 'document-rows-help';
    help.id = 'document-rows-help-' + (++sequence);
    help.textContent = 'Κάθε γραμμή είναι μία εγγραφή. Συμπληρώστε τις στήλες και προσθέστε όσες γραμμές χρειάζεστε. Τα παραδείγματα δεν αποθηκεύονται.';
    const rows = document.createElement('div'); rows.className = 'document-rows-list';
    const actions = document.createElement('div'); actions.className = 'document-rows-actions';
    const add = document.createElement('button'); add.type = 'button'; add.className = 'button document-rows-add'; add.textContent = '+ Προσθήκη γραμμής';
    const status = document.createElement('span'); status.setAttribute('role','status');
    actions.append(add, status); editor.append(legend, help, rows, actions);
    originalLabel.replaceWith(editor); source.hidden = true; editor.append(source);
    let dirty = false;
    const values = () => [...rows.children].map(row => [...row.querySelectorAll('input')].map(input => input.value.trim()));
    const sync = () => { if (dirty) { source.value = values().filter(row => row.some(Boolean)).map(row => row.join(' | ')).join('\n'); source.dispatchEvent(new Event('change',{bubbles:true})); } };
    const renumber = () => [...rows.children].forEach((row,i) => {
      row.querySelector('.document-row-number').textContent = 'Γραμμή ' + (i + 1);
      row.querySelector('button').setAttribute('aria-label', 'Αφαίρεση γραμμής ' + (i + 1) + ' — ' + title);
      row.querySelectorAll('input').forEach((input,j) => input.setAttribute('aria-label', title + ', γραμμή ' + (i + 1) + ', ' + columns[j]));
    });
    function addRow(cells = [], focus = false) {
      const row = document.createElement('div'); row.className = 'document-row';
      const head = document.createElement('div'); head.className = 'document-row-heading';
      const number = document.createElement('strong'); number.className = 'document-row-number';
      const remove = document.createElement('button'); remove.type='button'; remove.className='document-row-remove'; remove.textContent='Αφαίρεση';
      head.append(number,remove); const grid=document.createElement('div'); grid.className='document-row-fields';
      grid.style.setProperty('--row-columns',columns.length);
      columns.forEach((column,i) => {
        const label=document.createElement('label'); label.textContent=column;
        const input=document.createElement('input'); input.type='text'; input.value=cells[i] || ''; input.placeholder=examples[i]; input.setAttribute('aria-describedby',help.id);
        input.addEventListener('input',() => { input.setCustomValidity(input.value.includes('|') ? 'Χρησιμοποιήστε ξεχωριστά πεδία αντί για κάθετη γραμμή (|).' : ''); dirty=true; sync(); });
        input.addEventListener('paste',event => {
          const text=event.clipboardData?.getData('text');
          if (text && /[|\r\n\v\f\u0085\u2028\u2029]/u.test(text)) {
            event.preventDefault(); status.textContent='Επικολλήστε μία τιμή ανά πεδίο, χωρίς κάθετες γραμμές ή αλλαγές γραμμής.';
          }
        });
        label.append(input); grid.append(label);
      });
      remove.addEventListener('click',() => {
        if ([...row.querySelectorAll('input')].some(input=>input.value.trim()) && !window.confirm('Να αφαιρεθεί αυτή η γραμμή; Η αλλαγή οριστικοποιείται με την αποθήκευση του εγγράφου.')) return;
        row.remove(); dirty=true; if (!rows.children.length) addRow(); renumber(); sync(); add.focus(); status.textContent='Η γραμμή αφαιρέθηκε. Αποθηκεύστε το έγγραφο για να κρατήσετε την αλλαγή.';
      });
      row.append(head,grid); rows.append(row); renumber(); if (focus) grid.querySelector('input').focus();
    }
    const initial=parse(source.value); (initial.length ? initial : [[]]).forEach(cells=>addRow(cells));
    add.addEventListener('click',()=>{addRow([],true); status.textContent='Προστέθηκε νέα γραμμή.';});
    source.form?.addEventListener('submit',sync);
    source.form?.addEventListener('reset',()=>setTimeout(()=>{rows.replaceChildren(); const reset=parse(source.value); (reset.length?reset:[[]]).forEach(cells=>addRow(cells)); dirty=false; status.textContent='';},0));
  });
})();
