(() => {
  'use strict';
  const source = document.querySelector('[data-call-speech]');
  if (!source) return;
  const play = document.querySelector('[data-call-play]');
  const stop = document.querySelector('[data-call-stop]');
  const auto = document.querySelector('[data-call-auto]');
  const status = document.querySelector('[data-call-voice-status]');
  const synth = window.speechSynthesis;
  let voices = [], utterance = null, autoStarted = false;
  const save = value => { try { localStorage.setItem('academy-sim-voice', value); } catch (_) {} };
  try { auto.checked = localStorage.getItem('academy-sim-voice') === 'on'; } catch (_) {}
  if (!synth || !window.SpeechSynthesisUtterance) {
    play.disabled = stop.disabled = auto.disabled = true;
    status.textContent = 'Ο browser δεν υποστηρίζει ανάγνωση φωνής. Συνέχισε κανονικά με τους υπότιτλους — η βαθμολογία δεν επηρεάζεται.';
    return;
  }
  function speak() {
    const voice = voices.find(v => /^el(?:-|_|$)/i.test(v.lang) && v.localService);
    if (!voice) {
      status.textContent = 'Δεν βρέθηκε τοπική ελληνική φωνή. Μπορείς να εγκαταστήσεις ελληνική φωνή στη συσκευή σου ή να συνεχίσεις με κείμενο. Δεν χρησιμοποιείται εξωτερική υπηρεσία φωνής.';
      return;
    }
    synth.cancel();
    utterance = new SpeechSynthesisUtterance(source.innerText);
    utterance.voice = voice; utterance.lang = voice.lang; utterance.rate = 0.94;
    utterance.onstart = () => { status.textContent = 'Μιλά ο εικονικός πελάτης…'; };
    utterance.onend = () => { status.textContent = 'Μπορείς να επιλέξεις την απάντησή σου ή να ακούσεις ξανά.'; };
    utterance.onerror = event => {
      if (!['interrupted', 'canceled'].includes(event.error)) status.textContent = 'Η αυτόματη φωνή δεν ξεκίνησε. Πάτησε «Άκου τον πελάτη» ή συνέχισε με το κείμενο.';
    };
    synth.speak(utterance);
  }
  function updateVoices() {
    voices = synth.getVoices();
    if (auto.checked && !autoStarted && voices.some(v => /^el(?:-|_|$)/i.test(v.lang) && v.localService)) {
      autoStarted = true; speak();
    }
  }
  play.addEventListener('click', () => { updateVoices(); speak(); });
  stop.addEventListener('click', () => { synth.cancel(); status.textContent = 'Η φωνή σταμάτησε. Συνέχισε με τους υπότιτλους ή πάτησε ξανά ακρόαση.'; });
  auto.addEventListener('change', () => { save(auto.checked ? 'on' : 'off'); if (auto.checked) speak(); else synth.cancel(); });
  synth.addEventListener('voiceschanged', updateVoices);
  window.addEventListener('pagehide', () => { synth.cancel(); synth.removeEventListener('voiceschanged', updateVoices); });
  document.addEventListener('visibilitychange', () => { if (document.hidden) synth.cancel(); });
  updateVoices();
})();
