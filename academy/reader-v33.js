(() => {
  'use strict';
  const outline = document.querySelector('.ac-lesson-outline nav');
  if (!outline) return;
  // Continuous reading: search filters only the index, never hides teaching material.
  const normalize = s => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('el');
  const label = document.createElement('label'); label.className = 'reader-search'; label.textContent = 'Αναζήτηση μέσα στη θεωρία';
  const input = document.createElement('input'); input.type = 'search'; input.placeholder = 'Θέμα ή λέξη…'; label.append(input); outline.prepend(label);
  const empty = document.createElement('p'); empty.textContent = 'Δεν βρέθηκε ενότητα.'; empty.hidden = true; outline.append(empty);
  input.addEventListener('input', () => {
    const term = normalize(input.value.trim()); let count = 0;
    outline.querySelectorAll('a[data-ac-jump]').forEach(a => {
      const section = document.getElementById(a.hash.slice(1));
      const match = !term || normalize(section?.textContent || a.textContent).includes(term);
      a.closest('li').hidden = !match; if (match) count++;
    }); empty.hidden = count > 0;
  });
})();
