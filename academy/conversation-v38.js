/* Text-only conversation. No microphone, speech synthesis, audio or external service. */
(() => {
  'use strict';
  const thread=document.querySelector('[data-sim-thread]');
  if(thread&&Number(thread.dataset.turnCount)>0)thread.scrollTop=thread.scrollHeight;
  if(location.hash==='#call-next'){
    const next=document.getElementById('call-next');
    if(next){next.tabIndex=-1;next.focus({preventScroll:true});}
  }
})();
