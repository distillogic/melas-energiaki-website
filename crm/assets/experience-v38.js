/* Progressive navigation only. No network calls, persistence of business data or automatic submissions. */
(() => {
  'use strict';
  const page=document.querySelector('#workspace-content');if(!page)return;
  const params=new URLSearchParams(location.search);
  document.querySelectorAll('.pipeline-strip a').forEach(link=>{
    if(params.get('status')&&new URL(link.href).searchParams.get('status')===params.get('status'))link.setAttribute('aria-current','true');
  });
  const form=document.querySelector('#lead-form');
  if(form){
    const sections=[...form.querySelectorAll(':scope > .form-section')];
    if(sections.length>1){
      const nav=document.createElement('nav');nav.className='ux-section-nav';nav.setAttribute('aria-label','Ενότητες καταχώρισης ευκαιρίας');
      sections.forEach((section,i)=>{
        section.id=section.id||'lead-section-'+(i+1);section.classList.add('ux-section-target');
        const link=document.createElement('a');link.href='#'+section.id;
        const number=document.createElement('b');number.textContent=String(i+1).padStart(2,'0');
        link.append(number,document.createTextNode(section.querySelector('h2')?.textContent||'Ενότητα '+(i+1)));nav.append(link);
      });form.before(nav);
      if('IntersectionObserver' in window){
        const observer=new IntersectionObserver(entries=>{for(const entry of entries)if(entry.isIntersecting){nav.querySelectorAll('a').forEach(a=>a.removeAttribute('aria-current'));nav.querySelector('a[href="#'+entry.target.id+'"]')?.setAttribute('aria-current','location');}},{rootMargin:'-140px 0px -55% 0px',threshold:0});sections.forEach(s=>observer.observe(s));
      }
      // All fields remain in the DOM and visible: native/server validation is unchanged.
    }
  }
  const top=document.createElement('a');top.className='ux-back-top';top.href='#workspace-content';top.setAttribute('aria-label','Επιστροφή στην αρχή της σελίδας');
  top.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 12 6-6 6 6M12 6v13"/></svg>';document.body.append(top);
  const sync=()=>top.classList.toggle('is-visible',scrollY>500);addEventListener('scroll',sync,{passive:true});sync();
  // Keep keyboard focus inside the small-screen navigation while it is open.
  const sidebar=document.querySelector('.sidebar'),trigger=document.querySelector('[data-menu]');
  if(sidebar&&trigger){
    const syncMenu=()=>{const mobile=matchMedia('(max-width:980px)').matches,open=document.body.classList.contains('menu-open');sidebar.inert=mobile&&!open;if(mobile&&open){document.querySelector('.main').inert=true}else document.querySelector('.main').inert=false;};
    let wasOpen=false;
    new MutationObserver(()=>{syncMenu();const open=document.body.classList.contains('menu-open');if(open&&matchMedia('(max-width:980px)').matches)sidebar.querySelector('a')?.focus();else if(wasOpen)trigger.focus();wasOpen=open;}).observe(document.body,{attributes:true,attributeFilter:['class']});
    addEventListener('resize',syncMenu);syncMenu();
    sidebar.addEventListener('keydown',e=>{if(e.key!=='Tab'||!document.body.classList.contains('menu-open'))return;const nodes=[...sidebar.querySelectorAll('a,button,summary,[tabindex="0"]')].filter(x=>x.getClientRects().length);const first=nodes[0],last=nodes.at(-1);if(e.shiftKey&&document.activeElement===first){e.preventDefault();last?.focus()}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first?.focus()}});
  }
})();
