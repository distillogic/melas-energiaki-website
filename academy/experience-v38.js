/* Local presentation state only; quiz attempts and permissions remain server-authoritative. */
(() => {
  'use strict';
  const body=document.body;if(!body.classList.contains('school-app'))return;
  body.classList.add('ux-ready');
  const menu=document.querySelector('[data-school-menu]'),sidebar=document.querySelector('.school-sidebar'),main=document.querySelector('.school-main'),bar=document.querySelector('.school-topbar');
  const mobile=matchMedia('(max-width:980px)');
  function state(open,focus=true){
    open=mobile.matches&&open;body.classList.toggle('school-menu-open',open);menu.setAttribute('aria-expanded',String(open));
    sidebar.inert=mobile.matches&&!open;main.inert=open;bar.inert=open;
    if(focus){if(open)sidebar.querySelector('[data-school-close]')?.focus();else menu.focus();}
  }
  menu.addEventListener('click',()=>state(true));document.querySelectorAll('[data-school-close]').forEach(b=>b.addEventListener('click',()=>state(false)));
  mobile.addEventListener('change',()=>state(false,false));state(false,false);
  sidebar.addEventListener('keydown',e=>{
    if(!body.classList.contains('school-menu-open'))return;
    if(e.key==='Escape'){e.preventDefault();state(false);return;}
    if(e.key==='Tab'){const nodes=[...sidebar.querySelectorAll('a,button')].filter(x=>x.getClientRects().length);const first=nodes[0],last=nodes.at(-1);if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus()}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus()}}
  });
  const outline=document.querySelector('.ac-lesson-outline');
  if(outline){
    outline.id='lesson-contents';if(innerWidth<=1280)outline.open=false;
    const tools=document.createElement('nav');tools.className='school-reading-tools';tools.setAttribute('aria-label','Γρήγορη πλοήγηση μαθήματος');
    for(const [label,target] of [['Περιεχόμενα μαθήματος','#lesson-contents'],['Στο quiz κατανόησης ↓','#quiz']]){const a=document.createElement('a');a.textContent=label;a.href=target;if(target==='#lesson-contents')a.addEventListener('click',()=>{outline.open=true});tools.append(a)}
    document.querySelector('.ac-learning-layout')?.before(tools);
  }
  document.querySelectorAll('.ac-quiz-form').forEach(form=>{
    const fields=[...form.querySelectorAll('fieldset')];if(!fields.length)return;
    const box=document.createElement('div');box.className='school-quiz-progress';
    const status=document.createElement('span');status.setAttribute('role','status');status.setAttribute('aria-live','polite');
    const progress=document.createElement('progress');progress.max=fields.length;progress.setAttribute('aria-label','Απαντημένες ερωτήσεις, όχι βαθμολογία');box.append(status,progress);form.prepend(box);
    const update=()=>{const count=fields.filter(f=>f.querySelector('input[type=radio]:checked')).length;progress.value=count;status.textContent=count+' / '+fields.length+' ερωτήσεις απαντημένες';};form.addEventListener('change',update);update();
  });
  document.querySelectorAll('.ac-table-wrap,.lab-table-scroll').forEach(el=>{el.tabIndex=0;el.setAttribute('role','region');el.setAttribute('aria-label','Πίνακας με οριζόντια κύλιση')});
})();
