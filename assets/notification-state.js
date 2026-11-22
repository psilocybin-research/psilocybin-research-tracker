/* Shared by the app and service worker. Only device choices and public batch IDs are stored. */
(function (root) {
  'use strict';
  const defaults = () => ({preferences:{sound:true,vibration:true,badge:true,haptics:true},events:[],seen:[],history:[]});
  const transact = (change) => new Promise((resolve,reject) => {
    const opening = indexedDB.open('publication-tracker-notifications-v1',1);
    opening.onupgradeneeded=()=>opening.result.createObjectStore('device');
    opening.onerror=()=>reject(opening.error);
    opening.onblocked=()=>reject(new Error('Notification storage is unavailable.'));
    opening.onsuccess=()=>{
      const db=opening.result,tx=db.transaction('device','readwrite'),store=tx.objectStore('device');
      let state;
      const request=store.get('state');
      request.onsuccess=()=>{state={...defaults(),...request.result};state.preferences={...defaults().preferences,...state.preferences};change?.(state);store.put(state,'state');};
      tx.oncomplete=()=>{db.close();resolve(state);};
      tx.onerror=tx.onabort=()=>{db.close();reject(tx.error||new Error('Notification storage is unavailable.'));};
    };
  });
  const count=state=>state.events.reduce((sum,event)=>sum+event.count,0);
  const badge=async()=>{
    const state=await transact();
    const n=state.preferences.badge?count(state):0;
    try {if(n&&root.navigator?.setAppBadge)await root.navigator.setAppBadge(n);else if(root.navigator?.clearAppBadge)await root.navigator.clearAppBadge();}catch(_){}
    return state;
  };
  root.TrackerNotifications={
    read:()=>transact(),count,badge,
    async preferences(preferences){await transact(state=>{state.preferences={...state.preferences,...preferences};});return badge();},
    async receive(data){
      if(data.kind==='test'||!/^[a-f0-9]{64}$/.test(data.batch||''))return transact();
      await transact(state=>{if(!state.seen.includes(data.batch)&&!state.events.some(event=>event.id===data.batch))state.events.push({id:data.batch,count:Math.max(1,Math.floor(Number(data.count)||1)),url:data.url,time:Date.now()});});
      return badge();
    },
    async recordReceived(data){ return transact(state=>{ const history=Array.isArray(state.history)?state.history:[]; const id=data.kind==='test'?'test-'+Date.now():String(data.batch||''); if(!id||history.some(item=>item.id===id))return; history.unshift({id,title:String(data.title||'Research notification').slice(0,200),time:Date.now(),url:String(data.url||'')}); state.history=history.slice(0,200); }); },
    async viewed(id){
      await transact(state=>{
        const ids=id?[id]:state.events.map(event=>event.id);
        state.seen=[...new Set([...state.seen,...ids])];
        state.events=state.events.filter(event=>!ids.includes(event.id));
      });
      return badge();
    }
  };
})(typeof self!=='undefined'?self:globalThis);
