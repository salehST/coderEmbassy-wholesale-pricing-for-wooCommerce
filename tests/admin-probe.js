// Execute the actual modal-save callback with an isolated DOM and fetch boundary.
const fs=require('fs'),vm=require('vm');
const src=fs.readFileSync(require('path').join(__dirname,'../assets/admin.js'),'utf8');
if(process.argv[2]==='tabs'){
 (async()=>{
  // Expose the real router at the existing init boundary; no replacement registry.
  const boundary=src.lastIndexOf('\nloadTheme();');
  if(boundary<0)throw Error('Missing app init boundary');
  const elements=new Map();
  const el=()=>({innerHTML:'',textContent:'',dataset:{},style:{},classList:{add(){},remove(){},toggle(){}},appendChild(){},addEventListener(){},querySelectorAll(){return [];}});
  const doc={body:el(),createElement:el,querySelectorAll:()=>[],getElementById:id=>{if(!elements.has(id))elements.set(id,el());return elements.get(id);}};
  const tabs=['example/a','example/b','example/missing'].map(id=>({id,label:id,order:50,capability:'manage_woocommerce',script_handle:'example-admin'}));
  const sandbox={document:doc,CEWP:{tabs},localStorage:{getItem(){return null;}},setTimeout(){},clearTimeout(){},console,fetch:async()=>{throw Error('Unexpected fetch');}};
  sandbox.window=sandbox;vm.createContext(sandbox);
  vm.runInContext(src.slice(0,boundary)+'\nwindow.__probe={state,renderPage,loadPage};\n})();',sandbox);
  const register=sandbox.CEWP.registerTab;
  const result={available:typeof register==='function',selectedOnly:false,duplicateRejected:false,coreRejected:false,unknownRejected:false,safeMissing:false};
  if(result.available){
   let a=0,b=0,duplicate=0,unknown=0,core=0;
   const tryRegister=(id,fn)=>{try{register(id,fn);}catch(e){}};
   register('example/a',()=>{a++;return 'Example A';});register('example/b',()=>{b++;return 'Example B';});
   tryRegister('example/a',()=>{duplicate++;return 'duplicate';});
   tryRegister('settings',()=>{core++;return 'core override';});
   tryRegister('example/unknown',()=>{unknown++;return 'unknown override';});
   const select=async id=>{sandbox.__probe.state.page=id;const html=sandbox.__probe.renderPage();await sandbox.__probe.loadPage();return String(html)+' '+[...elements.values()].map(e=>e.innerHTML+' '+e.textContent).join(' ');};
   await select('example/a');result.selectedOnly=a===1&&b===0;
   await select('example/b');result.selectedOnly=result.selectedOnly&&a===1&&b===1;
   result.duplicateRejected=duplicate===0;
   await select('example/unknown');result.unknownRejected=unknown===0;
   const missing=await select('example/missing');result.safeMissing=/unavailable|not available|not loaded/i.test(missing)&&!/<script/i.test(missing);
   // render the core page without fetching; extension renderer must never win.
   sandbox.__probe.state.page='settings';sandbox.__probe.renderPage();result.coreRejected=core===0;
  }
  console.log(JSON.stringify(result));
 })().catch(e=>{console.error(e);process.exitCode=1;});
}else{
const start=src.indexOf('  `, async () => {',src.indexOf('pm-price-type-'));
const end=src.indexOf('\n  });',start);
if(start<0||end<0)throw Error('Cannot locate modal callback; update test adapter explicitly');
const callback=src.slice(start+'  `, '.length,end)+'\n}';
const apiStart=src.indexOf('async function api('),apiEnd=src.indexOf('\n}',apiStart)+2;
const api=src.slice(apiStart,apiEnd);
(async()=>{const results=[];for(const scenario of ['http-message','http-error','success-error','network','unknown']){
 const errorEl={textContent:'',style:{}};const toasts=[];
 const payload=scenario==='http-error'?{error:'duplicate_rule'}:{error:'duplicate_rule',message:'Duplicate rule for product and role'};
 const sandbox={API:'',NONCE:'',uid:'test',id:7,document:{getElementById:k=>k.startsWith('pm-error')?errorEl:{value:k.includes('price-type')?'fixed':'1'}},toast:(m,t)=>toasts.push([m,t]),loadPrices:()=>{},fetch:async()=>{if(scenario==='network')throw Error('Network unavailable');return {ok:scenario==='success-error',text:async()=>scenario==='unknown'?'':JSON.stringify(payload)};}};
 vm.createContext(sandbox);vm.runInContext(api+'\nthis.save='+callback,sandbox);
 const close=await sandbox.save();const text=[errorEl.textContent,...toasts.map(t=>t[0])].join(' ');
 results.push({scenario,close,text,specific:/duplicate/i.test(text),safe:!!text&&!/SQLSTATE|SELECT |UPDATE |INSERT /i.test(text)});
}console.log(JSON.stringify(results));})();
}
