import{c as m,m as v,r,j as s}from"./chunks/mount-DDHaFljI.js";/**
 * @license lucide-react v0.469.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const y=m("ArrowRight",[["path",{d:"M5 12h14",key:"1ays0h"}],["path",{d:"m12 5 7 7-7 7",key:"xquz4c"}]]);/**
 * @license lucide-react v0.469.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const b=m("X",[["path",{d:"M18 6 6 18",key:"1bl5f8"}],["path",{d:"m6 6 12 12",key:"d8bk6v"}]]),N=1e4,S=550,l=2;function g({pool:t=[]}){const[f,x]=r.useState(0),[d,c]=r.useState(!1),[i,j]=r.useState(!1),n=r.useRef(null);if(r.useEffect(()=>{const e=setTimeout(()=>c(!0),1200);return()=>clearTimeout(e)},[]),r.useEffect(()=>{if(i||t.length===0)return;function e(){n.current&&clearInterval(n.current),n.current=null}function a(){c(!1),setTimeout(()=>{x(u=>u+l>=t.length?0:u+l),c(!0)},S)}function o(){e(),n.current=setInterval(a,N)}o();function h(){document.hidden?e():o()}return document.addEventListener("visibilitychange",h),()=>{e(),document.removeEventListener("visibilitychange",h)}},[i,t.length]),i||t.length===0)return null;const p=Array.from({length:Math.min(l,t.length)},(e,a)=>t[(f+a)%t.length]);return s.jsxs("div",{className:"rh-ad-wrap"+(d?" show":""),"aria-label":"Sponsored listings",children:[s.jsxs("div",{className:"rh-ad-header",children:[s.jsx("span",{children:"Sponsored · For You"}),s.jsx("button",{type:"button",className:"rh-ad-close","aria-label":"Close ads",onClick:()=>j(!0),children:s.jsx(b,{size:14,"aria-hidden":"true"})})]}),p.map((e,a)=>s.jsxs("a",{href:e.url,className:"rh-ad-card "+(d?"show":"hide"),style:a===1?{transitionDelay:".12s"}:void 0,children:[s.jsx("img",{className:"rh-ad-thumb",src:e.image,alt:e.title}),s.jsxs("div",{className:"rh-ad-body",children:[s.jsx("p",{className:"rh-ad-title",children:e.title}),s.jsx("p",{className:"rh-ad-loc",children:e.location||""}),s.jsxs("p",{className:"rh-ad-price",children:["₱"+e.price,s.jsx("span",{style:{fontWeight:600,color:"#8B93A6"},children:"/month"})]}),s.jsxs("span",{className:"rh-ad-cta",children:["VIEW LISTING ",s.jsx(y,{size:11,"aria-hidden":"true"})]})]})]},e.id))]})}v("react-ads",g);

