// Execute the production modal with a small observable DOM (no browser dependencies).
const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
const src=fs.readFileSync(require('path').join(__dirname,'../assets/admin.js'),'utf8');
const code=src.slice(src.indexOf('function createModal('),src.indexOf('// ── Product autocomplete'));
let checks=0;
const check=(value,label)=>{assert.ok(value,label);checks++;};
class Element {
 constructor(){this.listeners={};this.disabled=false;this.hidden=false;this.isConnected=true;this.attrs={};}
 addEventListener(type,fn){(this.listeners[type]??=[]).push(fn);}
 async fire(type,event={}){for(const fn of this.listeners[type]||[])await fn({target:this,...event});}
 focus(){document.activeElement=this;}
 remove(){this.isConnected=false;}
 getAttribute(name){return this.attrs[name]??null;}
 setAttribute(name,value){this.attrs[name]=String(value);}
 getClientRects(){return this.hidden?[]:[{}];}
 querySelector(selector){return this.nodes[selector];}
 querySelectorAll(){return this.controls.filter(e=>!e.disabled&&!e.hidden);}
 set innerHTML(html){
  this.html=html;this.controls=Array.from({length:5},()=>new Element());
  this.nodes={'.dpp-modal':new Element(),'.dpp-modal-title':new Element(),'.dpp-modal-close':this.controls[0],'.dpp-modal-cancel':this.controls[3],'.dpp-modal-save':this.controls[4]};
  for(const [selector,tag] of [['.dpp-modal',/<div class="dpp-modal"([^>]*)>/],['.dpp-modal-title',/<div class="dpp-modal-title"([^>]*)>/]]){
   const match=html.match(tag);for(const a of (match?.[1]||'').matchAll(/([\w-]+)="([^"]*)"/g))this.nodes[selector].attrs[a[1]]=a[2];
  }
 }
}
const opener=new Element(),document={activeElement:opener,createElement:()=>new Element()};
const root={querySelectorAll:()=>[],appendChild(){}};
const sandbox={document,root,icons:{close:'x'}};vm.createContext(sandbox);vm.runInContext(code,sandbox);
(async()=>{
 for(const title of ['Add Price Rule','Edit Price Rule','Shared extension dialog']){
  opener.focus();let saves=0,allow=false;
  const ov=sandbox.createModal(title,'<input><button disabled>Hidden</button>',async()=>{saves++;return allow;});
  const dialog=ov.querySelector('.dpp-modal');
  check(dialog.getAttribute('role')==='dialog','dialog role');
  check(dialog.getAttribute('aria-modal')==='true','modal semantics');
  check(!!dialog.getAttribute('aria-labelledby')&&dialog.getAttribute('aria-labelledby')===ov.querySelector('.dpp-modal-title').getAttribute('id'),'label resolves to title');
  check(ov.controls.includes(document.activeElement),'focus enters modal');
  const key=async(key,shiftKey=false)=>{let prevented=false;await ov.fire('keydown',{key,shiftKey,preventDefault(){prevented=true;}});return prevented;};
  ov.controls[4].focus();check(await key('Tab')&&document.activeElement===ov.controls[0],'Tab wraps');
  check(await key('Tab',true)&&document.activeElement===ov.controls[4],'Shift+Tab wraps');
  ov.controls[1].focus();check(!await key('Tab'),'interior Tab remains native');
  ov.controls[4].disabled=true;ov.controls[2].hidden=true;ov.controls[3].focus();
  check(await key('Tab')&&document.activeElement===ov.controls[0],'trap refresh excludes disabled/hidden controls');
  ov.controls[4].disabled=false;
  await ov.fire('click',{target:dialog});check(ov.isConnected,'interior click stays open');
  await ov.controls[4].fire('click');check(saves===1&&ov.isConnected&&!ov.controls[4].disabled,'rejected submit remains open and re-enabled');
  allow=true;await ov.controls[4].fire('click');check(saves===2&&!ov.isConnected&&document.activeElement===opener,'successful submit closes and restores focus');
  for(const action of ['escape','backdrop','cancel','close']){
   const next=sandbox.createModal(title,'',async()=>true);
   if(action==='escape')await next.fire('keydown',{key:'Escape',preventDefault(){}});
   else if(action==='backdrop')await next.fire('click');
   else await next.querySelector('.dpp-modal-'+action).fire('click');
   check(!next.isConnected&&document.activeElement===opener,action+' closes and restores focus');
  }
 }
 // Exercise the actual add/edit callers and inline import handlers as well.
 const elements=new Map(),calls=[],toasts=[];let current,fail=false,reloads=0;
 document.getElementById=id=>{if(!elements.has(id)){const el=new Element();el.style={};el.value=id.includes('price-type')?'fixed':'1';elements.set(id,el);}return elements.get(id);};
 root.appendChild=ov=>{current=ov;};
 Object.assign(sandbox,{CUR:'$',Date,escHtml:String,bindProductAC(){},toast:(...args)=>toasts.push(args),loadPrices:()=>reloads++,state:{importer:{}},render(){},loadPage(){},api:async(path,method,body)=>{
  calls.push({path,method,body});if(fail)throw Error('Fixture rejection');
  return path==='roles'?[{role_key:'wholesale',label:'Wholesale'}]:{id:7,product_id:1,price_value:5};
 }});
 const start=src.indexOf('async function openPriceModal('),end=src.indexOf('function renderImportPage()',start);
 vm.runInContext(src.slice(start,end),sandbox);
 for(const id of [null,7]){
  opener.focus();await sandbox.openPriceModal(id);
  check(current.html.includes(id?'Edit Price Rule':'Add Price Rule'),'actual price dialog opens');
  await current.querySelector('.dpp-modal-save').fire('click');
  const call=calls.findLast(c=>c.method);
  check(call.path===(id?'prices/7':'prices')&&call.method===(id?'PUT':'POST'),'actual price submission route');
  check(!current.isConnected&&document.activeElement===opener,'actual price submission closes/restores');
  check(toasts.at(-1)[0]===(id?'Rule updated!':'Rule created!'),'success toast unchanged');
 }
 check(reloads===2,'successful saves reload prices');
 await sandbox.openPriceModal();fail=true;await current.querySelector('.dpp-modal-save').fire('click');
 check(current.isConnected&&toasts.at(-1)[1]==='error','actual failed save remains open with error toast');
 fail=false;await current.querySelector('.dpp-modal-cancel').fire('click');
 const importStart=src.indexOf('async function loadImport('),importEnd=src.indexOf('function renderSettingsPage()',importStart);
 vm.runInContext(src.slice(importStart,importEnd),sandbox);await sandbox.loadImport();
 const file=document.getElementById('dpp-import-file'),run=document.getElementById('dpp-import-run');
 file.files=[{name:'fixture.csv',text:async()=> 'product_id,price_value\n1,5'}];await file.fire('change');
 check(sandbox.state.importer.preview.length===2,'actual import preview opens');
 await run.fire('click');check(calls.at(-1).path==='import/csv'&&toasts.at(-1)[0]==='Import complete'&&!run.disabled,'actual import submit succeeds');
 fail=true;await run.fire('click');check(toasts.at(-1)[0]==='Import failed: Fixture rejection'&&!run.disabled,'actual import error restores button');
 console.log(`${checks} dialog/workflow assertions passed`);
})().catch(e=>{console.error(e);process.exitCode=1;});
