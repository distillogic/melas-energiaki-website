(() => {
  'use strict';
  const source=document.querySelector('[data-call-speech]');if(!source)return;
  const play=document.querySelector('[data-call-play]'),stop=document.querySelector('[data-call-stop]');
  const auto=document.querySelector('[data-call-auto]'),select=document.querySelector('[data-call-voice]');
  const rate=document.querySelector('[data-call-rate]'),status=document.querySelector('[data-call-voice-status]');
  const synth=window.speechSynthesis;
  const read=key=>{try{return localStorage.getItem(key);}catch(_){return null;}};
  const save=(key,value)=>{try{localStorage.setItem(key,value);}catch(_){}};
  auto.checked=read('academy-sim-voice')==='on';
  if(['0.85','0.98','1.1'].includes(read('academy-sim-rate')))rate.value=read('academy-sim-rate');
  if(!synth||!window.SpeechSynthesisUtterance){
    [play,stop,auto,select,rate].forEach(el=>{el.disabled=true;});
    status.textContent='Η συσκευή δεν υποστηρίζει σύνθεση ομιλίας. Η άσκηση και η βαθμολογία λειτουργούν πλήρως με κείμενο.';return;
  }
  // The browser exposes neither gender nor a quality guarantee. These are
  // preferences based on recognizable names, not an invented gender property.
  const female=v=>/melina|μελίνα|athina|athena|αθηνά|female|γυναικ/i.test(v.name);
  const ranking=v=>(female(v)?100:0)+(/natural|enhanced|premium|φυσικ/i.test(v.name)?10:0);
  let voices=[],generation=0,utterance=null,timer=null,autoStarted=false;
  function cancel(){generation++;clearTimeout(timer);synth.cancel();utterance=null;}
  function refresh(){
    const wanted=select.value||read('academy-sim-voice-id');
    voices=synth.getVoices().filter(v=>/^el(?:-|_|$)/i.test(v.lang)&&v.localService===true).sort((a,b)=>ranking(b)-ranking(a)||a.name.localeCompare(b.name));
    select.replaceChildren();
    for(const v of voices){const o=document.createElement('option');o.value=v.voiceURI;o.textContent=v.name+(female(v)?' · προτίμηση γυναικείας':'');select.append(o);}
    if(voices.some(v=>v.voiceURI===wanted))select.value=wanted;
    if(!voices.length){
      const o=document.createElement('option');o.textContent='Δεν διατίθεται τοπική ελληνική φωνή';o.value='';select.append(o);
      status.textContent='Δεν βρέθηκε τοπική ελληνική φωνή. Δεν στέλνουμε το κείμενο σε εξωτερική υπηρεσία. Μπορείς να συνεχίσεις με τους υπότιτλους.';
    }else{
      const v=voices.find(v=>v.voiceURI===select.value)||voices[0];
      status.textContent=female(v)?'Ελληνική γυναικεία φωνή: '+v.name+'. Δοκίμασε την ακρόαση.':'Διαθέσιμη φωνή: '+v.name+'. Η συσκευή δεν εκθέτει αναγνωρίσιμη γυναικεία φωνή. Μπορείς να επιλέξεις άλλη διαθέσιμη ή να συνεχίσεις με κείμενο.';
    }
    play.disabled=select.disabled=!voices.length;
  }
  function speak(){
    if(!voices.length){refresh();if(!voices.length)return;}
    cancel();const current=generation,voice=voices.find(v=>v.voiceURI===select.value)||voices[0];
    const parts=[...source.querySelectorAll('p')].map(p=>p.innerText.trim()).filter(Boolean);let i=0;
    function next(){
      if(current!==generation)return;
      if(i>=parts.length){status.textContent='Επίλεξε την απάντησή σου ή άκου ξανά.';return;}
      utterance=new SpeechSynthesisUtterance(parts[i++]);utterance.voice=voice;utterance.lang=voice.lang;
      utterance.rate=Number(rate.value)||.98;utterance.pitch=1;
      utterance.onstart=()=>{if(current===generation)status.textContent='Μιλά η εικονική επαφή · '+voice.name+'…';};
      utterance.onend=()=>{if(current===generation)timer=setTimeout(next,240);};
      utterance.onerror=e=>{if(current===generation&&!['interrupted','canceled'].includes(e.error))status.textContent='Η φωνή δεν ξεκίνησε. Πάτησε ξανά ακρόαση ή συνέχισε με το κείμενο.';};
      synth.speak(utterance);
    }
    next();
  }
  play.addEventListener('click',()=>{refresh();speak();});
  stop.addEventListener('click',()=>{cancel();status.textContent='Η ακρόαση σταμάτησε.';});
  auto.addEventListener('change',()=>{save('academy-sim-voice',auto.checked?'on':'off');if(auto.checked)speak();else cancel();});
  select.addEventListener('change',()=>{save('academy-sim-voice-id',select.value);cancel();status.textContent='Η επιλογή αποθηκεύτηκε. Πάτησε ακρόαση για δοκιμή.';});
  rate.addEventListener('change',()=>{save('academy-sim-rate',rate.value);cancel();});
  function changed(){refresh();if(auto.checked&&!autoStarted&&voices.length){autoStarted=true;speak();}}
  synth.addEventListener('voiceschanged',changed);
  window.addEventListener('pagehide',()=>{cancel();synth.removeEventListener('voiceschanged',changed);});
  document.addEventListener('visibilitychange',()=>{if(document.hidden)cancel();});changed();
})();
