import * as pdfjs from './vendor/pdf.min.mjs';
import {fieldNames,suggestFields,refineDashedFields,validFields,composeFinal} from './document-placement-core.js?v=20260914';

pdfjs.GlobalWorkerOptions.workerSrc=new URL('./vendor/pdf.worker.min.mjs',import.meta.url).href;
const options=JSON.parse(document.getElementById('placement-options').textContent);
const $=id=>document.getElementById(id);
const status=$('placement-status'),selector=$('placement-page'),stage=$('placement-stage'),overlay=$('placement-overlay');
const preview=$('placement-preview'),save=$('placement-save'),review=$('placement-review'),checked=$('placement-reviewed');
let source,signature,stamp,documentPdf,pageNumber=1,viewport,boxes={},active='signature',start=null,finalBytes=null,busy=false;
const pdfOptions=data=>({data:data.slice(0),isEvalSupported:false,standardFontDataUrl:new URL('./vendor/standard_fonts/',import.meta.url).href,wasmUrl:new URL('./vendor/wasm/',import.meta.url).href,cMapUrl:new URL('./vendor/cmaps/',import.meta.url).href,cMapPacked:true});
const message=text=>{status.textContent=text;};

function invalidate() { finalBytes=null;checked.checked=false;save.disabled=true;review.hidden=true; }
function paint() {
  overlay.replaceChildren();
  for(const [key,box] of Object.entries(boxes)) {
    const div=document.createElement('div');div.className='placement-box';
    Object.assign(div.style,{left:box.x/viewport.width*100+'%',top:box.y/viewport.height*100+'%',width:box.width/viewport.width*100+'%',height:box.height/viewport.height*100+'%'});
    const label=document.createElement('span');label.textContent=fieldNames[key];div.append(label);overlay.append(div);
  }
  preview.disabled=busy||!validFields(boxes,viewport);
}
function choose(key) {active=key;document.querySelectorAll('[data-field]').forEach(button=>button.classList.toggle('is-selected',button.dataset.field===key));}
async function render(pdfPage,canvas) {
  const view=pdfPage.getViewport({scale:1.7});canvas.width=Math.ceil(view.width);canvas.height=Math.ceil(view.height);
  await pdfPage.render({canvasContext:canvas.getContext('2d'),viewport:view}).promise;
}
async function detect(pdfPage) {
  const text=await pdfPage.getTextContent();
  return suggestFields(text.items,pdfPage.getViewport({scale:1}),pdfjs.Util.transform);
}
async function showPage(number,suggestion=null) {
  if(busy)return;
  busy=true;invalidate();boxes={};overlay.replaceChildren();preview.disabled=true;selector.disabled=true;
  try {
    pageNumber=number;selector.value=String(number);
    const page=await documentPdf.getPage(number);viewport=page.getViewport({scale:1});
    stage.style.maxWidth=Math.min(1100,viewport.width*1.7)+'px';
    await render(page,$('placement-source'));
    boxes=refineDashedFields(suggestion || await detect(page) || {},viewport,$('placement-source'));
    message(Object.keys(boxes).length===3?'Εντοπίστηκαν τα τρία πεδία. Ελέγξτε τα πλαίσια και ανοίξτε την προεπισκόπηση.':'Επιλέξτε Υπογραφή, Ημερομηνία και Σφραγίδα και σχεδιάστε τις περιοχές στην αριστερή κάρτα.');
  } finally {busy=false;selector.disabled=false;paint();}
}
const point=event=>{const r=overlay.getBoundingClientRect();return {x:Math.max(0,Math.min(viewport.width/2,(event.clientX-r.left)/r.width*viewport.width)),y:Math.max(0,Math.min(viewport.height,(event.clientY-r.top)/r.height*viewport.height))};};
overlay.addEventListener('pointerdown',event=>{if(busy||!viewport)return;invalidate();start=point(event);overlay.setPointerCapture(event.pointerId);});
overlay.addEventListener('pointermove',event=>{if(!start)return;const end=point(event);boxes[active]={x:Math.min(start.x,end.x),y:Math.min(start.y,end.y),width:Math.abs(end.x-start.x),height:Math.abs(end.y-start.y)};paint();});
overlay.addEventListener('pointerup',()=>{start=null;paint();});
overlay.addEventListener('pointercancel',()=>{start=null;paint();});
document.querySelectorAll('[data-field]').forEach(button=>button.addEventListener('click',()=>choose(button.dataset.field)));
selector.addEventListener('change',()=>showPage(Number(selector.value)).catch(error=>message(error.message)));
$('placement-auto').addEventListener('click',()=>showPage(pageNumber).catch(error=>message(error.message)));
preview.addEventListener('click',async()=>{
  if(busy)return;busy=true;selector.disabled=true;invalidate();paint();message('Δημιουργία και απόδοση της τελικής προεπισκόπησης…');
  try {
    const bytes=await composeFinal(window.PDFLib,source,signature,stamp,options.date,pageNumber,viewport,boxes);
    const finalPdf=await pdfjs.getDocument(pdfOptions(bytes)).promise;
    await render(await finalPdf.getPage(pageNumber),$('placement-result'));await finalPdf.destroy();
    finalBytes=bytes;review.hidden=false;review.scrollIntoView({behavior:'smooth',block:'start'});
    message('Ελέγξτε την τελική σελίδα παρακάτω πριν την αποθήκευση.');
  }catch(error){message(error.message||'Η προεπισκόπηση απέτυχε.');}
  finally{busy=false;selector.disabled=false;paint();}
});
checked.addEventListener('change',()=>{save.disabled=!checked.checked||!finalBytes||busy;});
save.addEventListener('click',()=>{
  if(!checked.checked||!finalBytes||busy)return;
  const transfer=new DataTransfer();transfer.items.add(new File([finalBytes],'fully-signed.pdf',{type:'application/pdf'}));
  $('final-pdf').files=transfer.files;
  if(!$('final-upload').reportValidity())return;
  save.disabled=true;busy=true;message('Αποθήκευση της ελεγμένης έκδοσης…');$('final-upload').submit();
});
async function bytesFrom(url) {const response=await fetch(url,{credentials:'same-origin',cache:'no-store'});if(!response.ok)throw new Error('Δεν ήταν δυνατή η φόρτωση του αρχείου.');return new Uint8Array(await response.arrayBuffer());}
choose('signature');
try {
  [source,signature,stamp]=await Promise.all([bytesFrom(options.source),bytesFrom(options.signature),bytesFrom(options.stamp)]);
  documentPdf=await pdfjs.getDocument(pdfOptions(source)).promise;
  for(let i=1;i<=documentPdf.numPages;i++){const option=document.createElement('option');option.value=i;option.textContent=`${i} / ${documentPdf.numPages}`;selector.append(option);}
  let suggestion=null,chosen=1;
  for(let i=documentPdf.numPages;i>=1;i--){suggestion=await detect(await documentPdf.getPage(i));if(suggestion){chosen=i;break;}}
  await showPage(chosen,suggestion);$('placement-auto').disabled=false;
}catch(error){message('Δεν φορτώθηκε η προεπισκόπηση: '+error.message);preview.disabled=true;save.disabled=true;}
