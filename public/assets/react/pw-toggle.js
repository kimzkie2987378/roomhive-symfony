import{c as a,m as l,r as n,j as o}from"./chunks/mount-DDHaFljI.js";/**
 * @license lucide-react v0.469.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const p=a("EyeOff",[["path",{d:"M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49",key:"ct8e1f"}],["path",{d:"M14.084 14.158a3 3 0 0 1-4.242-4.242",key:"151rxh"}],["path",{d:"M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143",key:"13bj9a"}],["path",{d:"m2 2 20 20",key:"1ooewy"}]]);/**
 * @license lucide-react v0.469.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const d=a("Eye",[["path",{d:"M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0",key:"1nclc0"}],["circle",{cx:"12",cy:"12",r:"3",key:"1v7zrd"}]]);function f({targetId:e=""}){const[t,c]=n.useState(!1),r=n.useRef(null);if(n.useEffect(()=>{r.current=e?document.getElementById(e):null},[e]),!e)return null;function u(){c(function(i){var s=!i;return r.current&&(r.current.type=s?"text":"password"),s})}return o.jsx("button",{type:"button",onClick:u,"aria-label":t?"Hide password":"Show password","aria-pressed":t,className:"absolute right-3 top-1/2 -translate-y-1/2 p-1 bg-transparent border-0 rounded-md text-ink-soft hover:text-honey-dark transition-colors cursor-pointer",children:t?o.jsx(p,{size:17}):o.jsx(d,{size:17})})}l("react-pwtoggle",f);

