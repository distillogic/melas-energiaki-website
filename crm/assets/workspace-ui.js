/* Progressive UI only. No authorization, signature or submission logic. */
(() => {
  'use strict';
  document.querySelectorAll('.nav-group').forEach((group,index)=>{
    const toggle=group.querySelector('.nav-group-label'),children=group.querySelector('.nav-children');
    if(!toggle||!children)return;
    children.id=`workspace-nav-${index}`;
    toggle.setAttribute('role','button');toggle.tabIndex=0;
    toggle.setAttribute('aria-controls',children.id);
    const set=open=>{toggle.setAttribute('aria-expanded',String(open));children.hidden=!open;};
    set(group.classList.contains('open')||Boolean(children.querySelector('.active')));
    toggle.addEventListener('click',()=>set(children.hidden));
    toggle.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();set(children.hidden);}});
  });
  document.querySelectorAll('.sidebar a.active').forEach(a=>a.setAttribute('aria-current','page'));
  document.querySelectorAll('input[type="password"]').forEach(input=>{
    const wrap=document.createElement('span');wrap.className='password-field';input.before(wrap);wrap.append(input);
    const button=document.createElement('button');button.type='button';button.className='password-toggle';button.textContent='Εμφάνιση';button.setAttribute('aria-label','Εμφάνιση κωδικού');button.setAttribute('aria-pressed','false');
    button.addEventListener('click',event=>{event.preventDefault();const reveal=input.type==='password';input.type=reveal?'text':'password';button.textContent=reveal?'Απόκρυψη':'Εμφάνιση';button.setAttribute('aria-label',reveal?'Απόκρυψη κωδικού':'Εμφάνιση κωδικού');button.setAttribute('aria-pressed',String(reveal));});wrap.append(button);
  });
  document.querySelectorAll('input[name="approval_code"]').forEach(input=>{
    if(!input.labels?.length&&!input.hasAttribute('aria-label'))input.setAttribute('aria-label','Εξαψήφιος κωδικός έγκρισης');
    // Keep one real input for paste, autofill, keyboard and the existing server checks.
    input.autocomplete='one-time-code';
  });
  document.querySelectorAll('.alert').forEach(alert=>{
    alert.setAttribute('role',alert.classList.contains('error')?'alert':'status');
    const button=document.createElement('button');button.type='button';button.className='alert-dismiss';button.textContent='×';button.setAttribute('aria-label','Κλείσιμο μηνύματος');button.addEventListener('click',()=>alert.remove());alert.append(button);
  });
  document.querySelectorAll('.table-wrap').forEach(wrap=>{wrap.tabIndex=0;wrap.setAttribute('role','region');wrap.setAttribute('aria-label','Πίνακας δεδομένων — οριζόντια κύλιση όπου χρειάζεται');});
  const menu=document.querySelector('[data-menu]');
  const syncMenu=()=>menu?.setAttribute('aria-expanded',String(document.body.classList.contains('menu-open')));
  syncMenu();new MutationObserver(syncMenu).observe(document.body,{attributes:true,attributeFilter:['class']});
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&document.body.classList.contains('menu-open')){document.body.classList.remove('menu-open');menu?.focus();}});
  const trigger=document.querySelector('.search-button');
  if(!trigger||typeof HTMLDialogElement==='undefined')return;
  const dialog=document.createElement('dialog');dialog.className='workspace-command';dialog.setAttribute('aria-labelledby','command-title');
  dialog.innerHTML='<div class="command-header"><h2 id="command-title">Αναζήτηση & πλοήγηση</h2><button type="button" aria-label="Κλείσιμο αναζήτησης">Esc</button></div><div class="command-search"><input type="search" aria-label="Κωδικός αιτήματος ή σελίδα" placeholder="π.χ. DL-B7B37D4E ή Πελάτες" autocomplete="off"></div><div class="command-results" aria-live="polite"></div>';
  document.body.append(dialog);
  const input=dialog.querySelector('input'),results=dialog.querySelector('.command-results');
  // Navigation is sourced only from links the server already rendered for this user.
  const links=[...document.querySelectorAll('.sidebar nav a')].map(a=>({label:a.textContent.trim(),href:a.href}));
  const normalize=s=>s.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase('el');
  let searchVersion=0,request;
  const notice=text=>{const p=document.createElement('p');p.textContent=text;results.replaceChildren(p);};
  async function render(){
    const version=++searchVersion;request?.abort();results.replaceChildren();
    const query=input.value.trim(),endpoint=trigger.dataset.customerSearch;
    if(endpoint && /^DL-[A-F0-9]{8}$/i.test(query)){
      request=new AbortController();notice('Αναζήτηση πελάτη…');
      try{
        const url=new URL(endpoint,location.href);url.searchParams.set('q',query.toUpperCase());
        const response=await fetch(url,{credentials:'same-origin',signal:request.signal,headers:{Accept:'application/json'}});
        if(!response.ok)throw Error('search');const data=await response.json();
        if(version!==searchVersion)return;
        results.replaceChildren();
        for(const item of data.results || []){const target=new URL(item.href,location.href);if(target.origin!==location.origin)continue;const a=document.createElement('a');a.href=target.href;a.textContent=item.label;const small=document.createElement('small');small.textContent=item.reference+' · Καρτέλα πελάτη';a.append(small);results.append(a);}
        if(!results.children.length)notice('Δεν βρέθηκε ενεργός πελάτης για αυτόν τον κωδικό.');
      }catch(error){if(error.name!=='AbortError'&&version===searchVersion)notice('Η αναζήτηση δεν ολοκληρώθηκε. Δοκιμάστε ξανά ή ελέγξτε τη σύνδεσή σας.');}
      return;
    }
    const found=links.filter(x=>normalize(x.label).includes(normalize(query)));for(const item of found){const a=document.createElement('a');a.href=item.href;a.textContent=item.label;results.append(a);}
    if(!found.length)notice(/^DL-/i.test(query)?'Συμπληρώστε ολόκληρο τον κωδικό, π.χ. DL-B7B37D4E.':'Δεν βρέθηκε σελίδα. Δοκιμάστε π.χ. «πελάτες».');
  }
  const open=()=>{if(dialog.open)return;input.value='';render();dialog.showModal();input.focus();};
  trigger.addEventListener('click',event=>{event.preventDefault();open();});
  const keyboard=trigger.querySelector('kbd');if(keyboard)keyboard.textContent='Ctrl K';
  trigger.setAttribute('aria-label','Αναζήτηση πελάτη με κωδικό ή σελίδας');
  document.addEventListener('keydown',event=>{if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='k'){event.preventDefault();open();}});
  dialog.querySelector('button').addEventListener('click',()=>dialog.close());dialog.addEventListener('close',()=>trigger.focus());
  dialog.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();event.stopPropagation();dialog.close();}});
  dialog.addEventListener('click',event=>{if(event.target===dialog){const r=dialog.getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom)dialog.close();}});
  input.addEventListener('input',render);input.addEventListener('keydown',event=>{if(event.key==='ArrowDown'){event.preventDefault();results.querySelector('a')?.focus();}if(event.key==='Enter'){event.preventDefault();results.querySelector('a')?.click();}});
})();
