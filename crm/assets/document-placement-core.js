export const fieldNames = {signature:'Υπογραφή',date:'Ημερομηνία',stamp:'Σφραγίδα'};

// Coordinates are in the displayed PDF viewport, in points, including its crop and rotation.
export function suggestFields(items, viewport, transform) {
  const text = items.filter(item => item.str && item.str.trim()).map(item => {
    const matrix = transform(viewport.transform, item.transform);
    const height = Math.hypot(matrix[2], matrix[3]);
    return {text:item.str.trim().replace(/:$/, '').toLowerCase(), x:matrix[4], y:matrix[5]-height, height};
  });
  const left = text.filter(item => item.x < viewport.width / 2);
  if (!left.some(item => /for\s+distillogic/.test(item.text))) return null;
  const signature = left.find(item => item.text === 'signature');
  if (!signature) return null;
  const date = left.find(item => item.text === 'date' && item.y > signature.y);
  const stamp = left.find(item => /^(company )?stamp$/.test(item.text) && item.y > signature.y);
  if (!date || !stamp || stamp.y < date.y) return null;
  const rightSignature = text.find(item => item.text === 'signature' && item.x > viewport.width / 2);
  const width = Math.min(viewport.width/2-18-signature.x, rightSignature ? rightSignature.x-signature.x-30 : 1000);
  const x = signature.x + 6;
  const sigTop = signature.y + signature.height + 10;
  const dateTop = date.y + date.height + 7;
  const stampTop = stamp.y + stamp.height + 20;
  const footer = text.filter(item => item.y > stampTop && /distillogic|engineering|legal review/.test(item.text)).sort((a,b)=>a.y-b.y)[0];
  const boxes = {
    signature:{x,y:sigTop,width:width-12,height:date.y-sigTop-12},
    date:{x,y:dateTop,width:width-12,height:Math.min(19,stamp.y-dateTop-17)},
    stamp:{x,y:stampTop,width:width-12,height:Math.min(49,(footer?.y ?? viewport.height-30)-stampTop-14)},
  };
  return validFields(boxes,viewport) ? boxes : null;
}

export function validFields(boxes,viewport) {
  const list = Object.keys(fieldNames).map(key=>boxes[key]);
  if (list.some(box => !box || ![box.x,box.y,box.width,box.height].every(Number.isFinite) || box.width<25 || box.height<10 || box.x<0 || box.y<0 || box.x+box.width>viewport.width/2 || box.y+box.height>viewport.height)) return false;
  for (let i=0;i<list.length;i++) for(let j=i+1;j<list.length;j++) {
    const a=list[i],b=list[j];
    if(a.x<b.x+b.width && b.x<a.x+a.width && a.y<b.y+b.height && b.y<a.y+a.height) return false;
  }
  return true;
}

export function fitAsset(asset,box) {
  const scale=Math.min((box.width-4)/asset.width,(box.height-4)/asset.height);
  return {x:box.x+(box.width-asset.width*scale)/2,y:box.y+(box.height-asset.height*scale)/2,width:asset.width*scale,height:asset.height*scale};
}

// Refine against the actual dashed borders in the rendered source page.
// Text locates the field; repeated coloured dashes locate its exact interior.
export function refineDashedFields(boxes,viewport,canvas) {
  if (!boxes.signature || !boxes.stamp) return boxes;
  const {data,width,height}=canvas.getContext('2d').getImageData(0,0,canvas.width,canvas.height);
  const scaleX=width/viewport.width,scaleY=height/viewport.height;
  const result=structuredClone(boxes);
  for(const key of ['signature','stamp']) {
    const box=boxes[key],left=Math.max(0,Math.floor((box.x-6)*scaleX)),right=Math.min(width,Math.ceil((box.x+box.width+6)*scaleX));
    const top=Math.max(0,Math.floor((box.y-22)*scaleY)),bottom=Math.min(height,Math.ceil((box.y+box.height+35)*scaleY));
    const rows=[];
    for(let y=top;y<bottom;y++) {
      let count=0,runs=0,last=false;
      for(let x=left;x<right;x++) {
        const offset=(y*width+x)*4,r=data[offset],g=data[offset+1],b=data[offset+2];
        const ink=r>70&&r<195&&g-r>15&&b-r>45&&b-g>20;
        if(ink){count++;if(!last)runs++;}last=ink;
      }
      if(count>(right-left)*.20&&count<(right-left)*.90&&runs>=8)rows.push(y);
    }
    const groups=[];for(const row of rows){if(!groups.length||row>groups.at(-1).at(-1)+2)groups.push([row]);else groups.at(-1).push(row);}
    if(groups.length===2) {
      const innerTop=(groups[0].at(-1)+1)/scaleY+3,innerBottom=groups[1][0]/scaleY-3;
      if(innerBottom-innerTop>=15)result[key]={...box,y:innerTop,height:innerBottom-innerTop};
    }
  }
  return validFields(result,viewport)?result:boxes;
}

export async function composeFinal(PDFLib,source,signature,stamp,date,pageNumber,viewport,boxes) {
  if (!validFields(boxes,viewport)) throw new Error('Επιλέξτε τρία χωριστά πλαίσια στην πλευρά της DISTILLOGIC.');
  const pdf=await PDFLib.PDFDocument.load(source.slice(0));
  for(const [,object] of pdf.context.enumerateIndirectObjects()) {
    if(object instanceof PDFLib.PDFDict && object.has(PDFLib.PDFName.of('ByteRange'))) {
      throw new Error('Το PDF περιέχει κρυπτογραφική υπογραφή. Δεν θα τροποποιηθεί από αυτή τη διαδικασία· απαιτείται συμβατή διαδικασία συνυπογραφής.');
    }
  }
  const page=pdf.getPage(pageNumber-1);
  const rotation=PDFLib.degrees(viewport.rotation);
  const images={signature:await pdf.embedPng(signature),stamp:await pdf.embedPng(stamp)};
  for(const key of ['signature','stamp']) {
    const rect=fitAsset(images[key],boxes[key]);
    const [x,y]=viewport.convertToPdfPoint(rect.x,rect.y+rect.height);
    // Transparent PNG only. Never paint an opaque patch over the source PDF.
    page.drawImage(images[key],{x,y,width:rect.width,height:rect.height,rotate:rotation});
  }
  const font=await pdf.embedFont(PDFLib.StandardFonts.HelveticaBold);
  const box=boxes.date;
  const size=Math.min(10.5,box.height-4,(box.width-4)/font.widthOfTextAtSize(date,1));
  const left=box.x+(box.width-font.widthOfTextAtSize(date,size))/2;
  const baseline=box.y+(box.height+size*.7)/2;
  const [x,y]=viewport.convertToPdfPoint(left,baseline);
  page.drawText(date,{x,y,size,font,color:PDFLib.rgb(23/255,106/255,252/255),rotate:rotation});
  return pdf.save();
}
